<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\StateMachine;

use Payum\Core\Exception\ExceptionInterface;
use Payum\Core\Payum;
use Payum\Core\Request\Cancel;
use Payum\Core\Request\Capture;
use Payum\Core\Request\GetHumanStatus;
use Payum\Core\Request\Refund;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Setono\Quickpay\Exception\QuickpayException;
use SM\Event\TransitionEvent;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Payment\PaymentTransitions;

final class PaymentProcessor
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly Payum $payum,
        private readonly bool $disableCapture,
        private readonly bool $disableRefund,
        private readonly bool $disableCancel,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function __invoke(PaymentInterface $payment, TransitionEvent $event): void
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

        switch ($event->getTransition()) {
            case PaymentTransitions::TRANSITION_COMPLETE:
                if ($this->disableCapture) {
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
                if ($this->disableRefund) {
                    return;
                }

                $gateway->execute($status = new GetHumanStatus($payment));
                if ($status->isRefunded()) {
                    return;
                }

                // An unqualified Refund refunds the remaining balance (payum-quickpay >= 2.0.0-alpha.2),
                // so a payment partially refunded directly in the Quickpay manager refunds only what
                // is left; an explicit refund_amount in the details is passed through untouched
                $gateway->execute(new Refund($payment));

                break;
            case PaymentTransitions::TRANSITION_CANCEL:
                if ($this->disableCancel) {
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
                    $this->logger->warning(sprintf('Could not cancel Quickpay payment: %s', $e->getMessage()), [
                        'quickpayPaymentId' => $quickpayPaymentId,
                        'paymentId' => $payment->getId(),
                    ]);
                }

                break;
        }
    }
}
