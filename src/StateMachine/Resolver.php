<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\StateMachine;

use Setono\SyliusQuickpayPlugin\Exception\UnsupportedPaymentTransitionException;
use Setono\Payum\QuickPay\QuickPayGatewayFactory;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Request\Capture;
use Payum\Core\Request\Refund;
use Sylius\Component\Core\OrderPaymentTransitions;
use Sylius\Component\Order\Model\OrderInterface;
use Sylius\Component\Order\StateResolver\StateResolverInterface;
use Sylius\Component\Resource\Factory\FactoryInterface;
use Webmozart\Assert\Assert;

/**
 * @author jdk
 */
final class Resolver implements StateResolverInterface
{
    /**
     * @var FactoryInterface
     */
    private $stateMachineFactory;

    /**
     * @param FactoryInterface $stateMachineFactory
     */
    public function __construct(FactoryInterface $stateMachineFactory)
    {
        $this->stateMachineFactory = $stateMachineFactory;
    }

    /**
     * {@inheritdoc}
     */
    public function resolve(OrderInterface $order): void
    {
        /** @var OrderInterface $order */
        Assert::isInstanceOf($order, OrderInterface::class);

        $stateMachine = $this->stateMachineFactory->get($order, OrderPaymentTransitions::GRAPH);
        $targetTransition = $this->getTargetTransition($order);

        $payments = $order->getPayments();
        $payment = end($payments);

        // Check if it's a QP payment
        $details = $payment->getDetails();
        if (!isset($details['quickpayPaymentId'])) {
            return;
        }

        $gatewayConfig = $payment->getMethod()->getGatewayConfig();
        $factory = new QuickPayGatewayFactory();
        $gateway = $factory->create($gatewayConfig->getConfig());

        $model = new ArrayObject($details);

        switch ($targetTransition) {
            case OrderPaymentTransitions::TRANSITION_PAY:
                $gateway->execute(new Capture($model));

                break;
            case OrderPaymentTransitions::TRANSITION_REFUND:
                $gateway->execute(new Refund($model));

                break;
            case OrderPaymentTransitions::TRANSITION_PARTIALLY_REFUND:
            case OrderPaymentTransitions::TRANSITION_PARTIALLY_PAY:
            default:
                throw new UnsupportedPaymentTransitionException("Payment transition \"$targetTransition\" is not supported.");

                break;
        }
    }
}
