<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * The plugin's Twig functions; the work happens lazily in {@see PaymentRuntime}.
 */
final class PaymentExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('setono_sylius_quickpay_payment_method_logos', [PaymentRuntime::class, 'paymentMethodLogos']),
            new TwigFunction('setono_sylius_quickpay_payment_link', [PaymentRuntime::class, 'paymentLink']),
        ];
    }
}
