<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Fraud;

use Sylius\Component\Core\Model\PaymentInterface;

interface FraudCheckerInterface
{
    /**
     * Whether Quickpay reports the payment as fraud suspected. Answers false whenever the question
     * cannot be answered — no Quickpay payment, no api key, Quickpay unreachable — so a broken check
     * never blocks the payment flow.
     */
    public function isFraudSuspected(PaymentInterface $payment): bool;
}
