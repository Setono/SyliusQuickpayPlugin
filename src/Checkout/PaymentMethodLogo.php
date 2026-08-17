<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Checkout;

/**
 * One payment method the Quickpay payment window offers, ready to be shown on the checkout: the
 * normalized Quickpay token, a human label, and — when one is bundled or configured — an image
 * path suitable for Twig's `asset()`. A null image means the label is rendered as text.
 */
final class PaymentMethodLogo
{
    public function __construct(
        public readonly string $token,
        public readonly string $label,
        public readonly ?string $image,
    ) {
    }
}
