<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Controller;

use Setono\Quickpay\Callback\Callback;
use Setono\Quickpay\Enum\ResourceType;
use Setono\SyliusQuickpayPlugin\GatewayConfiguration\GatewayConfigurationProviderInterface;
use Setono\SyliusQuickpayPlugin\GatewayConfiguration\UnusableGatewayConfiguration;
use Setono\SyliusQuickpayPlugin\Provider\PaymentProviderInterface;
use Setono\SyliusQuickpayPlugin\Reconciliation\PaymentReconcilerInterface;
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
final class NotifyAction
{
    /**
     * @param OrderRepositoryInterface<OrderInterface> $orderRepository
     */
    public function __construct(
        private readonly PaymentReconcilerInterface $reconciler,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly PaymentProviderInterface $paymentProvider,
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

        $order = $this->resolveOrder($data['order_id']);

        if (null === $order) {
            return new Response('', 204);
        }

        $payment = $this->paymentProvider->findByQuickpayPaymentId($order, $quickpayPaymentId);

        if (!$payment instanceof PaymentInterface) {
            throw new BadRequestHttpException();
        }

        // A payment skipped because its lock is held is answered like a reconciled one: the lock's holder,
        // e.g. the original of this duplicate, reconciles it, so Quickpay can stop retrying. Errors
        // (a forged callback, Quickpay unreachable) propagate to an error response, so Quickpay retries.
        $this->reconciler->reconcileFromCallback($payment);

        return new Response('', 204);
    }

    private function resolveOrder(string $orderId): ?OrderInterface
    {
        foreach ($this->resolveOrderNumberCandidates($orderId) as $orderNumber) {
            $order = $this->orderRepository->findOneByNumber($orderNumber);
            if ($order instanceof OrderInterface) {
                return $order;
            }
        }

        return null;
    }

    /**
     * The Quickpay order_id was built by prepending the order_prefix of whichever Quickpay gateway
     * created the payment, so the candidates are the incoming order_id stripped of each configured
     * gateway's prefix — the values actually used at creation time, not an env var that may have
     * drifted since.
     *
     * A gateway configuration whose order prefix can't be read fails the callback, so Quickpay retries
     * it: the order might be that gateway's.
     *
     * @return list<string>
     *
     * @throws UnusableGatewayConfiguration
     */
    private function resolveOrderNumberCandidates(string $orderId): array
    {
        $candidates = [];

        foreach ($this->gatewayConfigurationProvider->all() as $gatewayConfiguration) {
            $prefix = $gatewayConfiguration->options()->orderPrefix;
            if ('' !== $prefix && str_starts_with($orderId, $prefix)) {
                $candidates[] = substr($orderId, \strlen($prefix));
            }
        }

        // Covers prefixless configurations and payments created under a prefix that no longer
        // matches any configured gateway
        $candidates[] = $orderId;

        return array_values(array_unique($candidates));
    }
}
