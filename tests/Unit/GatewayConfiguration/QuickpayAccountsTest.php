<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Tests\Unit\GatewayConfiguration;

use PHPUnit\Framework\TestCase;
use Setono\SyliusQuickpayPlugin\GatewayConfiguration\QuickpayAccount;
use Setono\SyliusQuickpayPlugin\GatewayConfiguration\QuickpayAccounts;
use Setono\SyliusQuickpayPlugin\GatewayConfiguration\UnusableGatewayConfiguration;
use Setono\SyliusQuickpayPlugin\Tests\GatewayConfiguration\GatewayConfigurations;

final class QuickpayAccountsTest extends TestCase
{
    /**
     * Card and MobilePay on one api key, the usual setup, are one account
     *
     * @test
     */
    public function it_makes_the_gateway_configurations_sharing_an_api_key_one_account(): void
    {
        $creditCard = GatewayConfigurations::of(self::withApiKey('the-api-key'), 'quickpay_credit_card');
        $otherShop = GatewayConfigurations::of(self::withApiKey('the-other-api-key'), 'quickpay_other_shop');
        $mobilePay = GatewayConfigurations::of(self::withApiKey('the-api-key'), 'quickpay_mobilepay');

        $accounts = QuickpayAccounts::of([$creditCard, $otherShop, $mobilePay]);

        self::assertSame(
            [['quickpay_credit_card', 'quickpay_mobilepay'], ['quickpay_other_shop']],
            array_map(static fn (QuickpayAccount $account): array => $account->gatewayNames(), $accounts->accounts),
        );
        self::assertSame([$creditCard, $mobilePay], $accounts->accounts[0]->gatewayConfigurations);
        self::assertSame($creditCard, $accounts->accounts[0]->gatewayConfiguration());
        self::assertSame($otherShop, $accounts->accounts[1]->gatewayConfiguration());
        self::assertSame([], $accounts->unusable);
    }

    /**
     * Its identity, without the api key: the key an account's watermark is kept under
     *
     * @test
     */
    public function it_keys_an_account_by_the_first_32_hex_digits_of_its_api_keys_sha256(): void
    {
        $accounts = QuickpayAccounts::of([GatewayConfigurations::of(self::withApiKey('the-api-key'))]);

        self::assertSame(substr(hash('sha256', 'the-api-key'), 0, 32), $accounts->accounts[0]->key);
        self::assertSame($accounts->accounts[0]->key, QuickpayAccount::keyOf('the-api-key'));
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $accounts->accounts[0]->key);
    }

    /**
     * @test
     */
    public function it_keys_an_account_whose_api_key_is_digits(): void
    {
        $accounts = QuickpayAccounts::of([GatewayConfigurations::of(self::withApiKey('1234567890'))]);

        self::assertSame(QuickpayAccount::keyOf('1234567890'), $accounts->accounts[0]->key);
    }

    /**
     * Its api key can't be read, so it belongs to no known account
     *
     * @test
     */
    public function it_returns_an_unusable_gateway_configuration_apart(): void
    {
        $usable = GatewayConfigurations::of(gatewayName: 'quickpay_credit_card');

        $accounts = QuickpayAccounts::of([
            GatewayConfigurations::encrypted('quickpay_encrypted'),
            $usable,
            GatewayConfigurations::of(['private_key' => 'the-private-key'], 'quickpay_without_api_key'),
        ]);

        self::assertSame([['quickpay_credit_card']], array_map(static fn (QuickpayAccount $account): array => $account->gatewayNames(), $accounts->accounts));
        self::assertSame(
            ['quickpay_encrypted', 'quickpay_without_api_key'],
            array_map(static fn (UnusableGatewayConfiguration $unusable): string => $unusable->gatewayName, $accounts->unusable),
        );
    }

    /**
     * @test
     */
    public function it_has_no_accounts_without_gateway_configurations(): void
    {
        $accounts = QuickpayAccounts::of([]);

        self::assertSame([], $accounts->accounts);
        self::assertSame([], $accounts->unusable);
    }

    /**
     * @return array<string, mixed>
     */
    private static function withApiKey(string $apiKey): array
    {
        return ['api_key' => $apiKey] + GatewayConfigurations::USABLE;
    }
}
