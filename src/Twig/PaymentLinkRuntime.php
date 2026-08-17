<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Twig;

use Setono\SyliusQuickpayPlugin\PaymentLink\PaymentLinkProviderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Twig\Extension\RuntimeExtensionInterface;

final class PaymentLinkRuntime implements RuntimeExtensionInterface
{
    public function __construct(private readonly PaymentLinkProviderInterface $paymentLinkProvider)
    {
    }

    public function paymentLink(PaymentInterface $payment): ?string
    {
        return $this->paymentLinkProvider->provide($payment);
    }
}
