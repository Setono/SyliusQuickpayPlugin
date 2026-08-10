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
     */
    public function __construct(
        private readonly Payum $payum,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly PaymentProviderInterface $paymentProvider,
        private readonly string $orderPrefix,
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
        $orderNumber = $data['order_id'];

        // an attempt to remove the order prefix in non-prod environments
        // it's optimistic because the prefix saved in the database might be different
        // TODO: better ideas are very welcome
        if ('' !== $this->orderPrefix && str_starts_with($orderNumber, $this->orderPrefix)) {
            $orderNumber = substr($orderNumber, \strlen($this->orderPrefix));
        }

        /** @var OrderInterface|null $order */
        $order = $this->orderRepository->findOneByNumber($orderNumber);

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

}
