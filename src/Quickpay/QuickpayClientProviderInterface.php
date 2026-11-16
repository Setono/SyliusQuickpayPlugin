<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Quickpay;

use Payum\Core\Exception\LogicException;
use Setono\Quickpay\Client\ClientInterface;
use Setono\Quickpay\Exception\QuickpayException;
use Setono\Quickpay\Response\Payment\Payment;
use Setono\SyliusQuickpayPlugin\GatewayConfiguration\GatewayConfiguration;
use Setono\SyliusQuickpayPlugin\GatewayConfiguration\UnusableGatewayConfiguration;
use Sylius\Component\Payment\Model\PaymentInterface;

/**
 * The plugin's one road to Quickpay (ADR 0003): every SDK client, the Payum gateway's included, is built here, on the
 * scoped HTTP client `setono_sylius_quickpay.http_client` and so with its one HTTP policy
 */
interface QuickpayClientProviderInterface
{
    /**
     * A new SDK client for the gateway configuration: its api key and `synchronized` gateway option, read by
     * payum-quickpay's rules (ADR 0002)
     *
     * @throws UnusableGatewayConfiguration
     */
    public function forGatewayConfiguration(GatewayConfiguration $gatewayConfiguration): ClientInterface;

    /**
     * The Quickpay payment of the Sylius payment, read from Quickpay with a client for the payment's gateway
     * configuration. Null when the Sylius payment has no Quickpay payment: its method has no Quickpay gateway configuration
     * (the gateway configuration module's `forPayment()`), or its details carry no Quickpay payment id (payum-quickpay's
     * `Details`). Each caller applies its own error policy to what it throws.
     *
     * @throws UnusableGatewayConfiguration for the payment's gateway configuration
     * @throws LogicException when the details carry a Quickpay payment id payum-quickpay can't read
     * @throws QuickpayException when Quickpay doesn't answer with the payment
     */
    public function quickpayPaymentOf(PaymentInterface $payment): ?Payment;
}
