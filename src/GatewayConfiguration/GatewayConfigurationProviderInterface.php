<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\GatewayConfiguration;

use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Model\PaymentInterface;

/**
 * The stored Quickpay gateway configurations (see GLOSSARY.md): the one way the plugin finds them. Listing and
 * looking up never throw for an unusable configuration; reading one does ({@see GatewayConfiguration}), so each
 * reader decides how to fail.
 */
interface GatewayConfigurationProviderInterface
{
    /**
     * Every stored Quickpay gateway configuration
     *
     * @return list<GatewayConfiguration>
     */
    public function all(): array;

    /**
     * The gateway configuration of the named gateway, or null when no gateway of that name is stored or it
     * isn't a Quickpay one
     */
    public function forGatewayName(string $gatewayName): ?GatewayConfiguration;

    /**
     * The payment method's gateway configuration, or null when it has none or it isn't a Quickpay one
     */
    public function forPaymentMethod(PaymentMethodInterface $paymentMethod): ?GatewayConfiguration;

    /**
     * The gateway configuration of the payment's payment method, or null when it has no payment method, or
     * the method's gateway configuration is missing or isn't a Quickpay one
     */
    public function forPayment(PaymentInterface $payment): ?GatewayConfiguration;
}
