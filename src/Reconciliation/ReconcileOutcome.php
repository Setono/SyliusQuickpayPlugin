<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Reconciliation;

/**
 * What reconciling did to the Sylius payment
 */
enum ReconcileOutcome
{
    /**
     * Sylius applied a transition: the payment's state changed
     */
    case Transitioned;

    /**
     * No state change: the payment's state already matched its Quickpay payment, or no transition leads
     * there. The details the status read refreshed, such as Quickpay's balance, are still saved.
     */
    case Unchanged;

    /**
     * The payment's lock was held, e.g. by a callback being handled, so nothing was run
     */
    case Skipped;
}
