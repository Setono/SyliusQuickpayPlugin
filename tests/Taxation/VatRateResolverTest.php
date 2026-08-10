<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Tests\Taxation;

use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;
use Setono\SyliusQuickpayPlugin\Taxation\VatRateResolver;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Core\Model\ShipmentInterface;
use Sylius\Component\Order\Model\AdjustmentInterface;

final class VatRateResolverTest extends TestCase
{
    use ProphecyTrait;

    /**
     * @test
     */
    public function it_resolves_the_rate_from_the_tax_adjustment_details(): void
    {
        $orderItem = $this->createOrderItem([$this->createAdjustment(['taxRateAmount' => 0.25])]);

        self::assertSame(0.25, (new VatRateResolver())->forOrderItem($orderItem));
    }

    /**
     * @test
     */
    public function it_resolves_zero_for_an_item_without_tax_adjustments(): void
    {
        $orderItem = $this->createOrderItem([], taxTotal: 0, total: 1000);

        self::assertSame(0.0, (new VatRateResolver())->forOrderItem($orderItem));
    }

    /**
     * @test
     */
    public function it_derives_the_rate_from_the_totals_when_the_details_carry_no_rate(): void
    {
        $orderItem = $this->createOrderItem([$this->createAdjustment([])], taxTotal: 250, total: 1250);

        self::assertSame(0.25, (new VatRateResolver())->forOrderItem($orderItem));
    }

    /**
     * @test
     */
    public function it_resolves_the_shipping_rate_from_the_shipment_tax_adjustment(): void
    {
        $shipment = $this->prophesize(ShipmentInterface::class);
        $shipment->getAdjustments('tax')->willReturn(new ArrayCollection([$this->createAdjustment(['taxRateAmount' => 0.19])]));

        $order = $this->prophesize(OrderInterface::class);
        $order->getShipments()->willReturn(new ArrayCollection([$shipment->reveal()]));

        self::assertSame(0.19, (new VatRateResolver())->forShipping($order->reveal()));
    }

    /**
     * @test
     */
    public function it_resolves_zero_shipping_rate_without_shipments(): void
    {
        $order = $this->prophesize(OrderInterface::class);
        $order->getShipments()->willReturn(new ArrayCollection());

        self::assertSame(0.0, (new VatRateResolver())->forShipping($order->reveal()));
    }

    /**
     * @param array<string, mixed> $details
     */
    private function createAdjustment(array $details): AdjustmentInterface
    {
        $adjustment = $this->prophesize(AdjustmentInterface::class);
        $adjustment->getDetails()->willReturn($details);

        return $adjustment->reveal();
    }

    /**
     * @param list<AdjustmentInterface> $adjustments
     */
    private function createOrderItem(array $adjustments, int $taxTotal = 0, int $total = 0): OrderItemInterface
    {
        $orderItem = $this->prophesize(OrderItemInterface::class);
        $orderItem->getAdjustmentsRecursively('tax')->willReturn(new ArrayCollection($adjustments));
        $orderItem->getTaxTotal()->willReturn($taxTotal);
        $orderItem->getTotal()->willReturn($total);

        return $orderItem->reveal();
    }
}
