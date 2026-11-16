<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Fraud;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Setono\SyliusQuickpayPlugin\Quickpay\QuickpayClientProviderInterface;
use Sylius\Component\Core\Model\PaymentInterface;

final class FraudChecker implements FraudCheckerInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    public function __construct(private readonly QuickpayClientProviderInterface $quickpayClientProvider)
    {
    }

    public function isFraudSuspected(PaymentInterface $payment): bool
    {
        try {
            $quickpayPayment = $this->quickpayClientProvider->quickpayPaymentOf($payment);
        } catch (\Throwable $e) {
            // An unusable gateway configuration, and a present but unusable id, land in the same fail-open catch as
            // an unreachable Quickpay: the question cannot be answered, and a configuration problem never blocks a
            // capture
            $this->logger?->warning(sprintf('Could not check the Quickpay payment for suspected fraud: %s', $e->getMessage()), [
                'quickpayPaymentId' => $payment->getDetails()['quickpayPaymentId'] ?? null,
                'paymentId' => $payment->getId(),
            ]);

            return false;
        }

        return true === $quickpayPayment?->metadata?->fraudSuspected;
    }
}
