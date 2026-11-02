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

    /**
     * Reconcile on demand: brings the Sylius payment's state in line with its Quickpay payment by
     * running a status request on it, so Sylius' payment-state extension applies at most one
     * transition (with all its callbacks), and persists the result in one database transaction.
     *
     * The payment is reloaded first, so what is reconciled is its committed state; one the entity manager no
     * longer manages is loaded afresh. Read what happened from the result, not from the payment passed in.
     *
     * Errors propagate: the SDK's {@see QuickpayException} (e.g. Quickpay unreachable), Payum's
     * {@see ExceptionInterface} (e.g. a payment without a gateway) or whatever a state-machine callback throws.
     * A reconcile that fails saves nothing, not even a transition that was halfway applied when a
     * state-machine callback threw: its transaction is rolled back and the entity manager cleared, so it is
     * usable for the next payment. A failed save is the exception: Doctrine closes the entity manager, and
     * every later reconcile in the same process fails as well.
     *
     * @throws QuickpayException
     */
    public function reconcile(PaymentInterface $payment): ReconcileResult;

    /**
     * Reconcile from a callback: what reconcile on demand does, triggered by Quickpay's callback about the
     * payment, which must be the request being handled. Payum's Notify runs first, so the gateway library
     * validates the callback's HMAC and confirms the stored details before anything is reconciled: a forged
     * callback changes nothing. Then Sylius' payment-state extension applies at most one transition, and the
     * result, confirmed details included, is persisted.
     *
     * A payment whose lock is held, e.g. by a duplicate of the callback being handled, is skipped untouched.
     *
     * Errors propagate, so the callback is answered with an error and Quickpay retries it: the SDK's
     * {@see QuickpayException} (e.g. Quickpay unreachable), Payum's {@see ExceptionInterface}, Payum's
     * HttpResponse reply rejecting a callback whose HMAC doesn't match (a 400) or whatever a state-machine
     * callback throws. As on demand, a reconcile that fails saves nothing, so the retry finds the payment
     * as it was.
     *
     * @throws QuickpayException
     */
    public function reconcileFromCallback(PaymentInterface $payment): ReconcileResult;
}
