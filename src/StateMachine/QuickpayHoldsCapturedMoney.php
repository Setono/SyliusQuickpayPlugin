<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\StateMachine;

use Sylius\Component\Core\Model\PaymentInterface;

/**
 * Cancelling a Sylius payment cancels its Quickpay payment, and Quickpay holds the payment's captured money: it was
 * captured at checkout, in the Quickpay manager, or by a capture whose callback never arrived. Thrown from the
 * `cancel` transition after the status check and before anything else is sent to Quickpay, so the transition aborts:
 * a cancelled Sylius payment can't be refunded, so cancelling it would leave the customer's money at Quickpay.
 *
 * Not a state machine execution exception, which Sylius' unpaid orders canceller catches before saving what it
 * cancelled: under winzou, the order is already cancelled in memory when its payment's cancel runs.
 */
final class QuickpayHoldsCapturedMoney extends \RuntimeException
{
    public function __construct(
        public readonly PaymentInterface $payment,
        public readonly int $quickpayPaymentId,
    ) {
        $paymentId = $payment->getId();

        parent::__construct(sprintf(
            'The Sylius payment %s can\'t be cancelled: Quickpay holds the captured money of its Quickpay payment %d. Reconcile the payment (the setono:sylius-quickpay:reconcile-payments command), so it is completed in Sylius, then refund it',
            is_scalar($paymentId) ? (string) $paymentId : '(not saved)',
            $quickpayPaymentId,
        ));
    }
}
