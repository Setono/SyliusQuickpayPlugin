<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Tests\Quickpay;

use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Bridge\Symfony\Builder\GatewayFactoryBuilder;
use Payum\Core\GatewayFactoryInterface;
use Setono\Quickpay\Client\Client;

/**
 * Decorates the plugin's gateway factory builder in the test application, so every Quickpay gateway
 * gets an SDK client sending its HTTP to the scripted client, through the library's `quickpay.client`
 * gateway option. Everything else about the gateway is real.
 */
final class ScriptedGatewayFactoryBuilder
{
    public function __construct(private readonly GatewayFactoryBuilder $inner, private readonly ScriptedHttpClient $httpClient)
    {
    }

    /**
     * Payum calls the builder like this when it builds its gateway factories
     *
     * @param array<mixed> $defaultConfig
     */
    public function __invoke(array $defaultConfig, GatewayFactoryInterface $coreGatewayFactory): GatewayFactoryInterface
    {
        $httpClient = $this->httpClient;

        // Payum resolves a closure option with the gateway's own config, so the SDK client carries
        // the gateway's api key and the synchronized flag the library insists it agrees with
        $defaultConfig['quickpay.client'] = static function (ArrayObject $config) use ($httpClient): Client {
            $apiKey = $config['api_key'];
            if (!\is_string($apiKey)) {
                throw new \LogicException('The Quickpay gateway is configured without an api key');
            }

            return new Client(
                $apiKey,
                $httpClient,
                synchronized: true === filter_var($config['synchronized'], \FILTER_VALIDATE_BOOL),
            );
        };

        return $this->inner->build($defaultConfig, $coreGatewayFactory);
    }
}
