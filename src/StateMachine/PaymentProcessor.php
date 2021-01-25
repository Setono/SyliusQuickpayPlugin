<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\StateMachine;

use Payum\Core\Payum;
use Payum\Core\Request\Cancel;
use Payum\Core\Request\Capture;
use Payum\Core\Request\Refund;
use SM\Event\TransitionEvent;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Payment\PaymentTransitions;

final class PaymentProcessor
{
    private Payum $payum;
    private bool $disableCapture;
    private bool $disableRefund;
    private bool $disableCancel;

    public function __construct(Payum $payum, bool $disableCapture, bool $disableRefund, bool $disableCancel)
    {
        $this->payum = $payum;
        $this->disableCapture = $disableCapture;
        $this->disableRefund = $disableRefund;
        $this->disableCancel = $disableCancel;
    }

    public function __invoke(PaymentInterface $payment, TransitionEvent $event): void
    {
        if (!isset($payment->getDetails()['quickpayPaymentId'])) {
            return;
        }

        /** @var PaymentMethodInterface|null $method */
        $method = $payment->getMethod();
        if (null === $method) {
            return;
        }

        $gatewayConfig = $method->getGatewayConfig();
        if (null === $gatewayConfig) {
            return;
        }

        $gateway = $this->payum->getGateway($gatewayConfig->getGatewayName());

        switch ($event->getTransition()) {
            case PaymentTransitions::TRANSITION_COMPLETE:
                if ($this->disableCapture) {
                    return;
                }

                $gateway->execute(new Capture($payment->getDetails()));

                break;
            case PaymentTransitions::TRANSITION_REFUND:
                if ($this->disableRefund) {
                    return;
                }

                $gateway->execute(new Refund($payment->getDetails()));

                break;
            case PaymentTransitions::TRANSITION_CANCEL:
                if ($this->disableCancel) {
                    return;
                }

                $gateway->execute(new Cancel($payment->getDetails()));

                break;
        }
    }
}
