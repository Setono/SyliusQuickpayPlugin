<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Controller;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Setono\Payum\Quickpay\OrderId;
use Setono\Quickpay\Callback\Callback;
use Setono\Quickpay\Enum\ResourceType;
use Setono\SyliusQuickpayPlugin\GatewayConfiguration\GatewayConfigurationProviderInterface;
use Setono\SyliusQuickpayPlugin\GatewayConfiguration\UnusableGatewayConfiguration;
use Setono\SyliusQuickpayPlugin\Quickpay\QuickpayPaymentIdProviderInterface;
use Setono\SyliusQuickpayPlugin\Reconciliation\PaymentReconcilerInterface;
use Setono\SyliusQuickpayPlugin\Reconciliation\ReconcileOutcome;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Repository\OrderRepositoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * The shared callback endpoint: finds the Sylius payment a Quickpay callback is about and reconciles it
 * from the callback (see GLOSSARY.md) @see https://learn.quickpay.net/tech-talk/api/callback/
 */
final class NotifyAction implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * @param OrderRepositoryInterface<OrderInterface> $orderRepository
     */
    public function __construct(
        private readonly PaymentReconcilerInterface $reconciler,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly QuickpayPaymentIdProviderInterface $quickpayPaymentIdProvider,
        private readonly GatewayConfigurationProviderInterface $gatewayConfigurationProvider,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $type = ResourceType::tryFrom((string) $request->headers->get(Callback::RESOURCE_TYPE_HEADER));

        // only handle payments for now
        if (ResourceType::Payment !== $type) {
            return new Response('', 204);
        }

        // @see https://learn.quickpay.net/tech-talk/api/callback/#request-example
        // Invalid JSON or a non-object payload makes toArray() throw Symfony's JsonException,
        // which the framework converts to a 400 response by itself
        $data = $request->toArray();

        if (!isset($data['id'], $data['order_id']) || !is_numeric($data['id']) || !is_string($data['order_id'])) {
            throw new BadRequestHttpException();
        }

        $quickpayPaymentId = (int) $data['id'];

        $payment = $this->findPayment($data['order_id'], $quickpayPaymentId);

        // Retrying can't help: the lookup gives the same answer every time. A payment made under an order prefix
        // since changed, or another environment's payment on the same account, ends here.
        if (null === $payment) {
            $this->logger?->warning(sprintf(
                'No Sylius payment matches the callback of Quickpay payment %d with order id "%s", so nothing was reconciled. Was the order prefix of its payment method changed?',
                $quickpayPaymentId,
                $data['order_id'],
            ), ['quickpayPaymentId' => $quickpayPaymentId, 'orderId' => $data['order_id']]);

            return new Response('', 204);
        }

        // A payment skipped because its lock is held is answered 409, so Quickpay retries the callback: it may
        // report an operation newer than the lock's holder read, and the retry reconciles from a fresh read. Errors
        // (a forged callback, Quickpay unreachable) propagate to an error response, so Quickpay retries too.
        $result = $this->reconciler->reconcileFromCallback($payment);

        return new Response('', ReconcileOutcome::Skipped === $result->outcome ? 409 : 204);
    }

    /**
     * The Sylius payment of the Quickpay payment: the first candidate order number whose order holds it
     *
     * @throws UnusableGatewayConfiguration
     */
    private function findPayment(string $orderId, int $quickpayPaymentId): ?PaymentInterface
    {
        foreach ($this->orderNumberCandidates($orderId) as $orderNumber) {
            $order = $this->orderRepository->findOneByNumber($orderNumber);
            if (!$order instanceof OrderInterface) {
                continue;
            }

            $payment = $this->paymentOf($order, $quickpayPaymentId);
            if (null !== $payment) {
                return $payment;
            }
        }

        return null;
    }

    /**
     * The order's payment linked to the Quickpay payment: the last one whose Quickpay payment id it is. An id that
     * can't be read never matches, and neither does a payment whose method is no longer a Quickpay gateway's
     */
    private function paymentOf(OrderInterface $order, int $quickpayPaymentId): ?PaymentInterface
    {
        $linked = null;
        foreach ($order->getPayments() as $payment) {
            if ($payment instanceof PaymentInterface && $this->quickpayPaymentIdProvider->tryIdOf($payment) === $quickpayPaymentId) {
                $linked = $payment;
            }
        }

        return $linked;
    }

    /**
     * The Quickpay order id (see GLOSSARY.md) is the order prefix of whichever Quickpay gateway created the
     * payment followed by the order number, so the candidates are the number payum-quickpay's
     * `OrderId::numberIn()` reads under each gateway's current prefix. One prefix can start another's
     * (`qp` and `qp_`), so each candidate is only a guess until its order holds the payment.
     *
     * A gateway configuration whose order prefix can't be read fails the callback, so Quickpay retries
     * it: the order might be that gateway's.
     *
     * @return list<string>
     *
     * @throws UnusableGatewayConfiguration
     */
    private function orderNumberCandidates(string $orderId): array
    {
        $candidates = [];

        foreach ($this->gatewayConfigurationProvider->all() as $gatewayConfiguration) {
            $orderNumber = OrderId::numberIn($orderId, $gatewayConfiguration->options()->orderPrefix);
            if (null !== $orderNumber) {
                $candidates[] = $orderNumber;
            }
        }

        // The order id itself, last: a payment created without a prefix by a gateway that has one now
        $candidates[] = $orderId;

        return array_values(array_unique($candidates));
    }
}
