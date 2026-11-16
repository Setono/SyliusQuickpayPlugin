<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Reconciliation;

use Sylius\Component\Core\Model\PaymentInterface;
use Webmozart\Assert\Assert;

/**
 * One reconcile run's pending payments ({@see PendingPaymentsInterface}): the ones whose Quickpay payment changed in
 * the run's windows and the ones to retry, yielded once each, stuck ones (new, processing) first, then authorized
 * ones, each oldest first. Iterate it once, pass each payment that errored or was skipped to {@see self::retry()},
 * {@see self::finish()} it, and {@see self::release()} it however it ends.
 *
 * @implements \IteratorAggregate<int, PaymentInterface>
 */
final class PendingPaymentsRun implements \IteratorAggregate
{
    /** @var array<int, \DateTimeImmutable> the time each payment retried in this run first failed, by its id */
    private array $retried = [];

    /**
     * @internal a run is started by {@see PendingPaymentsInterface::start()}
     *
     * @param \Generator<int, PaymentInterface> $pendingPayments
     * @param array<int, \DateTimeImmutable> $retryList the time each payment to retry first failed, by its id
     * @param list<AccountWindow> $windows
     * @param list<ListingFailure> $failedGateways
     * @param (\Closure(array<int, \DateTimeImmutable>): bool)|null $finish stores what the next run starts from,
     *        given the payments to retry, and says whether the cache saved it; null on a dry run
     * @param (\Closure(): void)|null $releaseRunLock null on a dry run, which holds no run lock
     */
    public function __construct(
        private readonly \Generator $pendingPayments,
        private readonly array $retryList,
        private readonly array $windows,
        private readonly array $failedGateways,
        private readonly ?\Closure $finish,
        private ?\Closure $releaseRunLock,
    ) {
    }

    /**
     * @return \Generator<int, PaymentInterface>
     */
    public function getIterator(): \Generator
    {
        return $this->pendingPayments;
    }

    /**
     * The changes the run lists for each Quickpay account, whether or not Quickpay listed them
     *
     * @return list<AccountWindow>
     */
    public function windows(): array
    {
        return $this->windows;
    }

    /**
     * The gateways whose changes the run couldn't list
     *
     * @return list<ListingFailure>
     */
    public function failedGateways(): array
    {
        return $this->failedGateways;
    }

    /**
     * How many payments an earlier run couldn't reconcile; the run yields those still pending
     */
    public function paymentsToRetry(): int
    {
        return \count($this->retryList);
    }

    /**
     * Since when the payment has been failing, when an earlier run couldn't reconcile it: the time it first failed.
     * Null when it is no payment to retry.
     */
    public function failingSince(PaymentInterface $payment): ?\DateTimeImmutable
    {
        $id = $payment->getId();

        return is_int($id) ? $this->retryList[$id] ?? null : null;
    }

    /**
     * Retries the payment the run yielded on every later run until it reconciles or is no longer pending: for a
     * payment that errored or was skipped. It keeps the time it first failed.
     */
    public function retry(PaymentInterface $payment): void
    {
        $id = $payment->getId();
        Assert::integer($id);

        $this->retried[$id] = $this->retryList[$id] ?? new \DateTimeImmutable();
    }

    /**
     * Ends the run, once its payments are reconciled. Unless it is a dry run: each account that was listed is listed
     * from this run's start on next time, and an account that couldn't be listed from where it was this time; the
     * payments retried are the ones to retry next time, and no others. Then it releases the run lock.
     *
     * @return bool false when the cache didn't save what the next run starts from. The next run then starts from what
     *              the previous run stored, so it lists again the changes this one listed. True otherwise, also on a
     *              dry run, which saves nothing.
     */
    public function finish(): bool
    {
        $saved = true;
        if (null !== $this->finish && null !== $this->releaseRunLock) {
            $saved = ($this->finish)($this->retried);
        }

        $this->release();

        return $saved;
    }

    /**
     * Ends the run without storing anything, unless it is finished already: releases the run lock a run that writes
     * holds, so the next run isn't skipped. Call it however the run ends, say in a `finally`, for a run abandoned on
     * an error; a run neither finished nor released keeps the next runs skipping until its lock expires.
     */
    public function release(): void
    {
        if (null !== $this->releaseRunLock) {
            ($this->releaseRunLock)();
            $this->releaseRunLock = null;
        }
    }
}
