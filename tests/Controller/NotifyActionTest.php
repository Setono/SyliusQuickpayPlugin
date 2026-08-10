<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Tests\Controller;

use Payum\Core\Payum;
use PHPUnit\Framework\TestCase;
use Setono\SyliusQuickpayPlugin\Controller\NotifyAction;
use Setono\SyliusQuickpayPlugin\Provider\QuickpayPaymentProvider;
use Sylius\Component\Core\Repository\OrderRepositoryInterface;
use Symfony\Component\HttpFoundation\Request;

final class NotifyActionTest extends TestCase
{
    /**
     * @test
     *
     * @dataProvider orderIdProvider
     */
    public function it_resolves_the_order_number_from_the_order_id(string $orderPrefix, string $orderId, string $expectedOrderNumber): void
    {
        $orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $orderRepository
            ->expects(self::once())
            ->method('findOneByNumber')
            ->with($expectedOrderNumber)
            ->willReturn(null)
        ;

        $action = new NotifyAction($this->createMock(Payum::class), $orderRepository, new QuickpayPaymentProvider(), $orderPrefix);

        $response = $action($this->createRequest($orderId));

        self::assertSame(204, $response->getStatusCode());
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function orderIdProvider(): iterable
    {
        yield 'prefix is stripped' => ['qp_', 'qp_000000123', '000000123'];

        yield 'empty prefix leaves the order id untouched' => ['', '000000123', '000000123'];

        yield 'order id without the prefix is left untouched' => ['qp_', '000000123', '000000123'];

        yield 'only the leading prefix occurrence is stripped' => ['1', '100121', '00121'];
    }

    private function createRequest(string $orderId): Request
    {
        $request = new Request(content: (string) json_encode(['id' => 1, 'order_id' => $orderId]));
        $request->headers->set('QuickPay-Resource-Type', 'Payment');

        return $request;
    }
}
