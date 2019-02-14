<?php

declare(strict_types=1);

namespace spec\Setono\SyliusQuickpayPlugin\StateMachine;

use PhpSpec\ObjectBehavior;
use Setono\SyliusQuickpayPlugin\StateMachine\Resolver;
use Sylius\Bundle\PayumBundle\Model\GatewayConfigInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Core\OrderPaymentTransitions;
use Sylius\Component\Order\StateResolver\StateResolverInterface;

class ResolverSpec extends ObjectBehavior
{
    public function it_is_initializable(): void
    {
        $this->shouldHaveType(Resolver::class);
    }

    public function it_is_an_abstract_type_form(): void
    {
        $this->shouldImplement(StateResolverInterface::class);
    }

    public function will_not_execute_if_no_payments(OrderInterface $order): void
    {
        $order->getLastPayment()->willReturn(null);
        $this->resolve($order)->shouldReturn(null);
    }

    public function will_not_execute_if_payment_has_quickpay_id(
        OrderInterface $order,
        PaymentInterface $payment
    ): void
    {
        $payment->getDetails()->willReturn([]);
        $order->getLastPayment()->willReturn($payment);
        $this->resolve($order);
    }

    public function will_not_execute_if_payment_has_no_payment_method(
        OrderInterface $order,
        PaymentInterface $payment
    ): void
    {
        $payment->getDetails()->willReturn(['quickpayPaymentId' => 1]);
        $payment->getMethod()->willReturn(null);
        $order->getLastPayment()->willReturn($payment);
        $this->resolve($order);
    }

    public function will_not_execute_if_no_gateway_config(
        OrderInterface $order,
        PaymentInterface $payment,
        PaymentMethodInterface $paymentMethod
    ): void
    {
        $paymentMethod->getGatewayConfig()->willReturn(null);
        $payment->getDetails()->willReturn(['quickpayPaymentId' => 1]);
        $payment->getMethod()->willReturn($paymentMethod);
        $order->getLastPayment()->willReturn($payment);
        $this->resolve($order);
    }

    public function will_not_execute_partial_pay_when_amount_is_too_much(
        OrderInterface $order,
        PaymentInterface $payment,
        PaymentMethodInterface $paymentMethod,
        GatewayConfigInterface $gatewayConfig
    ): void
    {
        $gatewayConfig->getConfig()->willReturn([]);
        $paymentMethod->getGatewayConfig()->willReturn($gatewayConfig);
        $payment->getDetails()->willReturn(['quickpayPaymentId' => 1, 'amount' => 50]);
        $payment->getMethod()->willReturn($paymentMethod);
        $order->getLastPayment()->willReturn($payment);
        $this->getPaymentTotalWithState($order, PaymentInterface::STATE_COMPLETED)->shouldReturn(100);
        $this->getTargetTransition($order)->shouldReturn(OrderPaymentTransitions::TRANSITION_PARTIALLY_PAY);
        $this->resolve($order);
    }

    public function will_execute_partial_pay_when_amount_is_not_payed(
        OrderInterface $order,
        PaymentInterface $payment,
        PaymentMethodInterface $paymentMethod,
        GatewayConfigInterface $gatewayConfig
    ): void
    {
        $gatewayConfig->getConfig()->willReturn([]);
        $paymentMethod->getGatewayConfig()->willReturn($gatewayConfig);
        $payment->getDetails()->willReturn(['quickpayPaymentId' => 1, 'amount' => 50]);
        $payment->getMethod()->willReturn($paymentMethod);
        $order->getLastPayment()->willReturn($payment);
        $this->getPaymentTotalWithState($order, PaymentInterface::STATE_COMPLETED)->shouldReturn(0);
        $this->getTargetTransition($order)->shouldReturn(OrderPaymentTransitions::TRANSITION_PARTIALLY_PAY);
        $this->resolve($order);
    }

    public function will_execute_pay(
        OrderInterface $order,
        PaymentInterface $payment,
        PaymentMethodInterface $paymentMethod,
        GatewayConfigInterface $gatewayConfig
    ): void
    {
        $gatewayConfig->getConfig()->willReturn([]);
        $paymentMethod->getGatewayConfig()->willReturn($gatewayConfig);
        $payment->getDetails()->willReturn(['quickpayPaymentId' => 1, 'amount' => 50]);
        $payment->getMethod()->willReturn($paymentMethod);
        $order->getLastPayment()->willReturn($payment);
        $this->getPaymentTotalWithState($order, PaymentInterface::STATE_COMPLETED)->shouldReturn(0);
        $this->getTargetTransition($order)->shouldReturn(OrderPaymentTransitions::TRANSITION_PAY);
        $this->resolve($order);
    }

    public function will_not_execute_refund_if_nothing_is_payed(
        OrderInterface $order,
        PaymentInterface $payment,
        PaymentMethodInterface $paymentMethod,
        GatewayConfigInterface $gatewayConfig
    ): void
    {
        $gatewayConfig->getConfig()->willReturn([]);
        $paymentMethod->getGatewayConfig()->willReturn($gatewayConfig);
        $payment->getDetails()->willReturn(['quickpayPaymentId' => 1, 'amount' => 50]);
        $payment->getMethod()->willReturn($paymentMethod);
        $order->getLastPayment()->willReturn($payment);
        $this->getPaymentTotalWithState($order, PaymentInterface::STATE_COMPLETED)->shouldReturn(0);
        $this->getTargetTransition($order)->shouldReturn(OrderPaymentTransitions::TRANSITION_REFUND);
        $this->resolve($order);
    }

    public function will_execute_refund_if_amount_is_payed(
        OrderInterface $order,
        PaymentInterface $payment,
        PaymentMethodInterface $paymentMethod,
        GatewayConfigInterface $gatewayConfig
    ): void
    {
        $gatewayConfig->getConfig()->willReturn([]);
        $paymentMethod->getGatewayConfig()->willReturn($gatewayConfig);
        $payment->getDetails()->willReturn(['quickpayPaymentId' => 1, 'amount' => 50]);
        $payment->getMethod()->willReturn($paymentMethod);
        $order->getLastPayment()->willReturn($payment);
        $this->getPaymentTotalWithState($order, PaymentInterface::STATE_COMPLETED)->shouldReturn(50);
        $this->getTargetTransition($order)->shouldReturn(OrderPaymentTransitions::TRANSITION_REFUND);
        $this->resolve($order);
    }
}
