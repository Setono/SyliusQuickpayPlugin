<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Quickpay;

use Payum\Core\Exception\LogicException;
use Setono\Payum\Quickpay\Details;
use Setono\SyliusQuickpayPlugin\GatewayConfiguration\GatewayConfigurationProviderInterface;
use Sylius\Component\Payment\Model\PaymentInterface;

final class QuickpayPaymentIdProvider implements QuickpayPaymentIdProviderInterface
{
    public function __construct(private readonly GatewayConfigurationProviderInterface $gatewayConfigurationProvider)
    {
    }

    public function has(PaymentInterface $payment): bool
    {
        return Details::hasPaymentId(new \ArrayObject($payment->getDetails())) &&
            null !== $this->gatewayConfigurationProvider->forPayment($payment);
    }

    public function idOf(PaymentInterface $payment): int
    {
        $details = new \ArrayObject($payment->getDetails());

        if (null === $this->gatewayConfigurationProvider->forPayment($payment)) {
            throw new LogicException(sprintf(
                'The Sylius payment %s has no Quickpay payment: its payment method has no Quickpay gateway configuration.',
                self::describe($payment),
            ));
        }

        if (!Details::hasPaymentId($details)) {
            throw new LogicException(sprintf(
                'The Sylius payment %s has no Quickpay payment yet: its details carry no Quickpay payment id.',
                self::describe($payment),
            ));
        }

        return Details::paymentId($details);
    }

    public function tryIdOf(PaymentInterface $payment): ?int
    {
        if (!$this->has($payment)) {
            return null;
        }

        try {
            return Details::paymentId(new \ArrayObject($payment->getDetails()));
        } catch (LogicException) {
            return null;
        }
    }

    private static function describe(PaymentInterface $payment): string
    {
        $id = $payment->getId();

        return is_scalar($id) ? (string) $id : '(not saved yet)';
    }
}
