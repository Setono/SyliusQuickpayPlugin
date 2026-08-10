<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Controller;

use Payum\Core\Payum;
use Payum\Core\Request\Notify;
use Setono\Quickpay\Callback\Callback;
use Setono\Quickpay\Enum\ResourceType;
use Setono\SyliusQuickpayPlugin\Provider\PaymentProviderInterface;
use Sylius\Bundle\PayumBundle\Model\GatewayConfigInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Core\Repository\OrderRepositoryInterface;
use Sylius\Component\Resource\Repository\RepositoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Handles callbacks from QuickPay @see https://learn.quickpay.net/tech-talk/api/callback/
 */
final class NotifyAction
{
    /**
     * @param OrderRepositoryInterface<OrderInterface> $orderRepository
     * @param RepositoryInterface<GatewayConfigInterface> $gatewayConfigRepository
     */
    public function __construct(
        private readonly Payum $payum,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly PaymentProviderInterface $paymentProvider,
        private readonly RepositoryInterface $gatewayConfigRepository,
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

        if (null === $payment) {
            throw new BadRequestHttpException();
        }

        /** @var PaymentMethodInterface $method */
        $method = $payment->getMethod();

        /** @var GatewayConfigInterface $gatewayConfig */
        $gatewayConfig = $method->getGatewayConfig();

        $gateway = $this->payum->getGateway($gatewayConfig->getGatewayName());

        // validates request checksum and set the request data
        $gateway->execute(new Notify($payment));

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
     * @return list<string>
     */
    private function resolveOrderNumberCandidates(string $orderId): array
    {
        $candidates = [];

        /** @var GatewayConfigInterface $gatewayConfig */
        foreach ($this->gatewayConfigRepository->findBy(['factoryName' => 'quickpay']) as $gatewayConfig) {
            $prefix = $gatewayConfig->getConfig()['order_prefix'] ?? null;
            if (is_string($prefix) && '' !== $prefix && str_starts_with($orderId, $prefix)) {
                $candidates[] = substr($orderId, \strlen($prefix));
            }
        }

        // Covers prefixless configurations and payments created under a prefix that no longer
        // matches any configured gateway
        $candidates[] = $orderId;

        return array_values(array_unique($candidates));
    }
}
