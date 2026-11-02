<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Tests\Functional;

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
}
