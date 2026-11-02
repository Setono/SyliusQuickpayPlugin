<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Reconciliation;

use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Exception\InvalidArgumentException;
use Payum\Core\GatewayInterface;
use Payum\Core\Payum;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\PayumBundle\Request\GetStatus;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Payment\PaymentTransitions;
use Webmozart\Assert\Assert;

final class PaymentReconciler implements PaymentReconcilerInterface
{
    public function __construct(
        private readonly Payum $payum,
        private readonly StateMachineInterface $stateMachine,
    ) {
    }

    public function checkStatus(PaymentInterface $payment): StatusCheck
    {
        // Sylius' own status request, so the status maps to a payment state exactly as Sylius' payment-state
        // extension maps it. It runs on a copy of the stored details instead of the Sylius payment: the
        // extension only applies a transition after a request on a Sylius payment, and Payum's storage only
        // persists one, so neither a transition nor the details the status read refreshes reach the payment
        $status = new GetStatus(new ArrayObject($payment->getDetails()));
        $this->gatewayOf($payment)->execute($status);

        // Sylius marks every status as a payment state string
        $quickpayStatus = $status->getValue();
        Assert::string($quickpayStatus);

        // The extension's own condition for applying a transition
        if ($quickpayStatus === $payment->getState() || PaymentInterface::STATE_UNKNOWN === $quickpayStatus) {
            return new StatusCheck($quickpayStatus, null);
        }

        return new StatusCheck(
            $quickpayStatus,
            $this->stateMachine->getTransitionToState($payment, PaymentTransitions::GRAPH, $quickpayStatus),
        );
    }

    private function gatewayOf(PaymentInterface $payment): GatewayInterface
    {
        $method = $payment->getMethod();
        $gatewayName = $method instanceof PaymentMethodInterface ? $method->getGatewayConfig()?->getGatewayName() : null;
        if (null === $gatewayName) {
            $id = $payment->getId();

            throw new InvalidArgumentException(sprintf(
                'Payment %s has no payment method with a gateway',
                is_scalar($id) ? (string) $id : '?',
            ));
        }

        return $this->payum->getGateway($gatewayName);
    }
}
