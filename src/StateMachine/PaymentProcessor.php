<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\StateMachine;

use Payum\Core\Exception\ExceptionInterface;
use Payum\Core\Payum;
use Payum\Core\Request\Cancel;
use Payum\Core\Request\Capture;
use Payum\Core\Request\Refund;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Setono\Payum\QuickPay\Model\QuickPayPayment;
use Setono\Payum\QuickPay\Model\QuickPayPaymentOperation;
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
                if ($this->disableCapture ||
                    $this->isLastOperationApproved($payment, QuickPayPaymentOperation::TYPE_CAPTURE)) {
                    return;
                }

                $gateway->execute(new Capture($payment->getDetails()));

                break;
            case PaymentTransitions::TRANSITION_REFUND:
                if ($this->disableRefund ||
                    $this->isLastOperationApproved($payment, QuickPayPaymentOperation::TYPE_REFUND)) {
                    return;
                }

                $gateway->execute(new Refund($payment->getDetails()));

                break;
            case PaymentTransitions::TRANSITION_CANCEL:
                if ($this->disableCancel ||
                    $this->isLastOperationApproved($payment, QuickPayPaymentOperation::TYPE_CANCEL)) {
                    return;
                }

                try {
                    $gateway->execute(new Cancel($payment->getDetails()));
                } catch (ExceptionInterface $e) {
                    // Cancelling the order must not be blocked by QuickPay being unable to cancel
                    // the payment, e.g. because it was never authorized or has already expired.
                    // Unused authorizations expire by themselves at QuickPay.
                    $this->logger->warning(sprintf('Could not cancel QuickPay payment: %s', $e->getMessage()), [
                        'quickpayPaymentId' => $quickpayPaymentId,
                        'paymentId' => $payment->getId(),
                    ]);
                }

                break;
        }
    }

    private function isLastOperationApproved(PaymentInterface $payment, string $state): bool
    {
        $quickpayPayment = $payment->getDetails()['quickpayPayment'] ?? null;

        if (!$quickpayPayment instanceof QuickPayPayment) {
            return false;
        }

        $operation = $quickpayPayment->getLatestOperation();

        if (null === $operation) {
            return false;
        }

        return $operation->getType() === $state && $operation->isApproved();
    }
}
