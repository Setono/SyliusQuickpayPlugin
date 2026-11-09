<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Tests\Functional;

use Nyholm\Psr7\Response;
use Setono\SyliusQuickpayPlugin\Quickpay\GatewayCheck\GatewayChecksInterface;
use Setono\SyliusQuickpayPlugin\Tests\GatewayConfiguration\GatewayConfigurations;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\OrderPaymentStates;

/**
 * Every request to Quickpay, a gateway operation's and the plugin's own, goes through the plugin's scoped HTTP client
 * and so follows its one HTTP policy (ADR 0003): at most 10 s idle and 10 s in total
 */
final class QuickpayHttpPolicyTest extends FunctionalTestCase
{
    /**
     * @test
     */
    public function a_gateway_operations_request_follows_the_policy(): void
    {
        self::bootKernel();

        $payment = $this->createSyliusPayment(PaymentInterface::STATE_AUTHORIZED, OrderPaymentStates::STATE_AUTHORIZED, quickpayPaymentId: 501);
        $this->quickpay()->respond('GET', '/payments/501', self::quickpayPayment(501, 'new', ['authorize'], balance: 0));

        $this->reconciler()->checkStatus($payment);

        self::assertSame(['GET /payments/501'], $this->quickpay()->sentRequests());
        $options = $this->quickpay()->sentRequestOptions()[0];
        self::assertSame(10.0, $options['timeout'] ?? null);
        self::assertSame(10.0, $options['max_duration'] ?? null);
        // The gateway's client is built from its own gateway configuration
        $headers = $options['normalized_headers'] ?? null;
        self::assertIsArray($headers);
        self::assertSame(['Authorization: Basic ' . base64_encode(':the-api-key')], $headers['authorization'] ?? null);
    }

    /**
     * @test
     */
    public function the_plugins_own_request_follows_the_policy(): void
    {
        self::bootKernel();

        $this->quickpay()->respond('GET', '/ping', new Response(200, ['Content-Type' => 'application/json'], '{"msg":"Pong from QuickPay API V10"}'));

        $this->gatewayChecks()->apiKey(GatewayConfigurations::of());

        self::assertSame(['GET /ping'], $this->quickpay()->sentRequests());
        $options = $this->quickpay()->sentRequestOptions()[0];
        self::assertSame(10.0, $options['timeout'] ?? null);
        self::assertSame(10.0, $options['max_duration'] ?? null);
    }

    private function gatewayChecks(): GatewayChecksInterface
    {
        $gatewayChecks = self::getContainer()->get(GatewayChecksInterface::class);
        self::assertInstanceOf(GatewayChecksInterface::class, $gatewayChecks);

        return $gatewayChecks;
    }
}
