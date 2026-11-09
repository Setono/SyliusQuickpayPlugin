<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Tests\Functional;

use Setono\SyliusQuickpayPlugin\Quickpay\ClientFactoryInterface;

/**
 * Proves the harness: the plugin's own Quickpay client factory, behind the fraud check, the operations panel,
 * api key verification, the doctor and the fraud report, sends its HTTP to the same scripted client as the
 * Quickpay gateways
 */
final class QuickpayClientFactoryTest extends FunctionalTestCase
{
    /**
     * @test
     */
    public function it_reads_a_quickpay_payment_through_the_scripted_client(): void
    {
        self::bootKernel();

        $this->quickpay()->respond('GET', '/payments/501', self::quickpayPayment(501, 'new', ['authorize'], balance: 0, fraudSuspected: true));

        $quickpayPayment = $this->clientFactory()->create('the-api-key')->payments()->getById(501);

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

        $this->clientFactory()->create('the-api-key')->payments()->getById(501);
    }

    private function clientFactory(): ClientFactoryInterface
    {
        $clientFactory = self::getContainer()->get(ClientFactoryInterface::class);
        self::assertInstanceOf(ClientFactoryInterface::class, $clientFactory);

        return $clientFactory;
    }
}
