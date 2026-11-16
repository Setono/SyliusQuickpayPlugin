<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Twig;

use Setono\SyliusQuickpayPlugin\Checkout\PaymentMethodLogo;
use Setono\SyliusQuickpayPlugin\Checkout\PaymentMethodLogoProviderInterface;
use Setono\SyliusQuickpayPlugin\PaymentLink\PaymentLinkProviderInterface;
use Setono\SyliusQuickpayPlugin\Quickpay\QuickpayPaymentIdProviderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Twig\Extension\RuntimeExtensionInterface;

final class PaymentRuntime implements RuntimeExtensionInterface
{
    public function __construct(
        private readonly PaymentMethodLogoProviderInterface $paymentMethodLogoProvider,
        private readonly PaymentLinkProviderInterface $paymentLinkProvider,
        private readonly QuickpayPaymentIdProviderInterface $quickpayPaymentIdProvider,
    ) {
    }

    /**
     * The brands a Quickpay payment method's payment window offers, for the checkout
     *
     * @return list<PaymentMethodLogo>
     */
    public function paymentMethodLogos(PaymentMethodInterface $paymentMethod): array
    {
        return $this->paymentMethodLogoProvider->provide($paymentMethod);
    }

    /**
     * The url that pays a Quickpay payment still awaiting payment, or null when there is none
     */
    public function paymentLink(PaymentInterface $payment): ?string
    {
        return $this->paymentLinkProvider->provide($payment);
    }

    /**
     * Whether the payment has a Quickpay payment, so its operation history can be read from Quickpay: its method has
     * a Quickpay gateway configuration and its details carry a Quickpay payment id
     */
    public function hasQuickpayPayment(PaymentInterface $payment): bool
    {
        return $this->quickpayPaymentIdProvider->has($payment);
    }
}
