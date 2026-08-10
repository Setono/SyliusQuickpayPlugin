<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Taxation;

use Sylius\Component\Core\Model\AdjustmentInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemInterface;

/**
 * Resolves the VAT rate Quickpay should be told about (a fraction, e.g. 0.25) from the tax
 * adjustments Sylius put on the order.
 */
final class VatRateResolver implements VatRateResolverInterface
{
    public function forOrderItem(OrderItemInterface $orderItem): float
    {
        foreach ($orderItem->getAdjustmentsRecursively(AdjustmentInterface::TAX_ADJUSTMENT) as $adjustment) {
            $rate = self::rateFromDetails($adjustment->getDetails());
            if (null !== $rate) {
                return $rate;
            }
        }

        // Adjustments created before Sylius stored the rate in the details carry no taxRateAmount;
        // derive the rate from the totals instead. The item total includes the tax in both the
        // included-in-price and added-on-top cases.
        return self::rateFromTotals($orderItem->getTaxTotal(), $orderItem->getTotal());
    }

    public function forShipping(OrderInterface $order): float
    {
        foreach ($order->getShipments() as $shipment) {
            foreach ($shipment->getAdjustments(AdjustmentInterface::TAX_ADJUSTMENT) as $adjustment) {
                $rate = self::rateFromDetails($adjustment->getDetails());
                if (null !== $rate) {
                    return $rate;
                }
            }
        }

        return 0.0;
    }

    /**
     * @param array<array-key, mixed> $details
     */
    private static function rateFromDetails(array $details): ?float
    {
        $rate = $details['taxRateAmount'] ?? null;

        return is_numeric($rate) ? (float) $rate : null;
    }

    private static function rateFromTotals(int $taxTotal, int $total): float
    {
        if ($taxTotal <= 0 || $total <= $taxTotal) {
            return 0.0;
        }

        return round($taxTotal / ($total - $taxTotal), 4);
    }
}
