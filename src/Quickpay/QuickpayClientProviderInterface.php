<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Quickpay;

use Setono\Quickpay\Client\ClientInterface;
use Setono\SyliusQuickpayPlugin\GatewayConfiguration\GatewayConfiguration;
use Setono\SyliusQuickpayPlugin\GatewayConfiguration\UnusableGatewayConfiguration;

/**
 * The plugin's one road to Quickpay (ADR 0003): every SDK client, the Payum gateway's included, is built here, on the
 * scoped HTTP client `setono_sylius_quickpay.http_client` and so with its one HTTP policy
 */
interface QuickpayClientProviderInterface
{
    /**
     * A new SDK client for the gateway configuration: its api key and `synchronized` gateway option, read by
     * payum-quickpay's rules (ADR 0002)
     *
     * @throws UnusableGatewayConfiguration
     */
    public function forGatewayConfiguration(GatewayConfiguration $gatewayConfiguration): ClientInterface;
}
