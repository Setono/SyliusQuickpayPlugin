<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Mailer;

use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Mailer\Sender\SenderInterface;
use Webmozart\Assert\Assert;

/**
 * Sends the payment link email the way Sylius' own email managers do: through the Sylius mailer,
 * with the order, channel and locale in the data so the template renders in the customer's locale.
 */
final class PaymentLinkEmailManager implements PaymentLinkEmailManagerInterface
{
    public function __construct(private readonly SenderInterface $emailSender)
    {
    }

    public function sendPaymentLinkEmail(PaymentInterface $payment, string $paymentLink): void
    {
        $order = $payment->getOrder();
        Assert::isInstanceOf($order, OrderInterface::class);

        $customer = $order->getCustomer();
        Assert::isInstanceOf($customer, CustomerInterface::class);

        $email = $customer->getEmail();
        Assert::stringNotEmpty($email);

        $this->emailSender->send(Emails::PAYMENT_LINK, [$email], [
            'order' => $order,
            'payment' => $payment,
            'paymentLink' => $paymentLink,
            'channel' => $order->getChannel(),
            'localeCode' => $order->getLocaleCode(),
        ]);
    }
}
