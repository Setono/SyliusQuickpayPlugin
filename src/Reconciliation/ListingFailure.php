<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Reconciliation;

/**
 * Gateways whose Quickpay payments a reconcile run couldn't list: the gateways of a Quickpay account Quickpay didn't
 * list, or a gateway whose gateway configuration is unusable
 */
final class ListingFailure
{
    /**
     * @param non-empty-list<string> $gatewayNames
     */
    public function __construct(
        public readonly array $gatewayNames,
        public readonly string $message,
    ) {
    }
}
