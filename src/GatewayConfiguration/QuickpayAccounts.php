<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\GatewayConfiguration;

/**
 * The Quickpay accounts of the stored gateway configurations ({@see GatewayConfigurationProviderInterface::accounts()}),
 * and the gateway configurations that belong to no known account because they are unusable: an api key can't be read
 * from one, so it can't be placed.
 */
final class QuickpayAccounts
{
    /**
     * @param list<QuickpayAccount> $accounts
     * @param list<UnusableGatewayConfiguration> $unusable
     */
    private function __construct(
        public readonly array $accounts,
        public readonly array $unusable,
    ) {
    }

    /**
     * The accounts the gateway configurations belong to, a distinct api key each, in the order their first gateway
     * configuration comes
     *
     * @param list<GatewayConfiguration> $gatewayConfigurations
     */
    public static function of(array $gatewayConfigurations): self
    {
        $byApiKey = [];
        $unusable = [];

        foreach ($gatewayConfigurations as $gatewayConfiguration) {
            try {
                $byApiKey[$gatewayConfiguration->options()->apiKey][] = $gatewayConfiguration;
            } catch (UnusableGatewayConfiguration $e) {
                $unusable[] = $e;
            }
        }

        $accounts = [];
        foreach ($byApiKey as $apiKey => $sharingIt) {
            // PHP turns an api key of digits into an int array key
            $accounts[] = new QuickpayAccount(QuickpayAccount::keyOf((string) $apiKey), $sharingIt);
        }

        return new self($accounts, $unusable);
    }
}
