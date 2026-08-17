<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Mailer;

use Sylius\Component\Core\Model\PaymentInterface;

interface PaymentLinkEmailManagerInterface
{
    /**
     * Emails the customer of the payment's order the link that pays the payment.
     *
     * @throws \InvalidArgumentException when the order has no customer email to send to
     */
    public function sendPaymentLinkEmail(PaymentInterface $payment, string $paymentLink): void;
}
