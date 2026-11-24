<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Diagnosis;

/**
 * One thing a diagnosis says about a gateway configuration (see GLOSSARY.md). The text is English, for the console and
 * logs; a caller showing a finding to an admin words it in their language itself, from its kind and data.
 */
final class Finding
{
    public function __construct(
        public readonly FindingKind $kind,
        public readonly FindingOutcome $outcome,
        public readonly string $text,
        /**
         * The gateway option payum-quickpay rejects, by its current name
         */
        public readonly ?string $option = null,
        /**
         * The Quickpay order id the order prefix gives the order number
         */
        public readonly ?string $orderId = null,
        /**
         * The order number the order id was built from: the latest order's, or the stand-in while there is none
         */
        public readonly ?string $orderNumber = null,
        /**
         * The agreement id the gateway sends
         */
        public readonly ?int $agreementId = null,
    ) {
    }
}
