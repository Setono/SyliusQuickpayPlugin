<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Controller\Admin;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Setono\SyliusQuickpayPlugin\Quickpay\QuickpayClientProviderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Core\Repository\PaymentRepositoryInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Twig\Environment;

/**
 * Returns the rendered Quickpay operation history for a payment. The admin order view loads
 * this after the page itself has rendered, so a slow or unreachable Quickpay never delays the
 * order page — a failure here renders an inline notice with a retry link instead, and is logged.
 */
final class PaymentOperationsAction implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * @param PaymentRepositoryInterface<PaymentInterface> $paymentRepository
     */
    public function __construct(
        private readonly PaymentRepositoryInterface $paymentRepository,
        private readonly QuickpayClientProviderInterface $quickpayClientProvider,
        private readonly Environment $twig,
    ) {
    }

    public function __invoke(int $id): Response
    {
        $payment = $this->paymentRepository->find($id);
        if (!$payment instanceof PaymentInterface) {
            throw new NotFoundHttpException(sprintf('Payment %d does not exist', $id));
        }

        try {
            $quickpayPayment = $this->quickpayClientProvider->quickpayPaymentOf($payment);
        } catch (\Throwable $e) {
            // An unusable gateway configuration renders the same inline notice as an unreachable Quickpay
            $method = $payment->getMethod();
            $this->logger?->warning(sprintf('Could not load the operation history from Quickpay: %s', $e->getMessage()), [
                'paymentId' => $id,
                'gatewayName' => $method instanceof PaymentMethodInterface ? $method->getGatewayConfig()?->getGatewayName() : null,
                'exception' => $e,
            ]);

            return new Response(
                $this->twig->render('@SetonoSyliusQuickpayPlugin/admin/order/show/payment/_operations_error.html.twig'),
                Response::HTTP_BAD_GATEWAY,
            );
        }

        if (null === $quickpayPayment) {
            throw new NotFoundHttpException(sprintf('Payment %d has no Quickpay payment', $id));
        }

        return new Response($this->twig->render('@SetonoSyliusQuickpayPlugin/admin/order/show/payment/_operations.html.twig', [
            'quickpay_payment' => $quickpayPayment,
        ]));
    }
}
