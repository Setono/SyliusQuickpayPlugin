<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Setono\SyliusQuickpayPlugin\Controller\NotifyAction;
use Setono\SyliusQuickpayPlugin\GatewayConfiguration\GatewayConfigurationProviderInterface;
use Setono\SyliusQuickpayPlugin\Quickpay\QuickpayPaymentIdProvider;
use Setono\SyliusQuickpayPlugin\Reconciliation\PaymentReconcilerInterface;
use Setono\SyliusQuickpayPlugin\Reconciliation\ReconcileResult;
use Setono\SyliusQuickpayPlugin\Tests\GatewayConfiguration\GatewayConfigurations;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\Payment;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Repository\OrderRepositoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class NotifyActionTest extends TestCase
{
    use ProphecyTrait;

    /** @var list<string> */
    private array $requestedOrderNumbers = [];

    protected function setUp(): void
    {
        $this->requestedOrderNumbers = [];
    }

    /**
     * @test
     *
     * @dataProvider orderIdProvider
     *
     * @param list<mixed> $configuredPrefixes
     * @param list<string> $expectedCandidates
     */
    public function it_resolves_the_order_number_from_the_configured_gateway_prefixes(
        array $configuredPrefixes,
        string $orderId,
        array $expectedCandidates,
    ): void {
        $gatewayConfigurationProvider = $this->createGatewayConfigurationProvider($configuredPrefixes);
        $action = new NotifyAction(
            $this->prophesize(PaymentReconcilerInterface::class)->reveal(),
            $this->createOrderRepository(),
            new QuickpayPaymentIdProvider($gatewayConfigurationProvider),
            $gatewayConfigurationProvider,
        );

        $response = $action($this->createRequest($orderId));

        self::assertSame(204, $response->getStatusCode());
        self::assertSame($expectedCandidates, $this->requestedOrderNumbers);
    }

    /**
     * @return iterable<string, array{list<mixed>, string, list<string>}>
     */
    public static function orderIdProvider(): iterable
    {
        yield 'configured prefix is stripped' => [['qp_'], 'qp_000000123', ['000000123', 'qp_000000123']];

        yield 'no configured gateways leaves the order id untouched' => [[], '000000123', ['000000123']];

        yield 'non-matching prefix leaves the order id untouched' => [['other_'], '000000123', ['000000123']];

        yield 'the matching prefix among several gateways is used' => [['qp1_', 'qp2_'], 'qp2_000000123', ['000000123', 'qp2_000000123']];

        yield 'a prefix that also appears inside the order id is only stripped from the start' => [['1'], '100121', ['00121', '100121']];

        // The gateway reads the prefix as a string, so the order id it created carries it as one
        yield 'a prefix stored as a number is stripped' => [[42], '42000000123', ['000000123', '42000000123']];

        // The order id is the order number itself, which is looked up once
        yield 'a gateway without an order prefix' => [[''], '000000123', ['000000123']];

        yield 'gateways sharing an order prefix' => [['qp_', 'qp_'], 'qp_000000123', ['000000123', 'qp_000000123']];
    }

    /**
     * The order's payment whose Quickpay payment id, read by payum-quickpay's rule, is the callback's is reconciled
     *
     * @test
     *
     * @dataProvider quickpayPaymentIdsOfTheCallbacksPayment
     */
    public function it_reconciles_the_orders_payment_whose_quickpay_payment_id_is_the_callbacks(mixed $storedId): void
    {
        $payment = self::payment($storedId);
        $reconciler = $this->prophesize(PaymentReconcilerInterface::class);
        $reconciler->reconcileFromCallback($payment)->willReturn(ReconcileResult::reconciled('new', 'completed', 'completed'))->shouldBeCalledOnce();

        $response = $this->createActionFor($payment, $reconciler->reveal())($this->createRequest('000001000', quickpayPaymentId: 1000));

        self::assertSame(204, $response->getStatusCode());
    }

    /**
     * The callback's body is JSON, so it may name the Quickpay payment id as a digit string
     *
     * @test
     */
    public function it_reconciles_the_payment_of_a_callback_naming_its_quickpay_payment_id_as_a_digit_string(): void
    {
        $payment = self::payment(1000);
        $reconciler = $this->prophesize(PaymentReconcilerInterface::class);
        $reconciler->reconcileFromCallback($payment)->willReturn(ReconcileResult::reconciled('new', 'completed', 'completed'))->shouldBeCalledOnce();

        $response = $this->createActionFor($payment, $reconciler->reveal())($this->createRequest('000001000', quickpayPaymentId: '1000'));

        self::assertSame(204, $response->getStatusCode());
    }

    /**
     * Only callbacks about a payment are handled: any other resource Quickpay calls back about (a subscription, or a
     * callback without its resource type) is answered 204, so Quickpay doesn't retry it, and reconciles nothing
     *
     * @test
     *
     * @dataProvider callbacksAboutAnotherResourceThanAPayment
     */
    public function it_answers_a_callback_about_another_resource_than_a_payment_with_a_204_and_reconciles_nothing(?string $resourceType): void
    {
        $payment = self::payment(1000);
        $reconciler = $this->prophesize(PaymentReconcilerInterface::class);
        $reconciler->reconcileFromCallback(Argument::any())->shouldNotBeCalled();

        $request = $this->createRequest('000001000', quickpayPaymentId: 1000);
        if (null === $resourceType) {
            $request->headers->remove('QuickPay-Resource-Type');
        } else {
            $request->headers->set('QuickPay-Resource-Type', $resourceType);
        }

        $response = $this->createActionFor($payment, $reconciler->reveal())($request);

        self::assertSame(204, $response->getStatusCode());
        self::assertSame('', $response->getContent());
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function callbacksAboutAnotherResourceThanAPayment(): iterable
    {
        yield 'a subscription' => ['Subscription'];

        yield 'no resource type' => [null];
    }

    /**
     * A callback about a payment must name the Quickpay payment by its numeric id and the order id as a string;
     * otherwise it is a bad request, and nothing is looked up or reconciled
     *
     * @test
     *
     * @dataProvider callbackBodiesNamingNoPayment
     *
     * @param array<string, mixed> $body
     */
    public function it_refuses_a_callback_whose_body_names_no_quickpay_payment_as_a_bad_request(array $body): void
    {
        $reconciler = $this->prophesize(PaymentReconcilerInterface::class);
        $reconciler->reconcileFromCallback(Argument::any())->shouldNotBeCalled();
        $orderRepository = $this->prophesize(OrderRepositoryInterface::class);
        $orderRepository->findOneByNumber(Argument::any())->shouldNotBeCalled();
        $gatewayConfigurationProvider = $this->prophesize(GatewayConfigurationProviderInterface::class);
        $gatewayConfigurationProvider->all()->willReturn([GatewayConfigurations::of()]);

        $action = new NotifyAction(
            $reconciler->reveal(),
            $orderRepository->reveal(),
            new QuickpayPaymentIdProvider($gatewayConfigurationProvider->reveal()),
            $gatewayConfigurationProvider->reveal(),
        );

        $request = new Request(content: (string) json_encode($body));
        $request->headers->set('QuickPay-Resource-Type', 'Payment');

        $this->expectException(BadRequestHttpException::class);

        $action($request);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function callbackBodiesNamingNoPayment(): iterable
    {
        yield 'no id' => [['order_id' => '000001000']];

        yield 'no order id' => [['id' => 1000]];

        yield 'an id that is no number' => [['id' => 'abc', 'order_id' => '000001000']];

        yield 'an order id that is no string' => [['id' => 1000, 'order_id' => 1000]];
    }

    /**
     * A payment skipped because its lock is held is answered 409, so Quickpay retries the callback
     *
     * @test
     */
    public function it_answers_a_409_when_the_payment_is_skipped_on_its_held_lock(): void
    {
        $payment = self::payment(1000);
        $reconciler = $this->prophesize(PaymentReconcilerInterface::class);
        $reconciler->reconcileFromCallback($payment)->willReturn(ReconcileResult::skipped('authorized'))->shouldBeCalledOnce();

        $response = $this->createActionFor($payment, $reconciler->reveal())($this->createRequest('000001000', quickpayPaymentId: 1000));

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('', $response->getContent());
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function quickpayPaymentIdsOfTheCallbacksPayment(): iterable
    {
        yield 'an int' => [1000];

        yield 'a digit string' => ['1000'];
    }

    /**
     * An id payum-quickpay can't read never matches, so a callback is never reconciled onto the wrong payment, nor
     * does it fail
     *
     * @test
     *
     * @dataProvider quickpayPaymentIdsOfAnotherPayment
     */
    public function it_reconciles_no_payment_whose_quickpay_payment_id_is_not_the_callbacks(mixed $storedId): void
    {
        $reconciler = $this->prophesize(PaymentReconcilerInterface::class);
        $reconciler->reconcileFromCallback(Argument::any())->shouldNotBeCalled();

        $response = $this->createActionFor(self::payment($storedId), $reconciler->reveal())($this->createRequest('000001000', quickpayPaymentId: 1000));

        self::assertSame(204, $response->getStatusCode());
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function quickpayPaymentIdsOfAnotherPayment(): iterable
    {
        yield 'another Quickpay payment' => [1001];

        yield 'an id in exponent notation' => ['1e3'];

        yield 'a fractional id' => [1000.5];

        yield 'an id that is no number' => ['abc'];

        yield 'no id' => [null];
    }

    /**
     * The shared endpoint over a store whose order 000001000 holds the payment, paid with a Quickpay gateway without an
     * order prefix
     */
    private function createActionFor(PaymentInterface $payment, PaymentReconcilerInterface $reconciler): NotifyAction
    {
        $order = new Order();
        $order->addPayment($payment);

        $orderRepository = $this->prophesize(OrderRepositoryInterface::class);
        $orderRepository->findOneByNumber('000001000')->willReturn($order);

        $gatewayConfigurationProvider = $this->prophesize(GatewayConfigurationProviderInterface::class);
        $gatewayConfigurationProvider->all()->willReturn([GatewayConfigurations::of()]);
        $gatewayConfigurationProvider->forPayment($payment)->willReturn(GatewayConfigurations::of());

        return new NotifyAction(
            $reconciler,
            $orderRepository->reveal(),
            new QuickpayPaymentIdProvider($gatewayConfigurationProvider->reveal()),
            $gatewayConfigurationProvider->reveal(),
        );
    }

    private static function payment(mixed $quickpayPaymentId): PaymentInterface
    {
        $payment = new Payment();
        $payment->setDetails(['quickpayPaymentId' => $quickpayPaymentId]);

        return $payment;
    }

    /**
     * @return OrderRepositoryInterface<\Sylius\Component\Core\Model\OrderInterface>
     */
    private function createOrderRepository(): OrderRepositoryInterface
    {
        $requestedOrderNumbers = &$this->requestedOrderNumbers;

        $orderRepository = $this->prophesize(OrderRepositoryInterface::class);
        $orderRepository
            ->findOneByNumber(Argument::type('string'))
            ->will(function (array $args) use (&$requestedOrderNumbers): mixed {
                $requestedOrderNumbers[] = $args[0];

                return null;
            })
        ;

        return $orderRepository->reveal();
    }

    /**
     * @param list<mixed> $prefixes
     */
    private function createGatewayConfigurationProvider(array $prefixes): GatewayConfigurationProviderInterface
    {
        $gatewayConfigurations = [];
        foreach ($prefixes as $i => $prefix) {
            $gatewayConfigurations[] = GatewayConfigurations::of(GatewayConfigurations::USABLE + ['order_prefix' => $prefix], gatewayName: 'quickpay_' . $i);
        }

        $provider = $this->prophesize(GatewayConfigurationProviderInterface::class);
        $provider->all()->willReturn($gatewayConfigurations);

        return $provider->reveal();
    }

    private function createRequest(string $orderId, int|string $quickpayPaymentId = 1): Request
    {
        $request = new Request(content: (string) json_encode(['id' => $quickpayPaymentId, 'order_id' => $orderId]));
        $request->headers->set('QuickPay-Resource-Type', 'Payment');

        return $request;
    }
}
