<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Taxation;

use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemInterface;

/**
 * Resolves the VAT rate Quickpay should be told about (a fraction, e.g. 0.25)
 */
interface VatRateResolverInterface
{
    public function forOrderItem(OrderItemInterface $orderItem): float;

    public function forShipping(OrderInterface $order): float;
}
