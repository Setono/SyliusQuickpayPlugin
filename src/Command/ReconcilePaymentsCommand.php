<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Command;

use Setono\Quickpay\Exception\QuickpayException;
use Setono\Quickpay\Request\Payment\PaymentsQuery;
use Setono\SyliusQuickpayPlugin\GatewayConfiguration\GatewayConfigurationProviderInterface;
use Setono\SyliusQuickpayPlugin\GatewayConfiguration\UnusableGatewayConfiguration;
use Setono\SyliusQuickpayPlugin\Quickpay\QuickpayClientProviderInterface;
use Setono\SyliusQuickpayPlugin\Reconciliation\AccountWindow;
use Setono\SyliusQuickpayPlugin\Reconciliation\AnotherRunIsReconciling;
use Setono\SyliusQuickpayPlugin\Reconciliation\PaymentReconcilerInterface;
use Setono\SyliusQuickpayPlugin\Reconciliation\PendingPaymentsInterface;
use Setono\SyliusQuickpayPlugin\Reconciliation\PendingPaymentsRun;
use Setono\SyliusQuickpayPlugin\Reconciliation\ReconcileOutcome;
use Sylius\Component\Core\Model\PaymentInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The Quickpay callback is normally the only path by which a payment learns about a state
 * change at Quickpay. This command closes the gap left by callbacks that never arrive: it
 * reconciles each pending payment whose Quickpay payment changed since the last run, and each
 * one an earlier run couldn't reconcile, making the state change the callback would have made.
 */
#[AsCommand(
    name: 'setono:sylius-quickpay:reconcile-payments',
    description: 'Reconciles the pending payments Quickpay changed since the last run with their Quickpay payments',
)]
final class ReconcilePaymentsCommand extends Command
{
    public function __construct(
        private readonly PendingPaymentsInterface $pendingPayments,
        private readonly PaymentReconcilerInterface $reconciler,
        private readonly QuickpayClientProviderInterface $clientProvider,
        private readonly GatewayConfigurationProviderInterface $gatewayConfigurationProvider,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('since', null, InputOption::VALUE_REQUIRED, 'How far back to look for the payments Quickpay changed when there was no earlier run, or with --full; with --fraud-suspected, the period to report', '7 days')
            ->addOption('full', null, InputOption::VALUE_NONE, 'Ignore the last run: reconcile every payment Quickpay changed within --since')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'With --fraud-suspected, the maximum number of payments to report across all gateways')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report the transition each payment would get, changing nothing')
            ->addOption('fraud-suspected', null, InputOption::VALUE_NONE, 'Report the payments Quickpay flags as fraud suspected instead of reconciling; no transition is applied')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var string $since */
        $since = $input->getOption('since');
        $period = ltrim($since, '+- ');

        try {
            $lookbackFrom = new \DateTimeImmutable(sprintf('-%s', $period));
        } catch (\Exception) {
            $io->error(sprintf('Cannot parse "%s" as a period. Use a relative format like "7 days" or "12 hours".', $since));

            return Command::INVALID;
        }

        /** @var string|null $limit */
        $limit = $input->getOption('limit');
        $full = (bool) $input->getOption('full');
        $dryRun = (bool) $input->getOption('dry-run');

        if (true === $input->getOption('fraud-suspected')) {
            if (null !== $limit && ((string) (int) $limit !== $limit || (int) $limit < 1)) {
                $io->error(sprintf('Cannot use "%s" as --limit. Use a whole number of payments, at least 1.', $limit));

                return Command::INVALID;
            }

            return $this->reportFraudSuspected($io, $lookbackFrom, null === $limit ? null : (int) $limit);
        }

        if (null !== $limit) {
            $io->error('--limit only applies to --fraud-suspected: a run reconciles every pending payment Quickpay changed since the last run.');

            return Command::INVALID;
        }

        try {
            $run = $this->pendingPayments->start($lookbackFrom, $full, $dryRun);
        } catch (AnotherRunIsReconciling) {
            // The run going on does the work, so this one sends nothing and changes nothing. Not a failure either, so
            // overlapping cron runs don't alert anyone
            $io->writeln('Another run is reconciling payments; skipped.');

            return Command::SUCCESS;
        }

        try {
            return $this->reconcile($io, $run, $full, $dryRun, $period);
        } finally {
            // A run that ended on an error stores nothing, and the next run isn't kept waiting for the run lock
            $run->release();
        }
    }

    /**
     * Reconciles each pending payment of a run, or runs the status check on it in a dry run, and reports the run
     */
    private function reconcile(SymfonyStyle $io, PendingPaymentsRun $run, bool $full, bool $dryRun, string $period): int
    {
        foreach ($run->windows() as $window) {
            $io->writeln(sprintf(
                'Gateway(s) %s: the payments Quickpay changed since %s (%s)',
                self::quoted($window->gatewayNames),
                $window->changedSince->format('Y-m-d H:i'),
                self::describeWindow($window, $full, $period),
            ));
        }

        if ($run->paymentsToRetry() > 0) {
            $io->writeln(sprintf('%d payment(s) to retry', $run->paymentsToRetry()));
        }

        // Its changes are listed again by the next run that lists the account
        foreach ($run->failedGateways() as $failure) {
            $io->warning(sprintf(
                'Gateway(s) %s: could not list the payments Quickpay changed: %s',
                self::quoted($failure->gatewayNames),
                $failure->message,
            ));
        }

        $checked = $transitioned = $unchanged = $skipped = $errored = 0;

        foreach ($run as $payment) {
            ++$checked;

            // Besides Quickpay and Payum, a transition's state-machine callbacks can fail (say, on stock):
            // whatever one payment throws, it's reported and the run goes on with the next
            try {
                if ($dryRun) {
                    // A status check, so nothing about the payment changes and nothing is written
                    $statusCheck = $this->reconciler->checkStatus($payment);

                    if (null === $statusCheck->transition) {
                        ++$unchanged;
                        $outcome = 'no transition';
                    } else {
                        ++$transitioned;
                        $outcome = sprintf('would apply "%s"', $statusCheck->transition);
                    }

                    $io->writeln(self::describe($payment, sprintf(
                        '"%s", %s (Quickpay → "%s")',
                        $payment->getState(),
                        $outcome,
                        $statusCheck->targetState,
                    )));

                    continue;
                }

                // Persists the payment itself, so each one is saved as soon as it is reconciled
                $result = $this->reconciler->reconcile($payment);

                switch ($result->outcome) {
                    case ReconcileOutcome::Transitioned:
                        ++$transitioned;
                        $io->writeln(self::describe($payment, sprintf(
                            '"%s" -> "%s" (Quickpay → "%s")',
                            $result->stateBefore,
                            $result->stateAfter,
                            (string) $result->targetState,
                        )));

                        break;
                    case ReconcileOutcome::Unchanged:
                        ++$unchanged;

                        break;
                    case ReconcileOutcome::Skipped:
                        // Whoever holds its lock may fail to reconcile it, so the next run makes sure
                        ++$skipped;
                        $run->retry($payment);
                        $io->writeln(self::describe($payment, 'skipped, it is locked by a callback being handled or another run'));

                        break;
                }
            } catch (\Exception $e) {
                ++$errored;
                $run->retry($payment);

                $failingSince = $run->failingSince($payment);
                $io->warning(self::describe($payment, null === $failingSince ? $e->getMessage() : sprintf(
                    '%s (failing since %s)',
                    $e->getMessage(),
                    $failingSince->format('Y-m-d H:i'),
                )));
            }
        }

        $run->finish();

        $io->success(sprintf(
            '%d checked, %d transitioned%s, %d unchanged, %d skipped, %d errored',
            $checked,
            $transitioned,
            $dryRun ? ' (dry run)' : '',
            $unchanged,
            $skipped,
            $errored,
        ));

        return $errored > 0 || [] !== $run->failedGateways() ? Command::FAILURE : Command::SUCCESS;
    }

    private static function describeWindow(AccountWindow $window, bool $full, string $period): string
    {
        if ($window->sinceLastRun) {
            return 'since the last run';
        }

        return sprintf('%s: the last %s', $full ? '--full' : 'no earlier run', $period);
    }

    /**
     * @param list<string> $gatewayNames
     */
    private static function quoted(array $gatewayNames): string
    {
        return implode(', ', array_map(static fn (string $gatewayName): string => sprintf('"%s"', $gatewayName), $gatewayNames));
    }

    /**
     * Unlike reconciliation, which walks the local pending payments, this asks Quickpay directly:
     * every configured Quickpay gateway is queried for payments flagged fraud suspected within the
     * period, regardless of their local state. Report only — nothing is transitioned or persisted.
     * A gateway that can't be queried, its gateway configuration unusable included, is reported and
     * fails the run, so a gap in the report is never silent. Neither is a limit: without one, every
     * flagged payment is reported; with one, it caps the payments reported across gateways, and once
     * the report holds that many, it says so and names the gateways it didn't query. Reaching the
     * limit isn't a failure.
     */
    private function reportFraudSuspected(SymfonyStyle $io, \DateTimeImmutable $createdSince, ?int $limit): int
    {
        $rows = [];
        $errored = false;
        $notQueried = [];

        foreach ($this->gatewayConfigurationProvider->all() as $gatewayConfiguration) {
            if (null !== $limit && \count($rows) >= $limit) {
                $notQueried[] = sprintf('"%s"', $gatewayConfiguration->gatewayName);

                continue;
            }

            try {
                $payments = $this->clientProvider->forGatewayConfiguration($gatewayConfiguration)->payments()->paginate(new PaymentsQuery(
                    minTime: $createdSince,
                    fraudSuspected: true,
                    sortBy: 'created_at',
                    sortDir: 'desc',
                ));

                foreach ($payments as $payment) {
                    $rows[] = [
                        $gatewayConfiguration->gatewayName,
                        (string) $payment->id,
                        $payment->orderId,
                        $payment->state,
                        $payment->createdAt?->format('Y-m-d H:i:s') ?? '',
                        $payment->testMode ? 'yes' : 'no',
                    ];

                    if (null !== $limit && \count($rows) >= $limit) {
                        break;
                    }
                }
            } catch (UnusableGatewayConfiguration|QuickpayException $e) {
                $errored = true;
                $io->warning(sprintf('Gateway "%s": %s', $gatewayConfiguration->gatewayName, $e->getMessage()));
            }
        }

        $stopped = null !== $limit && \count($rows) >= $limit;

        if ([] !== $rows) {
            $io->table(['Gateway', 'Quickpay id', 'Order id', 'State', 'Created', 'Test mode'], $rows);
            $io->warning(sprintf('%d payment(s) flagged as fraud suspected. Review them in the Quickpay manager before capturing.', \count($rows)));
        } elseif (!$stopped) {
            $io->success('Quickpay reports no fraud suspected payments in the period.');
        }

        if ($stopped) {
            $io->warning(sprintf(
                'The report stopped at the limit of %d payment(s) and %s. Raise --limit to see more.',
                $limit,
                [] === $notQueried ? 'may be incomplete' : sprintf("didn't query the gateway(s) %s", implode(', ', $notQueried)),
            ));
        }

        // Only a gateway that couldn't be queried fails the run, never the limit
        return $errored ? Command::FAILURE : Command::SUCCESS;
    }

    private static function describe(PaymentInterface $payment, string $message): string
    {
        $id = $payment->getId();

        return sprintf('Payment %s: %s', is_scalar($id) ? (string) $id : '?', $message);
    }
}
