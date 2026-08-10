<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Tests\Controller;

use Payum\Core\Payum;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Setono\SyliusQuickpayPlugin\Controller\NotifyAction;
use Setono\SyliusQuickpayPlugin\Provider\PaymentProvider;
use Sylius\Bundle\PayumBundle\Model\GatewayConfigInterface;
use Sylius\Component\Core\Repository\OrderRepositoryInterface;
use Sylius\Component\Resource\Repository\RepositoryInterface;
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
        $repository->findBy(['factoryName' => 'quickpay'])->willReturn($gatewayConfigs);

        return $repository->reveal();
    }

    private function createRequest(string $orderId): Request
    {
        $request = new Request(content: (string) json_encode(['id' => 1, 'order_id' => $orderId]));
        $request->headers->set('QuickPay-Resource-Type', 'Payment');

        return $request;
    }
}
