<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Fraud;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Setono\SyliusQuickpayPlugin\Quickpay\ClientFactoryInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;

final class FraudChecker implements FraudCheckerInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    public function __construct(private readonly ClientFactoryInterface $clientFactory)
    {
    }

    public function isFraudSuspected(PaymentInterface $payment): bool
    {
        $quickpayPaymentId = $payment->getDetails()['quickpayPaymentId'] ?? null;
        if (!is_numeric($quickpayPaymentId)) {
            return false;
        }

        $apiKey = self::resolveApiKey($payment);
        if (null === $apiKey) {
            return false;
        }

        try {
            $quickpayPayment = $this->clientFactory->create($apiKey)->payments()->getById((int) $quickpayPaymentId);
        } catch (\Throwable $e) {
            // An unreachable Quickpay must not block the payment flow: report the payment as clean
            $this->logger?->warning(sprintf('Could not check the Quickpay payment for suspected fraud: %s', $e->getMessage()), [
                'quickpayPaymentId' => (int) $quickpayPaymentId,
                'paymentId' => $payment->getId(),
            ]);

            return false;
        }

        return true === $quickpayPayment->metadata?->fraudSuspected;
    }

    private static function resolveApiKey(PaymentInterface $payment): ?string
    {
        $method = $payment->getMethod();
        if (!$method instanceof PaymentMethodInterface) {
            return null;
        }

        $config = $method->getGatewayConfig()?->getConfig() ?? [];

        // Configurations written by the 1.x form may still carry the old key
        $apiKey = $config['api_key'] ?? $config['apikey'] ?? null;

        return is_string($apiKey) && '' !== $apiKey ? $apiKey : null;
    }
}
