<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\OrderId;

/**
 * The store's latest order number, the one a Quickpay order id (see GLOSSARY.md) is checked with before checkout:
 * payum-quickpay's `OrderId::of()` says whether an order prefix fits the store's order numbers, which only the
 * store knows, since a store may number its orders its own way
 */
interface LatestOrderNumberProviderInterface
{
    /**
     * What the check uses when the store has no order yet: Sylius' default first order number
     */
    public const STAND_IN_ORDER_NUMBER = '000000001';

    /**
     * The number of the newest order that has one (a cart has none), or null when there is none
     */
    public function getLatestOrderNumber(): ?string;
}
