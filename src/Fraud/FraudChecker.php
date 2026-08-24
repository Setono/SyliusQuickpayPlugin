<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Fraud;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Setono\Payum\Quickpay\Details;
use Setono\SyliusQuickpayPlugin\Quickpay\ApiKeyResolver;
use Setono\SyliusQuickpayPlugin\Quickpay\ClientFactoryInterface;
use Sylius\Component\Core\Model\PaymentInterface;

final class FraudChecker implements FraudCheckerInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    public function __construct(private readonly ClientFactoryInterface $clientFactory)
    {
    }

    public function isFraudSuspected(PaymentInterface $payment): bool
    {
        $details = new \ArrayObject($payment->getDetails());
        if (!Details::hasPaymentId($details)) {
            return false;
        }

        $apiKey = ApiKeyResolver::fromPayment($payment);
        if (null === $apiKey) {
            return false;
        }

        try {
            // Details::paymentId() throws for a present but unusable id, which lands in the same
            // fail-open catch as an unreachable Quickpay: the question cannot be answered
            $quickpayPaymentId = Details::paymentId($details);

            $quickpayPayment = $this->clientFactory->create($apiKey)->payments()->getById($quickpayPaymentId);
        } catch (\Throwable $e) {
            $this->logger?->warning(sprintf('Could not check the Quickpay payment for suspected fraud: %s', $e->getMessage()), [
                'quickpayPaymentId' => $details['quickpayPaymentId'] ?? null,
                'paymentId' => $payment->getId(),
            ]);

            return false;
        }

        return true === $quickpayPayment->metadata?->fraudSuspected;
    }
}
