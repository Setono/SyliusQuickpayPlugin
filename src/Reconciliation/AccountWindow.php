<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Reconciliation;

/**
 * The changes a reconcile run lists for one Quickpay account (a distinct api key): the payments Quickpay changed since
 * the account's watermark, or since the lookback when the account has none, it is older than the lookback, or the
 * run is a full one
 */
final class AccountWindow
{
    /**
     * @param non-empty-list<string> $gatewayNames the gateways sharing the account
     * @param bool $sinceLastRun whether the run lists since the account's watermark, rather than the lookback
     */
    public function __construct(
        public readonly array $gatewayNames,
        public readonly \DateTimeImmutable $changedSince,
        public readonly bool $sinceLastRun,
    ) {
    }
}
