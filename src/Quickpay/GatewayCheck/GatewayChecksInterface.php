<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Quickpay\GatewayCheck;

use Setono\SyliusQuickpayPlugin\GatewayConfiguration\GatewayConfiguration;
use Setono\SyliusQuickpayPlugin\GatewayConfiguration\UnusableGatewayConfiguration;

/**
 * Checks against Quickpay what a gateway configuration's credentials allow, each answering with a verdict, so no
 * caller interprets Quickpay's errors itself. A check takes a usable gateway configuration.
 */
interface GatewayChecksInterface
{
    /**
     * Whether Quickpay accepts the api key
     *
     * @throws UnusableGatewayConfiguration
     */
    public function apiKey(GatewayConfiguration $gatewayConfiguration): Verdict;
}
