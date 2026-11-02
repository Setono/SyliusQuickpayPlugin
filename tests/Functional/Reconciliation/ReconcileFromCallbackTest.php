<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Tests\Functional\Reconciliation;

use Payum\Core\Reply\HttpResponse;
use Setono\SyliusQuickpayPlugin\Reconciliation\ReconcileOutcome;
use Setono\SyliusQuickpayPlugin\Tests\Functional\FunctionalTestCase;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\OrderPaymentStates;
use Sylius\Component\Payment\PaymentTransitions;

/**
 * Reconciling from a callback: the library validates the callback and confirms the payment's details,
 * then Sylius applies at most one state transition, all under the payment's lock
 */
final class ReconcileFromCallbackTest extends FunctionalTestCase
{
    /**
     * @test
     *
     * @dataProvider stateMachineAdapters
     */
    public function it_applies_the_transition_once_and_persists_it(string $stateMachineAdapter): void
    {
        self::bootKernelWithStateMachineAdapter($stateMachineAdapter);

        $payment = $this->createSyliusPayment(PaymentInterface::STATE_AUTHORIZED, OrderPaymentStates::STATE_AUTHORIZED, quickpayPaymentId: 501);
        $this->quickpay()->respond('GET', '/payments/501', self::quickpayPayment(501, 'processed', ['authorize', 'capture'], balance: 10_000));
        $transitions = $this->recordPaymentTransitions();
        $this->receiveCallback(self::quickpayCallback(501));

        $result = $this->reconciler()->reconcileFromCallback($payment);

        self::assertSame(ReconcileOutcome::Transitioned, $result->outcome);
        self::assertSame(PaymentInterface::STATE_AUTHORIZED, $result->stateBefore);
        self::assertSame(PaymentInterface::STATE_COMPLETED, $result->stateAfter);
        self::assertSame(PaymentInterface::STATE_COMPLETED, $result->quickpayStatus);
        self::assertSame([PaymentTransitions::TRANSITION_COMPLETE], $transitions->getArrayCopy());

        // Only reads at Quickpay
        self::assertSame(['GET /payments/501'], array_values(array_unique($this->quickpay()->sentRequests())));

        // The transition's callbacks ran (the order is paid), and the details the library confirmed from
        // the callback reached the database with it
        $payment = $this->reload($payment);
        self::assertSame(PaymentInterface::STATE_COMPLETED, $payment->getState());
        self::assertSame(OrderPaymentStates::STATE_PAID, $payment->getOrder()?->getPaymentState());
        self::assertSame('processed', $payment->getDetails()['state'] ?? null);
    }

    /**
     * @test
     *
     * @dataProvider stateMachineAdapters
     */
    public function a_duplicate_arriving_while_the_callback_is_handled_is_skipped_and_changes_nothing(string $stateMachineAdapter): void
    {
        self::bootKernelWithStateMachineAdapter($stateMachineAdapter);

        $payment = $this->createSyliusPayment(PaymentInterface::STATE_AUTHORIZED, OrderPaymentStates::STATE_AUTHORIZED, quickpayPaymentId: 501);
        $details = $payment->getDetails();
        $this->quickpay()->respond('GET', '/payments/501', self::quickpayPayment(501, 'processed', ['authorize', 'capture'], balance: 10_000));
        $transitions = $this->recordPaymentTransitions();

        // The original callback, being handled elsewhere, holds the payment's lock
        $this->holdLockOf(501);
        $this->receiveCallback(self::quickpayCallback(501));

        $result = $this->reconciler()->reconcileFromCallback($payment);

        self::assertSame(ReconcileOutcome::Skipped, $result->outcome);
        self::assertSame(PaymentInterface::STATE_AUTHORIZED, $result->stateBefore);
        self::assertSame(PaymentInterface::STATE_AUTHORIZED, $result->stateAfter);
        self::assertNull($result->quickpayStatus);
        self::assertSame([], $transitions->getArrayCopy());
        self::assertSame([], $this->quickpay()->sentRequests());

        $payment = $this->reload($payment);
        self::assertSame(PaymentInterface::STATE_AUTHORIZED, $payment->getState());
        self::assertSame(OrderPaymentStates::STATE_AUTHORIZED, $payment->getOrder()?->getPaymentState());
        self::assertSame($details, $payment->getDetails());
    }

    /**
     * @test
     *
     * @dataProvider stateMachineAdapters
     */
    public function a_duplicate_arriving_after_the_callback_was_handled_changes_nothing(string $stateMachineAdapter): void
    {
        self::bootKernelWithStateMachineAdapter($stateMachineAdapter);

        // Checkout put the ordered unit on hold
        $variant = $this->createTrackedVariant('t_shirt', onHand: 5, onHold: 1);
        $payment = $this->createSyliusPayment(PaymentInterface::STATE_AUTHORIZED, OrderPaymentStates::STATE_AUTHORIZED, quickpayPaymentId: 501, orderedVariants: [$variant]);
        $this->quickpay()->respond('GET', '/payments/501', self::quickpayPayment(501, 'processed', ['authorize', 'capture'], balance: 10_000));
        $transitions = $this->recordPaymentTransitions();
        $this->receiveCallback(self::quickpayCallback(501));
        self::assertSame(ReconcileOutcome::Transitioned, $this->reconciler()->reconcileFromCallback($payment)->outcome);

        // Quickpay's retry of the same callback
        $this->receiveCallback(self::quickpayCallback(501));
        $result = $this->reconciler()->reconcileFromCallback($this->reload($payment));

        self::assertSame(ReconcileOutcome::Unchanged, $result->outcome);
        self::assertSame(PaymentInterface::STATE_COMPLETED, $result->stateBefore);
        self::assertSame(PaymentInterface::STATE_COMPLETED, $result->stateAfter);
        self::assertSame(PaymentInterface::STATE_COMPLETED, $result->quickpayStatus);

        // Completed, the order paid and the unit sold, once
        self::assertSame([PaymentTransitions::TRANSITION_COMPLETE], $transitions->getArrayCopy());
        $payment = $this->reload($payment);
        self::assertSame(PaymentInterface::STATE_COMPLETED, $payment->getState());
        self::assertSame(OrderPaymentStates::STATE_PAID, $payment->getOrder()?->getPaymentState());
        self::assertSame(['onHand' => 4, 'onHold' => 0], $this->inventoryOf($variant));
    }

    /**
     * @test
     *
     * @dataProvider stateMachineAdapters
     */
    public function a_forged_callback_is_rejected_before_anything_changes_and_releases_the_lock(string $stateMachineAdapter): void
    {
        self::bootKernelWithStateMachineAdapter($stateMachineAdapter);

        $payment = $this->createSyliusPayment(PaymentInterface::STATE_AUTHORIZED, OrderPaymentStates::STATE_AUTHORIZED, quickpayPaymentId: 501);
        $details = $payment->getDetails();
        $this->quickpay()->respond('GET', '/payments/501', self::quickpayPayment(501, 'processed', ['authorize', 'capture'], balance: 10_000));
        $this->receiveCallback(self::quickpayCallback(501, privateKey: 'not-the-private-key'));

        try {
            $this->reconciler()->reconcileFromCallback($payment);
            self::fail('The forged callback was accepted');
        } catch (HttpResponse $reply) {
            // Answered with an error, so a genuine callback is still retried
            self::assertSame(400, $reply->getStatusCode());
        }

        $payment = $this->reload($payment);
        self::assertSame(PaymentInterface::STATE_AUTHORIZED, $payment->getState());
        self::assertSame(OrderPaymentStates::STATE_AUTHORIZED, $payment->getOrder()?->getPaymentState());
        self::assertSame($details, $payment->getDetails());
        self::assertSame([], $this->quickpay()->sentRequests());

        // The genuine callback is not locked out
        $this->receiveCallback(self::quickpayCallback(501));
        self::assertSame(ReconcileOutcome::Transitioned, $this->reconciler()->reconcileFromCallback($payment)->outcome);
    }
}
