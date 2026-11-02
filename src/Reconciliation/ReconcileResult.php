<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Reconciliation;

/**
 * What reconciling a Sylius payment did
 */
final class ReconcileResult
{
    private function __construct(
        public readonly ReconcileOutcome $outcome,

        /**
         * The Sylius payment's state before reconciling, as committed when its lock was taken
         */
        public readonly string $stateBefore,

        /**
         * The Sylius payment's state after reconciling, as persisted
         */
        public readonly string $stateAfter,

        /**
         * The Quickpay payment's status, as the Sylius payment state Sylius maps it to (see
         * {@see StatusCheck::$quickpayStatus}), or null when the payment was skipped and nothing was read
         */
        public readonly ?string $quickpayStatus,
    ) {
    }

    public static function skipped(string $state): self
    {
        return new self(ReconcileOutcome::Skipped, $state, $state, null);
    }

    public static function reconciled(string $stateBefore, string $stateAfter, string $quickpayStatus): self
    {
        return new self(
            $stateBefore === $stateAfter ? ReconcileOutcome::Unchanged : ReconcileOutcome::Transitioned,
            $stateBefore,
            $stateAfter,
            $quickpayStatus,
        );
    }
}
