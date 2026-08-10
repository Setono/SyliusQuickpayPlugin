<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Provider;

use Sylius\Component\Core\Model\PaymentInterface;

interface PendingPaymentProviderInterface
{
    /**
     * Returns Quickpay payments in a non-final state, created on or after the given boundary,
     * that carry a quickpayPaymentId to poll.
     *
     * @return list<PaymentInterface>
     */
    public function findPending(\DateTimeImmutable $createdSince, int $limit): array;
}
