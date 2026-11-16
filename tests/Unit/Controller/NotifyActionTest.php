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
        $reconciler->reconcileFromCallback($payment)->willReturn(ReconcileResult::skipped('new'))->shouldBeCalledOnce();

        $response = $this->createActionFor($payment, $reconciler->reveal())($this->createRequest('000001000', quickpayPaymentId: 1000));

        self::assertSame(204, $response->getStatusCode());
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

    private function createRequest(string $orderId, int $quickpayPaymentId = 1): Request
    {
        $request = new Request(content: (string) json_encode(['id' => $quickpayPaymentId, 'order_id' => $orderId]));
        $request->headers->set('QuickPay-Resource-Type', 'Payment');

        return $request;
    }
}
