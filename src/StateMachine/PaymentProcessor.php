<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\StateMachine;

use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Exception\ExceptionInterface;
use Payum\Core\Payum;
use Payum\Core\Request\Cancel;
use Payum\Core\Request\Capture;
use Payum\Core\Request\Refund;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Setono\Payum\Quickpay\Details;
use Setono\Quickpay\Exception\QuickpayException;
use Setono\SyliusQuickpayPlugin\Fraud\FraudCheckerInterface;
use Setono\SyliusQuickpayPlugin\GatewayConfiguration\UnusableGatewayConfiguration;
use Setono\SyliusQuickpayPlugin\Reconciliation\PaymentReconcilerInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Payment\PaymentTransitions;

final class PaymentProcessor implements PaymentProcessorInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    public function __construct(
        private readonly Payum $payum,
        private readonly PaymentReconcilerInterface $reconciler,
        private readonly FraudCheckerInterface $fraudChecker,
        private readonly bool $captureEnabled,
        private readonly bool $refundEnabled,
        private readonly bool $cancelEnabled,
        private readonly bool $blockCaptureOnSuspectedFraud,
    ) {
    }

    public function __invoke(PaymentInterface $payment, string $transition): void
    {
        // An operation switched off in the plugin's config leaves the gateway alone: nothing is built, read or
        // sent, so not even an unusable gateway configuration stops the transition
        if (!$this->isSwitchedOn($transition)) {
            return;
        }

        $quickpayPaymentId = $payment->getDetails()['quickpayPaymentId'] ?? null;
        if (null === $quickpayPaymentId) {
            return;
        }

        /** @var PaymentMethodInterface|null $method */
        $method = $payment->getMethod();
        $gatewayConfig = $method?->getGatewayConfig();
        if (null === $gatewayConfig) {
            return;
        }

        $gatewayName = $gatewayConfig->getGatewayName();

        switch ($transition) {
            case PaymentTransitions::TRANSITION_COMPLETE:
                $this->capture($payment, $gatewayName);

                break;
            case PaymentTransitions::TRANSITION_REFUND:
                $this->refund($payment, $gatewayName);

                break;
            case PaymentTransitions::TRANSITION_CANCEL:
                $this->cancel($payment, $gatewayName, $quickpayPaymentId);

                break;
        }
    }

    private function isSwitchedOn(string $transition): bool
    {
        return match ($transition) {
            PaymentTransitions::TRANSITION_COMPLETE => $this->captureEnabled,
            PaymentTransitions::TRANSITION_REFUND => $this->refundEnabled,
            PaymentTransitions::TRANSITION_CANCEL => $this->cancelEnabled,
            default => false,
        };
    }

    /**
     * Captures the payment's authorization at Quickpay. Every error, an unusable gateway configuration's
     * included, aborts the transition: completing a payment must not silently leave its money uncaptured
     *
     * @throws NoAuthorizationToCapture when Quickpay holds no authorization to capture
     */
    private function capture(PaymentInterface $payment, string $gatewayName): void
    {
        $targetState = $this->reconciler->checkStatus($payment)->targetState;

        // Guards against capturing a payment that was already captured — at checkout by a
        // payment method in the immediate capture mode, or in the Quickpay manager
        if (PaymentInterface::STATE_COMPLETED === $targetState) {
            return;
        }

        // The status check read the Quickpay payment by this id, so it is usable
        $quickpayPaymentId = Details::paymentId(new ArrayObject($payment->getDetails()));

        // Only an authorization can be captured. Executing a Capture without one makes Sylius create a
        // payment link instead (or fail building one), so the transition aborts here, with nothing sent to
        // Quickpay after the status check
        if (PaymentInterface::STATE_AUTHORIZED !== $targetState) {
            throw new NoAuthorizationToCapture($payment, $quickpayPaymentId, $targetState);
        }

        // Opt-in guard: leave a fraud suspected payment for manual review instead of taking
        // the money automatically. The transition itself proceeds — the merchant captures
        // (or cancels) in the Quickpay manager after reviewing
        if ($this->blockCaptureOnSuspectedFraud && $this->fraudChecker->isFraudSuspected($payment)) {
            $this->logger?->warning('Skipped the automatic capture: Quickpay reports the payment as fraud suspected. Review the payment and capture or cancel it manually', [
                'quickpayPaymentId' => $quickpayPaymentId,
                'paymentId' => $payment->getId(),
            ]);

            return;
        }

        $this->payum->getGateway($gatewayName)->execute(new Capture($payment));
    }

    /**
     * Refunds the payment at Quickpay. Every error, an unusable gateway configuration's included, aborts the
     * transition: refunding a payment must not silently keep the money
     */
    private function refund(PaymentInterface $payment, string $gatewayName): void
    {
        if ($this->targetStateIs($payment, PaymentInterface::STATE_REFUNDED)) {
            return;
        }

        // An unqualified Refund refunds the remaining balance,
        // so a payment partially refunded directly in the Quickpay manager refunds only what
        // is left; an explicit refund_amount in the details is passed through untouched
        $this->payum->getGateway($gatewayName)->execute(new Refund($payment));
    }

    /**
     * Cancels the payment at Quickpay. Fails open: an error, an unusable gateway configuration's included, is
     * logged and the transition proceeds
     */
    private function cancel(PaymentInterface $payment, string $gatewayName, mixed $quickpayPaymentId): void
    {
        try {
            if ($this->targetStateIs($payment, PaymentInterface::STATE_CANCELLED)) {
                return;
            }

            $this->payum->getGateway($gatewayName)->execute(new Cancel($payment));
        } catch (ExceptionInterface|QuickpayException|UnusableGatewayConfiguration $e) {
            // Cancelling the order must not be blocked by Quickpay being unable to cancel
            // the payment, e.g. because it was never authorized or has already expired.
            // Unused authorizations expire by themselves at Quickpay. Building the gateway of an
            // unusable gateway configuration throws the plugin's UnusableGatewayConfiguration;
            // Payum's ExceptionInterface covers Payum's other errors
            $this->logger?->warning(sprintf('Could not cancel Quickpay payment: %s', $e->getMessage()), [
                'quickpayPaymentId' => $quickpayPaymentId,
                'paymentId' => $payment->getId(),
            ]);
        }
    }

    /**
     * Whether the Quickpay payment's status maps to the given Sylius payment state, i.e. the operation
     * already happened at Quickpay. A status check, never a status request on the Sylius payment: that
     * would make Sylius apply the transition again from inside the one being applied
     *
     * @throws QuickpayException
     */
    private function targetStateIs(PaymentInterface $payment, string $state): bool
    {
        return $state === $this->reconciler->checkStatus($payment)->targetState;
    }
}
