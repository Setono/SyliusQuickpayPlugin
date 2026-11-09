<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Tests\GatewayConfiguration;

use Payum\Core\Model\GatewayConfig as PayumGatewayConfig;
use Setono\Payum\Quickpay\QuickpayGatewayFactory;
use Setono\SyliusQuickpayPlugin\GatewayConfiguration\GatewayConfiguration;
use Sylius\Bundle\PayumBundle\Model\GatewayConfig;

/**
 * Real gateway configurations for tests: Sylius' gateway config entity, as a payment method stores it, and
 * the gateway configuration module's value read from one. Build them here instead of doubling `getConfig()`,
 * so a test reads its configuration through the same rules production does.
 */
final class GatewayConfigurations
{
    /**
     * A gateway configuration the Quickpay gateway can be built from: only the two required gateway options
     */
    public const USABLE = ['api_key' => 'the-api-key', 'private_key' => 'the-private-key'];

    private function __construct()
    {
    }

    /**
     * The module's value for a stored Quickpay gateway configuration
     *
     * @param array<array-key, mixed> $config
     */
    public static function of(array $config = self::USABLE, string $gatewayName = 'quickpay'): GatewayConfiguration
    {
        return GatewayConfiguration::fromGatewayConfig(self::entity($config, $gatewayName));
    }

    /**
     * The module's value for a stored Quickpay gateway configuration that is encrypted and that nothing has
     * decrypted (see {@see self::encryptedEntity()})
     */
    public static function encrypted(string $gatewayName = 'quickpay'): GatewayConfiguration
    {
        return GatewayConfiguration::fromGatewayConfig(self::encryptedEntity($gatewayName));
    }

    /**
     * Sylius' gateway config entity holding the configuration, for a Quickpay gateway unless another
     * factory is given
     *
     * @param array<array-key, mixed> $config
     */
    public static function entity(
        array $config = self::USABLE,
        string $gatewayName = 'quickpay',
        string $factoryName = QuickpayGatewayFactory::NAME,
    ): GatewayConfig {
        $gatewayConfig = new GatewayConfig();
        $gatewayConfig->setFactoryName($factoryName);
        $gatewayConfig->setGatewayName($gatewayName);
        $gatewayConfig->setConfig($config);

        return $gatewayConfig;
    }

    /**
     * Sylius' gateway config entity of a Quickpay gateway whose configuration PayumBundle encrypted, as
     * Doctrine loads it: the stored configuration carries the encrypted flag, and nothing has decrypted it
     * (only Payum's storage and Sylius' admin form do). Payum's model then reads it as no configuration at
     * all. Like Payum's encryption, it keeps booleans in plain text.
     */
    public static function encryptedEntity(string $gatewayName = 'quickpay'): GatewayConfig
    {
        // Doctrine hydrates an entity without calling its constructor, which leaves nothing decrypted
        $gatewayConfig = (new \ReflectionClass(GatewayConfig::class))->newInstanceWithoutConstructor();
        $gatewayConfig->setFactoryName(QuickpayGatewayFactory::NAME);
        $gatewayConfig->setGatewayName($gatewayName);
        (new \ReflectionProperty(PayumGatewayConfig::class, 'config'))->setValue($gatewayConfig, [
            'encrypted' => true,
            'api_key' => 'def50200b1a7c3a0e5d4f0c2a9e1',
            'private_key' => 'def502004f3e9d8c7b6a5f4e3d2c',
            'use_authorize' => true,
        ]);

        return $gatewayConfig;
    }
}
