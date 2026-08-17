<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class PaymentMethodLogoExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('setono_sylius_quickpay_payment_method_logos', [PaymentMethodLogoRuntime::class, 'logos']),
        ];
    }
}
