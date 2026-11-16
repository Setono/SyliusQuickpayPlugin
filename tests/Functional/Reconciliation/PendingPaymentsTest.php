<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Tests\Functional\Reconciliation;

use Doctrine\Persistence\ManagerRegistry;
use Nyholm\Psr7\Response;
use Setono\SyliusQuickpayPlugin\GatewayConfiguration\GatewayConfigurationProviderInterface;
use Setono\SyliusQuickpayPlugin\Quickpay\QuickpayClientProviderInterface;
use Setono\SyliusQuickpayPlugin\Reconciliation\AccountWindow;
use Setono\SyliusQuickpayPlugin\Reconciliation\PendingPayments;
use Setono\SyliusQuickpayPlugin\Reconciliation\PendingPaymentsInterface;
use Setono\SyliusQuickpayPlugin\Reconciliation\PendingPaymentsRun;
use Setono\SyliusQuickpayPlugin\Tests\Functional\FunctionalTestCase;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Core\OrderPaymentStates;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TraceableAdapter;

/**
 * Which pending payments a reconcile run yields: the ones Quickpay changed since the run's window, as Quickpay lists
 * them, matched against the test database
 */
final class PendingPaymentsTest extends FunctionalTestCase
{
    private int $orders = 0;

    protected function setUp(): void
    {
        self::bootKernel();
    }

    /**
     * @test
     */
    public function it_yields_the_pending_payments_of_a_quickpay_gateway_whose_quickpay_payment_changed(): void
    {
        $new = $this->payment(PaymentInterface::STATE_NEW, 501);
        $processing = $this->payment(PaymentInterface::STATE_PROCESSING, 502);
        $authorized = $this->payment(PaymentInterface::STATE_AUTHORIZED, 503);
        // However old a pending payment is, a change at Quickpay is reconciled
        $old = $this->payment(PaymentInterface::STATE_NEW, 504, createdAt: new \DateTime('-2 years'));
        // Quickpay didn't change it
        $this->payment(PaymentInterface::STATE_NEW, 505);
        // Changed, but not pending
        foreach ([
            PaymentInterface::STATE_CART,
            PaymentInterface::STATE_COMPLETED,
            PaymentInterface::STATE_FAILED,
            PaymentInterface::STATE_CANCELLED,
            PaymentInterface::STATE_REFUNDED,
            PaymentInterface::STATE_UNKNOWN,
        ] as $i => $state) {
            $this->payment($state, 510 + $i);
        }
        // Never got a payment link, so it has no Quickpay payment
        $this->payment(PaymentInterface::STATE_NEW, null);
        // Linked to a changed Quickpay payment id all the same, so only its gateway leaves it out
        $this->payment(PaymentInterface::STATE_NEW, 520, method: $this->createPaymentMethod('bank_transfer', [], factoryName: 'offline'));

        // ...and one Quickpay payment the store doesn't know
        $this->quickpay()->respond('GET', '/payments', self::changedQuickpayPayments(501, 502, 503, 504, 510, 511, 512, 513, 514, 515, 520, 599));

        self::assertSame(
            [$new->getId(), $processing->getId(), $old->getId(), $authorized->getId()],
            self::idsOf($this->start()),
        );
    }

    /**
     * A new or processing payment is stuck; an authorized one is mostly an order waiting for shipment
     *
     * @test
     */
    public function it_yields_stuck_payments_before_authorized_ones_oldest_first_within_each(): void
    {
        $firstAuthorized = $this->payment(PaymentInterface::STATE_AUTHORIZED, 501);
        $firstNew = $this->payment(PaymentInterface::STATE_NEW, 502);
        $processing = $this->payment(PaymentInterface::STATE_PROCESSING, 503);
        $secondAuthorized = $this->payment(PaymentInterface::STATE_AUTHORIZED, 504);
        $secondNew = $this->payment(PaymentInterface::STATE_NEW, 505);
        // Quickpay lists its changes by id, whatever the order of the local payments
        $this->quickpay()->respond('GET', '/payments', self::changedQuickpayPayments(505, 504, 503, 502, 501));

        self::assertSame(
            [$firstNew->getId(), $processing->getId(), $secondNew->getId(), $firstAuthorized->getId(), $secondAuthorized->getId()],
            self::idsOf($this->start()),
        );
    }

    /**
     * @test
     */
    public function it_reads_every_local_pending_payment_a_page_at_a_time(): void
    {
        $firstAuthorized = $this->payment(PaymentInterface::STATE_AUTHORIZED, 501);
        $firstNew = $this->payment(PaymentInterface::STATE_NEW, 502);
        // Pages of payments that aren't yielded don't end the reading
        $this->payment(PaymentInterface::STATE_NEW, null);
        $this->payment(PaymentInterface::STATE_NEW, 520);
        $this->payment(PaymentInterface::STATE_PROCESSING, null);
        $this->payment(PaymentInterface::STATE_AUTHORIZED, null);
        $this->payment(PaymentInterface::STATE_AUTHORIZED, 521);
        $processing = $this->payment(PaymentInterface::STATE_PROCESSING, 503);
        $secondAuthorized = $this->payment(PaymentInterface::STATE_AUTHORIZED, 504);
        $secondNew = $this->payment(PaymentInterface::STATE_NEW, 505);
        $thirdAuthorized = $this->payment(PaymentInterface::STATE_AUTHORIZED, 506);
        $this->quickpay()->respond('GET', '/payments', self::changedQuickpayPayments(501, 502, 503, 504, 505, 506));

        self::assertSame(
            [
                $firstNew->getId(), $processing->getId(), $secondNew->getId(),
                $firstAuthorized->getId(), $secondAuthorized->getId(), $thirdAuthorized->getId(),
            ],
            self::idsOf($this->start(pageSize: 2)),
        );
    }

    /**
     * Reconciling a payment moves it on: a stuck payment that becomes authorized is not yielded again
     *
     * @test
     */
    public function it_yields_a_payment_once_although_it_moves_on_while_the_run_goes(): void
    {
        $new = $this->payment(PaymentInterface::STATE_NEW, 501);
        $authorized = $this->payment(PaymentInterface::STATE_AUTHORIZED, 502);
        $this->quickpay()->respond('GET', '/payments', self::changedQuickpayPayments(501, 502));

        $ids = [];
        foreach ($this->start(pageSize: 1) as $payment) {
            $ids[] = $payment->getId();
            // As reconciling it from an authorize callback missed would
            if (PaymentInterface::STATE_NEW === $payment->getState()) {
                $payment->setState(PaymentInterface::STATE_AUTHORIZED);
                $this->entityManager()->flush();
            }
        }

        self::assertSame([$new->getId(), $authorized->getId()], $ids);
    }

    /**
     * Quickpay lists a page of 100 at a time, by id, and a page short of that is the last
     *
     * @test
     */
    public function it_lists_every_page_of_quickpay_changes(): void
    {
        $onFirstPage = $this->payment(PaymentInterface::STATE_NEW, 501);
        $onSecondPage = $this->payment(PaymentInterface::STATE_NEW, 701);
        $this->quickpay()->respond('GET', '/payments', self::changedQuickpayPayments(501, ...range(602, 700)), ['page' => 1]);
        $this->quickpay()->respond('GET', '/payments', self::changedQuickpayPayments(701), ['page' => 2]);

        self::assertSame([$onFirstPage->getId(), $onSecondPage->getId()], self::idsOf($this->start()));
        self::assertSame(['GET /payments', 'GET /payments'], $this->quickpay()->sentRequests());
        self::assertSame(
            [['1', '100'], ['2', '100']],
            array_map(static fn (array $query): array => [$query['page'] ?? null, $query['page_size'] ?? null], $this->sentQueries()),
        );
    }

    /**
     * Gateways sharing an api key share a Quickpay account, whose changes are listed once
     *
     * @test
     */
    public function it_lists_the_changes_of_each_quickpay_account_once(): void
    {
        $creditCard = $this->payment(PaymentInterface::STATE_NEW, 501);
        $mobilePay = $this->payment(PaymentInterface::STATE_NEW, 502, method: $this->createPaymentMethod('quickpay_mobilepay', self::config('the-api-key')));
        $this->quickpay()->respond('GET', '/payments', self::changedQuickpayPayments(501, 502));

        $run = $this->start();

        self::assertSame([$creditCard->getId(), $mobilePay->getId()], self::idsOf($run));
        self::assertSame(['GET /payments'], $this->quickpay()->sentRequests());
        self::assertSame([], $run->failedGateways());
    }

    /**
     * @test
     */
    public function it_records_a_gateway_with_an_unusable_gateway_configuration_and_lists_the_others(): void
    {
        // A branding name where the branding id belongs, stored by fixtures or the database directly
        $this->createPaymentMethod('quickpay_se', self::config('the-se-api-key') + ['branding_id' => 'Our brand']);
        $payment = $this->payment(PaymentInterface::STATE_NEW, 501);
        $this->quickpay()->respond('GET', '/payments', self::changedQuickpayPayments(501));

        $run = $this->start();

        self::assertSame([$payment->getId()], self::idsOf($run));
        self::assertSame(['Authorization: Basic ' . base64_encode(':the-api-key')], self::authorizationOf($this->quickpay()->sentRequestOptions()[0]));
        self::assertCount(1, $run->failedGateways());
        self::assertSame(['quickpay_se'], $run->failedGateways()[0]->gatewayNames);
        self::assertStringContainsString('The gateway configuration of the Quickpay gateway "quickpay_se" is invalid: The "branding_id" option', $run->failedGateways()[0]->message);
    }

    /**
     * @test
     */
    public function it_records_a_quickpay_account_quickpay_fails_to_list(): void
    {
        $this->createPaymentMethod('quickpay_mobilepay', self::config('the-api-key'));
        $this->payment(PaymentInterface::STATE_NEW, 501);
        $this->quickpay()->respond('GET', '/payments', new Response(500, ['Content-Type' => 'application/json'], '{"message": "Quickpay is down"}'));

        $run = $this->start();

        self::assertSame([], self::idsOf($run));
        self::assertCount(1, $run->failedGateways());
        $gatewayNames = $run->failedGateways()[0]->gatewayNames;
        sort($gatewayNames);
        self::assertSame(['quickpay', 'quickpay_mobilepay'], $gatewayNames);
        self::assertStringContainsString('Quickpay is down', $run->failedGateways()[0]->message);
    }

    /**
     * @test
     */
    public function without_a_watermark_it_lists_the_changes_since_the_lookback(): void
    {
        $this->storeQuickpayGateway();
        $this->quickpay()->respond('GET', '/payments', self::changedQuickpayPayments());

        $run = $this->start(new \DateTimeImmutable('2026-11-09 12:00:00 +01:00'));

        self::assertEquals([new AccountWindow(['quickpay'], new \DateTimeImmutable('2026-11-09 12:00:00 +01:00'), false)], $run->windows());
        $query = $this->sentQueries()[0];
        self::assertSame('2026-11-09 12:00:00 +0100', $query['min_time'] ?? null);
        self::assertSame('updated_at', $query['timestamp'] ?? null);
        self::assertSame('id', $query['sort_by'] ?? null);
        self::assertSame('asc', $query['sort_dir'] ?? null);
    }

    /**
     * @test
     */
    public function it_lists_the_changes_since_the_watermark_the_previous_run_stored(): void
    {
        $this->storeQuickpayGateway();
        $this->quickpay()->respond('GET', '/payments', self::changedQuickpayPayments());
        [, $startedAfter, $startedBefore] = $this->finishedRun();

        $run = $this->start(new \DateTimeImmutable('-7 days'));

        $window = $run->windows()[0];
        self::assertSame(['quickpay'], $window->gatewayNames);
        self::assertTrue($window->sinceLastRun);
        // The previous run's start, less five minutes for clocks out of step
        self::assertGreaterThanOrEqual($startedAfter->modify('-5 minutes'), $window->changedSince);
        self::assertLessThanOrEqual($startedBefore->modify('-5 minutes'), $window->changedSince);
        self::assertSame($window->changedSince->format('Y-m-d H:i:s O'), $this->sentQueries()[1]['min_time'] ?? null);
    }

    /**
     * @test
     */
    public function a_watermark_older_than_the_lookback_is_clamped_to_it(): void
    {
        $this->storeQuickpayGateway();
        $this->quickpay()->respond('GET', '/payments', self::changedQuickpayPayments());
        $this->finishedRun();
        $lookbackFrom = new \DateTimeImmutable('+1 minute');

        $run = $this->start($lookbackFrom);

        self::assertEquals([new AccountWindow(['quickpay'], $lookbackFrom, false)], $run->windows());
    }

    /**
     * @test
     */
    public function a_full_run_lists_the_changes_since_the_lookback_whatever_the_watermark(): void
    {
        $this->storeQuickpayGateway();
        $this->quickpay()->respond('GET', '/payments', self::changedQuickpayPayments());
        $this->finishedRun();
        $lookbackFrom = new \DateTimeImmutable('-7 days');

        $run = $this->start($lookbackFrom, full: true);

        self::assertEquals([new AccountWindow(['quickpay'], $lookbackFrom, false)], $run->windows());
        self::assertSame($lookbackFrom->format('Y-m-d H:i:s O'), $this->sentQueries()[1]['min_time'] ?? null);
    }

    /**
     * @test
     */
    public function each_quickpay_account_is_listed_since_its_own_watermark(): void
    {
        $this->storeQuickpayGateway();
        $this->quickpay()->respond('GET', '/payments', self::changedQuickpayPayments());
        $this->finishedRun();
        // An account the previous run didn't list, e.g. a new api key
        $this->createPaymentMethod('quickpay_se', self::config('the-se-api-key'));
        $lookbackFrom = new \DateTimeImmutable('-7 days');

        $run = $this->start($lookbackFrom);

        $windows = self::windowsByGateway($run);
        self::assertTrue($windows['quickpay']->sinceLastRun);
        self::assertEquals(new AccountWindow(['quickpay_se'], $lookbackFrom, false), $windows['quickpay_se']);
        self::assertSame(
            [
                'quickpay' => $windows['quickpay']->changedSince->format('Y-m-d H:i:s O'),
                'quickpay_se' => $lookbackFrom->format('Y-m-d H:i:s O'),
            ],
            $this->minTimesSentByGateway(['the-api-key' => 'quickpay', 'the-se-api-key' => 'quickpay_se'], fromRequest: 1),
        );
    }

    /**
     * @test
     */
    public function an_account_quickpay_fails_to_list_keeps_its_watermark_while_the_others_move_on(): void
    {
        $this->storeQuickpayGateway();
        $this->quickpay()->respond('GET', '/payments', self::changedQuickpayPayments());
        $this->finishedRun();
        $this->createPaymentMethod('quickpay_se', self::config('the-se-api-key'));
        $lookbackFrom = new \DateTimeImmutable('-7 days');
        // Quickpay answers the new account, listed since the lookback, and fails the other
        $this->quickpay()->respond('GET', '/payments', self::changedQuickpayPayments(), ['min_time' => $lookbackFrom->format('Y-m-d H:i:s O')]);
        $this->quickpay()->respond('GET', '/payments', new Response(500, ['Content-Type' => 'application/json'], '{"message": "Quickpay is down"}'));

        [$failingRun, $startedAfter, $startedBefore] = $this->finishedRun($lookbackFrom);

        self::assertCount(1, $failingRun->failedGateways());
        self::assertSame(['quickpay'], $failingRun->failedGateways()[0]->gatewayNames);
        $failedAccountWatermark = self::windowsByGateway($failingRun)['quickpay']->changedSince;

        $this->quickpay()->respond('GET', '/payments', self::changedQuickpayPayments());
        $windows = self::windowsByGateway($this->start($lookbackFrom));

        self::assertEquals(new AccountWindow(['quickpay'], $failedAccountWatermark, true), $windows['quickpay']);
        self::assertTrue($windows['quickpay_se']->sinceLastRun);
        self::assertGreaterThanOrEqual($startedAfter->modify('-5 minutes'), $windows['quickpay_se']->changedSince);
        self::assertLessThanOrEqual($startedBefore->modify('-5 minutes'), $windows['quickpay_se']->changedSince);
    }

    /**
     * @test
     */
    public function a_dry_run_stores_no_watermark(): void
    {
        $this->storeQuickpayGateway();
        $this->quickpay()->respond('GET', '/payments', self::changedQuickpayPayments());
        $this->finishedRun(dryRun: true);
        $lookbackFrom = new \DateTimeImmutable('-7 days');

        self::assertEquals([new AccountWindow(['quickpay'], $lookbackFrom, false)], $this->start($lookbackFrom)->windows());
    }

    /**
     * @test
     */
    public function no_api_key_is_stored(): void
    {
        $this->storeQuickpayGateway();
        $this->quickpay()->respond('GET', '/payments', self::changedQuickpayPayments());

        $this->finishedRun();

        $stored = $this->cache()->getValues();
        self::assertNotEmpty($stored);
        self::assertStringNotContainsString('the-api-key', serialize($stored));
    }

    /**
     * A payment that errored or was skipped is retried on the next run, while the watermark moves on as for any run
     *
     * @test
     */
    public function a_payment_to_retry_is_yielded_on_the_next_run_although_quickpay_lists_no_change(): void
    {
        $failing = $this->payment(PaymentInterface::STATE_NEW, 501);
        $changed = $this->payment(PaymentInterface::STATE_AUTHORIZED, 502);
        $this->quickpay()->respond('GET', '/payments', self::changedQuickpayPayments(501));
        $this->runRetrying($failing);
        $this->quickpay()->respond('GET', '/payments', self::changedQuickpayPayments(502));

        $run = $this->start();

        self::assertSame(1, $run->paymentsToRetry());
        self::assertSame([$failing->getId(), $changed->getId()], self::idsOf($run));
        self::assertTrue($run->windows()[0]->sinceLastRun);
    }

    /**
     * @test
     */
    public function a_payment_leaves_the_retry_list_once_a_run_yields_it_and_it_is_not_retried_again(): void
    {
        $payment = $this->payment(PaymentInterface::STATE_NEW, 501);
        $this->quickpay()->respond('GET', '/payments', self::changedQuickpayPayments(501));
        $this->runRetrying($payment);
        $this->quickpay()->respond('GET', '/payments', self::changedQuickpayPayments());

        // It reconciles this time
        [$run] = $this->finishedRun();
        self::assertSame(1, $run->paymentsToRetry());

        $run = $this->start();
        self::assertSame(0, $run->paymentsToRetry());
        self::assertSame([], self::idsOf($run));
    }

    /**
     * @test
     */
    public function a_payment_leaves_the_retry_list_once_it_is_no_longer_pending(): void
    {
        $payment = $this->payment(PaymentInterface::STATE_NEW, 501);
        $this->quickpay()->respond('GET', '/payments', self::changedQuickpayPayments(501));
        $this->runRetrying($payment);
        $this->quickpay()->respond('GET', '/payments', self::changedQuickpayPayments());
        // A callback reconciled it in the meantime
        $payment->setState(PaymentInterface::STATE_COMPLETED);
        $this->entityManager()->flush();

        $run = $this->start();
        self::assertSame([], self::idsOf($run));
        $run->finish();

        self::assertSame(0, $this->start()->paymentsToRetry());
    }

    /**
     * @test
     */
    public function a_payment_on_the_retry_list_says_since_when_it_has_been_failing(): void
    {
        $failing = $this->payment(PaymentInterface::STATE_NEW, 501);
        $changed = $this->payment(PaymentInterface::STATE_NEW, 502);
        $this->quickpay()->respond('GET', '/payments', self::changedQuickpayPayments(501));
        $failedAfter = new \DateTimeImmutable();
        $this->runRetrying($failing);
        $failedBefore = new \DateTimeImmutable();
        $this->quickpay()->respond('GET', '/payments', self::changedQuickpayPayments(502));

        // It fails again
        $run = $this->runRetrying($failing);

        $failingSince = $run->failingSince($failing);
        self::assertNotNull($failingSince);
        self::assertGreaterThanOrEqual($failedAfter, $failingSince);
        self::assertLessThanOrEqual($failedBefore, $failingSince);
        self::assertNull($run->failingSince($changed));

        // ...and keeps the time it first failed
        self::assertEquals($failingSince, $this->start()->failingSince($failing));
    }

    /**
     * @test
     */
    public function a_dry_run_leaves_the_retry_list_as_it_was(): void
    {
        $toRetry = $this->payment(PaymentInterface::STATE_NEW, 501);
        $failingInDryRun = $this->payment(PaymentInterface::STATE_NEW, 502);
        $this->quickpay()->respond('GET', '/payments', self::changedQuickpayPayments(501));
        $this->runRetrying($toRetry);
        $this->quickpay()->respond('GET', '/payments', self::changedQuickpayPayments(502));

        // The payment to retry is yielded and not retried, and the one failing is retried, in a dry run
        $this->runRetrying($failingInDryRun, dryRun: true);

        $this->quickpay()->respond('GET', '/payments', self::changedQuickpayPayments());
        self::assertSame([$toRetry->getId()], self::idsOf($this->start()));
    }

    /**
     * Starts a run of the module as the container wires it, or of one reading the given number of local pending
     * payments at a time
     *
     * @param positive-int|null $pageSize
     */
    private function start(
        \DateTimeImmutable $lookbackFrom = new \DateTimeImmutable('-7 days'),
        bool $full = false,
        bool $dryRun = false,
        ?int $pageSize = null,
    ): PendingPaymentsRun {
        return $this->pendingPayments($pageSize)->start($lookbackFrom, $full, $dryRun);
    }

    /**
     * @param positive-int|null $pageSize
     */
    private function pendingPayments(?int $pageSize = null): PendingPaymentsInterface
    {
        $container = self::getContainer();
        $pendingPayments = $container->get(PendingPaymentsInterface::class);
        self::assertInstanceOf(PendingPayments::class, $pendingPayments);
        if (null === $pageSize) {
            return $pendingPayments;
        }

        $gatewayConfigurationProvider = $container->get(GatewayConfigurationProviderInterface::class);
        self::assertInstanceOf(GatewayConfigurationProviderInterface::class, $gatewayConfigurationProvider);
        $clientProvider = $container->get(QuickpayClientProviderInterface::class);
        self::assertInstanceOf(QuickpayClientProviderInterface::class, $clientProvider);
        $doctrine = $container->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $doctrine);
        $paymentClass = $container->getParameter('sylius.model.payment.class');
        self::assertIsString($paymentClass);
        self::assertTrue(is_a($paymentClass, PaymentInterface::class, true));

        return new PendingPayments($gatewayConfigurationProvider, $clientProvider, $this->cache(), $doctrine, $paymentClass, $pageSize);
    }

    /**
     * The cache pool the module keeps its watermarks and retry list in, in memory in the test env
     */
    private function cache(): ArrayAdapter
    {
        $cache = self::getContainer()->get('setono_sylius_quickpay.reconcile');
        if ($cache instanceof TraceableAdapter) {
            $cache = $cache->getPool();
        }
        self::assertInstanceOf(ArrayAdapter::class, $cache);

        return $cache;
    }

    /**
     * Runs a whole run, as the reconcile command does, retrying none of its payments. Returned with the earliest and
     * the latest time it can have started
     *
     * @return array{PendingPaymentsRun, \DateTimeImmutable, \DateTimeImmutable}
     */
    private function finishedRun(
        \DateTimeImmutable $lookbackFrom = new \DateTimeImmutable('-7 days'),
        bool $dryRun = false,
    ): array {
        $startedAfter = new \DateTimeImmutable();
        $run = $this->start($lookbackFrom, dryRun: $dryRun);
        $startedBefore = new \DateTimeImmutable();
        self::idsOf($run);
        $run->finish();

        return [$run, $startedAfter, $startedBefore];
    }

    /**
     * Runs a whole run, as the reconcile command does, retrying the given payment when the run yields it, as the
     * command does for a payment that errored or was skipped
     */
    private function runRetrying(PaymentInterface $failing, bool $dryRun = false): PendingPaymentsRun
    {
        $run = $this->start(dryRun: $dryRun);
        foreach ($run as $payment) {
            if ($payment->getId() === $failing->getId()) {
                $run->retry($payment);
            }
        }
        $run->finish();

        return $run;
    }

    /**
     * @return array<string, AccountWindow> each account's window, under the name of each of its gateways
     */
    private static function windowsByGateway(PendingPaymentsRun $run): array
    {
        $windows = [];
        foreach ($run->windows() as $window) {
            foreach ($window->gatewayNames as $gatewayName) {
                $windows[$gatewayName] = $window;
            }
        }

        return $windows;
    }

    /**
     * The `min_time` each request sent from the given one on listed from, under the gateway whose api key it was sent
     * with
     *
     * @param array<string, string> $gatewayByApiKey
     *
     * @return array<string, mixed>
     */
    private function minTimesSentByGateway(array $gatewayByApiKey, int $fromRequest = 0): array
    {
        $minTimes = [];
        foreach (\array_slice($this->quickpay()->sentRequestOptions(), $fromRequest) as $options) {
            $query = $options['query'] ?? null;
            self::assertIsArray($query);
            $authorization = self::authorizationOf($options);
            self::assertIsArray($authorization);

            $gatewayName = null;
            foreach ($gatewayByApiKey as $apiKey => $name) {
                if (['Authorization: Basic ' . base64_encode(':' . $apiKey)] === $authorization) {
                    $gatewayName = $name;
                }
            }
            self::assertNotNull($gatewayName, 'A request was sent with an unknown api key');

            $minTimes[$gatewayName] = $query['min_time'] ?? null;
        }
        ksort($minTimes);

        return $minTimes;
    }

    /**
     * The query of each request sent to Quickpay, in the order sent
     *
     * @return list<array<array-key, mixed>>
     */
    private function sentQueries(): array
    {
        $queries = [];
        foreach ($this->quickpay()->sentRequestOptions() as $options) {
            $query = $options['query'] ?? null;
            self::assertIsArray($query);
            $queries[] = $query;
        }

        return $queries;
    }

    /**
     * The ids of the Sylius payments the run yields, in the order it yields them
     *
     * @return list<mixed>
     */
    private static function idsOf(PendingPaymentsRun $run): array
    {
        $ids = [];
        foreach ($run as $payment) {
            $ids[] = $payment->getId();
        }

        return $ids;
    }

    /**
     * A persisted Sylius payment of its own order, in the given state, linked to the Quickpay payment of the given id
     * unless none is given
     */
    private function payment(
        string $state,
        ?int $quickpayPaymentId,
        ?PaymentMethodInterface $method = null,
        ?\DateTimeInterface $createdAt = null,
    ): PaymentInterface {
        return $this->createSyliusPayment(
            $state,
            OrderPaymentStates::STATE_AWAITING_PAYMENT,
            $quickpayPaymentId,
            orderNumber: sprintf('%09d', ++$this->orders),
            method: $method,
            createdAt: $createdAt,
        );
    }

    /**
     * Stores the shared Quickpay payment method, whose gateway `quickpay` uses the api key `the-api-key`
     */
    private function storeQuickpayGateway(): void
    {
        $this->quickpayPaymentMethod();
        $this->entityManager()->flush();
    }

    /**
     * A usable gateway configuration with the given api key
     *
     * @return array<string, string>
     */
    private static function config(string $apiKey): array
    {
        return ['api_key' => $apiKey, 'private_key' => self::PRIVATE_KEY];
    }

    /**
     * @param array<string, mixed> $options a request's options, as sent
     */
    private static function authorizationOf(array $options): mixed
    {
        $headers = $options['normalized_headers'] ?? null;
        self::assertIsArray($headers);

        return $headers['authorization'] ?? null;
    }

    /**
     * Quickpay's answer to a list request: a page of Quickpay payments with the given ids
     */
    private static function changedQuickpayPayments(int ...$ids): Response
    {
        $payments = [];
        foreach ($ids as $id) {
            $payments[] = [
                'id' => $id,
                'order_id' => self::ORDER_PREFIX . $id,
                'currency' => self::CURRENCY,
                'state' => 'new',
                'merchant_id' => 1,
                'updated_at' => '2026-11-16T10:00:00Z',
            ];
        }

        return new Response(200, ['Content-Type' => 'application/json'], json_encode($payments, \JSON_THROW_ON_ERROR));
    }
}
