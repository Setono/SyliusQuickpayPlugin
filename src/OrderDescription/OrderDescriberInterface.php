<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\OrderDescription;

use Setono\Payum\Quickpay\Model\OrderDescription;
use Sylius\Component\Core\Model\OrderInterface;

/**
 * Describes a Sylius order to Quickpay: what payum-quickpay sends about it (the invoice and shipping addresses, the
 * basket, shipping and the shopsystem) when it creates the order's Quickpay payment. Decorate it to change what
 * Quickpay is told.
 */
interface OrderDescriberInterface
{
    public function describe(OrderInterface $order): OrderDescription;
}
