<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Tests\Functional;

use Setono\SyliusQuickpayPlugin\Quickpay\QuickpayClientProviderInterface;
use Setono\SyliusQuickpayPlugin\Tests\GatewayConfiguration\GatewayConfigurations;

/**
 * Proves the harness: the clients the plugin's client provider builds, for the Quickpay gateways and for the plugin's
 * own calls (the fraud check, the operations panel, the gateway checks and the fraud report), send their HTTP through
 * the scoped HTTP client, to the scripted Quickpay below it
 */
final class QuickpayClientProviderTest extends FunctionalTestCase
{
    /**
     * @test
     */
    public function it_reads_a_quickpay_payment_through_the_scripted_client(): void
    {
        self::bootKernel();

        $this->quickpay()->respond('GET', '/payments/501', self::quickpayPayment(501, 'new', ['authorize'], balance: 0, fraudSuspected: true));

        $quickpayPayment = $this->clientProvider()->forGatewayConfiguration(GatewayConfigurations::of())->payments()->getById(501);

        self::assertSame(501, $quickpayPayment->id);
        self::assertTrue($quickpayPayment->metadata?->fraudSuspected);
        self::assertSame(['GET /payments/501'], $this->quickpay()->sentRequests());
    }

    /**
     * @test
     */
    public function it_fails_on_a_request_no_test_scripted(): void
    {
        self::bootKernel();

        $this->expectExceptionObject(new \LogicException('No response scripted for "GET /payments/501"'));

        $this->clientProvider()->forGatewayConfiguration(GatewayConfigurations::of())->payments()->getById(501);
    }

    private function clientProvider(): QuickpayClientProviderInterface
    {
        $clientProvider = self::getContainer()->get(QuickpayClientProviderInterface::class);
        self::assertInstanceOf(QuickpayClientProviderInterface::class, $clientProvider);

        return $clientProvider;
    }
}
