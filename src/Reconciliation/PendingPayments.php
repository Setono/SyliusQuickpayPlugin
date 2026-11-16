<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Reconciliation;

use Doctrine\Persistence\ManagerRegistry;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Setono\Doctrine\ORMTrait;
use Setono\Payum\Quickpay\QuickpayGatewayFactory;
use Setono\Quickpay\Exception\QuickpayException;
use Setono\Quickpay\Request\Payment\PaymentsQuery;
use Setono\SyliusQuickpayPlugin\GatewayConfiguration\GatewayConfiguration;
use Setono\SyliusQuickpayPlugin\GatewayConfiguration\GatewayConfigurationProviderInterface;
use Setono\SyliusQuickpayPlugin\GatewayConfiguration\UnusableGatewayConfiguration;
use Setono\SyliusQuickpayPlugin\Quickpay\QuickpayClientProviderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Symfony\Component\Lock\Exception\ExceptionInterface as LockExceptionInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;
use Webmozart\Assert\Assert;

final class PendingPayments implements PendingPaymentsInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;
    use ORMTrait;

    /**
     * The key of the run lock a run that writes holds, apart from the per-payment lock keys
     * (`setono_sylius_quickpay_payment_<Quickpay payment id>`)
     */
    public const RUN_LOCK_KEY = 'setono_sylius_quickpay_reconcile_run';

    /**
     * The run lock is refreshed after each list page and before each payment is yielded, so it only has to outlast one
     * page or one payment (a few Quickpay requests of at most 10 s each, a transition, a flush). A run that died
     * holding it makes the next runs skip for at most this long.
     */
    private const RUN_LOCK_TTL = 300.0;

    /**
     * How much earlier than a run's start the next run lists from, in case Quickpay's clock is behind the store's
     */
    private const CLOCK_SKEW_MARGIN = 'PT5M';

    /**
     * The cache key of what a run leaves the next one to start from: every account's watermark and the retry list, in
     * one item, so a cache that loses it loses them together
     */
    private const STATE_KEY = 'run_state';

    /**
     * How many changed payments a list request asks Quickpay for (it accepts 100, verified live), rather than the
     * SDK's 20: every page is a request, and a fallback run may list tens of pages
     */
    private const LIST_PAGE_SIZE = 100;

    /**
     * @param CacheItemPoolInterface $cache where the watermarks and the retry list are kept, the cache pool
     *        `setono_sylius_quickpay.reconcile`
     * @param class-string<PaymentInterface> $paymentClass
     * @param positive-int $pageSize how many local pending payments are read from the database at a time
     */
    public function __construct(
        private readonly GatewayConfigurationProviderInterface $gatewayConfigurationProvider,
        private readonly QuickpayClientProviderInterface $clientProvider,
        private readonly CacheItemPoolInterface $cache,
        private readonly LockFactory $lockFactory,
        ManagerRegistry $managerRegistry,
        private readonly string $paymentClass,
        private readonly int $pageSize = 100,
    ) {
        $this->managerRegistry = $managerRegistry;
    }

    public function start(\DateTimeImmutable $lookbackFrom, bool $full, bool $dryRun): PendingPaymentsRun
    {
        // Only a run that writes takes the run lock: a dry run stores nothing, so it may run alongside a run that does
        $runLock = $dryRun ? null : $this->acquireRunLock();

        try {
            return $this->startHolding($runLock, $lookbackFrom, $full);
        } catch (\Throwable $e) {
            // No run to release it later
            if (null !== $runLock) {
                $this->releaseRunLock($runLock);
            }

            throw $e;
        }
    }

    /**
     * Starts a run, holding the run lock unless it is a dry run
     */
    private function startHolding(?LockInterface $runLock, \DateTimeImmutable $lookbackFrom, bool $full): PendingPaymentsRun
    {
        $startedAt = new \DateTimeImmutable();
        [$watermarks, $retryList] = $this->storedState();
        $failures = [];

        // A Quickpay account is a distinct api key, however many gateways share it
        $accounts = [];
        foreach ($this->gatewayConfigurationProvider->all() as $gatewayConfiguration) {
            try {
                $accounts[$gatewayConfiguration->options()->apiKey][] = $gatewayConfiguration;
            } catch (UnusableGatewayConfiguration $e) {
                $failures[] = new ListingFailure([$gatewayConfiguration->gatewayName], $e->getMessage());
            }
        }

        $changed = [];
        $windows = [];
        $listed = [];
        foreach ($accounts as $apiKey => $gatewayConfigurations) {
            $accountKey = self::accountKey((string) $apiKey);
            $watermark = $full ? null : $watermarks[$accountKey] ?? null;
            $sinceLastRun = null !== $watermark && $watermark >= $lookbackFrom;

            $window = new AccountWindow(
                array_map(static fn (GatewayConfiguration $gatewayConfiguration): string => $gatewayConfiguration->gatewayName, $gatewayConfigurations),
                $sinceLastRun ? $watermark : $lookbackFrom,
                $sinceLastRun,
            );
            $windows[] = $window;

            try {
                // Every payment Quickpay changed since then, however long ago it was created. Quickpay can't sort them
                // by when they changed, so they are listed by id, which pages steadily while payments change
                $payments = $this->clientProvider->forGatewayConfiguration($gatewayConfigurations[0])->payments();
                $query = new PaymentsQuery(
                    minTime: $window->changedSince,
                    sortBy: 'id',
                    sortDir: 'asc',
                    extra: ['timestamp' => 'updated_at'],
                    pageSize: self::LIST_PAGE_SIZE,
                );

                // A page at a time, until a page short of the page size, as the SDK's paginate() does, so the run
                // lock is refreshed after each
                for ($page = 1; ; ++$page) {
                    $listedPayments = $payments->getPage($query->withPage($page))->items;
                    foreach ($listedPayments as $payment) {
                        $changed[$payment->id] = true;
                    }

                    $runLock?->refresh();

                    if (\count($listedPayments) < self::LIST_PAGE_SIZE) {
                        break;
                    }
                }

                $listed[] = $accountKey;
            } catch (UnusableGatewayConfiguration|QuickpayException $e) {
                $failures[] = new ListingFailure($window->gatewayNames, $e->getMessage());
            }
        }

        // The next run lists each account that was listed from this run's start on, less a margin for clocks out of
        // step, whatever became of its payments: the payments to retry are retried by id instead, so one that keeps
        // failing never widens a window. An account that couldn't be listed keeps its watermark, so the next run lists
        // its changes again
        $finish = null === $runLock ? null : function (array $retryList) use ($watermarks, $listed, $startedAt): bool {
            $watermark = $startedAt->sub(new \DateInterval(self::CLOCK_SKEW_MARGIN));
            foreach ($listed as $accountKey) {
                $watermarks[$accountKey] = $watermark;
            }

            // Should the cache refuse it, what the previous run stored stays: its watermarks are older, so the next run
            // lists again what this one saw
            return $this->cache->save($this->cache->getItem(self::STATE_KEY)->set([
                'watermarks' => $watermarks,
                'retry_list' => $retryList,
            ]));
        };

        return new PendingPaymentsRun(
            $this->pendingPaymentsAmong($changed, $retryList, $runLock),
            $retryList,
            $windows,
            $failures,
            $finish,
            null === $runLock ? null : fn () => $this->releaseRunLock($runLock),
        );
    }

    /**
     * Not auto-released: released when the run is finished or released, never left to whenever the lock object
     * happens to be collected. Non-blocking: the run going on does the work. A lock store that can't be reached
     * throws, failing the run before it starts.
     */
    private function acquireRunLock(): LockInterface
    {
        $runLock = $this->lockFactory->createLock(self::RUN_LOCK_KEY, self::RUN_LOCK_TTL, false);
        if (!$runLock->acquire()) {
            throw new AnotherRunIsReconciling();
        }

        return $runLock;
    }

    /**
     * A run lock that cannot be released expires with its TTL. Throwing here would turn a finished run into an error,
     * or replace the error that ended it.
     */
    private function releaseRunLock(LockInterface $runLock): void
    {
        try {
            $runLock->release();
        } catch (LockExceptionInterface $e) {
            $this->logger?->warning(sprintf(
                'Could not release the run lock, so reconcile runs are skipped until it expires within %d seconds: %s',
                self::RUN_LOCK_TTL,
                $e->getMessage(),
            ), ['exception' => $e]);
        }
    }

    /**
     * The key of an account's watermark in the run's state: derived from its api key, which is never stored
     */
    private static function accountKey(string $apiKey): string
    {
        return substr(hash('sha256', $apiKey), 0, 32);
    }

    /**
     * What the previous run that wrote left this one to start from: each account's watermark by its key, and the
     * payments it couldn't reconcile, as the time each first failed by the Sylius payment's id. Nothing when the cache
     * holds nothing, or nothing it can read.
     *
     * @return array{array<string, \DateTimeImmutable>, array<int, \DateTimeImmutable>}
     */
    private function storedState(): array
    {
        $stored = $this->cache->getItem(self::STATE_KEY)->get();
        if (!is_array($stored)) {
            return [[], []];
        }

        $watermarks = [];
        foreach (is_array($stored['watermarks'] ?? null) ? $stored['watermarks'] : [] as $accountKey => $watermark) {
            if (is_string($accountKey) && $watermark instanceof \DateTimeImmutable) {
                $watermarks[$accountKey] = $watermark;
            }
        }

        $retryList = [];
        foreach (is_array($stored['retry_list'] ?? null) ? $stored['retry_list'] : [] as $id => $failingSince) {
            if (is_int($id) && $failingSince instanceof \DateTimeImmutable) {
                $retryList[$id] = $failingSince;
            }
        }

        return [$watermarks, $retryList];
    }

    /**
     * The local pending payments whose Quickpay payment is among the given ones, or that are to be retried, read from
     * the database a page at a time: stuck ones first, then authorized ones, each oldest first. A payment to retry
     * that is no longer pending isn't yielded.
     *
     * @param array<int, true> $quickpayPaymentIds
     * @param array<int, \DateTimeImmutable> $retryList
     * @param LockInterface|null $runLock refreshed before each payment is yielded
     *
     * @return \Generator<int, PaymentInterface>
     */
    private function pendingPaymentsAmong(array $quickpayPaymentIds, array $retryList, ?LockInterface $runLock): \Generator
    {
        if ([] === $quickpayPaymentIds && [] === $retryList) {
            return;
        }

        // Reconciling a payment moves it on, e.g. from new to authorized, so it may turn up again in a later page
        $yielded = [];

        // A new or processing payment is stuck; an authorized one is mostly an order waiting for shipment
        foreach ([[PaymentInterface::STATE_NEW, PaymentInterface::STATE_PROCESSING], [PaymentInterface::STATE_AUTHORIZED]] as $states) {
            foreach ($this->pendingPaymentsIn($states) as $id => $payment) {
                if (isset($yielded[$id])) {
                    continue;
                }

                $quickpayPaymentId = $payment->getDetails()['quickpayPaymentId'] ?? null;
                $changed = is_scalar($quickpayPaymentId) && isset($quickpayPaymentIds[(int) $quickpayPaymentId]);
                if (!$changed && !isset($retryList[$id])) {
                    continue;
                }

                $yielded[$id] = true;

                // Gives the run lock its full TTL for reconciling this payment. A lock store that fails here throws,
                // ending the run unfinished: the next run lists the same changes again
                $runLock?->refresh();

                yield $payment;
            }
        }
    }

    /**
     * Every payment of a Quickpay gateway in the given states that carries a Quickpay payment id, oldest first, keyed
     * by its id. They are read a page at a time, after the last id read, so the payments a run moves out of these
     * states don't shift the pages. The entity manager is cleared between pages, so memory stays flat however many
     * there are: the reconciler loads a payment the entity manager no longer manages afresh.
     *
     * @param list<string> $states
     *
     * @return \Generator<int, PaymentInterface>
     */
    private function pendingPaymentsIn(array $states): \Generator
    {
        $manager = $this->getManager($this->paymentClass);
        $lastId = 0;

        do {
            if ($lastId > 0) {
                $manager->clear();
            }

            /** @var list<PaymentInterface> $payments */
            $payments = $manager->createQueryBuilder()
                ->select('payment')
                ->from($this->paymentClass, 'payment')
                ->join('payment.method', 'method')
                ->join('method.gatewayConfig', 'gatewayConfig')
                ->andWhere('gatewayConfig.factoryName = :factoryName')
                ->andWhere('payment.state IN (:states)')
                ->andWhere('payment.id > :lastId')
                ->orderBy('payment.id', 'ASC')
                ->setParameter('factoryName', QuickpayGatewayFactory::NAME)
                ->setParameter('states', $states)
                ->setParameter('lastId', $lastId)
                ->setMaxResults($this->pageSize)
                ->getQuery()
                ->getResult();

            foreach ($payments as $payment) {
                $id = $payment->getId();
                Assert::integer($id);
                $lastId = $id;

                // A payment without a Quickpay payment id never got a payment link, so there is no Quickpay payment
                // to reconcile it with
                if (isset($payment->getDetails()['quickpayPaymentId'])) {
                    yield $id => $payment;
                }
            }
        } while (\count($payments) === $this->pageSize);
    }
}
