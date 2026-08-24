<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Tests\Fraud;

use Nyholm\Psr7\Response;
use Payum\Core\Model\GatewayConfigInterface;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Setono\Quickpay\Client\Client;
use Setono\SyliusQuickpayPlugin\Fraud\FraudChecker;
use Setono\SyliusQuickpayPlugin\Quickpay\ClientFactoryInterface;
use Setono\SyliusQuickpayPlugin\Tests\Quickpay\FixedResponseHttpClient;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;

final class FraudCheckerTest extends TestCase
{
    use ProphecyTrait;

    /**
     * @test
     */
    public function it_reports_fraud_when_quickpay_flags_the_payment(): void
    {
        $checker = new FraudChecker($this->createClientFactory($this->paymentResponse(['fraud_suspected' => true])));

        self::assertTrue($checker->isFraudSuspected($this->createPayment()));
    }

    /**
     * @test
     */
    public function it_reports_clean_when_quickpay_does_not_flag_the_payment(): void
    {
        $checker = new FraudChecker($this->createClientFactory($this->paymentResponse(['fraud_suspected' => false])));

        self::assertFalse($checker->isFraudSuspected($this->createPayment()));
    }

    /**
     * @test
     */
    public function it_reports_clean_when_the_payment_carries_no_fraud_metadata(): void
    {
        $checker = new FraudChecker($this->createClientFactory($this->paymentResponse(null)));

        self::assertFalse($checker->isFraudSuspected($this->createPayment()));
    }

    /**
     * @test
     */
    public function it_reports_clean_when_the_payment_has_no_quickpay_payment_id(): void
    {
        $clientFactory = $this->prophesize(ClientFactoryInterface::class);
        $clientFactory->create(Argument::any())->shouldNotBeCalled();

        $checker = new FraudChecker($clientFactory->reveal());

        self::assertFalse($checker->isFraudSuspected($this->createPayment(details: [])));
    }

    /**
     * @test
     */
    public function it_reports_clean_when_the_gateway_carries_no_api_key(): void
    {
        $clientFactory = $this->prophesize(ClientFactoryInterface::class);
        $clientFactory->create(Argument::any())->shouldNotBeCalled();

        $checker = new FraudChecker($clientFactory->reveal());

        self::assertFalse($checker->isFraudSuspected($this->createPayment(gatewayConfig: [])));
    }

    /**
     * @test
     */
    public function it_reports_clean_when_the_payment_has_no_method(): void
    {
        $clientFactory = $this->prophesize(ClientFactoryInterface::class);
        $clientFactory->create(Argument::any())->shouldNotBeCalled();

        $payment = $this->prophesize(PaymentInterface::class);
        $payment->getDetails()->willReturn(['quickpayPaymentId' => 501]);
        $payment->getMethod()->willReturn(null);

        $checker = new FraudChecker($clientFactory->reveal());

        self::assertFalse($checker->isFraudSuspected($payment->reveal()));
    }

    /**
     * @test
     */
    public function it_fails_open_when_the_details_carry_an_unusable_quickpay_payment_id(): void
    {
        $clientFactory = $this->prophesize(ClientFactoryInterface::class);
        $clientFactory->create(Argument::any())->shouldNotBeCalled();

        $checker = new FraudChecker($clientFactory->reveal());

        self::assertFalse($checker->isFraudSuspected($this->createPayment(details: ['quickpayPaymentId' => 'foo'])));
    }

    /**
     * @test
     */
    public function it_fails_open_when_quickpay_cannot_be_reached(): void
    {
        $checker = new FraudChecker($this->createClientFactory(new Response(500, [], '{"message": "boom"}')));

        self::assertFalse($checker->isFraudSuspected($this->createPayment()));
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
     * @param array<string, mixed> $gatewayConfig
     */
    private function createPayment(
        array $details = ['quickpayPaymentId' => 501],
        array $gatewayConfig = ['api_key' => 'the-api-key'],
    ): PaymentInterface {
        $config = $this->prophesize(GatewayConfigInterface::class);
        $config->getConfig()->willReturn($gatewayConfig);

        $method = $this->prophesize(PaymentMethodInterface::class);
        $method->getGatewayConfig()->willReturn($config->reveal());

        $payment = $this->prophesize(PaymentInterface::class);
        $payment->getDetails()->willReturn($details);
        $payment->getMethod()->willReturn($method->reveal());
        $payment->getId()->willReturn(1);

        return $payment->reveal();
    }
}
