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
     * The payment's state already matched its Quickpay payment, or no transition leads there
     */
    case Unchanged;

    /**
     * The payment's lock was held, e.g. by a callback being handled, so nothing was run
     */
    case Skipped;
}
