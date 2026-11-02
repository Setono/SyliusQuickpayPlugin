<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Tests\Functional\Controller;

use Setono\SyliusQuickpayPlugin\Tests\Functional\FunctionalTestCase;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\OrderPaymentStates;
use Sylius\Component\Payment\PaymentTransitions;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Quickpay's callbacks on the plugin's shared callback endpoint reconcile the payment they are about
 */
final class NotifyActionTest extends FunctionalTestCase
{
    /**
     * @test
     */
    public function a_callback_completes_the_payment_once(): void
    {
        self::bootKernel();

        $payment = $this->createSyliusPayment(PaymentInterface::STATE_AUTHORIZED, OrderPaymentStates::STATE_AUTHORIZED, quickpayPaymentId: 501);
        $this->quickpay()->respond('GET', '/payments/501', self::quickpayPayment(501, 'processed', ['authorize', 'capture'], balance: 10_000));
        $transitions = $this->recordPaymentTransitions();

        $response = $this->post(self::quickpayCallback(501));

        self::assertTrue($response->isSuccessful(), (string) $response->getContent());
        self::assertSame([PaymentTransitions::TRANSITION_COMPLETE], $transitions->getArrayCopy());
        $payment = $this->reload($payment);
        self::assertSame(PaymentInterface::STATE_COMPLETED, $payment->getState());
        self::assertSame(OrderPaymentStates::STATE_PAID, $payment->getOrder()?->getPaymentState());
    }

    /**
     * @test
     */
    public function a_duplicate_arriving_while_the_callback_is_handled_is_answered_with_a_2xx_and_changes_nothing(): void
    {
        self::bootKernel();

        $payment = $this->createSyliusPayment(PaymentInterface::STATE_AUTHORIZED, OrderPaymentStates::STATE_AUTHORIZED, quickpayPaymentId: 501);
        $this->quickpay()->respond('GET', '/payments/501', self::quickpayPayment(501, 'processed', ['authorize', 'capture'], balance: 10_000));

        // The original callback, being handled elsewhere, holds the payment's lock
        $this->holdLockOf(501);

        $response = $this->post(self::quickpayCallback(501));

        // Quickpay considers it delivered and stops retrying
        self::assertTrue($response->isSuccessful(), (string) $response->getContent());
        self::assertSame([], $this->quickpay()->sentRequests());
        $payment = $this->reload($payment);
        self::assertSame(PaymentInterface::STATE_AUTHORIZED, $payment->getState());
        self::assertSame(OrderPaymentStates::STATE_AUTHORIZED, $payment->getOrder()?->getPaymentState());
    }

    /**
     * @test
     */
    public function a_forged_callback_is_answered_with_a_400_and_changes_nothing(): void
    {
        self::bootKernel();

        $payment = $this->createSyliusPayment(PaymentInterface::STATE_AUTHORIZED, OrderPaymentStates::STATE_AUTHORIZED, quickpayPaymentId: 501);
        $this->quickpay()->respond('GET', '/payments/501', self::quickpayPayment(501, 'processed', ['authorize', 'capture'], balance: 10_000));

        $response = $this->post(self::quickpayCallback(501, privateKey: 'not-the-private-key'));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame([], $this->quickpay()->sentRequests());
        self::assertSame(PaymentInterface::STATE_AUTHORIZED, $this->reload($payment)->getState());
    }

    private function post(Request $callback): Response
    {
        return self::$kernel->handle($callback);
    }
}
