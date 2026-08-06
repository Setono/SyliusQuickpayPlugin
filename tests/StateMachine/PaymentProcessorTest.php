<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Tests\StateMachine;

use Payum\Core\Exception\Http\HttpException;
use Payum\Core\GatewayInterface;
use Payum\Core\Model\GatewayConfigInterface;
use Payum\Core\Payum;
use Payum\Core\Request\Cancel;
use PHPUnit\Framework\TestCase;
use Setono\SyliusQuickpayPlugin\StateMachine\PaymentProcessor;
use SM\Event\TransitionEvent;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Payment\PaymentTransitions;

final class PaymentProcessorTest extends TestCase
{
    /**
     * @test
     */
    public function it_cancels_the_quickpay_payment_when_the_payment_is_cancelled(): void
    {
        $gateway = $this->createMock(GatewayInterface::class);
        $gateway->expects(self::once())->method('execute')->with(self::isInstanceOf(Cancel::class));

        $processor = new PaymentProcessor($this->createPayum($gateway), false, false, false);
        $processor($this->createPayment(), $this->createEvent(PaymentTransitions::TRANSITION_CANCEL));
    }

    /**
     * @test
     */
    public function it_does_not_block_the_cancel_transition_when_the_gateway_fails(): void
    {
        $gateway = $this->createMock(GatewayInterface::class);
        $gateway->method('execute')->willThrowException(new HttpException('Transaction in wrong state for this operation'));

        $processor = new PaymentProcessor($this->createPayum($gateway), false, false, false);
        $processor($this->createPayment(), $this->createEvent(PaymentTransitions::TRANSITION_CANCEL));

        // Reaching this point means the exception was caught and the transition can proceed
        $this->addToAssertionCount(1);
    }

    /**
     * @test
     */
    public function it_propagates_gateway_failures_on_the_complete_transition(): void
    {
        $gateway = $this->createMock(GatewayInterface::class);
        $gateway->method('execute')->willThrowException(new HttpException('Capture failed'));

        $processor = new PaymentProcessor($this->createPayum($gateway), false, false, false);

        $this->expectException(HttpException::class);
        $processor($this->createPayment(), $this->createEvent(PaymentTransitions::TRANSITION_COMPLETE));
    }

    /**
     * @test
     */
    public function it_does_nothing_when_the_payment_has_no_quickpay_payment_id(): void
    {
        $gateway = $this->createMock(GatewayInterface::class);
        $gateway->expects(self::never())->method('execute');

        $payment = $this->createMock(PaymentInterface::class);
        $payment->method('getDetails')->willReturn([]);

        $processor = new PaymentProcessor($this->createPayum($gateway), false, false, false);
        $processor($payment, $this->createEvent(PaymentTransitions::TRANSITION_CANCEL));
    }

    /**
     * @test
     */
    public function it_does_nothing_when_the_operation_is_disabled(): void
    {
        $gateway = $this->createMock(GatewayInterface::class);
        $gateway->expects(self::never())->method('execute');

        $processor = new PaymentProcessor($this->createPayum($gateway), false, false, true);
        $processor($this->createPayment(), $this->createEvent(PaymentTransitions::TRANSITION_CANCEL));
    }

    private function createPayum(GatewayInterface $gateway): Payum
    {
        $payum = $this->createMock(Payum::class);
        $payum->method('getGateway')->willReturn($gateway);

        return $payum;
    }

    private function createPayment(): PaymentInterface
    {
        $gatewayConfig = $this->createMock(GatewayConfigInterface::class);
        $gatewayConfig->method('getGatewayName')->willReturn('quickpay');

        $method = $this->createMock(PaymentMethodInterface::class);
        $method->method('getGatewayConfig')->willReturn($gatewayConfig);

        $payment = $this->createMock(PaymentInterface::class);
        $payment->method('getDetails')->willReturn(['quickpayPaymentId' => '12345']);
        $payment->method('getMethod')->willReturn($method);

        return $payment;
    }

    private function createEvent(string $transition): TransitionEvent
    {
        $event = $this->createMock(TransitionEvent::class);
        $event->method('getTransition')->willReturn($transition);

        return $event;
    }
}
