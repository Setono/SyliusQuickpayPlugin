<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Checkout;

use Sylius\Component\Core\Model\PaymentMethodInterface;

interface PaymentMethodLogoProviderInterface
{
    /**
     * The logos to show for a payment method, derived from its Quickpay gateway configuration's
     * `payment_methods` option. Empty for non-Quickpay methods, for an unset/empty option and for an
     * unusable gateway configuration.
     *
     * @return list<PaymentMethodLogo>
     */
    public function provide(PaymentMethodInterface $paymentMethod): array;
}
