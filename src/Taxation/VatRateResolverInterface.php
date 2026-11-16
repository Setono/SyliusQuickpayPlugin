<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Taxation;

use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Order\Model\AdjustmentInterface;

/**
 * Resolves the VAT rate Quickpay should be told about (a fraction, e.g. 0.25)
 */
interface VatRateResolverInterface
{
    public function forOrderItem(OrderItemInterface $orderItem): float;

    public function forShipping(OrderInterface $order): float;

    /**
     * The rate of a basket line describing an adjustment outside the units and shipping, such as a plugin's payment fee
     * or gift card on the order
     */
    public function forAdjustment(AdjustmentInterface $adjustment): float;
}
