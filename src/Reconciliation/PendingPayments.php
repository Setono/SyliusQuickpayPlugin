<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Reconciliation;

use Doctrine\Persistence\ManagerRegistry;
use Psr\Cache\CacheItemPoolInterface;
use Setono\Doctrine\ORMTrait;
use Setono\Payum\Quickpay\QuickpayGatewayFactory;
use Setono\Quickpay\Exception\QuickpayException;
use Setono\Quickpay\Request\Payment\PaymentsQuery;
use Setono\SyliusQuickpayPlugin\GatewayConfiguration\GatewayConfiguration;
use Setono\SyliusQuickpayPlugin\GatewayConfiguration\GatewayConfigurationProviderInterface;
use Setono\SyliusQuickpayPlugin\GatewayConfiguration\UnusableGatewayConfiguration;
use Setono\SyliusQuickpayPlugin\Quickpay\QuickpayClientProviderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Webmozart\Assert\Assert;

final class PendingPayments implements PendingPaymentsInterface
{
    use ORMTrait;

    /**
     * How much earlier than a run's start the next run lists from, in case Quickpay's clock is behind the store's
     */
    private const CLOCK_SKEW_MARGIN = 'PT5M';

    private const RETRY_LIST_KEY = 'retry_list';

    /**
     * @param CacheItemPoolInterface $cache where the watermarks are kept, the cache pool `setono_sylius_quickpay.reconcile`
     * @param class-string<PaymentInterface> $paymentClass
     * @param positive-int $pageSize how many local pending payments are read from the database at a time
     */
    public function __construct(
        private readonly GatewayConfigurationProviderInterface $gatewayConfigurationProvider,
        private readonly QuickpayClientProviderInterface $clientProvider,
        private readonly CacheItemPoolInterface $cache,
        ManagerRegistry $managerRegistry,
        private readonly string $paymentClass,
        private readonly int $pageSize = 100,
    ) {
        $this->managerRegistry = $managerRegistry;
    }

    public function start(\DateTimeImmutable $lookbackFrom, bool $full, bool $dryRun): PendingPaymentsRun
    {
        $startedAt = new \DateTimeImmutable();
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
            $watermarkKey = self::watermarkKey((string) $apiKey);
            $watermark = $full ? null : $this->watermark($watermarkKey);
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
                $payments = $this->clientProvider->forGatewayConfiguration($gatewayConfigurations[0])->payments()->paginate(new PaymentsQuery(
                    minTime: $window->changedSince,
                    sortBy: 'id',
                    sortDir: 'asc',
                    extra: ['timestamp' => 'updated_at'],
                ));

                foreach ($payments as $payment) {
                    $changed[$payment->id] = true;
                }

                $listed[] = $watermarkKey;
            } catch (UnusableGatewayConfiguration|QuickpayException $e) {
                $failures[] = new ListingFailure($window->gatewayNames, $e->getMessage());
            }
        }

        $retryList = $this->retryList();

        // The next run lists each account that was listed from this run's start on, less a margin for clocks out of
        // step, whatever became of its payments: the payments to retry are retried by id instead, so one that keeps
        // failing never widens a window. An account that couldn't be listed keeps its watermark, so the next run lists
        // its changes again
        $finish = $dryRun ? null : function (array $retryList) use ($listed, $startedAt): void {
            $watermark = $startedAt->sub(new \DateInterval(self::CLOCK_SKEW_MARGIN));
            foreach ($listed as $watermarkKey) {
                $this->cache->saveDeferred($this->cache->getItem($watermarkKey)->set($watermark));
            }

            $this->cache->saveDeferred($this->cache->getItem(self::RETRY_LIST_KEY)->set($retryList));
            $this->cache->commit();
        };

        return new PendingPaymentsRun(
            $this->pendingPaymentsAmong($changed, $retryList),
            $retryList,
            $windows,
            $failures,
            $finish,
        );
    }

    /**
     * The cache key of an account's watermark: derived from its api key, which is never stored
     */
    private static function watermarkKey(string $apiKey): string
    {
        return 'watermark_' . substr(hash('sha256', $apiKey), 0, 32);
    }

    private function watermark(string $watermarkKey): ?\DateTimeImmutable
    {
        $watermark = $this->cache->getItem($watermarkKey)->get();

        return $watermark instanceof \DateTimeImmutable ? $watermark : null;
    }

    /**
     * The payments the previous run couldn't reconcile, as the time each first failed by the Sylius payment's id
     *
     * @return array<int, \DateTimeImmutable>
     */
    private function retryList(): array
    {
        $stored = $this->cache->getItem(self::RETRY_LIST_KEY)->get();

        $retryList = [];
        foreach (is_array($stored) ? $stored : [] as $id => $failingSince) {
            if (is_int($id) && $failingSince instanceof \DateTimeImmutable) {
                $retryList[$id] = $failingSince;
            }
        }

        return $retryList;
    }

    /**
     * The local pending payments whose Quickpay payment is among the given ones, or that are to be retried, read from
     * the database a page at a time: stuck ones first, then authorized ones, each oldest first. A payment to retry
     * that is no longer pending isn't yielded.
     *
     * @param array<int, true> $quickpayPaymentIds
     * @param array<int, \DateTimeImmutable> $retryList
     *
     * @return \Generator<int, PaymentInterface>
     */
    private function pendingPaymentsAmong(array $quickpayPaymentIds, array $retryList): \Generator
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
