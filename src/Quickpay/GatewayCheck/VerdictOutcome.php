<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Quickpay\GatewayCheck;

/**
 * What a gateway check found
 */
enum VerdictOutcome
{
    /**
     * Quickpay's answer proves the gateway configuration allows what was checked
     */
    case Passed;

    /**
     * Quickpay's answer proves the gateway configuration does not allow what was checked
     */
    case Failed;

    /**
     * Quickpay's answer proves neither, e.g. it didn't answer, answered with an error, or the api user may not ask
     */
    case Undetermined;
}
