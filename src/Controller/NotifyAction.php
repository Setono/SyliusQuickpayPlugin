<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Controller;

use Payum\Core\Payum;
use Payum\Core\Request\Notify;
use Safe\Exceptions\JsonException;
use Sylius\Bundle\PayumBundle\Model\GatewayConfigInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Core\Repository\OrderRepositoryInterface;
use Sylius\Component\Payment\Model\PaymentInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Handles callbacks from QuickPay @see https://learn.quickpay.net/tech-talk/api/callback/
 */
final class NotifyAction
{
    private Payum $payum;

    private OrderRepositoryInterface $orderRepository;

    private string $orderPrefix;

    public function __construct(Payum $payum, OrderRepositoryInterface $orderRepository, string $orderPrefix)
    {
        $this->payum = $payum;
        $this->orderRepository = $orderRepository;
        $this->orderPrefix = $orderPrefix;
    }

    public function __invoke(Request $request): Response
    {
        $type = (string) $request->headers->get('QuickPay-Resource-Type');

        // only handle payments for now
        if ($type !== 'Payment') {
            return new Response('', 204);
        }

        try {
            // https://learn.quickpay.net/tech-talk/api/callback/#request-example
            $data = \Safe\json_decode((string) $request->getContent());
        } catch (JsonException $e) {
            throw new BadRequestHttpException();
        }

        if (!isset($data->id, $data->order_id)) {
            throw new BadRequestHttpException();
        }

        $orderNumber = (string) $data->order_id;

        // an attempt to remove the order prefix in non-prod environments
        // it's optimistic because the prefix saved in the database might be different
        // TODO: better ideas are very welcome
        if (0 === mb_strpos($orderNumber, $this->orderPrefix)) {
            $orderNumber = str_replace($this->orderPrefix, '', $orderNumber);
        }

        /** @var OrderInterface|null $order */
        $order = $this->orderRepository->findOneByNumber($orderNumber);

        if (null === $order) {
            return new Response('', 204);
        }

        $payment = $this->getPaymentFromOrder($order, (int) $data->id);

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

    /**
     * @TODO: maybe move this code somewhere outside?
     */
    private function getPaymentFromOrder(OrderInterface $order, int $quickpayPaymentId): ?PaymentInterface
    {
        $quickpayPayment = $order
            ->getPayments()
            ->filter(
                static function (PaymentInterface $payment) use ($quickpayPaymentId): bool {
                    if (!isset($payment->getDetails()['quickpayPaymentId'])) {
                        return false;
                    }

                    return (int) $payment->getDetails()['quickpayPaymentId'] === $quickpayPaymentId;
                }
            )
            ->last();

        return false === $quickpayPayment ? null : $quickpayPayment;
    }
}
