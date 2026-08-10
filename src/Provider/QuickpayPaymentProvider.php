<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Provider;

use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Payment\Model\PaymentInterface;

final class QuickpayPaymentProvider implements QuickpayPaymentProviderInterface
{
    public function findByQuickpayPaymentId(OrderInterface $order, int $quickpayPaymentId): ?PaymentInterface
    {
        $payment = $order
            ->getPayments()
            ->filter(
                static function (PaymentInterface $payment) use ($quickpayPaymentId): bool {
                    $id = $payment->getDetails()['quickpayPaymentId'] ?? null;

                    return is_numeric($id) && (int) $id === $quickpayPaymentId;
                },
            )
            ->last()
        ;

        return false === $payment ? null : $payment;
    }
}
