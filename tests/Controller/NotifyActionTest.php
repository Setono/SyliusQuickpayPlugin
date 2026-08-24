<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Tests\Controller;

use Payum\Core\Gateway;
use Payum\Core\Payum;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Setono\Payum\Quickpay\QuickpayGatewayFactory;
use Setono\SyliusQuickpayPlugin\Controller\NotifyAction;
use Setono\SyliusQuickpayPlugin\Payum\Extension\NotifyIdempotencyExtension;
use Setono\SyliusQuickpayPlugin\Provider\PaymentProvider;
use Sylius\Bundle\PayumBundle\Action\ExecuteSameRequestWithPaymentDetailsAction;
use Sylius\Bundle\PayumBundle\Model\GatewayConfigInterface;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\Payment;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Core\Repository\OrderRepositoryInterface;
use Sylius\Component\Resource\Repository\RepositoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

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
     * @param list<string> $configuredPrefixes
     * @param list<string> $expectedCandidates
     */
    public function it_resolves_the_order_number_from_the_configured_gateway_prefixes(
        array $configuredPrefixes,
        string $orderId,
        array $expectedCandidates,
    ): void {
        $action = new NotifyAction(
            $this->prophesize(Payum::class)->reveal(),
            $this->createOrderRepository(),
            new PaymentProvider(),
            $this->createGatewayConfigRepository($configuredPrefixes),
        );

        $response = $action($this->createRequest($orderId));

        self::assertSame(204, $response->getStatusCode());
        self::assertSame($expectedCandidates, $this->requestedOrderNumbers);
    }

    /**
     * @return iterable<string, array{list<string>, string, list<string>}>
     */
    public static function orderIdProvider(): iterable
    {
        yield 'configured prefix is stripped' => [['qp_'], 'qp_000000123', ['000000123', 'qp_000000123']];

        yield 'no configured gateways leaves the order id untouched' => [[], '000000123', ['000000123']];

        yield 'non-matching prefix leaves the order id untouched' => [['other_'], '000000123', ['000000123']];

        yield 'the matching prefix among several gateways is used' => [['qp1_', 'qp2_'], 'qp2_000000123', ['000000123', 'qp2_000000123']];

        yield 'a prefix that also appears inside the order id is only stripped from the start' => [['1'], '100121', ['00121', '100121']];
    }

    /**
     * @test
     */
    public function it_acknowledges_a_simultaneous_callback_for_the_same_payment_without_processing_it(): void
    {
        [$action, $handler] = $this->createNotifyHarness();

        $duplicateResponse = null;
        $handler->onFirstExecute = function () use ($action, &$duplicateResponse): void {
            // a retry of the same callback, delivered while the first one is still being processed
            $duplicateResponse = $action($this->createRequest('000000123', 123));
        };

        $response = $action($this->createRequest('000000123', 123));

        self::assertSame(204, $response->getStatusCode());
        self::assertNotNull($duplicateResponse);
        self::assertSame(204, $duplicateResponse->getStatusCode(), 'the duplicate must be acknowledged with a 2xx so Quickpay stops retrying');
        self::assertSame(1, $handler->processed, 'two simultaneous callbacks for the same payment must result in exactly one processing');
    }

    /**
     * @test
     */
    public function it_processes_callbacks_arriving_sequentially_for_the_same_payment(): void
    {
        [$action, $handler] = $this->createNotifyHarness();

        self::assertSame(204, $action($this->createRequest('000000123', 123))->getStatusCode());
        self::assertSame(204, $action($this->createRequest('000000123', 123))->getStatusCode());

        self::assertSame(2, $handler->processed, 'the lock must be released once a callback has been processed');
    }

    /**
     * The full notify funnel with a real Payum gateway: the idempotency extension plus Sylius'
     * ExecuteSameRequestWithPaymentDetailsAction (which rewraps Notify(payment) as Notify(details)),
     * ending in a spy that stands in for the gateway library's NotifyAction
     *
     * @return array{NotifyAction, NotifyHandlerSpy}
     */
    private function createNotifyHarness(): array
    {
        $payment = new Payment();
        $payment->setDetails(['quickpayPaymentId' => 123]);

        $gatewayConfig = $this->prophesize(GatewayConfigInterface::class);
        $gatewayConfig->getGatewayName()->willReturn('quickpay');

        $method = $this->prophesize(PaymentMethodInterface::class);
        $method->getGatewayConfig()->willReturn($gatewayConfig->reveal());
        $payment->setMethod($method->reveal());

        $order = new Order();
        $order->addPayment($payment);

        $orderRepository = $this->prophesize(OrderRepositoryInterface::class);
        $orderRepository->findOneByNumber('000000123')->willReturn($order);

        $handler = new NotifyHandlerSpy();

        $gateway = new Gateway();
        $gateway->addExtension(new NotifyIdempotencyExtension(new LockFactory(new InMemoryStore())));
        $gateway->addAction(new ExecuteSameRequestWithPaymentDetailsAction());
        $gateway->addAction($handler);

        $payum = $this->prophesize(Payum::class);
        $payum->getGateway('quickpay')->willReturn($gateway);

        $action = new NotifyAction(
            $payum->reveal(),
            $orderRepository->reveal(),
            new PaymentProvider(),
            $this->createGatewayConfigRepository([]),
        );

        return [$action, $handler];
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
     * @param list<string> $prefixes
     *
     * @return RepositoryInterface<GatewayConfigInterface>
     */
    private function createGatewayConfigRepository(array $prefixes): RepositoryInterface
    {
        $gatewayConfigs = [];
        foreach ($prefixes as $prefix) {
            $gatewayConfig = $this->prophesize(GatewayConfigInterface::class);
            $gatewayConfig->getConfig()->willReturn(['order_prefix' => $prefix]);
            $gatewayConfigs[] = $gatewayConfig->reveal();
        }

        $repository = $this->prophesize(RepositoryInterface::class);
        $repository->findBy(['factoryName' => QuickpayGatewayFactory::NAME])->willReturn($gatewayConfigs);

        return $repository->reveal();
    }

    private function createRequest(string $orderId, int $quickpayPaymentId = 1): Request
    {
        $request = new Request(content: (string) json_encode(['id' => $quickpayPaymentId, 'order_id' => $orderId]));
        $request->headers->set('QuickPay-Resource-Type', 'Payment');

        return $request;
    }
}
