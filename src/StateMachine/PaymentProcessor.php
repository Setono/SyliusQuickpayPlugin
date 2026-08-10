<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\StateMachine;

use Payum\Core\Exception\ExceptionInterface;
use Payum\Core\Payum;
use Payum\Core\Request\Cancel;
use Payum\Core\Request\Capture;
use Payum\Core\Request\GetHumanStatus;
use Payum\Core\Request\Refund;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Setono\Quickpay\Exception\QuickpayException;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Payment\PaymentTransitions;

final class PaymentProcessor implements PaymentProcessorInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    public function __construct(
        private readonly Payum $payum,
        private readonly bool $captureEnabled,
        private readonly bool $refundEnabled,
        private readonly bool $cancelEnabled,
    ) {
    }

    public function __invoke(PaymentInterface $payment, string $transition): void
    {
        $quickpayPaymentId = $payment->getDetails()['quickpayPaymentId'] ?? null;
        if (null === $quickpayPaymentId) {
            return;
        }

        /** @var PaymentMethodInterface|null $method */
        $method = $payment->getMethod();
        if (null === $method) {
            return;
        }

        $gatewayConfig = $method->getGatewayConfig();
        if (null === $gatewayConfig) {
            return;
        }

        $gateway = $this->payum->getGateway($gatewayConfig->getGatewayName());

        switch ($transition) {
            case PaymentTransitions::TRANSITION_COMPLETE:
                if (!$this->captureEnabled) {
                    return;
                }

                // The status is resolved through the gateway so the payment is re-fetched from
                // Quickpay, guarding against capturing a payment that was already auto captured
                $gateway->execute($status = new GetHumanStatus($payment));
                if ($status->isCaptured()) {
                    return;
                }

                $gateway->execute(new Capture($payment));

                break;
            case PaymentTransitions::TRANSITION_REFUND:
                if (!$this->refundEnabled) {
                    return;
                }

                $gateway->execute($status = new GetHumanStatus($payment));
                if ($status->isRefunded()) {
                    return;
                }

                // An unqualified Refund refunds the remaining balance,
                // so a payment partially refunded directly in the Quickpay manager refunds only what
                // is left; an explicit refund_amount in the details is passed through untouched
                $gateway->execute(new Refund($payment));

                break;
            case PaymentTransitions::TRANSITION_CANCEL:
                if (!$this->cancelEnabled) {
                    return;
                }

                try {
                    $gateway->execute($status = new GetHumanStatus($payment));
                    if ($status->isCanceled()) {
                        return;
                    }

                    $gateway->execute(new Cancel($payment));
                } catch (ExceptionInterface|QuickpayException $e) {
                    // Cancelling the order must not be blocked by Quickpay being unable to cancel
                    // the payment, e.g. because it was never authorized or has already expired.
                    // Unused authorizations expire by themselves at Quickpay.
                    $this->logger?->warning(sprintf('Could not cancel Quickpay payment: %s', $e->getMessage()), [
                        'quickpayPaymentId' => $quickpayPaymentId,
                        'paymentId' => $payment->getId(),
                    ]);
                }

                break;
        }
    }
}
