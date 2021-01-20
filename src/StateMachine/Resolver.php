<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\StateMachine;

use Doctrine\Common\Collections\Collection;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Registry\RegistryInterface;
use Payum\Core\Request\Capture;
use Payum\Core\Request\Refund;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Core\OrderPaymentTransitions;
use Sylius\Component\Order\Model\OrderInterface as BaseOrderInterface;
use Sylius\Component\Order\StateResolver\StateResolverInterface;
use Webmozart\Assert\Assert;

final class Resolver implements StateResolverInterface
{
    private RegistryInterface $payum;

    private bool $disableCapture;

    public function __construct(
        RegistryInterface $payum,
        bool $disableCapture
    ) {
        $this->payum = $payum;
        $this->disableCapture = $disableCapture;
    }

    /**
     * @param OrderInterface|BaseOrderInterface $order
     */
    public function resolve(BaseOrderInterface $order): void
    {
        Assert::isInstanceOf($order, OrderInterface::class);

        $targetTransition = $this->getTargetTransition($order);

        $lastPayment = $order->getLastPayment();
        if (null === $lastPayment) {
            return;
        }

        // Check if it's a QP payment
        $details = $lastPayment->getDetails();
        if (!isset($details['quickpayPaymentId'])) {
            return;
        }

        /** @var PaymentMethodInterface|null $paymentMethod */
        $paymentMethod = $lastPayment->getMethod();
        if (null === $paymentMethod) {
            return;
        }

        $gatewayConfig = $paymentMethod->getGatewayConfig();
        if (null === $gatewayConfig) {
            return;
        }

        $gatewayFactory = $this->payum->getGatewayFactory('quickpay');
        $gateway = $gatewayFactory->create($gatewayConfig->getConfig());

        $model = new ArrayObject($details);

        [$totalPayed] = $this->getPaymentTotalWithState($order, PaymentInterface::STATE_COMPLETED);
        switch ($targetTransition) {
            case OrderPaymentTransitions::TRANSITION_PARTIALLY_PAY:
                $model['amount'] -= $totalPayed;
                if ($model['amount'] <= 0) {
                    return;
                }
                // no break
            case OrderPaymentTransitions::TRANSITION_PAY:
                if ($this->disableCapture) {
                    // @todo Do we need to do something here to trigger
                    // update payment state to captured & update order state to paid
                } else {
                    $gateway->execute(new Capture($model));
                }

                break;
            case OrderPaymentTransitions::TRANSITION_PARTIALLY_REFUND:
                [$totalRefunded] = $this->getPaymentTotalWithState($order, PaymentInterface::STATE_REFUNDED);
                $model['amount'] = $totalPayed - $totalRefunded;
                if ($model['amount'] <= 0) {
                    return;
                }
                // no break
            case OrderPaymentTransitions::TRANSITION_REFUND:
                $gateway->execute(new Refund($model));

                break;
        }
    }

    private function getTargetTransition(OrderInterface $order): ?string
    {
        [$refundedPaymentTotal, $refundedPayments] = $this->getPaymentTotalWithState($order, PaymentInterface::STATE_REFUNDED);

        if (0 < $refundedPayments->count() && $refundedPaymentTotal >= $order->getTotal()) {
            return OrderPaymentTransitions::TRANSITION_REFUND;
        }

        if (0 < $refundedPaymentTotal && $refundedPaymentTotal < $order->getTotal()) {
            return OrderPaymentTransitions::TRANSITION_PARTIALLY_REFUND;
        }

        [$completedPaymentTotal, $completedPayments] = $this->getPaymentTotalWithState($order, PaymentInterface::STATE_COMPLETED);

        if (
            (0 < $completedPayments->count() && $completedPaymentTotal >= $order->getTotal()) ||
            $order->getPayments()->isEmpty()
        ) {
            return OrderPaymentTransitions::TRANSITION_PAY;
        }

        if (0 < $completedPaymentTotal && $completedPaymentTotal < $order->getTotal()) {
            return OrderPaymentTransitions::TRANSITION_PARTIALLY_PAY;
        }

        [$authorizedPaymentTotal, $authorizedPayments] = $this->getPaymentTotalWithState($order, PaymentInterface::STATE_AUTHORIZED);

        if (0 < $authorizedPayments->count() && $authorizedPaymentTotal >= $order->getTotal()) {
            return OrderPaymentTransitions::TRANSITION_AUTHORIZE;
        }

        if (0 < $authorizedPaymentTotal && $authorizedPaymentTotal < $order->getTotal()) {
            return OrderPaymentTransitions::TRANSITION_PARTIALLY_AUTHORIZE;
        }

        return null;
    }

    /**
     * @return Collection|PaymentInterface[]
     */
    private function getPaymentsWithState(OrderInterface $order, string $state): Collection
    {
        return $order->getPayments()->filter(function (PaymentInterface $payment) use ($state): bool {
            return $state === $payment->getState();
        });
    }

    private function getPaymentTotalWithState(OrderInterface $order, string $state): array
    {
        $paymentTotal = 0;
        $payments = $this->getPaymentsWithState($order, $state);

        foreach ($payments as $payment) {
            $paymentTotal += $payment->getAmount();
        }

        return [$paymentTotal, $payments];
    }
}
