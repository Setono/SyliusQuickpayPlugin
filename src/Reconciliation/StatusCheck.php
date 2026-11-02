<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Reconciliation;

/**
 * What a status check found
 */
final class StatusCheck
{
    public function __construct(
        /**
         * The Sylius payment state Sylius maps the Quickpay payment's status to: e.g. "completed" for a
         * captured Quickpay payment, "processing" for a pending one, or "unknown"
         */
        public readonly string $targetState,

        /**
         * The transition Sylius would apply to the Sylius payment, or null when it would apply none,
         * e.g. because the payment's state already matches
         */
        public readonly ?string $transition,
    ) {
    }
}
