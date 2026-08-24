<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Quickpay;

use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;

/**
 * Reads the Quickpay api key off a gateway configuration (or the configuration of a payment's own
 * method), answering null when no usable key is stored. The one place that knows configurations
 * written by the 1.x form may still carry the old `apikey` name.
 */
final class ApiKeyResolver
{
    private function __construct()
    {
    }

    /**
     * @param array<array-key, mixed> $config
     */
    public static function fromGatewayConfig(array $config): ?string
    {
        $apiKey = $config['api_key'] ?? $config['apikey'] ?? null;

        return is_string($apiKey) && '' !== $apiKey ? $apiKey : null;
    }

    public static function fromPayment(PaymentInterface $payment): ?string
    {
        $method = $payment->getMethod();
        if (!$method instanceof PaymentMethodInterface) {
            return null;
        }

        return self::fromGatewayConfig($method->getGatewayConfig()?->getConfig() ?? []);
    }
}
