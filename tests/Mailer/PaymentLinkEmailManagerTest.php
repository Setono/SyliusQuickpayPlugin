<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Tests\Mailer;

use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Setono\SyliusQuickpayPlugin\Mailer\Emails;
use Setono\SyliusQuickpayPlugin\Mailer\PaymentLinkEmailManager;
use Sylius\Component\Core\Model\Channel;
use Sylius\Component\Core\Model\Customer;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\Payment;
use Sylius\Component\Mailer\Sender\SenderInterface;

final class PaymentLinkEmailManagerTest extends TestCase
{
    use ProphecyTrait;

    /**
     * @test
     */
    public function it_sends_the_payment_link_email_to_the_customer_in_the_order_locale(): void
    {
        $customer = new Customer();
        $customer->setEmail('joe@example.com');
        $channel = new Channel();
        $channel->setCode('WEB');
        $order = new Order();
        $order->setLocaleCode('da_DK');
        $order->setChannel($channel);
        $order->setCustomer($customer);
        $payment = new Payment();
        $order->addPayment($payment);

        $sender = $this->prophesize(SenderInterface::class);
        $sender->send(Emails::PAYMENT_LINK, ['joe@example.com'], [
            'order' => $order,
            'payment' => $payment,
            'paymentLink' => 'https://shop.example/da_DK/order/tok3n/pay',
            'channel' => $channel,
            'localeCode' => 'da_DK',
        ])->shouldBeCalledOnce();

        (new PaymentLinkEmailManager($sender->reveal()))->sendPaymentLinkEmail($payment, 'https://shop.example/da_DK/order/tok3n/pay');
    }

    /**
     * @test
     */
    public function it_refuses_to_send_when_the_order_has_no_customer_email(): void
    {
        $order = new Order();
        $payment = new Payment();
        $order->addPayment($payment);

        $sender = $this->prophesize(SenderInterface::class);
        $sender->send(Argument::cetera())->shouldNotBeCalled();

        $this->expectException(\InvalidArgumentException::class);

        (new PaymentLinkEmailManager($sender->reveal()))->sendPaymentLinkEmail($payment, 'https://shop.example/da_DK/order/tok3n/pay');
    }
}
