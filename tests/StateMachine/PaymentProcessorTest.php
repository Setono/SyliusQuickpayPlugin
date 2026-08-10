<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Tests\StateMachine;

use Nyholm\Psr7\Response;
use Payum\Core\Exception\Http\HttpException;
use Payum\Core\GatewayInterface;
use Payum\Core\Model\GatewayConfigInterface;
use Payum\Core\Payum;
use Payum\Core\Request\Cancel;
use Payum\Core\Request\Capture;
use Payum\Core\Request\GetHumanStatus;
use Payum\Core\Request\Refund;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Setono\Quickpay\Exception\ValidationException;
use Setono\SyliusQuickpayPlugin\StateMachine\PaymentProcessor;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Payment\PaymentTransitions;

final class PaymentProcessorTest extends TestCase
{
    use ProphecyTrait;

    /** @var list<object> */
    private array $executedRequests = [];

    protected function setUp(): void
    {
        $this->executedRequests = [];
    }

    /**
     * @test
     */
    public function it_captures_the_quickpay_payment_when_the_payment_is_completed(): void
    {
        $processor = new PaymentProcessor($this->createPayum($this->createGateway()), true, true, true);
        $processor($this->createPayment(), PaymentTransitions::TRANSITION_COMPLETE);

        self::assertInstanceOf(GetHumanStatus::class, $this->executedRequests[0] ?? null);
        self::assertInstanceOf(Capture::class, $this->executedRequests[1] ?? null);
    }

    /**
     * @test
     */
    public function it_skips_capture_when_the_payment_is_already_captured(): void
    {
        $gateway = $this->createGateway(static function (object $request): void {
            if ($request instanceof GetHumanStatus) {
                $request->markCaptured();
            }
        });

        $processor = new PaymentProcessor($this->createPayum($gateway), true, true, true);
        $processor($this->createPayment(), PaymentTransitions::TRANSITION_COMPLETE);

        self::assertNotContainsInstanceOf(Capture::class, $this->executedRequests);
    }

    /**
     * @test
     */
    public function it_skips_refund_when_the_payment_is_already_refunded(): void
    {
        $gateway = $this->createGateway(static function (object $request): void {
            if ($request instanceof GetHumanStatus) {
                $request->markRefunded();
            }
        });

        $processor = new PaymentProcessor($this->createPayum($gateway), true, true, true);
        $processor($this->createPayment(), PaymentTransitions::TRANSITION_REFUND);

        self::assertNotContainsInstanceOf(Refund::class, $this->executedRequests);
    }

    /**
     * @test
     */
    public function it_refunds_the_quickpay_payment_when_the_payment_is_refunded(): void
    {
        $processor = new PaymentProcessor($this->createPayum($this->createGateway()), true, true, true);
        $processor($this->createPayment(), PaymentTransitions::TRANSITION_REFUND);

        self::assertInstanceOf(GetHumanStatus::class, $this->executedRequests[0] ?? null);
        self::assertInstanceOf(Refund::class, $this->executedRequests[1] ?? null);
    }

    /**
     * @test
     */
    public function it_cancels_the_quickpay_payment_when_the_payment_is_cancelled(): void
    {
        $processor = new PaymentProcessor($this->createPayum($this->createGateway()), true, true, true);
        $processor($this->createPayment(), PaymentTransitions::TRANSITION_CANCEL);

        self::assertInstanceOf(GetHumanStatus::class, $this->executedRequests[0] ?? null);
        self::assertInstanceOf(Cancel::class, $this->executedRequests[1] ?? null);
    }

    /**
     * @test
     */
    public function it_skips_cancel_when_the_payment_is_already_cancelled(): void
    {
        $gateway = $this->createGateway(static function (object $request): void {
            if ($request instanceof GetHumanStatus) {
                $request->markCanceled();
            }
        });

        $processor = new PaymentProcessor($this->createPayum($gateway), true, true, true);
        $processor($this->createPayment(), PaymentTransitions::TRANSITION_CANCEL);

        self::assertNotContainsInstanceOf(Cancel::class, $this->executedRequests);
    }

    /**
     * @test
     */
    public function it_does_not_block_the_cancel_transition_when_the_gateway_fails(): void
    {
        $gateway = $this->createGateway(static function (object $request): void {
            if ($request instanceof Cancel) {
                throw new HttpException('Transaction in wrong state for this operation');
            }
        });

        $processor = new PaymentProcessor($this->createPayum($gateway), true, true, true);
        $processor($this->createPayment(), PaymentTransitions::TRANSITION_CANCEL);

        // Reaching this point means the exception was caught and the transition can proceed
        $this->addToAssertionCount(1);
    }

    /**
     * @test
     */
    public function it_does_not_block_the_cancel_transition_on_sdk_exceptions(): void
    {
        $gateway = $this->createGateway(static function (object $request): void {
            if ($request instanceof Cancel) {
                throw new ValidationException(new Response(400), 'Payment is not in a valid state for cancel');
            }
        });

        $processor = new PaymentProcessor($this->createPayum($gateway), true, true, true);
        $processor($this->createPayment(), PaymentTransitions::TRANSITION_CANCEL);

        $this->addToAssertionCount(1);
    }

    /**
     * @test
     */
    public function it_propagates_gateway_failures_on_the_complete_transition(): void
    {
        $gateway = $this->createGateway(static function (object $request): void {
            if ($request instanceof Capture) {
                throw new HttpException('Capture failed');
            }
        });

        $processor = new PaymentProcessor($this->createPayum($gateway), true, true, true);

        $this->expectException(HttpException::class);
        $processor($this->createPayment(), PaymentTransitions::TRANSITION_COMPLETE);
    }

    /**
     * @test
     */
    public function it_does_nothing_when_the_payment_has_no_quickpay_payment_id(): void
    {
        $gateway = $this->prophesize(GatewayInterface::class);
        $gateway->execute(Argument::any())->shouldNotBeCalled();

        $payment = $this->prophesize(PaymentInterface::class);
        $payment->getDetails()->willReturn([]);

        $processor = new PaymentProcessor($this->createPayum($gateway->reveal()), true, true, true);
        $processor($payment->reveal(), PaymentTransitions::TRANSITION_CANCEL);
    }

    /**
     * @test
     */
    public function it_does_nothing_when_the_operation_is_disabled(): void
    {
        $gateway = $this->prophesize(GatewayInterface::class);
        $gateway->execute(Argument::any())->shouldNotBeCalled();

        $processor = new PaymentProcessor($this->createPayum($gateway->reveal()), true, true, false);
        $processor($this->createPayment(), PaymentTransitions::TRANSITION_CANCEL);
    }

    /**
     * @param class-string $class
     * @param list<object> $objects
     */
    private static function assertNotContainsInstanceOf(string $class, array $objects): void
    {
        foreach ($objects as $object) {
            self::assertNotInstanceOf($class, $object);
        }
        self::assertNotEmpty($objects);
    }

    private function createGateway(?callable $handler = null): GatewayInterface
    {
        $executedRequests = &$this->executedRequests;

        $gateway = $this->prophesize(GatewayInterface::class);
        $gateway
            ->execute(Argument::type('object'))
            ->will(function (array $args) use (&$executedRequests, $handler): void {
                $executedRequests[] = $args[0];

                if (null !== $handler) {
                    $handler($args[0]);
                }
            })
        ;

        return $gateway->reveal();
    }

    private function createPayum(GatewayInterface $gateway): Payum
    {
        $payum = $this->prophesize(Payum::class);
        $payum->getGateway(Argument::type('string'))->willReturn($gateway);

        return $payum->reveal();
    }

    private function createPayment(): PaymentInterface
    {
        $gatewayConfig = $this->prophesize(GatewayConfigInterface::class);
        $gatewayConfig->getGatewayName()->willReturn('quickpay');

        $method = $this->prophesize(PaymentMethodInterface::class);
        $method->getGatewayConfig()->willReturn($gatewayConfig->reveal());

        $payment = $this->prophesize(PaymentInterface::class);
        $payment->getDetails()->willReturn(['quickpayPaymentId' => 12345]);
        $payment->getMethod()->willReturn($method->reveal());

        return $payment->reveal();
    }
}
