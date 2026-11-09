<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Tests\Functional\GatewayConfiguration;

use Setono\SyliusQuickpayPlugin\GatewayConfiguration\GatewayConfiguration;
use Setono\SyliusQuickpayPlugin\GatewayConfiguration\GatewayConfigurationProviderInterface;
use Setono\SyliusQuickpayPlugin\Tests\Functional\FunctionalTestCase;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\OrderPaymentStates;

/**
 * The gateway configuration module answers which Quickpay gateway configurations are stored, and which one a
 * gateway, payment method or payment has, whether or not the configuration is usable
 */
final class GatewayConfigurationProviderTest extends FunctionalTestCase
{
    /**
     * @test
     */
    public function it_lists_every_quickpay_gateway_configuration_and_nothing_else(): void
    {
        self::bootKernel();

        $this->createPaymentMethod('quickpay_dk', ['api_key' => 'the-dk-api-key', 'private_key' => 'the-private-key']);
        // Unusable: the gateway rejects a branding name where the branding id belongs
        $this->createPaymentMethod('quickpay_se', ['api_key' => 'the-se-api-key', 'branding_id' => 'Our brand']);
        $this->createPaymentMethod('bank_transfer', [], factoryName: 'offline');

        $configurations = self::byGatewayName($this->provider()->all());

        self::assertSame(['quickpay_dk', 'quickpay_se'], array_keys($configurations));
        self::assertSame('the-dk-api-key', $configurations['quickpay_dk']->options()->apiKey);
    }

    /**
     * @test
     */
    public function it_finds_the_quickpay_gateway_configuration_of_a_gateway(): void
    {
        self::bootKernel();

        $this->createPaymentMethod('quickpay_se', ['api_key' => 'the-se-api-key', 'branding_id' => 'Our brand']);
        $this->createPaymentMethod('bank_transfer', [], factoryName: 'offline');

        self::assertSame('quickpay_se', $this->provider()->forGatewayName('quickpay_se')?->gatewayName);
        self::assertNull($this->provider()->forGatewayName('bank_transfer'), 'another gateway\'s');
        self::assertNull($this->provider()->forGatewayName('no_such_gateway'), 'a gateway that isn\'t stored');
    }

    /**
     * @test
     */
    public function it_finds_the_quickpay_gateway_configuration_of_a_payment_method(): void
    {
        self::bootKernel();

        $quickpay = $this->createPaymentMethod('quickpay_dk', ['api_key' => 'the-dk-api-key', 'private_key' => 'the-private-key']);
        $offline = $this->createPaymentMethod('bank_transfer', [], factoryName: 'offline');
        $withoutGateway = $this->createPaymentMethod('cash', []);
        $withoutGateway->setGatewayConfig(null);

        self::assertSame('the-dk-api-key', $this->provider()->forPaymentMethod($quickpay)?->options()->apiKey);
        self::assertNull($this->provider()->forPaymentMethod($offline), 'another gateway\'s');
        self::assertNull($this->provider()->forPaymentMethod($withoutGateway), 'a payment method without a gateway');
    }

    /**
     * @test
     */
    public function it_finds_the_quickpay_gateway_configuration_of_a_payment_through_its_payment_method(): void
    {
        self::bootKernel();

        $payment = $this->createSyliusPayment(PaymentInterface::STATE_NEW, OrderPaymentStates::STATE_AWAITING_PAYMENT, quickpayPaymentId: 501);
        self::assertSame(self::gatewayNameOf($payment), $this->provider()->forPayment($payment)?->gatewayName);

        $payment->setMethod($this->createPaymentMethod('bank_transfer', [], factoryName: 'offline'));
        self::assertNull($this->provider()->forPayment($payment), 'a payment on another gateway');

        $payment->setMethod(null);
        self::assertNull($this->provider()->forPayment($payment), 'a payment without a payment method');
    }

    private function provider(): GatewayConfigurationProviderInterface
    {
        $provider = self::getContainer()->get(GatewayConfigurationProviderInterface::class);
        self::assertInstanceOf(GatewayConfigurationProviderInterface::class, $provider);

        return $provider;
    }

    /**
     * @param list<GatewayConfiguration> $configurations
     *
     * @return array<string, GatewayConfiguration> sorted by gateway name
     */
    private static function byGatewayName(array $configurations): array
    {
        $byGatewayName = [];
        foreach ($configurations as $configuration) {
            $byGatewayName[$configuration->gatewayName] = $configuration;
        }
        ksort($byGatewayName);

        return $byGatewayName;
    }
}
