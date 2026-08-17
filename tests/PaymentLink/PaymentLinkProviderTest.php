<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Tests\PaymentLink;

use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Setono\SyliusQuickpayPlugin\PaymentLink\PaymentLinkProvider;
use Sylius\Bundle\PayumBundle\Model\GatewayConfig;
use Sylius\Component\Core\Model\Channel;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\Payment;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethod;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\UrlHelper;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;

final class PaymentLinkProviderTest extends TestCase
{
    use ProphecyTrait;

    /** @var ObjectProphecy<UrlGeneratorInterface> */
    private ObjectProphecy $urlGenerator;

    protected function setUp(): void
    {
        $this->urlGenerator = $this->prophesize(UrlGeneratorInterface::class);
        $this->urlGenerator->generate('sylius_shop_order_pay', ['tokenValue' => 'tok3n', '_locale' => 'da_DK'])->willReturn('/da_DK/order/tok3n/pay');
    }

    /**
     * @test
     */
    public function it_builds_the_pay_url_on_the_channel_hostname(): void
    {
        [$payment] = $this->createOrderWithPayment(hostname: 'shop.example');

        self::assertSame('https://shop.example/da_DK/order/tok3n/pay', $this->createProvider()->provide($payment));
        self::assertSame('http://shop.example/da_DK/order/tok3n/pay', $this->createProvider(unsecuredUrls: true)->provide($payment));
    }

    /**
     * @test
     */
    public function it_falls_back_to_the_current_host_when_the_channel_has_no_hostname(): void
    {
        [$payment] = $this->createOrderWithPayment(hostname: null);

        self::assertSame('http://localhost/da_DK/order/tok3n/pay', $this->createProvider()->provide($payment));
    }

    /**
     * @test
     */
    public function it_provides_nothing_when_the_payment_cannot_be_paid_through_the_link(): void
    {
        $provider = $this->createProvider();

        [$completed] = $this->createOrderWithPayment(paymentState: PaymentInterface::STATE_COMPLETED);
        self::assertNull($provider->provide($completed), 'a payment that is not awaiting payment');

        [$offline] = $this->createOrderWithPayment(factoryName: 'offline');
        self::assertNull($provider->provide($offline), 'a non-Quickpay payment');

        [$cancelled] = $this->createOrderWithPayment(orderState: OrderInterface::STATE_CANCELLED);
        self::assertNull($provider->provide($cancelled), 'a cancelled order');

        [$superseded, $order] = $this->createOrderWithPayment();
        $later = $this->createPayment($superseded->getMethod(), PaymentInterface::STATE_NEW);
        $order->addPayment($later);
        self::assertNull($provider->provide($superseded), 'an earlier payment when a later one awaits payment');
        self::assertNotNull($provider->provide($later));

        [$noToken] = $this->createOrderWithPayment(tokenValue: null);
        self::assertNull($provider->provide($noToken), 'an order without a token');
    }

    /**
     * @test
     */
    public function it_provides_nothing_when_the_shop_route_does_not_exist(): void
    {
        $this->urlGenerator->generate('sylius_shop_order_pay', ['tokenValue' => 'tok3n', '_locale' => 'da_DK'])->willThrow(new RouteNotFoundException());
        [$payment] = $this->createOrderWithPayment();

        self::assertNull($this->createProvider()->provide($payment));
    }

    private function createProvider(bool $unsecuredUrls = false): PaymentLinkProvider
    {
        // UrlHelper is final: a real one resolving against a request context stands in for "the current host"
        $urlHelper = new UrlHelper(new RequestStack(), new RequestContext('', 'GET', 'localhost', 'http'));

        return new PaymentLinkProvider($this->urlGenerator->reveal(), $urlHelper, $unsecuredUrls);
    }

    /**
     * @return array{0: PaymentInterface, 1: OrderInterface}
     */
    private function createOrderWithPayment(
        string $factoryName = 'quickpay',
        string $paymentState = PaymentInterface::STATE_NEW,
        string $orderState = OrderInterface::STATE_NEW,
        ?string $tokenValue = 'tok3n',
        ?string $hostname = 'shop.example',
    ): array {
        $gatewayConfig = new GatewayConfig();
        $gatewayConfig->setFactoryName($factoryName);
        $gatewayConfig->setGatewayName($factoryName);

        $method = new PaymentMethod();
        $method->setCode($factoryName);
        $method->setGatewayConfig($gatewayConfig);

        $channel = new Channel();
        $channel->setCode('WEB');
        $channel->setHostname($hostname);

        $order = new Order();
        $order->setState($orderState);
        $order->setTokenValue($tokenValue);
        $order->setLocaleCode('da_DK');
        $order->setChannel($channel);

        $payment = $this->createPayment($method, $paymentState);
        $order->addPayment($payment);

        return [$payment, $order];
    }

    private function createPayment(?\Sylius\Component\Payment\Model\PaymentMethodInterface $method, string $state): PaymentInterface
    {
        $payment = new Payment();
        $payment->setMethod($method);
        $payment->setState($state);
        $payment->setAmount(24999);
        $payment->setCurrencyCode('DKK');

        return $payment;
    }
}
