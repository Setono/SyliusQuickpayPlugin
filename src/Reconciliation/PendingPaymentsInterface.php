<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Reconciliation;

/**
 * The pending payments a reconcile run reconciles: the ones whose Quickpay payment changed since the run's window,
 * as Quickpay lists them, and the ones an earlier run couldn't reconcile. A pending payment is a Sylius payment of a
 * Quickpay gateway in state new, processing or authorized that carries a Quickpay payment id, however old it is.
 */
interface PendingPaymentsInterface
{
    /**
     * Starts a run: lists each Quickpay account's changes, once per distinct api key among the stored Quickpay gateway
     * configurations, since the account's watermark (when the previous run listed it), or since `$lookbackFrom` when
     * the account has no watermark, the watermark is older, or `$full` is set. A dry run stores nothing when it
     * finishes.
     *
     * A run that writes, unlike a dry run, holds the run lock from here until it is finished or released, so runs
     * reconcile one at a time.
     *
     * @throws AnotherRunIsReconciling when another run holds the run lock; nothing is listed
     */
    public function start(\DateTimeImmutable $lookbackFrom, bool $full, bool $dryRun): PendingPaymentsRun;
}
