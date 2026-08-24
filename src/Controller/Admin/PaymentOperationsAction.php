<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Controller\Admin;

use Setono\SyliusQuickpayPlugin\Quickpay\ApiKeyResolver;
use Setono\SyliusQuickpayPlugin\Quickpay\ClientFactoryInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Repository\PaymentRepositoryInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Twig\Environment;

/**
 * Returns the rendered Quickpay operation history for a payment. The admin order view loads
 * this after the page itself has rendered, so a slow or unreachable Quickpay never delays the
 * order page — a failure here renders an inline notice with a retry link instead.
 */
final class PaymentOperationsAction
{
    /**
     * @param PaymentRepositoryInterface<PaymentInterface> $paymentRepository
     */
    public function __construct(
        private readonly PaymentRepositoryInterface $paymentRepository,
        private readonly ClientFactoryInterface $clientFactory,
        private readonly Environment $twig,
    ) {
    }

    public function __invoke(int $id): Response
    {
        $payment = $this->paymentRepository->find($id);
        if (!$payment instanceof PaymentInterface) {
            throw new NotFoundHttpException(sprintf('Payment %d does not exist', $id));
        }

        $quickpayPaymentId = $payment->getDetails()['quickpayPaymentId'] ?? null;
        if (!is_numeric($quickpayPaymentId)) {
            throw new NotFoundHttpException(sprintf('Payment %d has no Quickpay payment id', $id));
        }

        $apiKey = ApiKeyResolver::fromPayment($payment);
        if (null === $apiKey) {
            throw new NotFoundHttpException('The payment\'s gateway carries no api key');
        }

        try {
            $quickpayPayment = $this->clientFactory
                ->create($apiKey)
                ->payments()
                ->getById((int) $quickpayPaymentId)
            ;
        } catch (\Throwable) {
            return new Response(
                $this->twig->render('@SetonoSyliusQuickpayPlugin/admin/order/show/payment/_operations_error.html.twig'),
                Response::HTTP_BAD_GATEWAY,
            );
        }

        return new Response($this->twig->render('@SetonoSyliusQuickpayPlugin/admin/order/show/payment/_operations.html.twig', [
            'quickpay_payment' => $quickpayPayment,
        ]));
    }
}
