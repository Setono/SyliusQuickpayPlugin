<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Setono\SyliusQuickpayPlugin\Controller\NotifyAction;
use Setono\SyliusQuickpayPlugin\GatewayConfiguration\GatewayConfigurationProviderInterface;
use Setono\SyliusQuickpayPlugin\Provider\PaymentProvider;
use Setono\SyliusQuickpayPlugin\Reconciliation\PaymentReconcilerInterface;
use Setono\SyliusQuickpayPlugin\Tests\GatewayConfiguration\GatewayConfigurations;
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
        $action = new NotifyAction(
            $this->prophesize(PaymentReconcilerInterface::class)->reveal(),
            $this->createOrderRepository(),
            new PaymentProvider(),
            $this->createGatewayConfigurationProvider($configuredPrefixes),
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

    private function createRequest(string $orderId): Request
    {
        $request = new Request(content: (string) json_encode(['id' => 1, 'order_id' => $orderId]));
        $request->headers->set('QuickPay-Resource-Type', 'Payment');

        return $request;
    }
}
