<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Tests\Functional\StateMachine;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Setono\Payum\Quickpay\Exception\InvalidGatewayOptions;
use Setono\Quickpay\Exception\QuickpayException;
use Setono\SyliusQuickpayPlugin\Fraud\FraudCheckerInterface;
use Setono\SyliusQuickpayPlugin\GatewayConfiguration\UnusableGatewayConfiguration;
use Setono\SyliusQuickpayPlugin\StateMachine\NoAuthorizationToCapture;
use Setono\SyliusQuickpayPlugin\StateMachine\PaymentProcessor;
use Setono\SyliusQuickpayPlugin\StateMachine\PaymentProcessorInterface;
use Setono\SyliusQuickpayPlugin\Tests\Functional\FunctionalTestCase;
use Setono\SyliusQuickpayPlugin\Tests\GatewayConfiguration\GatewayConfigurations;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\OrderPaymentStates;
use Sylius\Component\Payment\PaymentTransitions;

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
            self::assertStringContainsString(sprintf('"%s"', $targetState), $e->getMessage());
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
            yield sprintf('declined, %s', $name) => [$stateMachineAdapter, 'new', [], PaymentInterface::STATE_FAILED];
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
     * @test
     */
    public function it_cancels_a_payment_quickpay_refuses_to_cancel(): void
    {
        self::bootKernel();

        $payment = $this->createSyliusPayment(PaymentInterface::STATE_AUTHORIZED, OrderPaymentStates::STATE_AUTHORIZED, quickpayPaymentId: 501);
        $this->quickpay()->respond('GET', '/payments/501', self::quickpayPayment(501, 'new', ['authorize'], balance: 0));
        $this->quickpay()->respond('POST', '/payments/501/cancel', self::quickpayError(400, 'Transaction in wrong state for this operation'));

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
        } catch (InvalidGatewayOptions|UnusableGatewayConfiguration $e) {
            // Which of the two surfaces depends on what reads the configuration first
            self::assertStringContainsString('branding_id', $e->getMessage());
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
    ): PaymentProcessorInterface {
        $fraudChecker = self::getContainer()->get(FraudCheckerInterface::class);
        self::assertInstanceOf(FraudCheckerInterface::class, $fraudChecker);

        return new PaymentProcessor($this->payum(), $this->reconciler(), $fraudChecker, $capture, $refund, $cancel, $blockCaptureOnSuspectedFraud);
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
     *
     * @return object{records: list<array{mixed, string, array<array-key, mixed>}>}
     */
    private function recordProcessorLog(): object
    {
        $logger = new class() extends AbstractLogger {
            /** @var list<array{mixed, string, array<array-key, mixed>}> */
            public array $records = [];

            /**
             * @param mixed $level
             * @param string|\Stringable $message
             * @param array<array-key, mixed> $context
             */
            public function log($level, $message, array $context = []): void
            {
                $this->records[] = [$level, (string) $message, $context];
            }
        };

        $processor = self::getContainer()->get(PaymentProcessor::class);
        self::assertInstanceOf(PaymentProcessor::class, $processor);
        $processor->setLogger($logger);

        return $logger;
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
