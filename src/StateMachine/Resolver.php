<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\StateMachine;

use Setono\SyliusQuickpayPlugin\Exception\UnsupportedPaymentTransitionException;
use Setono\Payum\QuickPay\QuickPayGatewayFactory;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Request\Capture;
use Payum\Core\Request\Refund;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\OrderPaymentTransitions;
use Sylius\Component\Order\Model\OrderInterface;
use Sylius\Component\Order\StateResolver\StateResolverInterface;
use SM\Factory\FactoryInterface;
use Webmozart\Assert\Assert;
use Doctrine\Common\Collections\Collection;

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

        $lastPayment = $order->getLastPayment();

        // Check if it's a QP payment
        $details = $lastPayment->getDetails();
        if (!isset($details['quickpayPaymentId'])) {
            return;
        }

        $gatewayConfig = $lastPayment->getMethod()->getGatewayConfig();
        $factory = new QuickPayGatewayFactory();
        $gateway = $factory->create($gatewayConfig->getConfig());

        $model = new ArrayObject($details);

        list($totalPayed) = $this->getPaymentTotalWithState($order, PaymentInterface::STATE_COMPLETED);
        switch ($targetTransition) {
            case OrderPaymentTransitions::TRANSITION_PARTIALLY_PAY:
                $model['amount'] = $model['amount'] - $totalPayed;
                if ($model['amount'] <= 0) {
                    return;
                }
            case OrderPaymentTransitions::TRANSITION_PAY:
                $gateway->execute(new Capture($model));
                break;
            case OrderPaymentTransitions::TRANSITION_PARTIALLY_REFUND:
                list($totalRefunded) = $this->getPaymentTotalWithState($order, PaymentInterface::STATE_REFUNDED);
                $model['amount'] = $totalPayed - $totalRefunded;
                if ($model['amount'] <= 0) {
                    return;
                }
            case OrderPaymentTransitions::TRANSITION_REFUND:
                $gateway->execute(new Refund($model));
                break;
        }
    }

    /**
     * @param OrderInterface $order
     *
     * @return null|string
     */
    private function getTargetTransition(OrderInterface $order): ?string
    {
        list($refundedPaymentTotal, $refundedPayments) = $this->getPaymentTotalWithState($order, PaymentInterface::STATE_REFUNDED);

        if (0 < $refundedPayments->count() && $refundedPaymentTotal >= $order->getTotal()) {
            return OrderPaymentTransitions::TRANSITION_REFUND;
        }

        if ($refundedPaymentTotal < $order->getTotal() && 0 < $refundedPaymentTotal) {
            return OrderPaymentTransitions::TRANSITION_PARTIALLY_REFUND;
        }

        list($completedPaymentTotal, $completedPayments) = $this->getPaymentTotalWithState($order, PaymentInterface::STATE_COMPLETED);

        if (
            (0 < $completedPayments->count() && $completedPaymentTotal >= $order->getTotal()) ||
            $order->getPayments()->isEmpty()
        ) {
            return OrderPaymentTransitions::TRANSITION_PAY;
        }

        if ($completedPaymentTotal < $order->getTotal() && 0 < $completedPaymentTotal) {
            return OrderPaymentTransitions::TRANSITION_PARTIALLY_PAY;
        }

        list($authorizedPaymentTotal, $authorizedPayments) = $this->getPaymentTotalWithState($order, PaymentInterface::STATE_AUTHORIZED);

        if (0 < $authorizedPayments->count() && $authorizedPaymentTotal >= $order->getTotal()) {
            return OrderPaymentTransitions::TRANSITION_AUTHORIZE;
        }

        if ($authorizedPaymentTotal < $order->getTotal() && 0 < $authorizedPaymentTotal) {
            return OrderPaymentTransitions::TRANSITION_PARTIALLY_AUTHORIZE;
        }

        return null;
    }


    /**
     * @param OrderInterface $order
     * @param string         $state
     *
     * @return Collection|PaymentInterface[]
     */
    private function getPaymentsWithState(OrderInterface $order, string $state): Collection
    {
        return $order->getPayments()->filter(function (PaymentInterface $payment) use ($state) {
            return $state === $payment->getState();
        });
    }

    /**
     * @param OrderInterface $order
     *
     * @return array
     */
    private function getPaymentTotalWithState(OrderInterface $order, string $state): array
    {
        $paymentToal = 0;
        $payments = $this->getPaymentsWithState($order, $state);

        foreach ($payments as $payment) {
            $paymentToal += $payment->getAmount();
        }
        return [$paymentToal, $payments];
    }
}
