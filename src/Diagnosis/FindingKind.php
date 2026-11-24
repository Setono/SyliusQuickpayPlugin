<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Diagnosis;

/**
 * What a finding is about, so a caller can word it in another language or place it on a form field
 */
enum FindingKind: string
{
    /**
     * Nothing can be read from the gateway configuration, as from an encrypted one
     */
    case Unreadable = 'unreadable';

    /**
     * payum-quickpay rejects one gateway option, the finding's option
     */
    case RejectedOption = 'rejected_option';

    /**
     * A 1.x option name, or an `auto_capture` that is on, is stored
     */
    case DeprecatedOptions = 'deprecated_options';

    /**
     * The capture mode isn't stored, so checkout captures immediately
     */
    case CaptureModeNotSet = 'capture_mode_not_set';

    /**
     * The Quickpay order id the order prefix gives the store's latest order
     */
    case OrderId = 'order_id';

    /**
     * No order prefix is configured
     */
    case NoOrderPrefix = 'no_order_prefix';

    /**
     * The checks against Quickpay were skipped, because the gateway can't be built from the configuration
     */
    case QuickpayChecksSkipped = 'quickpay_checks_skipped';

    /**
     * The api key gateway check
     */
    case ApiKey = 'api_key';

    /**
     * The agreement the gateway sends
     */
    case Agreement = 'agreement';

    /**
     * The payment link permission gateway check, which writes to the Quickpay account
     */
    case PaymentLinkPermission = 'payment_link_permission';
}
