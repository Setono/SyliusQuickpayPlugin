<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Reconciliation;

use Sylius\Component\Core\Model\PaymentInterface;
use Webmozart\Assert\Assert;

/**
 * One reconcile run's pending payments ({@see PendingPaymentsInterface}): the ones whose Quickpay payment changed in
 * the run's windows and the ones to retry, yielded once each, stuck ones (new, processing) first, then authorized
 * ones, each oldest first. Iterate it once, pass each payment that errored or was skipped to {@see self::retry()},
 * and {@see self::finish()} it.
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
     * @param (\Closure(array<int, \DateTimeImmutable>): void)|null $finish stores what the next run starts from,
     *        given the payments to retry; null on a dry run
     */
    public function __construct(
        private readonly \Generator $pendingPayments,
        private readonly array $retryList,
        private readonly array $windows,
        private readonly array $failedGateways,
        private readonly ?\Closure $finish,
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
     * payments retried are the ones to retry next time, and no others.
     */
    public function finish(): void
    {
        if (null !== $this->finish) {
            ($this->finish)($this->retried);
        }
    }
}
