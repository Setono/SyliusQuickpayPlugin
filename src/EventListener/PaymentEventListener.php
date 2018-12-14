<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\EventListener;

use Combine\Payum\QuickPay\QuickPayGatewayFactory;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Request\Capture;
use Payum\Core\Request\Refund;
use Sylius\Bundle\ResourceBundle\Event\ResourceControllerEvent;
use Sylius\Component\Core\Model\Payment;

/**
 * @author jdk
 */
class PaymentEventListener
{
    /**
     * @param ResourceControllerEvent $event
     */
    public function onPostEvent(ResourceControllerEvent $event)
    {
        $payment = $event->getSubject();

        // Only act on "Payment" entity
        if (!$payment instanceof Payment) {
            return;
        }

        // Check if it's a QP payment
        $details = $payment->getDetails();
        if (!isset($details['quickpayPaymentId'])) {
            return;
        }

        $gatewayConfig = $payment->getMethod()->getGatewayConfig();
        $factory = new QuickPayGatewayFactory();
        $gateway = $factory->create($gatewayConfig->getConfig());

        $model = new ArrayObject($details);

        switch ($payment->getState()) {
            case Payment::STATE_COMPLETED:
                $gateway->execute(new Capture($model));

                break;
            case Payment::STATE_REFUNDED:
                $gateway->execute(new Refund($model));

                break;
        }
    }
}
