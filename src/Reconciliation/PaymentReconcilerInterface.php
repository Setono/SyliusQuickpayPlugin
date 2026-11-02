<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Reconciliation;

use Payum\Core\Exception\ExceptionInterface;
use Setono\Quickpay\Exception\QuickpayException;
use Sylius\Component\Core\Model\PaymentInterface;

/**
 * Brings a Sylius payment in line with its Quickpay payment (see GLOSSARY.md and docs/adr/0001-*.md).
 * Sylius' payment-state extension is the only thing that applies payment transitions, so this never
 * applies one itself.
 */
interface PaymentReconcilerInterface
{
    /**
     * The status check: reads the Quickpay payment's status and the transition Sylius would apply,
     * without changing the Sylius payment in any way. No state change, no state-machine callbacks and
     * nothing persisted, not even the stored details.
     *
     * When the status cannot be read, the error propagates: the SDK's {@see QuickpayException} (e.g.
     * Quickpay unreachable) or Payum's {@see ExceptionInterface} (e.g. a payment without a gateway).
     *
     * @throws QuickpayException
     */
    public function checkStatus(PaymentInterface $payment): StatusCheck;
}
