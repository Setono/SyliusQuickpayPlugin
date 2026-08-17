<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\PaymentLink;

use Sylius\Component\Core\Model\PaymentInterface;

interface PaymentLinkProviderInterface
{
    /**
     * The absolute url a customer can open to pay a Quickpay payment that is still awaiting
     * payment, or null when the payment cannot be paid that way (it is not a Quickpay payment,
     * it is not the order's payment awaiting payment, the order is cancelled, …).
     */
    public function provide(PaymentInterface $payment): ?string;
}
