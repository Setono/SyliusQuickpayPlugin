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
         * The Quickpay payment's status, as the Sylius payment state Sylius maps it to: e.g. "completed"
         * for a captured Quickpay payment, "processing" for a pending one, or "unknown"
         */
        public readonly string $quickpayStatus,

        /**
         * The transition Sylius would apply to the Sylius payment, or null when it would apply none,
         * e.g. because the payment's state already matches
         */
        public readonly ?string $transition,
    ) {
    }
}
