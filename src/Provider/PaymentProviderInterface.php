<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Provider;

use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Payment\Model\PaymentInterface;

interface PaymentProviderInterface
{
    /**
     * Returns the payment on the order that belongs to the given Quickpay payment id, if any
     */
    public function findByQuickpayPaymentId(OrderInterface $order, int $quickpayPaymentId): ?PaymentInterface;
}
