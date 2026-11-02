<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Tests\Functional\Reconciliation;

use Setono\SyliusQuickpayPlugin\Reconciliation\PaymentReconcilerInterface;
use Setono\SyliusQuickpayPlugin\Tests\Functional\FunctionalTestCase;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\OrderPaymentStates;

/**
 * The status check reads a Quickpay payment's status and the transition Sylius would apply, without
 * changing the Sylius payment in any way
 */
final class StatusCheckTest extends FunctionalTestCase
{
    /**
     * @test
     *
     * @dataProvider stateMachineAdapters
     */
    public function it_reports_the_transition_sylius_would_apply_and_changes_nothing(string $stateMachineAdapter): void
    {
        self::bootKernelWithStateMachineAdapter($stateMachineAdapter);

        $payment = $this->createSyliusPayment(PaymentInterface::STATE_AUTHORIZED, OrderPaymentStates::STATE_AUTHORIZED, quickpayPaymentId: 501);
        $details = $payment->getDetails();
        $this->quickpay()->respond('GET', '/payments/501', self::quickpayPayment(501, 'processed', ['authorize', 'capture'], balance: 10_000));

        $statusCheck = $this->reconciler()->checkStatus($payment);

        self::assertSame(PaymentInterface::STATE_COMPLETED, $statusCheck->quickpayStatus);
        self::assertSame('complete', $statusCheck->transition);
        self::assertSame(['GET /payments/501'], $this->quickpay()->sentRequests());

        // Nothing changed in memory: had Sylius applied `complete`, its callbacks would have marked the order paid
        self::assertSame(PaymentInterface::STATE_AUTHORIZED, $payment->getState());
        self::assertSame(OrderPaymentStates::STATE_AUTHORIZED, $payment->getOrder()?->getPaymentState());
        self::assertSame($details, $payment->getDetails());

        // ...and nothing reached the database, not even the balance the status read refreshes
        $payment = $this->reload($payment);
        self::assertSame(PaymentInterface::STATE_AUTHORIZED, $payment->getState());
        self::assertSame(OrderPaymentStates::STATE_AUTHORIZED, $payment->getOrder()?->getPaymentState());
        self::assertSame($details, $payment->getDetails());
    }

    /**
     * @test
     *
     * @dataProvider quickpayStatuses
     *
     * @param list<string> $approvedOperations
     */
    public function it_reports_the_transition_sylius_maps_the_quickpay_status_to(
        string $state,
        string $orderPaymentState,
        string $quickpayState,
        array $approvedOperations,
        ?int $balance,
        string $expectedQuickpayStatus,
        ?string $expectedTransition,
    ): void {
        self::bootKernel();

        $payment = $this->createSyliusPayment($state, $orderPaymentState, quickpayPaymentId: 501);
        $this->quickpay()->respond('GET', '/payments/501', self::quickpayPayment(501, $quickpayState, $approvedOperations, $balance));

        $statusCheck = $this->reconciler()->checkStatus($payment);

        self::assertSame($expectedQuickpayStatus, $statusCheck->quickpayStatus);
        self::assertSame($expectedTransition, $statusCheck->transition);
        self::assertSame($state, $this->reload($payment)->getState());
    }

    /**
     * @return iterable<string, array{string, string, string, list<string>, int|null, string, string|null}>
     */
    public static function quickpayStatuses(): iterable
    {
        $awaitingPayment = OrderPaymentStates::STATE_AWAITING_PAYMENT;

        yield 'pending moves a new payment to processing' => [PaymentInterface::STATE_NEW, $awaitingPayment, 'pending', [], null, 'processing', 'process'];
        yield 'authorized' => [PaymentInterface::STATE_NEW, $awaitingPayment, 'new', ['authorize'], 0, 'authorized', 'authorize'];
        yield 'captured' => [PaymentInterface::STATE_PROCESSING, $awaitingPayment, 'processed', ['authorize', 'capture'], 10_000, 'completed', 'complete'];
        yield 'cancelled' => [PaymentInterface::STATE_AUTHORIZED, OrderPaymentStates::STATE_AUTHORIZED, 'processed', ['authorize', 'cancel'], 0, 'cancelled', 'cancel'];
        yield 'rejected' => [PaymentInterface::STATE_NEW, $awaitingPayment, 'rejected', [], null, 'failed', 'fail'];
        yield 'refunded' => [PaymentInterface::STATE_COMPLETED, OrderPaymentStates::STATE_PAID, 'processed', ['authorize', 'capture', 'refund'], 0, 'refunded', 'refund'];
        yield 'already matching' => [PaymentInterface::STATE_AUTHORIZED, OrderPaymentStates::STATE_AUTHORIZED, 'new', ['authorize'], 0, 'authorized', null];
        yield 'unknown' => [PaymentInterface::STATE_NEW, $awaitingPayment, 'processed', [], null, 'unknown', null];
        yield 'no transition leads there' => [PaymentInterface::STATE_AUTHORIZED, OrderPaymentStates::STATE_AUTHORIZED, 'pending', [], null, 'processing', null];
    }

    private function reconciler(): PaymentReconcilerInterface
    {
        $reconciler = self::getContainer()->get(PaymentReconcilerInterface::class);
        self::assertInstanceOf(PaymentReconcilerInterface::class, $reconciler);

        return $reconciler;
    }
}
