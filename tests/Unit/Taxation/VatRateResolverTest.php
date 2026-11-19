<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Tests\Unit\Taxation;

use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;
use Setono\SyliusQuickpayPlugin\Taxation\VatRateResolver;
use Sylius\Component\Core\Model\Adjustment;
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
     * The totals are rounded minor units, so the rate derived from them only approximates the configured one: an item
     * of 33 with 25 % VAT included carries a tax of 7, which gives 7 / 26
     *
     * @test
     */
    public function it_derives_the_rate_from_the_totals_to_four_decimals(): void
    {
        $orderItem = $this->createOrderItem([$this->createAdjustment([])], taxTotal: 7, total: 33);

        self::assertSame(0.2692, (new VatRateResolver())->forOrderItem($orderItem));
    }

    /**
     * Totals without tax, or whose tax isn't below the total (Sylius clamps a negative item total at 0), hold no rate
     *
     * @test
     *
     * @dataProvider totalsWithoutARate
     */
    public function it_derives_no_rate_from_totals_that_hold_none(int $taxTotal, int $total): void
    {
        $orderItem = $this->createOrderItem([$this->createAdjustment([])], taxTotal: $taxTotal, total: $total);

        self::assertSame(0.0, (new VatRateResolver())->forOrderItem($orderItem));
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function totalsWithoutARate(): iterable
    {
        yield 'no tax' => [0, 1_000];
        yield 'a total that is all tax' => [250, 250];
        yield 'a total clamped at 0' => [250, 0];
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
     * @test
     *
     * @dataProvider adjustmentDetails
     *
     * @param array<string, mixed> $details
     */
    public function it_resolves_an_adjustments_rate_from_its_details_else_zero(array $details, float $expectedRate): void
    {
        $adjustment = new Adjustment();
        $adjustment->setType('payment_fee');
        $adjustment->setAmount(2_500);
        $adjustment->setDetails($details);

        self::assertSame($expectedRate, (new VatRateResolver())->forAdjustment($adjustment));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, float}>
     */
    public static function adjustmentDetails(): iterable
    {
        yield 'a rate' => [['taxRateAmount' => 0.25], 0.25];
        yield 'a rate stored as a string' => [['taxRateAmount' => '0.255'], 0.255];
        yield 'no rate' => [[], 0.0];
        yield 'a rate that is not a number' => [['taxRateAmount' => 'standard'], 0.0];
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
