<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Tests\Functional\Reconciliation;

use Doctrine\Persistence\ManagerRegistry;
use Nyholm\Psr7\Response;
use Payum\Core\Payum;
use Setono\Quickpay\Exception\QuickpayException;
use Setono\SyliusQuickpayPlugin\Reconciliation\PaymentReconciler;
use Setono\SyliusQuickpayPlugin\Reconciliation\PaymentReconcilerInterface;
use Setono\SyliusQuickpayPlugin\Reconciliation\ReconcileOutcome;
use Setono\SyliusQuickpayPlugin\Tests\Functional\FunctionalTestCase;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\OrderPaymentStates;
use Sylius\Component\Payment\PaymentTransitions;
use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\PersistingStoreInterface;

/**
 * Reconcile brings a Sylius payment's state in line with its Quickpay payment, applying at most one
 * state transition, under the payment's lock
 */
final class ReconcileTest extends FunctionalTestCase
{
    /**
     * @test
     *
     * @dataProvider stateMachineAdapters
     */
    public function it_applies_the_transition_sylius_maps_the_quickpay_status_to_and_persists_it(string $stateMachineAdapter): void
    {
        self::bootKernelWithStateMachineAdapter($stateMachineAdapter);

        $payment = $this->createSyliusPayment(PaymentInterface::STATE_AUTHORIZED, OrderPaymentStates::STATE_AUTHORIZED, quickpayPaymentId: 501);
        $this->quickpay()->respond('GET', '/payments/501', self::quickpayPayment(501, 'processed', ['authorize', 'capture'], balance: 10_000));
        $transitions = $this->recordPaymentTransitions();

        $result = $this->reconciler()->reconcile($payment);

        self::assertSame(ReconcileOutcome::Transitioned, $result->outcome);
        self::assertSame(PaymentInterface::STATE_AUTHORIZED, $result->stateBefore);
        self::assertSame(PaymentInterface::STATE_COMPLETED, $result->stateAfter);
        self::assertSame(PaymentInterface::STATE_COMPLETED, $result->quickpayStatus);
        self::assertSame([PaymentTransitions::TRANSITION_COMPLETE], $transitions->getArrayCopy());

        // Only reads at Quickpay: reconciling never captures, refunds or cancels anything
        self::assertSame(['GET /payments/501'], array_values(array_unique($this->quickpay()->sentRequests())));

        // The transition's callbacks ran (the order is paid) and everything reached the database
        $payment = $this->reload($payment);
        self::assertSame(PaymentInterface::STATE_COMPLETED, $payment->getState());
        self::assertSame(OrderPaymentStates::STATE_PAID, $payment->getOrder()?->getPaymentState());
    }

    /**
     * @test
     *
     * @dataProvider transitioningQuickpayStatuses
     *
     * @param list<string> $approvedOperations
     */
    public function it_makes_the_state_change_a_callback_would_have_made(
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

        $result = $this->reconciler()->reconcile($payment);

        self::assertSame(ReconcileOutcome::Transitioned, $result->outcome);
        self::assertSame($state, $result->stateBefore);
        self::assertSame($expectedState, $result->stateAfter);
        self::assertSame($expectedState, $result->quickpayStatus);

        $payment = $this->reload($payment);
        self::assertSame($expectedState, $payment->getState());
        self::assertSame($expectedOrderPaymentState, $payment->getOrder()?->getPaymentState());
    }

    /**
     * Sylius' payment-state mapping and its order payment state rules (the test orders total 0, so one
     * payment covers them in full)
     *
     * @return iterable<string, array{string, string, string, list<string>, int|null, string, string}>
     */
    public static function transitioningQuickpayStatuses(): iterable
    {
        $awaitingPayment = OrderPaymentStates::STATE_AWAITING_PAYMENT;

        yield 'pending moves a new payment to processing' => [PaymentInterface::STATE_NEW, $awaitingPayment, 'pending', [], null, PaymentInterface::STATE_PROCESSING, $awaitingPayment];
        yield 'authorized' => [PaymentInterface::STATE_NEW, $awaitingPayment, 'new', ['authorize'], 0, PaymentInterface::STATE_AUTHORIZED, OrderPaymentStates::STATE_AUTHORIZED];
        yield 'captured' => [PaymentInterface::STATE_PROCESSING, $awaitingPayment, 'processed', ['authorize', 'capture'], 10_000, PaymentInterface::STATE_COMPLETED, OrderPaymentStates::STATE_PAID];
        yield 'refunded' => [PaymentInterface::STATE_COMPLETED, OrderPaymentStates::STATE_PAID, 'processed', ['authorize', 'capture', 'refund'], 0, PaymentInterface::STATE_REFUNDED, OrderPaymentStates::STATE_REFUNDED];
        // Sylius resolves no order payment state on cancel or fail
        yield 'cancelled' => [PaymentInterface::STATE_AUTHORIZED, OrderPaymentStates::STATE_AUTHORIZED, 'processed', ['authorize', 'cancel'], 0, PaymentInterface::STATE_CANCELLED, OrderPaymentStates::STATE_AUTHORIZED];
        yield 'rejected' => [PaymentInterface::STATE_NEW, $awaitingPayment, 'rejected', [], null, PaymentInterface::STATE_FAILED, $awaitingPayment];
        yield 'authorization never approved' => [PaymentInterface::STATE_NEW, $awaitingPayment, 'new', [], null, PaymentInterface::STATE_FAILED, $awaitingPayment];
    }

    /**
     * @test
     *
     * @dataProvider nonTransitioningQuickpayStatuses
     *
     * @param list<string> $approvedOperations
     */
    public function it_reports_unchanged_when_sylius_applies_no_transition(
        string $state,
        string $orderPaymentState,
        string $quickpayState,
        array $approvedOperations,
        string $expectedQuickpayStatus,
    ): void {
        self::bootKernel();

        $payment = $this->createSyliusPayment($state, $orderPaymentState, quickpayPaymentId: 501);
        $this->quickpay()->respond('GET', '/payments/501', self::quickpayPayment(501, $quickpayState, $approvedOperations, 0));

        $result = $this->reconciler()->reconcile($payment);

        self::assertSame(ReconcileOutcome::Unchanged, $result->outcome);
        self::assertSame($state, $result->stateBefore);
        self::assertSame($state, $result->stateAfter);
        self::assertSame($expectedQuickpayStatus, $result->quickpayStatus);

        $payment = $this->reload($payment);
        self::assertSame($state, $payment->getState());
        self::assertSame($orderPaymentState, $payment->getOrder()?->getPaymentState());
    }

    /**
     * @return iterable<string, array{string, string, string, list<string>, string}>
     */
    public static function nonTransitioningQuickpayStatuses(): iterable
    {
        $authorized = OrderPaymentStates::STATE_AUTHORIZED;

        yield 'already matching' => [PaymentInterface::STATE_AUTHORIZED, $authorized, 'new', ['authorize'], PaymentInterface::STATE_AUTHORIZED];
        yield 'unknown' => [PaymentInterface::STATE_NEW, OrderPaymentStates::STATE_AWAITING_PAYMENT, 'processed', [], PaymentInterface::STATE_UNKNOWN];
        yield 'no transition leads there' => [PaymentInterface::STATE_AUTHORIZED, $authorized, 'pending', [], PaymentInterface::STATE_PROCESSING];
    }

    /**
     * @test
     *
     * @dataProvider stateMachineAdapters
     */
    public function it_skips_a_payment_whose_lock_is_held_and_touches_nothing(string $stateMachineAdapter): void
    {
        self::bootKernelWithStateMachineAdapter($stateMachineAdapter);

        $payment = $this->createSyliusPayment(PaymentInterface::STATE_AUTHORIZED, OrderPaymentStates::STATE_AUTHORIZED, quickpayPaymentId: 501);
        $details = $payment->getDetails();
        $this->quickpay()->respond('GET', '/payments/501', self::quickpayPayment(501, 'processed', ['authorize', 'capture'], balance: 10_000));
        $lock = $this->holdLockOf(501);

        $result = $this->reconciler()->reconcile($payment);

        self::assertSame(ReconcileOutcome::Skipped, $result->outcome);
        self::assertSame(PaymentInterface::STATE_AUTHORIZED, $result->stateBefore);
        self::assertSame(PaymentInterface::STATE_AUTHORIZED, $result->stateAfter);
        self::assertNull($result->quickpayStatus);
        self::assertSame([], $this->quickpay()->sentRequests());

        self::assertSame(PaymentInterface::STATE_AUTHORIZED, $payment->getState());
        $payment = $this->reload($payment);
        self::assertSame(PaymentInterface::STATE_AUTHORIZED, $payment->getState());
        self::assertSame(OrderPaymentStates::STATE_AUTHORIZED, $payment->getOrder()?->getPaymentState());
        self::assertSame($details, $payment->getDetails());

        // Once whoever held the lock is done, the payment reconciles as usual
        $lock->release();
        self::assertSame(ReconcileOutcome::Transitioned, $this->reconciler()->reconcile($payment)->outcome);
    }

    /**
     * @test
     */
    public function it_sees_a_change_committed_before_it_took_the_lock_and_does_not_apply_it_again(): void
    {
        self::bootKernel();

        $payment = $this->createSyliusPayment(PaymentInterface::STATE_AUTHORIZED, OrderPaymentStates::STATE_AUTHORIZED, quickpayPaymentId: 501);
        $this->quickpay()->respond('GET', '/payments/501', self::quickpayPayment(501, 'processed', ['authorize', 'capture'], balance: 10_000));

        // A callback handled elsewhere completed the payment and committed it, behind the back of the
        // payment this process loaded earlier
        $connection = $this->entityManager()->getConnection();
        $connection->executeStatement('UPDATE sylius_payment SET state = ? WHERE id = ?', [PaymentInterface::STATE_COMPLETED, $payment->getId()]);
        $connection->executeStatement('UPDATE sylius_order SET payment_state = ? WHERE id = ?', [OrderPaymentStates::STATE_PAID, $payment->getOrder()?->getId()]);
        self::assertSame(PaymentInterface::STATE_AUTHORIZED, $payment->getState());
        $transitions = $this->recordPaymentTransitions();

        $result = $this->reconciler()->reconcile($payment);

        self::assertSame(ReconcileOutcome::Unchanged, $result->outcome);
        self::assertSame(PaymentInterface::STATE_COMPLETED, $result->stateBefore);
        self::assertSame(PaymentInterface::STATE_COMPLETED, $result->stateAfter);
        self::assertSame([], $transitions->getArrayCopy());

        $payment = $this->reload($payment);
        self::assertSame(PaymentInterface::STATE_COMPLETED, $payment->getState());
        self::assertSame(OrderPaymentStates::STATE_PAID, $payment->getOrder()?->getPaymentState());
    }

    /**
     * @test
     */
    public function a_failing_request_propagates_its_error_and_releases_the_lock(): void
    {
        self::bootKernel();

        $payment = $this->createSyliusPayment(PaymentInterface::STATE_AUTHORIZED, OrderPaymentStates::STATE_AUTHORIZED, quickpayPaymentId: 501);
        $this->quickpay()->respond('GET', '/payments/501', new Response(500, ['Content-Type' => 'application/json'], '{"message": "Quickpay is down"}'));

        try {
            $this->reconciler()->reconcile($payment);
            self::fail('The Quickpay error was swallowed');
        } catch (QuickpayException) {
        }

        self::assertSame(PaymentInterface::STATE_AUTHORIZED, $this->reload($payment)->getState());

        // The next attempt is not locked out
        $this->quickpay()->respond('GET', '/payments/501', self::quickpayPayment(501, 'processed', ['authorize', 'capture'], balance: 10_000));
        self::assertSame(ReconcileOutcome::Transitioned, $this->reconciler()->reconcile($this->reload($payment))->outcome);
    }

    /**
     * @test
     */
    public function a_broken_lock_store_fails_open(): void
    {
        self::bootKernel();

        $payment = $this->createSyliusPayment(PaymentInterface::STATE_AUTHORIZED, OrderPaymentStates::STATE_AUTHORIZED, quickpayPaymentId: 501);
        $this->quickpay()->respond('GET', '/payments/501', self::quickpayPayment(501, 'processed', ['authorize', 'capture'], balance: 10_000));

        $payum = self::getContainer()->get('payum');
        self::assertInstanceOf(Payum::class, $payum);
        $stateMachine = self::getContainer()->get('sylius_abstraction.state_machine');
        self::assertInstanceOf(StateMachineInterface::class, $stateMachine);
        $managerRegistry = self::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $managerRegistry);

        // A store that cannot be reached, e.g. a Redis server that is down
        $unavailableStore = new class() implements PersistingStoreInterface {
            public function save(Key $key): void
            {
                throw new \RuntimeException('The lock store is unavailable');
            }

            public function delete(Key $key): void
            {
                throw new \RuntimeException('The lock store is unavailable');
            }

            public function exists(Key $key): bool
            {
                throw new \RuntimeException('The lock store is unavailable');
            }

            public function putOffExpiration(Key $key, float $ttl): void
            {
                throw new \RuntimeException('The lock store is unavailable');
            }
        };

        $reconciler = new PaymentReconciler($payum, $stateMachine, new LockFactory($unavailableStore), $managerRegistry);

        $result = $reconciler->reconcile($payment);

        self::assertSame(ReconcileOutcome::Transitioned, $result->outcome);
        self::assertSame(PaymentInterface::STATE_COMPLETED, $this->reload($payment)->getState());
    }

    private function reconciler(): PaymentReconcilerInterface
    {
        $reconciler = self::getContainer()->get(PaymentReconcilerInterface::class);
        self::assertInstanceOf(PaymentReconcilerInterface::class, $reconciler);

        return $reconciler;
    }
}
