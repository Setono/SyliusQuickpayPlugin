<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Tests\Functional\Reconciliation;

use Payum\Core\Reply\HttpResponse;
use Setono\SyliusQuickpayPlugin\Reconciliation\ReconcileOutcome;
use Setono\SyliusQuickpayPlugin\Tests\Functional\FunctionalTestCase;
use Sylius\Component\Core\Inventory\Exception\NotEnoughUnitsOnHoldException;
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
        self::assertSame(PaymentInterface::STATE_COMPLETED, $result->targetState);
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
     * @dataProvider transitioningQuickpayStatuses
     *
     * @param list<string> $approvedOperations
     */
    public function it_makes_the_state_change_sylius_maps_the_quickpay_status_to(
        string $state,
        string $orderPaymentState,
        string $quickpayState,
        array $approvedOperations,
        ?int $balance,
        string $expectedState,
        string $expectedOrderPaymentState,
    ): void {
        self::bootKernel();

        $payment = $this->createSyliusPayment($state, $orderPaymentState, quickpayPaymentId: 501);
        $this->quickpay()->respond('GET', '/payments/501', self::quickpayPayment(501, $quickpayState, $approvedOperations, $balance));
        $transitions = $this->recordPaymentTransitions();
        $this->receiveCallback(self::quickpayCallback(501));

        $result = $this->reconciler()->reconcileFromCallback($payment);

        self::assertSame(ReconcileOutcome::Transitioned, $result->outcome);
        self::assertSame($state, $result->stateBefore);
        self::assertSame($expectedState, $result->stateAfter);
        self::assertSame($expectedState, $result->targetState);
        self::assertCount(1, $transitions);

        $payment = $this->reload($payment);
        self::assertSame($expectedState, $payment->getState());
        self::assertSame($expectedOrderPaymentState, $payment->getOrder()?->getPaymentState());
        self::assertSame($quickpayState, $payment->getDetails()['state'] ?? null);
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
        self::assertNull($result->targetState);
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
        self::assertSame(PaymentInterface::STATE_COMPLETED, $result->targetState);

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
    public function a_state_machine_callback_failing_mid_transition_leaves_nothing_of_it_persisted(string $stateMachineAdapter): void
    {
        self::bootKernelWithStateMachineAdapter($stateMachineAdapter);

        // The ordered unit is no longer on hold, so Sylius fails to sell it when `complete` pays the order
        $variant = $this->createTrackedVariant('t_shirt', onHand: 5, onHold: 0);
        $payment = $this->createSyliusPayment(PaymentInterface::STATE_AUTHORIZED, OrderPaymentStates::STATE_AUTHORIZED, quickpayPaymentId: 501, orderedVariants: [$variant]);
        $details = $payment->getDetails();
        $this->quickpay()->respond('GET', '/payments/501', self::quickpayPayment(501, 'processed', ['authorize', 'capture'], balance: 10_000));
        $this->receiveCallback(self::quickpayCallback(501));

        try {
            $this->reconciler()->reconcileFromCallback($payment);
            self::fail('The state-machine callback\'s error was swallowed');
        } catch (NotEnoughUnitsOnHoldException) {
            // Answered with an error, so Quickpay retries the callback
        }

        // A retry must find the payment as it was, or it would take it for reconciled and the unit would
        // never be sold
        $payment = $this->reload($payment);
        self::assertSame(PaymentInterface::STATE_AUTHORIZED, $payment->getState());
        self::assertSame(OrderPaymentStates::STATE_AUTHORIZED, $payment->getOrder()?->getPaymentState());
        self::assertSame($details, $payment->getDetails());
        self::assertSame(['onHand' => 5, 'onHold' => 0], $this->inventoryOf($variant));

        // Quickpay's retry, once the unit is back on hold
        $this->entityManager()->getConnection()->executeStatement('UPDATE sylius_product_variant SET on_hold = 1 WHERE id = ?', [$variant->getId()]);
        $this->receiveCallback(self::quickpayCallback(501));

        self::assertSame(ReconcileOutcome::Transitioned, $this->reconciler()->reconcileFromCallback($this->reload($payment))->outcome);
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
