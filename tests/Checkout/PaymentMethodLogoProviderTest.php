<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Tests\Checkout;

use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;
use Setono\SyliusQuickpayPlugin\Checkout\PaymentMethodLogo;
use Setono\SyliusQuickpayPlugin\Checkout\PaymentMethodLogoProvider;
use Sylius\Bundle\PayumBundle\Model\GatewayConfigInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;

final class PaymentMethodLogoProviderTest extends TestCase
{
    use ProphecyTrait;

    /**
     * @test
     */
    public function it_maps_the_configured_payment_methods_to_bundled_logos(): void
    {
        $logos = (new PaymentMethodLogoProvider())->provide($this->createPaymentMethod('quickpay', 'mobilepay, dankort,visa , apple-pay'));

        self::assertSame(['mobilepay', 'dankort', 'visa', 'apple-pay'], self::tokens($logos));
        self::assertSame(['MobilePay', 'Dankort', 'Visa', 'Apple Pay'], self::labels($logos));
        self::assertSame('bundles/setonosyliusquickpayplugin/images/payment-methods/mobilepay.svg', $logos[0]->image);
        self::assertSame('bundles/setonosyliusquickpayplugin/images/payment-methods/apple-pay.svg', $logos[3]->image);
    }

    /**
     * @test
     */
    public function it_expands_creditcard_to_the_configured_brands(): void
    {
        self::assertSame(['visa', 'mastercard'], self::tokens((new PaymentMethodLogoProvider())->provide($this->createPaymentMethod('quickpay', 'creditcard'))));
        self::assertSame(
            ['dankort', 'visa', 'mastercard', 'mobilepay'],
            self::tokens((new PaymentMethodLogoProvider([], ['dankort', 'visa', 'mastercard']))->provide($this->createPaymentMethod('quickpay', 'creditcard, mobilepay'))),
        );
    }

    /**
     * @test
     */
    public function it_follows_quickpay_token_grammar(): void
    {
        $logos = (new PaymentMethodLogoProvider())->provide($this->createPaymentMethod(
            'quickpay',
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
        $logos = (new PaymentMethodLogoProvider())->provide($this->createPaymentMethod('quickpay', 'resurs, unzer-pay-later-invoice'));

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
        $provider = new PaymentMethodLogoProvider([
            'mobilepay' => 'build/images/my-mobilepay.svg',
            'resurs' => 'https://cdn.example/resurs.png',
            'apple-pay' => null,
        ]);

        $logos = $provider->provide($this->createPaymentMethod('quickpay', 'mobilepay, resurs, apple-pay, visa'));

        self::assertSame(['mobilepay', 'resurs', 'visa'], self::tokens($logos));
        self::assertSame('build/images/my-mobilepay.svg', $logos[0]->image);
        self::assertSame('MobilePay', $logos[0]->label);
        self::assertSame('https://cdn.example/resurs.png', $logos[1]->image);
        self::assertSame('Resurs Bank', $logos[1]->label);
    }

    /**
     * @test
     */
    public function it_returns_nothing_for_other_gateways_and_empty_configuration(): void
    {
        $provider = new PaymentMethodLogoProvider();

        self::assertSame([], $provider->provide($this->createPaymentMethod('offline', 'visa')));
        self::assertSame([], $provider->provide($this->createPaymentMethod('quickpay', '')));
        self::assertSame([], $provider->provide($this->createPaymentMethod('quickpay', null)));

        $method = $this->prophesize(PaymentMethodInterface::class);
        $method->getGatewayConfig()->willReturn(null);
        self::assertSame([], $provider->provide($method->reveal()));
    }

    private function createPaymentMethod(string $factoryName, ?string $paymentMethods): PaymentMethodInterface
    {
        $gatewayConfig = $this->prophesize(GatewayConfigInterface::class);
        $gatewayConfig->getFactoryName()->willReturn($factoryName);
        $gatewayConfig->getConfig()->willReturn(null === $paymentMethods ? [] : ['payment_methods' => $paymentMethods]);

        $method = $this->prophesize(PaymentMethodInterface::class);
        $method->getGatewayConfig()->willReturn($gatewayConfig->reveal());

        return $method->reveal();
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
