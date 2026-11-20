<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Tests\Functional\StateMachine;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LogLevel;
use Setono\Payum\Quickpay\Exception\OperationPendingException;
use Setono\Quickpay\Exception\QuickpayException;
use Setono\SyliusQuickpayPlugin\Fraud\FraudCheckerInterface;
use Setono\SyliusQuickpayPlugin\GatewayConfiguration\GatewayConfigurationProviderInterface;
use Setono\SyliusQuickpayPlugin\GatewayConfiguration\UnusableGatewayConfiguration;
use Setono\SyliusQuickpayPlugin\Quickpay\QuickpayPaymentIdProviderInterface;
use Setono\SyliusQuickpayPlugin\StateMachine\NoAuthorizationToCapture;
use Setono\SyliusQuickpayPlugin\StateMachine\PaymentProcessor;
use Setono\SyliusQuickpayPlugin\StateMachine\QuickpayHoldsCapturedMoney;
use Setono\SyliusQuickpayPlugin\Tests\Functional\FunctionalTestCase;
use Setono\SyliusQuickpayPlugin\Tests\GatewayConfiguration\GatewayConfigurations;
use Setono\SyliusQuickpayPlugin\Tests\Log\RecordingLogger;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\OrderPaymentStates;
use Sylius\Component\Core\Updater\UnpaidOrdersStateUpdaterInterface;
use Sylius\Component\Payment\PaymentTransitions;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * When an admin completes, refunds or cancels a Sylius payment, the plugin carries the operation out at
 * Quickpay, unless Quickpay's payment already went that way
 */
final class PaymentProcessorTest extends FunctionalTestCase
{
    /**
     * A gateway configuration payum-quickpay rejects (the branding id must be a number), so no Quickpay gateway
     * can be built from it
     */
    private const UNUSABLE_GATEWAY_CONFIGURATION = GatewayConfigurations::USABLE + ['branding_id' => 'Our brand'];

    /**
     * @test
     *
     * @dataProvider stateMachineAdapters
     */
    public function it_completes_a_payment_quickpay_already_captured_once_without_capturing_it_again(string $stateMachineAdapter): void
    {
        self::bootKernelWithStateMachineAdapter($stateMachineAdapter);

        $payment = $this->createSyliusPayment(PaymentInterface::STATE_AUTHORIZED, OrderPaymentStates::STATE_AUTHORIZED, quickpayPaymentId: 501);
        $this->quickpay()->respond('GET', '/payments/501', self::quickpayPayment(501, 'processed', ['authorize', 'capture'], balance: 10_000));
        $transitions = $this->recordPaymentTransitions();

        $this->applyTransition($payment, PaymentTransitions::TRANSITION_COMPLETE);

        self::assertSame([PaymentTransitions::TRANSITION_COMPLETE], $transitions->getArrayCopy());
        self::assertSame([], $this->operationsSentToQuickpay());

        $payment = $this->reload($payment);
        self::assertSame(PaymentInterface::STATE_COMPLETED, $payment->getState());
        self::assertSame(OrderPaymentStates::STATE_PAID, $payment->getOrder()?->getPaymentState());
    }

    /**
     * @test
     *
     * @dataProvider stateMachineAdapters
     */
    public function it_captures_an_authorized_payment_once_when_completing_it(string $stateMachineAdapter): void
    {
        self::bootKernelWithStateMachineAdapter($stateMachineAdapter);

        $payment = $this->createSyliusPayment(PaymentInterface::STATE_AUTHORIZED, OrderPaymentStates::STATE_AUTHORIZED, quickpayPaymentId: 501);
        $this->quickpay()->respond('GET', '/payments/501', self::quickpayPayment(501, 'new', ['authorize'], balance: 0));
        $this->quickpay()->respond('POST', '/payments/501/capture', self::quickpayPayment(501, 'new', ['authorize'], balance: 0));
        $transitions = $this->recordPaymentTransitions();

        $this->applyTransition($payment, PaymentTransitions::TRANSITION_COMPLETE);

        self::assertSame([PaymentTransitions::TRANSITION_COMPLETE], $transitions->getArrayCopy());
        self::assertSame(['POST /payments/501/capture'], $this->operationsSentToQuickpay());

        $payment = $this->reload($payment);
        self::assertSame(PaymentInterface::STATE_COMPLETED, $payment->getState());
        self::assertSame(OrderPaymentStates::STATE_PAID, $payment->getOrder()?->getPaymentState());
    }

    /**
     * @test
     *
     * @dataProvider quickpayPaymentsHoldingNoAuthorization
     *
     * @param list<string> $approvedOperations
     */
    public function it_refuses_to_complete_a_payment_quickpay_holds_no_authorization_for(
        string $stateMachineAdapter,
        string $quickpayState,
        array $approvedOperations,
        string $targetState,
    ): void {
        self::bootKernelWithStateMachineAdapter($stateMachineAdapter);

        $payment = $this->createSyliusPayment(PaymentInterface::STATE_NEW, OrderPaymentStates::STATE_AWAITING_PAYMENT, quickpayPaymentId: 501);
        $this->quickpay()->respond('GET', '/payments/501', self::quickpayPayment(501, $quickpayState, $approvedOperations, balance: 0));
        $transitions = $this->recordPaymentTransitions();

        try {
            $this->applyTransition($payment, PaymentTransitions::TRANSITION_COMPLETE);
            self::fail('Completing a payment Quickpay holds no authorization for must fail');
        } catch (NoAuthorizationToCapture $e) {
            self::assertSame($payment, $e->payment);
            self::assertSame(501, $e->quickpayPaymentId);
            self::assertSame($targetState, $e->targetState);
            self::assertSame(sprintf('The Sylius payment %d can\'t be completed: Quickpay holds no authorization to capture for its Quickpay payment 501, whose status maps to the payment state "%s"', (int) $payment->getId(), $targetState), $e->getMessage());
        }

        self::assertSame([], $transitions->getArrayCopy());
        // The status check, and nothing after it: no payment link created or replaced, no capture
        self::assertSame(['GET /payments/501'], $this->quickpay()->sentRequests());

        $payment = $this->reload($payment);
        self::assertSame(PaymentInterface::STATE_NEW, $payment->getState());
        self::assertSame(OrderPaymentStates::STATE_AWAITING_PAYMENT, $payment->getOrder()?->getPaymentState());
    }

    /**
     * Each state machine adapter with a Quickpay payment whose status maps to a Sylius payment state other
     * than authorized or completed: the Quickpay payment's state and approved operations, then the state
     * Sylius maps them to
     *
     * @return iterable<string, array{string, string, list<string>, string}>
     */
    public static function quickpayPaymentsHoldingNoAuthorization(): iterable
    {
        foreach (self::stateMachineAdapters() as $name => [$stateMachineAdapter]) {
            yield sprintf('never paid, %s', $name) => [$stateMachineAdapter, 'initial', [], PaymentInterface::STATE_NEW];
            // Capturing it used to replace the payment's link at Quickpay with an auto-capture link
            yield sprintf('declined, %s', $name) => [$stateMachineAdapter, 'rejected', [], PaymentInterface::STATE_NEW];
            yield sprintf('invalid, %s', $name) => [$stateMachineAdapter, 'invalid', [], PaymentInterface::STATE_FAILED];
        }
    }

    /**
     * @test
     *
     * @dataProvider stateMachineAdapters
     */
    public function it_refunds_a_payment_quickpay_already_refunded_once_without_refunding_it_again(string $stateMachineAdapter): void
    {
        self::bootKernelWithStateMachineAdapter($stateMachineAdapter);

        $payment = $this->createSyliusPayment(PaymentInterface::STATE_COMPLETED, OrderPaymentStates::STATE_PAID, quickpayPaymentId: 501);
        $this->quickpay()->respond('GET', '/payments/501', self::quickpayPayment(501, 'processed', ['authorize', 'capture', 'refund'], balance: 0));
        $transitions = $this->recordPaymentTransitions();

        $this->applyTransition($payment, PaymentTransitions::TRANSITION_REFUND);

        self::assertSame([PaymentTransitions::TRANSITION_REFUND], $transitions->getArrayCopy());
        self::assertSame([], $this->operationsSentToQuickpay());

        $payment = $this->reload($payment);
        self::assertSame(PaymentInterface::STATE_REFUNDED, $payment->getState());
        self::assertSame(OrderPaymentStates::STATE_REFUNDED, $payment->getOrder()?->getPaymentState());
    }

    /**
     * @test
     *
     * @dataProvider stateMachineAdapters
     */
    public function it_refunds_a_captured_payment_once_when_refunding_it(string $stateMachineAdapter): void
    {
        self::bootKernelWithStateMachineAdapter($stateMachineAdapter);

        $payment = $this->createSyliusPayment(PaymentInterface::STATE_COMPLETED, OrderPaymentStates::STATE_PAID, quickpayPaymentId: 501);
        $this->quickpay()->respond('GET', '/payments/501', self::quickpayPayment(501, 'processed', ['authorize', 'capture'], balance: 10_000));
        $this->quickpay()->respond('POST', '/payments/501/refund', self::quickpayPayment(501, 'processed', ['authorize', 'capture'], balance: 10_000));
        $transitions = $this->recordPaymentTransitions();

        $this->applyTransition($payment, PaymentTransitions::TRANSITION_REFUND);

        self::assertSame([PaymentTransitions::TRANSITION_REFUND], $transitions->getArrayCopy());
        self::assertSame(['POST /payments/501/refund'], $this->operationsSentToQuickpay());

        $payment = $this->reload($payment);
        self::assertSame(PaymentInterface::STATE_REFUNDED, $payment->getState());
        self::assertSame(OrderPaymentStates::STATE_REFUNDED, $payment->getOrder()?->getPaymentState());
    }

    /**
     * @test
     *
     * @dataProvider stateMachineAdapters
     */
    public function it_cancels_a_payment_quickpay_already_cancelled_once_without_cancelling_it_again(string $stateMachineAdapter): void
    {
        self::bootKernelWithStateMachineAdapter($stateMachineAdapter);

        $payment = $this->createSyliusPayment(PaymentInterface::STATE_AUTHORIZED, OrderPaymentStates::STATE_AUTHORIZED, quickpayPaymentId: 501);
        $this->quickpay()->respond('GET', '/payments/501', self::quickpayPayment(501, 'processed', ['authorize', 'cancel'], balance: 0));
        $transitions = $this->recordPaymentTransitions();

        $this->applyTransition($payment, PaymentTransitions::TRANSITION_CANCEL);

        self::assertSame([PaymentTransitions::TRANSITION_CANCEL], $transitions->getArrayCopy());
        self::assertSame([], $this->operationsSentToQuickpay());
        self::assertSame(PaymentInterface::STATE_CANCELLED, $this->reload($payment)->getState());
    }

    /**
     * @test
     *
     * @dataProvider stateMachineAdapters
     */
    public function it_cancels_an_authorized_payment_once_when_cancelling_it(string $stateMachineAdapter): void
    {
        self::bootKernelWithStateMachineAdapter($stateMachineAdapter);

        $payment = $this->createSyliusPayment(PaymentInterface::STATE_AUTHORIZED, OrderPaymentStates::STATE_AUTHORIZED, quickpayPaymentId: 501);
        $this->quickpay()->respond('GET', '/payments/501', self::quickpayPayment(501, 'new', ['authorize'], balance: 0));
        $this->quickpay()->respond('POST', '/payments/501/cancel', self::quickpayPayment(501, 'new', ['authorize'], balance: 0));
        $transitions = $this->recordPaymentTransitions();

        $this->applyTransition($payment, PaymentTransitions::TRANSITION_CANCEL);

        self::assertSame([PaymentTransitions::TRANSITION_CANCEL], $transitions->getArrayCopy());
        self::assertSame(['POST /payments/501/cancel'], $this->operationsSentToQuickpay());
        self::assertSame(PaymentInterface::STATE_CANCELLED, $this->reload($payment)->getState());
    }

    /**
     * Cancelling the payment would leave the customer's money at Quickpay with nothing in Sylius to refund it from
     *
     * @test
     *
     * @dataProvider stateMachineAdapters
     */
    public function it_refuses_to_cancel_a_payment_whose_money_quickpay_captured(string $stateMachineAdapter): void
    {
        self::bootKernelWithStateMachineAdapter($stateMachineAdapter);

        $payment = $this->createSyliusPayment(PaymentInterface::STATE_AUTHORIZED, OrderPaymentStates::STATE_AUTHORIZED, quickpayPaymentId: 501);
        $this->quickpay()->respond('GET', '/payments/501', self::quickpayPayment(501, 'processed', ['authorize', 'capture'], balance: 10_000));
        $transitions = $this->recordPaymentTransitions();

        try {
            $this->applyTransition($payment, PaymentTransitions::TRANSITION_CANCEL);
            self::fail('Cancelling a payment whose money Quickpay captured must fail');
        } catch (QuickpayHoldsCapturedMoney $e) {
            self::assertSame($payment, $e->payment);
            self::assertSame(501, $e->quickpayPaymentId);
            // What the admin is told to do instead
            self::assertSame(sprintf('The Sylius payment %d can\'t be cancelled: Quickpay holds the captured money of its Quickpay payment 501. Reconcile the payment (the setono:sylius-quickpay:reconcile-payments command), so it is completed in Sylius, then refund it', (int) $payment->getId()), $e->getMessage());
        }

        self::assertSame([], $transitions->getArrayCopy());
        // The status check, and nothing after it: no cancel Quickpay would refuse
        self::assertSame(['GET /payments/501'], $this->quickpay()->sentRequests());

        $payment = $this->reload($payment);
        self::assertSame(PaymentInterface::STATE_AUTHORIZED, $payment->getState());
        self::assertSame(OrderPaymentStates::STATE_AUTHORIZED, $payment->getOrder()?->getPaymentState());
    }

    /**
     * Sylius' unpaid orders canceller (`sylius:cancel-unpaid-orders`) saves its batch after logging an order whose
     * cancel failed in the state machine. Under winzou that order is already cancelled in memory when its payment's
     * cancel runs, so the refusal must end the run instead: nothing in the batch is saved, and the order stays unpaid
     * until its payment is reconciled
     *
     * @test
     *
     * @dataProvider stateMachineAdapters
     */
    public function it_ends_cancelling_unpaid_orders_without_saving_any_when_quickpay_captured_the_money_of_one(string $stateMachineAdapter): void
    {
        self::bootKernelWithStateMachineAdapter($stateMachineAdapter);

        $expiredLongAgo = new \DateTime('-1 year');
        // Created first, so the canceller cancels it in memory before it reaches the captured one
        $bankTransfer = $this->createSyliusPayment(PaymentInterface::STATE_NEW, OrderPaymentStates::STATE_AWAITING_PAYMENT, quickpayPaymentId: null, orderNumber: '000000002', method: $this->createPaymentMethod('bank_transfer', [], factoryName: 'offline'));
        $bankTransfer->getOrder()?->setCheckoutCompletedAt($expiredLongAgo);
        $captured = $this->createSyliusPayment(PaymentInterface::STATE_NEW, OrderPaymentStates::STATE_AWAITING_PAYMENT, quickpayPaymentId: 501);
        $captured->getOrder()?->setCheckoutCompletedAt($expiredLongAgo);
        $this->entityManager()->flush();
        $this->quickpay()->respond('GET', '/payments/501', self::quickpayPayment(501, 'processed', ['authorize', 'capture'], balance: 10_000));

        $unpaidOrdersStateUpdater = self::getContainer()->get('sylius.unpaid_orders_state_updater');
        self::assertInstanceOf(UnpaidOrdersStateUpdaterInterface::class, $unpaidOrdersStateUpdater);

        try {
            $unpaidOrdersStateUpdater->cancel();
            self::fail('Cancelling the unpaid orders must fail on the order whose payment Quickpay captured');
        } catch (QuickpayHoldsCapturedMoney $e) {
            self::assertSame(501, $e->quickpayPaymentId);
        }
        self::assertSame(OrderInterface::STATE_CANCELLED, $bankTransfer->getOrder()?->getState(), 'The canceller must have cancelled the other order in its batch before it reached the captured one');

        self::assertSame([], $this->operationsSentToQuickpay());

        foreach ([$captured, $bankTransfer] as $payment) {
            $payment = $this->reload($payment);
            self::assertSame(PaymentInterface::STATE_NEW, $payment->getState());
            self::assertSame(OrderInterface::STATE_NEW, $payment->getOrder()?->getState());
        }
    }

    /**
     * The capture's outcome isn't known yet, so the cancel would race it: the money may be captured a moment later
     *
     * @test
     *
     * @dataProvider stateMachineAdapters
     */
    public function it_refuses_to_cancel_a_payment_whose_capture_is_in_flight_at_quickpay(string $stateMachineAdapter): void
    {
        self::bootKernelWithStateMachineAdapter($stateMachineAdapter);

        $payment = $this->createSyliusPayment(PaymentInterface::STATE_AUTHORIZED, OrderPaymentStates::STATE_AUTHORIZED, quickpayPaymentId: 501);
        $this->quickpay()->respond('GET', '/payments/501', self::quickpayPayment(501, 'processed', ['authorize'], balance: 0, pendingOperations: ['capture']));
        $transitions = $this->recordPaymentTransitions();

        try {
            $this->applyTransition($payment, PaymentTransitions::TRANSITION_CANCEL);
            self::fail('Cancelling a payment whose capture is in flight must fail');
        } catch (OperationPendingException $e) {
            self::assertSame(501, $e->getPaymentId());
        }

        self::assertSame([], $transitions->getArrayCopy());
        self::assertSame([], $this->operationsSentToQuickpay());

        $payment = $this->reload($payment);
        self::assertSame(PaymentInterface::STATE_AUTHORIZED, $payment->getState());
        self::assertSame(OrderPaymentStates::STATE_AUTHORIZED, $payment->getOrder()?->getPaymentState());
    }

    /**
     * @test
     */
    public function it_cancels_a_payment_quickpay_refuses_to_cancel_with_a_warning(): void
    {
        self::bootKernel();
        $logger = $this->recordProcessorLog();

        $payment = $this->createSyliusPayment(PaymentInterface::STATE_AUTHORIZED, OrderPaymentStates::STATE_AUTHORIZED, quickpayPaymentId: 501);
        $this->quickpay()->respond('GET', '/payments/501', self::quickpayPayment(501, 'new', ['authorize'], balance: 0));
        $this->quickpay()->respond('POST', '/payments/501/cancel', self::quickpayError(400, 'Transaction in wrong state for this operation'));

        $this->applyTransition($payment, PaymentTransitions::TRANSITION_CANCEL);

        self::assertSame(['POST /payments/501/cancel'], $this->operationsSentToQuickpay());
        self::assertSame(PaymentInterface::STATE_CANCELLED, $this->reload($payment)->getState());

        self::assertCount(1, $logger->records);
        [$level, $message, $context] = $logger->records[0];
        self::assertSame(LogLevel::WARNING, $level);
        self::assertStringStartsWith('Could not cancel Quickpay payment: ', $message);
        self::assertStringContainsString('Transaction in wrong state for this operation', $message);
        self::assertSame(['quickpayPaymentId' => 501, 'paymentId' => $payment->getId()], $context);
    }

    /**
     * Quickpay answered the cancel, and the acquirer declined it: the money stays authorized, and unused
     * authorizations expire by themselves at Quickpay
     *
     * @test
     */
    public function it_cancels_a_payment_whose_cancel_quickpay_declines_with_a_warning(): void
    {
        self::bootKernel();
        $logger = $this->recordProcessorLog();

        $payment = $this->createSyliusPayment(PaymentInterface::STATE_AUTHORIZED, OrderPaymentStates::STATE_AUTHORIZED, quickpayPaymentId: 501);
        $this->quickpay()->respond('GET', '/payments/501', self::quickpayPayment(501, 'new', ['authorize'], balance: 0));
        $this->quickpay()->respond('POST', '/payments/501/cancel', self::quickpayPaymentDeclining('cancel'));

        $this->applyTransition($payment, PaymentTransitions::TRANSITION_CANCEL);

        self::assertSame(['POST /payments/501/cancel'], $this->operationsSentToQuickpay());
        self::assertSame(PaymentInterface::STATE_CANCELLED, $this->reload($payment)->getState());
        self::assertSame([[
            LogLevel::WARNING,
            'Could not cancel Quickpay payment: Quickpay declined the cancel of payment 501: status 40000 (Rejected By Acquirer).',
            ['quickpayPaymentId' => 501, 'paymentId' => $payment->getId()],
        ]], $logger->records);
    }

    /**
     * Its details carry a Quickpay payment id payum-quickpay can't read, so its status can't be checked: the cancel
     * proceeds without contacting Quickpay, and the warning says why
     *
     * @test
     */
    public function it_cancels_a_payment_whose_quickpay_payment_id_cannot_be_read_with_a_warning(): void
    {
        self::bootKernel();
        $logger = $this->recordProcessorLog();

        $payment = $this->createSyliusPayment(PaymentInterface::STATE_AUTHORIZED, OrderPaymentStates::STATE_AUTHORIZED, quickpayPaymentId: 501);
        $payment->setDetails(['quickpayPaymentId' => 'abc'] + $payment->getDetails());
        $this->entityManager()->flush();

        $this->applyTransition($payment, PaymentTransitions::TRANSITION_CANCEL);

        self::assertSame([], $this->quickpay()->sentRequests());
        self::assertSame(PaymentInterface::STATE_CANCELLED, $this->reload($payment)->getState());

        self::assertCount(1, $logger->records);
        [$level, $message, $context] = $logger->records[0];
        self::assertSame(LogLevel::WARNING, $level);
        self::assertStringStartsWith('Could not cancel Quickpay payment: ', $message);
        self::assertStringContainsString('"quickpayPaymentId"', $message);
        // The id can't be read, so the log names none
        self::assertSame(['quickpayPaymentId' => null, 'paymentId' => $payment->getId()], $context);
    }

    /**
     * The processor's logger is optional, as the plugin wires it: without one, a cancel Quickpay refuses still lets the
     * transition proceed
     *
     * @test
     */
    public function it_lets_a_cancel_quickpay_refuses_proceed_without_a_logger(): void
    {
        self::bootKernel();

        $payment = $this->createSyliusPayment(PaymentInterface::STATE_AUTHORIZED, OrderPaymentStates::STATE_AUTHORIZED, quickpayPaymentId: 501);
        $this->quickpay()->respond('GET', '/payments/501', self::quickpayPayment(501, 'new', ['authorize'], balance: 0));
        $this->quickpay()->respond('POST', '/payments/501/cancel', self::quickpayError(400, 'Transaction in wrong state for this operation'));

        ($this->processor())($payment, PaymentTransitions::TRANSITION_CANCEL);

        self::assertSame(['POST /payments/501/cancel'], $this->operationsSentToQuickpay());
    }

    /**
     * @test
     */
    public function it_cancels_a_payment_whose_cancel_answer_from_quickpay_breaks_off(): void
    {
        self::bootKernel();

        $payment = $this->createSyliusPayment(PaymentInterface::STATE_AUTHORIZED, OrderPaymentStates::STATE_AUTHORIZED, quickpayPaymentId: 501);
        $this->quickpay()->respond('GET', '/payments/501', self::quickpayPayment(501, 'new', ['authorize'], balance: 0));
        // The connection drops after Quickpay's headers arrived
        $this->quickpay()->respond('POST', '/payments/501/cancel', new MockResponse((static function (): \Generator {
            yield '{"id": 501, "state": "ne';

            throw new \RuntimeException('Connection reset by peer');
        })(), ['http_code' => 202, 'response_headers' => ['Content-Type' => 'application/json']]));

        $this->applyTransition($payment, PaymentTransitions::TRANSITION_CANCEL);

        self::assertSame(['POST /payments/501/cancel'], $this->operationsSentToQuickpay());
        self::assertSame(PaymentInterface::STATE_CANCELLED, $this->reload($payment)->getState());
    }

    /**
     * @test
     */
    public function it_cancels_a_payment_whose_quickpay_status_cannot_be_read(): void
    {
        self::bootKernel();

        $payment = $this->createSyliusPayment(PaymentInterface::STATE_AUTHORIZED, OrderPaymentStates::STATE_AUTHORIZED, quickpayPaymentId: 501);
        $this->quickpay()->respond('GET', '/payments/501', self::quickpayError(500, 'Internal server error'));

        $this->applyTransition($payment, PaymentTransitions::TRANSITION_CANCEL);

        self::assertSame([], $this->operationsSentToQuickpay());
        self::assertSame(PaymentInterface::STATE_CANCELLED, $this->reload($payment)->getState());
    }

    /**
     * @test
     *
     * @dataProvider stateMachineAdapters
     */
    public function it_cancels_a_payment_whose_gateway_configuration_is_unusable_with_a_warning(string $stateMachineAdapter): void
    {
        self::bootKernelWithStateMachineAdapter($stateMachineAdapter);
        $logger = $this->recordProcessorLog();

        $payment = $this->createSyliusPayment(PaymentInterface::STATE_AUTHORIZED, OrderPaymentStates::STATE_AUTHORIZED, quickpayPaymentId: 501);
        $payment->setMethod($this->createPaymentMethod('unusable', self::UNUSABLE_GATEWAY_CONFIGURATION));
        $this->entityManager()->flush();
        $transitions = $this->recordPaymentTransitions();

        $this->applyTransition($payment, PaymentTransitions::TRANSITION_CANCEL);

        self::assertSame([PaymentTransitions::TRANSITION_CANCEL], $transitions->getArrayCopy());
        self::assertSame([], $this->quickpay()->sentRequests());
        self::assertSame(PaymentInterface::STATE_CANCELLED, $this->reload($payment)->getState());

        self::assertCount(1, $logger->records);
        [$level, $message, $context] = $logger->records[0];
        self::assertSame(LogLevel::WARNING, $level);
        self::assertStringStartsWith('Could not cancel Quickpay payment', $message);
        self::assertStringContainsString('branding_id', $message);
        self::assertSame(['quickpayPaymentId' => 501, 'paymentId' => $payment->getId()], $context);
    }

    /**
     * @test
     *
     * @dataProvider moneyOperationsUnderEachStateMachineAdapter
     */
    public function it_does_not_apply_a_money_operation_whose_gateway_configuration_is_unusable(
        string $stateMachineAdapter,
        string $paymentState,
        string $orderPaymentState,
        string $transition,
    ): void {
        self::bootKernelWithStateMachineAdapter($stateMachineAdapter);

        $payment = $this->createSyliusPayment($paymentState, $orderPaymentState, quickpayPaymentId: 501);
        $payment->setMethod($this->createPaymentMethod('unusable', self::UNUSABLE_GATEWAY_CONFIGURATION));
        $this->entityManager()->flush();
        $transitions = $this->recordPaymentTransitions();

        try {
            $this->applyTransition($payment, $transition);
            self::fail('Capturing or refunding with an unusable gateway configuration must fail');
        } catch (UnusableGatewayConfiguration $e) {
            // Building the gateway reads the configuration through the client provider first
            self::assertSame(['branding_id'], array_keys($e->getViolations()));
        }

        self::assertSame([], $transitions->getArrayCopy());
        self::assertSame([], $this->quickpay()->sentRequests());

        $payment = $this->reload($payment);
        self::assertSame($paymentState, $payment->getState());
        self::assertSame($orderPaymentState, $payment->getOrder()?->getPaymentState());
    }

    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function moneyOperationsUnderEachStateMachineAdapter(): iterable
    {
        foreach (self::stateMachineAdapters() as $name => [$stateMachineAdapter]) {
            yield sprintf('capture, %s', $name) => [$stateMachineAdapter, PaymentInterface::STATE_AUTHORIZED, OrderPaymentStates::STATE_AUTHORIZED, PaymentTransitions::TRANSITION_COMPLETE];
            yield sprintf('refund, %s', $name) => [$stateMachineAdapter, PaymentInterface::STATE_COMPLETED, OrderPaymentStates::STATE_PAID, PaymentTransitions::TRANSITION_REFUND];
        }
    }

    /**
     * @test
     */
    public function it_does_not_complete_a_payment_quickpay_refuses_to_capture(): void
    {
        self::bootKernel();

        $payment = $this->createSyliusPayment(PaymentInterface::STATE_AUTHORIZED, OrderPaymentStates::STATE_AUTHORIZED, quickpayPaymentId: 501);
        $this->quickpay()->respond('GET', '/payments/501', self::quickpayPayment(501, 'new', ['authorize'], balance: 0));
        $this->quickpay()->respond('POST', '/payments/501/capture', self::quickpayError(400, 'Transaction in wrong state for this operation'));

        try {
            $this->applyTransition($payment, PaymentTransitions::TRANSITION_COMPLETE);
            self::fail('Completing a payment Quickpay refused to capture must fail');
        } catch (QuickpayException) {
        }

        $payment = $this->reload($payment);
        self::assertSame(PaymentInterface::STATE_AUTHORIZED, $payment->getState());
        self::assertSame(OrderPaymentStates::STATE_AUTHORIZED, $payment->getOrder()?->getPaymentState());
    }

    /**
     * The customer opened the Quickpay window, then switched the order to bank transfer: Sylius kept the payment and its
     * details, Quickpay payment id included. It is the bank transfer's now, so the plugin builds no gateway, checks no
     * status and contacts no one, and the admin can mark it paid, refund it or cancel it
     *
     * @test
     *
     * @dataProvider transitionsOfAPaymentSwitchedToAnotherMethod
     */
    public function it_leaves_a_payment_switched_to_another_payment_method_alone(
        string $stateMachineAdapter,
        string $state,
        string $orderPaymentState,
        string $transition,
        string $stateAfter,
    ): void {
        self::bootKernelWithStateMachineAdapter($stateMachineAdapter);
        $logger = $this->recordProcessorLog();

        $payment = $this->createSyliusPayment($state, $orderPaymentState, quickpayPaymentId: 501);
        $payment->setMethod($this->createPaymentMethod('bank_transfer', [], factoryName: 'offline'));
        $this->entityManager()->flush();
        $details = $payment->getDetails();

        $this->applyTransition($payment, $transition);

        self::assertSame([], $this->quickpay()->sentRequests());
        self::assertSame([], $logger->records);
        $payment = $this->reload($payment);
        self::assertSame($stateAfter, $payment->getState());
        // Not even the offline gateway's own refund or cancel ran on it. Equal, not identical: MySQL's JSON type stores
        // the keys in an order of its own
        self::assertEquals($details, $payment->getDetails());
    }

    /**
     * @return iterable<string, array{string, string, string, string, string}>
     */
    public static function transitionsOfAPaymentSwitchedToAnotherMethod(): iterable
    {
        foreach (self::stateMachineAdapters() as $adapterName => [$adapter]) {
            yield sprintf('%s: complete', $adapterName) => [$adapter, PaymentInterface::STATE_NEW, OrderPaymentStates::STATE_AWAITING_PAYMENT, PaymentTransitions::TRANSITION_COMPLETE, PaymentInterface::STATE_COMPLETED];
            yield sprintf('%s: complete a processing payment', $adapterName) => [$adapter, PaymentInterface::STATE_PROCESSING, OrderPaymentStates::STATE_AWAITING_PAYMENT, PaymentTransitions::TRANSITION_COMPLETE, PaymentInterface::STATE_COMPLETED];
            yield sprintf('%s: refund', $adapterName) => [$adapter, PaymentInterface::STATE_COMPLETED, OrderPaymentStates::STATE_PAID, PaymentTransitions::TRANSITION_REFUND, PaymentInterface::STATE_REFUNDED];
            yield sprintf('%s: cancel', $adapterName) => [$adapter, PaymentInterface::STATE_NEW, OrderPaymentStates::STATE_AWAITING_PAYMENT, PaymentTransitions::TRANSITION_CANCEL, PaymentInterface::STATE_CANCELLED];
        }
    }

    /**
     * @test
     */
    public function it_completes_a_payment_never_sent_to_quickpay_without_contacting_quickpay(): void
    {
        self::bootKernel();

        $payment = $this->createSyliusPayment(PaymentInterface::STATE_NEW, OrderPaymentStates::STATE_AWAITING_PAYMENT, quickpayPaymentId: 501);
        $payment->setDetails([]);

        $this->applyTransition($payment, PaymentTransitions::TRANSITION_COMPLETE);

        self::assertSame([], $this->quickpay()->sentRequests());
        self::assertSame(PaymentInterface::STATE_COMPLETED, $this->reload($payment)->getState());
    }

    /**
     * @test
     */
    public function it_captures_a_fraud_suspected_payment_when_the_fraud_guard_is_off(): void
    {
        self::bootKernel();

        $payment = $this->createSyliusPayment(PaymentInterface::STATE_AUTHORIZED, OrderPaymentStates::STATE_AUTHORIZED, quickpayPaymentId: 501);
        $this->quickpay()->respond('GET', '/payments/501', self::quickpayPayment(501, 'new', ['authorize'], balance: 0, fraudSuspected: true));
        $this->quickpay()->respond('POST', '/payments/501/capture', self::quickpayPayment(501, 'new', ['authorize'], balance: 0, fraudSuspected: true));

        ($this->processor(blockCaptureOnSuspectedFraud: false))($payment, PaymentTransitions::TRANSITION_COMPLETE);

        self::assertSame(['POST /payments/501/capture'], $this->operationsSentToQuickpay());
    }

    /**
     * @test
     */
    public function it_leaves_a_fraud_suspected_payment_uncaptured_when_the_fraud_guard_is_on(): void
    {
        self::bootKernel();

        $payment = $this->createSyliusPayment(PaymentInterface::STATE_AUTHORIZED, OrderPaymentStates::STATE_AUTHORIZED, quickpayPaymentId: 501);
        $this->quickpay()->respond('GET', '/payments/501', self::quickpayPayment(501, 'new', ['authorize'], balance: 0, fraudSuspected: true));

        ($this->processor(blockCaptureOnSuspectedFraud: true))($payment, PaymentTransitions::TRANSITION_COMPLETE);

        self::assertSame([], $this->operationsSentToQuickpay());
    }

    /**
     * The transition proceeds, and the warning tells the merchant to review the payment and capture or cancel it in the
     * Quickpay manager
     *
     * @test
     */
    public function it_completes_a_fraud_suspected_payment_without_capturing_it_and_with_a_warning_when_the_fraud_guard_is_on(): void
    {
        self::bootKernel();
        $this->configureProcessor(blockCaptureOnSuspectedFraud: true);
        $logger = $this->recordProcessorLog();

        $payment = $this->createSyliusPayment(PaymentInterface::STATE_AUTHORIZED, OrderPaymentStates::STATE_AUTHORIZED, quickpayPaymentId: 501);
        $this->quickpay()->respond('GET', '/payments/501', self::quickpayPayment(501, 'new', ['authorize'], balance: 0, fraudSuspected: true));
        $transitions = $this->recordPaymentTransitions();

        $this->applyTransition($payment, PaymentTransitions::TRANSITION_COMPLETE);

        self::assertSame([PaymentTransitions::TRANSITION_COMPLETE], $transitions->getArrayCopy());
        self::assertSame([], $this->operationsSentToQuickpay());
        self::assertSame(PaymentInterface::STATE_COMPLETED, $this->reload($payment)->getState());
        self::assertSame([[
            LogLevel::WARNING,
            'Skipped the automatic capture: Quickpay reports the payment as fraud suspected. Review the payment and capture or cancel it manually',
            ['quickpayPaymentId' => 501, 'paymentId' => $payment->getId()],
        ]], $logger->records);
    }

    /**
     * @test
     */
    public function it_refuses_to_complete_a_fraud_suspected_payment_quickpay_holds_no_authorization_for_when_the_fraud_guard_is_on(): void
    {
        self::bootKernel();

        $payment = $this->createSyliusPayment(PaymentInterface::STATE_NEW, OrderPaymentStates::STATE_AWAITING_PAYMENT, quickpayPaymentId: 501);
        $this->quickpay()->respond('GET', '/payments/501', self::quickpayPayment(501, 'new', [], balance: 0, fraudSuspected: true));

        try {
            ($this->processor(blockCaptureOnSuspectedFraud: true))($payment, PaymentTransitions::TRANSITION_COMPLETE);
            self::fail('Completing a payment Quickpay holds no authorization for must fail, fraud suspected or not');
        } catch (NoAuthorizationToCapture) {
        }

        // The status check only: the fraud guard is for an authorization about to be captured
        self::assertSame(['GET /payments/501'], $this->quickpay()->sentRequests());
    }

    /**
     * @test
     */
    public function it_captures_a_payment_without_suspected_fraud_when_the_fraud_guard_is_on(): void
    {
        self::bootKernel();

        $payment = $this->createSyliusPayment(PaymentInterface::STATE_AUTHORIZED, OrderPaymentStates::STATE_AUTHORIZED, quickpayPaymentId: 501);
        $this->quickpay()->respond('GET', '/payments/501', self::quickpayPayment(501, 'new', ['authorize'], balance: 0));
        $this->quickpay()->respond('POST', '/payments/501/capture', self::quickpayPayment(501, 'new', ['authorize'], balance: 0));

        ($this->processor(blockCaptureOnSuspectedFraud: true))($payment, PaymentTransitions::TRANSITION_COMPLETE);

        self::assertSame(['POST /payments/501/capture'], $this->operationsSentToQuickpay());
    }

    /**
     * @test
     *
     * @dataProvider operations
     */
    public function it_leaves_quickpay_alone_when_the_operation_is_turned_off(string $paymentState, string $transition, string $operation): void
    {
        self::bootKernel();

        $payment = $this->createSyliusPayment($paymentState, OrderPaymentStates::STATE_AUTHORIZED, quickpayPaymentId: 501);

        ($this->processor(...[$operation => false]))($payment, $transition);

        self::assertSame([], $this->quickpay()->sentRequests());
    }

    /**
     * Only completing, refunding and cancelling a payment carry an operation out at Quickpay
     *
     * @test
     *
     * @dataProvider transitionsWithoutAnOperation
     */
    public function it_leaves_quickpay_alone_on_any_other_transition(string $transition): void
    {
        self::bootKernel();

        $payment = $this->createSyliusPayment(PaymentInterface::STATE_NEW, OrderPaymentStates::STATE_AWAITING_PAYMENT, quickpayPaymentId: 501);

        ($this->processor())($payment, $transition);

        self::assertSame([], $this->quickpay()->sentRequests());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function transitionsWithoutAnOperation(): iterable
    {
        yield 'authorize' => [PaymentTransitions::TRANSITION_AUTHORIZE];

        yield 'fail' => [PaymentTransitions::TRANSITION_FAIL];
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function operations(): iterable
    {
        yield 'capture' => [PaymentInterface::STATE_AUTHORIZED, PaymentTransitions::TRANSITION_COMPLETE, 'capture'];
        yield 'refund' => [PaymentInterface::STATE_COMPLETED, PaymentTransitions::TRANSITION_REFUND, 'refund'];
        yield 'cancel' => [PaymentInterface::STATE_AUTHORIZED, PaymentTransitions::TRANSITION_CANCEL, 'cancel'];
    }

    /**
     * @test
     *
     * @dataProvider operationsUnderEachStateMachineAdapter
     */
    public function it_applies_the_transition_of_a_switched_off_operation_without_building_the_gateway(
        string $stateMachineAdapter,
        string $paymentState,
        string $transition,
        string $operation,
        string $expectedState,
    ): void {
        self::bootKernelWithStateMachineAdapter($stateMachineAdapter);
        $this->configureProcessor(...[$operation => false]);

        // The Quickpay gateway can't be built from this configuration, so building it would abort the transition
        $payment = $this->createSyliusPayment($paymentState, OrderPaymentStates::STATE_AUTHORIZED, quickpayPaymentId: 501);
        $payment->setMethod($this->createPaymentMethod('unusable', self::UNUSABLE_GATEWAY_CONFIGURATION));
        $this->entityManager()->flush();
        $transitions = $this->recordPaymentTransitions();

        $this->applyTransition($payment, $transition);

        self::assertSame([$transition], $transitions->getArrayCopy());
        self::assertSame([], $this->quickpay()->sentRequests());
        self::assertSame($expectedState, $this->reload($payment)->getState());
    }

    /**
     * @return iterable<string, array{string, string, string, string, string}>
     */
    public static function operationsUnderEachStateMachineAdapter(): iterable
    {
        $expectedStates = [
            'capture' => PaymentInterface::STATE_COMPLETED,
            'refund' => PaymentInterface::STATE_REFUNDED,
            'cancel' => PaymentInterface::STATE_CANCELLED,
        ];

        foreach (self::stateMachineAdapters() as $adapterName => [$stateMachineAdapter]) {
            foreach (self::operations() as $operationName => [$paymentState, $transition, $operation]) {
                yield sprintf('%s, %s', $operationName, $adapterName) => [$stateMachineAdapter, $paymentState, $transition, $operation, $expectedStates[$operation]];
            }
        }
    }

    /**
     * The payment processor as the plugin wires it, with the operations and the fraud guard configured as
     * given. Its fraud checker is the container's, reading Quickpay through the scripted client
     */
    private function processor(
        bool $capture = true,
        bool $refund = true,
        bool $cancel = true,
        bool $blockCaptureOnSuspectedFraud = false,
    ): PaymentProcessor {
        $fraudChecker = self::getContainer()->get(FraudCheckerInterface::class);
        self::assertInstanceOf(FraudCheckerInterface::class, $fraudChecker);

        $gatewayConfigurationProvider = self::getContainer()->get(GatewayConfigurationProviderInterface::class);
        self::assertInstanceOf(GatewayConfigurationProviderInterface::class, $gatewayConfigurationProvider);
        $quickpayPaymentIdProvider = self::getContainer()->get(QuickpayPaymentIdProviderInterface::class);
        self::assertInstanceOf(QuickpayPaymentIdProviderInterface::class, $quickpayPaymentIdProvider);

        return new PaymentProcessor($this->payum(), $this->reconciler(), $fraudChecker, $gatewayConfigurationProvider, $quickpayPaymentIdProvider, $capture, $refund, $cancel, $blockCaptureOnSuspectedFraud);
    }

    /**
     * Configures the plugin's operations and fraud guard as an app does in its plugin config: the state
     * machine then hands every payment transition to a processor configured so. Call it before anything
     * uses the container's processor
     */
    private function configureProcessor(
        bool $capture = true,
        bool $refund = true,
        bool $cancel = true,
        bool $blockCaptureOnSuspectedFraud = false,
    ): void {
        self::getContainer()->set(PaymentProcessor::class, $this->processor($capture, $refund, $cancel, $blockCaptureOnSuspectedFraud));
    }

    /**
     * Records what the container's payment processor logs from now on
     */
    private function recordProcessorLog(): RecordingLogger
    {
        $logger = new RecordingLogger();

        $processor = self::getContainer()->get(PaymentProcessor::class);
        self::assertInstanceOf(PaymentProcessor::class, $processor);
        $processor->setLogger($logger);

        return $logger;
    }

    /**
     * Quickpay's answer to an operation on payment 501 that the acquirer declined after the payment was authorized: a
     * 2xx whose operation completed without being approved
     */
    private static function quickpayPaymentDeclining(string $operation): ResponseInterface
    {
        return new Response(202, ['Content-Type' => 'application/json'], json_encode([
            'id' => 501,
            'order_id' => self::ORDER_PREFIX . '000000001',
            'currency' => self::CURRENCY,
            'state' => 'new',
            'merchant_id' => 1,
            'accepted' => true,
            'test_mode' => true,
            'balance' => 0,
            'operations' => [
                ['id' => 1, 'type' => 'authorize', 'amount' => 10_000, 'pending' => false, 'qp_status_code' => '20000', 'qp_status_msg' => 'Approved'],
                ['id' => 2, 'type' => $operation, 'amount' => 10_000, 'pending' => false, 'qp_status_code' => '40000', 'qp_status_msg' => 'Rejected By Acquirer'],
            ],
        ], \JSON_THROW_ON_ERROR));
    }

    /**
     * Quickpay's answer to a request it fails or refuses
     */
    private static function quickpayError(int $statusCode, string $message): ResponseInterface
    {
        return new Response($statusCode, ['Content-Type' => 'application/json'], json_encode(['message' => $message], \JSON_THROW_ON_ERROR));
    }

    /**
     * Applies the transition through Sylius' state machine and flushes, as the admin's request does
     */
    private function applyTransition(PaymentInterface $payment, string $transition): void
    {
        $stateMachine = self::getContainer()->get('sylius_abstraction.state_machine');
        self::assertInstanceOf(StateMachineInterface::class, $stateMachine);

        $stateMachine->apply($payment, PaymentTransitions::GRAPH, $transition);
        $this->entityManager()->flush();
    }

    /**
     * The requests sent to Quickpay that act on a payment, i.e. everything but reads
     *
     * @return list<string>
     */
    private function operationsSentToQuickpay(): array
    {
        return array_values(array_filter(
            $this->quickpay()->sentRequests(),
            static fn (string $request): bool => !str_starts_with($request, 'GET '),
        ));
    }
}
