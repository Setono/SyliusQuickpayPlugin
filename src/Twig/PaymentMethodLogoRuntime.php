<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Twig;

use Setono\SyliusQuickpayPlugin\Checkout\PaymentMethodLogo;
use Setono\SyliusQuickpayPlugin\Checkout\PaymentMethodLogoProviderInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Twig\Extension\RuntimeExtensionInterface;

final class PaymentMethodLogoRuntime implements RuntimeExtensionInterface
{
    public function __construct(private readonly PaymentMethodLogoProviderInterface $paymentMethodLogoProvider)
    {
    }

    /**
     * @return list<PaymentMethodLogo>
     */
    public function logos(PaymentMethodInterface $paymentMethod): array
    {
        return $this->paymentMethodLogoProvider->provide($paymentMethod);
    }
}
