<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Quickpay;

use Payum\Core\Exception\LogicException;
use Sylius\Component\Payment\Model\PaymentInterface;

/**
 * A Sylius payment's link to its Quickpay payment, its Quickpay payment id (see GLOSSARY.md and ADR 0006): the one
 * way the plugin reads it. A Sylius payment has one only while its payment method has a Quickpay gateway
 * configuration, whatever its details hold, since Sylius keeps the details when the customer switches methods. The id
 * itself is read by payum-quickpay's `Details` rule.
 */
interface QuickpayPaymentIdProviderInterface
{
    /**
     * Whether the payment has a Quickpay payment: its method has a Quickpay gateway configuration and its details carry
     * a Quickpay payment id (payum-quickpay's `Details::hasPaymentId()`: present and not null), readable or not. Never
     * throws
     */
    public function has(PaymentInterface $payment): bool;

    /**
     * The payment's Quickpay payment id, for whoever needs it to act: a positive int, or a digit string read as one
     * (payum-quickpay's `Details::paymentId()`)
     *
     * @throws LogicException when the payment has no Quickpay payment ({@see has()}) or its id can't be read
     */
    public function idOf(PaymentInterface $payment): int;

    /**
     * The payment's Quickpay payment id, or null when it has none or its id can't be read: what matching payments by
     * Quickpay payment id uses, so an unreadable id never matches and never throws
     */
    public function tryIdOf(PaymentInterface $payment): ?int;
}
