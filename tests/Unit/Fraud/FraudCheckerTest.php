<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Tests\Unit\Fraud;

use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Psr\Log\LoggerInterface;
use Setono\Quickpay\Client\Client;
use Setono\SyliusQuickpayPlugin\Fraud\FraudChecker;
use Setono\SyliusQuickpayPlugin\GatewayConfiguration\GatewayConfiguration;
use Setono\SyliusQuickpayPlugin\GatewayConfiguration\GatewayConfigurationProviderInterface;
use Setono\SyliusQuickpayPlugin\Quickpay\ClientFactoryInterface;
use Setono\SyliusQuickpayPlugin\Tests\GatewayConfiguration\GatewayConfigurations;
use Setono\SyliusQuickpayPlugin\Tests\Quickpay\FixedResponseHttpClient;
use Sylius\Component\Core\Model\Payment;
use Sylius\Component\Core\Model\PaymentInterface;

final class FraudCheckerTest extends TestCase
{
    use ProphecyTrait;

    /**
     * @test
     */
    public function it_reports_fraud_when_quickpay_flags_the_payment(): void
    {
        $checker = $this->createChecker($this->createClientFactory($this->paymentResponse(['fraud_suspected' => true])), GatewayConfigurations::of());

        self::assertTrue($checker->isFraudSuspected(self::createPayment()));
    }

    /**
     * @test
     */
    public function it_reports_clean_when_quickpay_does_not_flag_the_payment(): void
    {
        $checker = $this->createChecker($this->createClientFactory($this->paymentResponse(['fraud_suspected' => false])), GatewayConfigurations::of());

        self::assertFalse($checker->isFraudSuspected(self::createPayment()));
    }

    /**
     * @test
     */
    public function it_reports_clean_when_the_payment_carries_no_fraud_metadata(): void
    {
        $checker = $this->createChecker($this->createClientFactory($this->paymentResponse(null)), GatewayConfigurations::of());

        self::assertFalse($checker->isFraudSuspected(self::createPayment()));
    }

    /**
     * @test
     */
    public function it_reports_clean_when_the_payment_has_no_quickpay_payment_id(): void
    {
        $checker = $this->createChecker($this->createUnusedClientFactory(), GatewayConfigurations::of());

        self::assertFalse($checker->isFraudSuspected(self::createPayment(details: [])));
    }

    /**
     * @test
     */
    public function it_reports_clean_when_the_payment_has_no_quickpay_gateway_configuration(): void
    {
        $checker = $this->createChecker($this->createUnusedClientFactory(), null);

        self::assertFalse($checker->isFraudSuspected(self::createPayment()));
    }

    /**
     * @test
     *
     * @dataProvider unusableGatewayConfigurations
     */
    public function it_fails_open_with_a_warning_when_the_payment_s_gateway_configuration_is_unusable(GatewayConfiguration $gatewayConfiguration): void
    {
        $logger = $this->prophesize(LoggerInterface::class);
        $logger->warning(Argument::containingString('"quickpay_credit_card"'), Argument::any())->shouldBeCalledOnce();

        $checker = $this->createChecker($this->createUnusedClientFactory(), $gatewayConfiguration, $logger->reveal());

        self::assertFalse($checker->isFraudSuspected(self::createPayment()));
    }

    /**
     * @return iterable<string, array{GatewayConfiguration}>
     */
    public static function unusableGatewayConfigurations(): iterable
    {
        yield 'invalid' => [GatewayConfigurations::of(['api_key' => 'the-api-key'], 'quickpay_credit_card')];

        yield 'encrypted' => [GatewayConfigurations::encrypted('quickpay_credit_card')];
    }

    /**
     * @test
     */
    public function it_fails_open_when_the_details_carry_an_unusable_quickpay_payment_id(): void
    {
        $checker = $this->createChecker($this->createUnusedClientFactory(), GatewayConfigurations::of());

        self::assertFalse($checker->isFraudSuspected(self::createPayment(details: ['quickpayPaymentId' => 'foo'])));
    }

    /**
     * @test
     */
    public function it_fails_open_when_quickpay_cannot_be_reached(): void
    {
        $checker = $this->createChecker($this->createClientFactory(new Response(500, [], '{"message": "boom"}')), GatewayConfigurations::of());

        self::assertFalse($checker->isFraudSuspected(self::createPayment()));
    }

    /**
     * A fraud checker for payments whose gateway configuration is the given one, null meaning the payment has
     * no Quickpay gateway configuration
     */
    private function createChecker(
        ClientFactoryInterface $clientFactory,
        ?GatewayConfiguration $gatewayConfiguration,
        ?LoggerInterface $logger = null,
    ): FraudChecker {
        $gatewayConfigurationProvider = $this->prophesize(GatewayConfigurationProviderInterface::class);
        $gatewayConfigurationProvider->forPayment(Argument::type(PaymentInterface::class))->willReturn($gatewayConfiguration);

        $checker = new FraudChecker($gatewayConfigurationProvider->reveal(), $clientFactory);
        if (null !== $logger) {
            $checker->setLogger($logger);
        }

        return $checker;
    }

    private function createClientFactory(Response $response): ClientFactoryInterface
    {
        $clientFactory = $this->prophesize(ClientFactoryInterface::class);
        $clientFactory
            ->create('the-api-key')
            ->willReturn(new Client('the-api-key', new FixedResponseHttpClient($response)))
        ;

        return $clientFactory->reveal();
    }

    private function createUnusedClientFactory(): ClientFactoryInterface
    {
        $clientFactory = $this->prophesize(ClientFactoryInterface::class);
        $clientFactory->create(Argument::any())->shouldNotBeCalled();

        return $clientFactory->reveal();
    }

    /**
     * @param array{fraud_suspected: bool}|null $metadata
     */
    private function paymentResponse(?array $metadata): Response
    {
        $payment = [
            'id' => 501,
            'order_id' => 'qp_000000123',
            'currency' => 'DKK',
            'state' => 'new',
            'merchant_id' => 1,
        ];

        if (null !== $metadata) {
            $payment['metadata'] = $metadata;
        }

        return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode($payment));
    }

    /**
     * @param array<string, mixed> $details
     */
    private static function createPayment(array $details = ['quickpayPaymentId' => 501]): PaymentInterface
    {
        $payment = new Payment();
        $payment->setDetails($details);

        return $payment;
    }
}
