<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\GatewayConfiguration;

/**
 * A Quickpay account (see GLOSSARY.md), as the plugin knows it: the gateway configurations sharing one api key. Quickpay
 * lists their payments together, so whatever lists payments asks each account once.
 */
final class QuickpayAccount
{
    /**
     * @param string $key the account's identity, derived from its api key, which it never holds
     * @param non-empty-list<GatewayConfiguration> $gatewayConfigurations the usable gateway configurations sharing the
     *        api key, in the order the gateway configuration module lists them
     */
    public function __construct(
        public readonly string $key,
        public readonly array $gatewayConfigurations,
    ) {
    }

    /**
     * The key of the account an api key belongs to: the first 32 hex digits of its sha256, so the api key itself is
     * never stored or shown
     */
    public static function keyOf(string $apiKey): string
    {
        return substr(hash('sha256', $apiKey), 0, 32);
    }

    /**
     * A gateway configuration to reach the account with: the first, since every one shares its api key
     */
    public function gatewayConfiguration(): GatewayConfiguration
    {
        return $this->gatewayConfigurations[0];
    }

    /**
     * @return non-empty-list<string>
     */
    public function gatewayNames(): array
    {
        return array_map(
            static fn (GatewayConfiguration $gatewayConfiguration): string => $gatewayConfiguration->gatewayName,
            $this->gatewayConfigurations,
        );
    }
}
