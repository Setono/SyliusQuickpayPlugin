<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\StateMachine;

use Sylius\Component\Core\Model\PaymentInterface;

/**
 * Completing a Sylius payment captures its Quickpay payment, and Quickpay holds no authorization to capture:
 * the customer never paid, the authorization was declined, or the payment went another way. Thrown from the
 * `complete` transition after the status check and before anything else is sent to Quickpay, so the
 * transition aborts and the Quickpay payment is left as it is.
 */
final class NoAuthorizationToCapture extends \RuntimeException
{
    public function __construct(
        public readonly PaymentInterface $payment,
        public readonly int $quickpayPaymentId,
        /**
         * The Sylius payment state the status check maps the Quickpay payment's status to, e.g. "new" for a
         * payment the customer never paid or "failed" for a declined one
         */
        public readonly string $targetState,
    ) {
        $paymentId = $payment->getId();

        parent::__construct(sprintf(
            'The Sylius payment %s can\'t be completed: Quickpay holds no authorization to capture for its Quickpay payment %d, whose status maps to the payment state "%s"',
            is_scalar($paymentId) ? (string) $paymentId : '(not saved)',
            $quickpayPaymentId,
            $targetState,
        ));
    }
}
