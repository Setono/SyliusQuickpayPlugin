<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Tests\Functional;

use Setono\SyliusQuickpayPlugin\GatewayConfiguration\UnusableGatewayConfiguration;
use Sylius\Bundle\PayumBundle\Request\GetStatus;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\OrderPaymentStates;

/**
 * Proves the harness: a Sylius payment's status is read through its real Quickpay gateway, with only
 * Quickpay's HTTP scripted, and Sylius' payment-state extension, the state machine and the database
 * do the rest
 */
final class QuickpayGatewayTest extends FunctionalTestCase
{
    /**
     * @test
     *
     * @dataProvider stateMachineAdapters
     */
    public function it_reads_a_sylius_payments_status_from_its_quickpay_payment(string $stateMachineAdapter): void
    {
        self::bootKernelWithStateMachineAdapter($stateMachineAdapter);

        $payment = $this->createSyliusPayment(PaymentInterface::STATE_AUTHORIZED, OrderPaymentStates::STATE_AUTHORIZED, quickpayPaymentId: 501);
        $this->quickpay()->respond('GET', '/payments/501', self::quickpayPayment(501, 'processed', ['authorize', 'capture'], balance: 10_000));

        $status = new GetStatus($payment);
        $this->gatewayOf($payment)->execute($status);

        self::assertSame(PaymentInterface::STATE_COMPLETED, $status->getValue());
        self::assertContains('GET /payments/501', $this->quickpay()->sentRequests());

        $payment = $this->reload($payment);
        self::assertSame(PaymentInterface::STATE_COMPLETED, $payment->getState());
        self::assertSame(OrderPaymentStates::STATE_PAID, $payment->getOrder()?->getPaymentState());
    }

    /**
     * @test
     */
    public function it_fails_on_a_request_no_test_scripted(): void
    {
        self::bootKernel();

        $payment = $this->createSyliusPayment(PaymentInterface::STATE_AUTHORIZED, OrderPaymentStates::STATE_AUTHORIZED, quickpayPaymentId: 501);

        $this->expectExceptionObject(new \LogicException('No response scripted for "GET /payments/501"'));

        $this->gatewayOf($payment)->execute(new GetStatus($payment));
    }

    /**
     * The gateway gets its client from the client provider, which reads the gateway configuration first, so building
     * the gateway of an unusable one throws the plugin's exception, before payum-quickpay reads it
     *
     * @test
     */
    public function building_the_gateway_of_an_unusable_gateway_configuration_throws(): void
    {
        self::bootKernel();

        // A branding name where the branding id belongs, stored by fixtures or the database directly
        $this->createPaymentMethod('quickpay_se', ['api_key' => 'the-se-api-key', 'private_key' => self::PRIVATE_KEY, 'branding_id' => 'Our brand']);

        try {
            $this->payum()->getGateway('quickpay_se');
            self::fail('No exception was thrown');
        } catch (UnusableGatewayConfiguration $e) {
            self::assertSame(['branding_id'], array_keys($e->getViolations()));
            self::assertStringStartsWith('The gateway configuration of a Quickpay gateway is invalid: The "branding_id" option', $e->getMessage());
        }

        self::assertSame([], $this->quickpay()->sentRequests());
    }
}
