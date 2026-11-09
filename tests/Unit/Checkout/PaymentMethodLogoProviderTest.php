<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Tests\Unit\Checkout;

use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Psr\Log\LoggerInterface;
use Setono\SyliusQuickpayPlugin\Checkout\PaymentMethodLogo;
use Setono\SyliusQuickpayPlugin\Checkout\PaymentMethodLogoProvider;
use Setono\SyliusQuickpayPlugin\GatewayConfiguration\GatewayConfiguration;
use Setono\SyliusQuickpayPlugin\GatewayConfiguration\GatewayConfigurationProviderInterface;
use Setono\SyliusQuickpayPlugin\Tests\GatewayConfiguration\GatewayConfigurations;
use Sylius\Component\Core\Model\PaymentMethod;

final class PaymentMethodLogoProviderTest extends TestCase
{
    use ProphecyTrait;

    /**
     * @test
     */
    public function it_maps_the_configured_payment_methods_to_bundled_logos(): void
    {
        $logos = $this->provide(self::quickpay('mobilepay, dankort,visa , apple-pay'));

        self::assertSame(['mobilepay', 'dankort', 'visa', 'apple-pay'], self::tokens($logos));
        self::assertSame(['MobilePay', 'Dankort', 'Visa', 'Apple Pay'], self::labels($logos));
        self::assertSame('bundles/setonosyliusquickpayplugin/images/payment-methods/mobilepay.svg', $logos[0]->image);
        self::assertSame('bundles/setonosyliusquickpayplugin/images/payment-methods/apple-pay.svg', $logos[3]->image);
    }

    /**
     * @test
     */
    public function it_maps_payment_methods_stored_as_a_list(): void
    {
        $logos = $this->provide(self::quickpay(['mobilepay', ' dankort ', '', 'visa']));

        self::assertSame(['mobilepay', 'dankort', 'visa'], self::tokens($logos));
    }

    /**
     * @test
     */
    public function it_expands_creditcard_to_the_configured_brands(): void
    {
        self::assertSame(['visa', 'mastercard'], self::tokens($this->provide(self::quickpay('creditcard'))));
        self::assertSame(
            ['dankort', 'visa', 'mastercard', 'mobilepay'],
            self::tokens($this->provide(self::quickpay('creditcard, mobilepay'), creditcardBrands: ['dankort', 'visa', 'mastercard'])),
        );
    }

    /**
     * @test
     */
    public function it_follows_quickpay_token_grammar(): void
    {
        $logos = $this->provide(self::quickpay(
            '3d-creditcard, !diners, visa-dk, mastercard-debet-dk, mobilepay-subscriptions, american-express-dk, klarna-payments, visa-electron-dk, VISA',
        ));

        // 3d- is stripped, exclusions are skipped, regional/debit variants collapse onto their brand,
        // and the result is deduplicated in first-seen order
        self::assertSame(
            ['visa', 'mastercard', 'mobilepay', 'american-express', 'klarna-payments', 'visa-electron'],
            self::tokens($logos),
        );
    }

    /**
     * @test
     */
    public function it_renders_unknown_tokens_as_text_labels_without_stripping_them(): void
    {
        $logos = $this->provide(self::quickpay('resurs, unzer-pay-later-invoice'));

        self::assertSame(['resurs', 'unzer-pay-later-invoice'], self::tokens($logos));
        self::assertSame(['Resurs Bank', 'Unzer Pay Later Invoice'], self::labels($logos));
        self::assertNull($logos[0]->image);
        self::assertNull($logos[1]->image);
    }

    /**
     * @test
     */
    public function it_lets_configuration_add_override_and_hide_logos(): void
    {
        $logos = $this->provide(self::quickpay('mobilepay, resurs, apple-pay, visa'), images: [
            'mobilepay' => 'build/images/my-mobilepay.svg',
            'resurs' => 'https://cdn.example/resurs.png',
            'apple-pay' => null,
        ]);

        self::assertSame(['mobilepay', 'resurs', 'visa'], self::tokens($logos));
        self::assertSame('build/images/my-mobilepay.svg', $logos[0]->image);
        self::assertSame('MobilePay', $logos[0]->label);
        self::assertSame('https://cdn.example/resurs.png', $logos[1]->image);
        self::assertSame('Resurs Bank', $logos[1]->label);
    }

    /**
     * @test
     */
    public function it_returns_nothing_for_other_gateways_and_unrestricted_payment_methods(): void
    {
        self::assertSame([], $this->provide(null));
        self::assertSame([], $this->provide(self::quickpay('')));
        self::assertSame([], $this->provide(self::quickpay(null)));
        self::assertSame([], $this->provide(self::quickpay([])));
    }

    /**
     * @test
     *
     * @dataProvider unusableGatewayConfigurations
     */
    public function it_shows_no_logos_and_logs_a_warning_for_an_unusable_gateway_configuration(GatewayConfiguration $gatewayConfiguration): void
    {
        $logger = $this->prophesize(LoggerInterface::class);
        $logger->warning(Argument::containingString('"quickpay_credit_card"'), Argument::any())->shouldBeCalledOnce();

        self::assertSame([], $this->provide($gatewayConfiguration, logger: $logger->reveal()));
    }

    /**
     * @return iterable<string, array{GatewayConfiguration}>
     */
    public static function unusableGatewayConfigurations(): iterable
    {
        yield 'invalid' => [GatewayConfigurations::of(['payment_methods' => 'visa'], 'quickpay_credit_card')];

        yield 'encrypted' => [GatewayConfigurations::encrypted('quickpay_credit_card')];
    }

    /**
     * @param list<string>|string|null $paymentMethods
     */
    private static function quickpay(array|string|null $paymentMethods): GatewayConfiguration
    {
        return GatewayConfigurations::of(GatewayConfigurations::USABLE + ['payment_methods' => $paymentMethods]);
    }

    /**
     * The logos for a payment method whose gateway configuration is the given one, or null for a payment
     * method that isn't a Quickpay one
     *
     * @param array<string, string|null> $images
     * @param list<string> $creditcardBrands
     *
     * @return list<PaymentMethodLogo>
     */
    private function provide(
        ?GatewayConfiguration $gatewayConfiguration,
        array $images = [],
        array $creditcardBrands = ['visa', 'mastercard'],
        ?LoggerInterface $logger = null,
    ): array {
        $paymentMethod = new PaymentMethod();

        $gatewayConfigurationProvider = $this->prophesize(GatewayConfigurationProviderInterface::class);
        $gatewayConfigurationProvider->forPaymentMethod($paymentMethod)->willReturn($gatewayConfiguration);

        $provider = new PaymentMethodLogoProvider($gatewayConfigurationProvider->reveal(), $images, $creditcardBrands);
        if (null !== $logger) {
            $provider->setLogger($logger);
        }

        return $provider->provide($paymentMethod);
    }

    /**
     * @param list<PaymentMethodLogo> $logos
     *
     * @return list<string>
     */
    private static function tokens(array $logos): array
    {
        return array_map(static fn (PaymentMethodLogo $logo): string => $logo->token, $logos);
    }

    /**
     * @param list<PaymentMethodLogo> $logos
     *
     * @return list<string>
     */
    private static function labels(array $logos): array
    {
        return array_map(static fn (PaymentMethodLogo $logo): string => $logo->label, $logos);
    }
}
