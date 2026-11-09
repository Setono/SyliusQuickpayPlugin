<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Tests\Quickpay;

use Setono\Quickpay\Client\Client;
use Setono\Quickpay\Client\ClientInterface;
use Setono\SyliusQuickpayPlugin\Quickpay\ClientFactoryInterface;

/**
 * Stands in for the plugin's Quickpay client factory in the test application, so the Quickpay clients it
 * creates for the fraud check, the operations panel, api key verification, the doctor and the fraud report
 * send their HTTP to the scripted client, like the Quickpay gateways (see {@see ScriptedGatewayFactoryBuilder})
 */
final class ScriptedClientFactory implements ClientFactoryInterface
{
    public function __construct(private readonly ScriptedHttpClient $httpClient)
    {
    }

    public function create(string $apiKey): ClientInterface
    {
        return new Client($apiKey, $this->httpClient);
    }
}
