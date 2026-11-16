<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\OrderDescription;

use Setono\Payum\Quickpay\Model\OrderDescription;
use Sylius\Component\Core\Model\OrderInterface;

/**
 * Describes a Sylius order to Quickpay: what payum-quickpay sends about it (the invoice and shipping addresses, the
 * basket, shipping and the shop system) when it creates the order's Quickpay payment. Decorate it to change what
 * Quickpay is told.
 *
 * What the order lacks is left out, since Quickpay takes every address field as optional: without a customer's email
 * the addresses have none, without a billing or shipping address there is no invoice or shipping address, and an address
 * in a country without an ISO 3166-1 alpha-3 code has no country. An order Sylius would never produce, an item without a
 * variant or an address without a street or country, fails.
 */
interface OrderDescriberInterface
{
    public function describe(OrderInterface $order): OrderDescription;
}
