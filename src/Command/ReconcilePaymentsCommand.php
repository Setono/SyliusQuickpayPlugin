<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Command;

use Setono\Quickpay\Exception\QuickpayException;
use Setono\Quickpay\Request\Payment\PaymentsQuery;
use Setono\SyliusQuickpayPlugin\GatewayConfiguration\GatewayConfigurationProviderInterface;
use Setono\SyliusQuickpayPlugin\GatewayConfiguration\UnusableGatewayConfiguration;
use Setono\SyliusQuickpayPlugin\Provider\PendingPaymentProviderInterface;
use Setono\SyliusQuickpayPlugin\Quickpay\ClientFactoryInterface;
use Setono\SyliusQuickpayPlugin\Reconciliation\PaymentReconcilerInterface;
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
 * reconciles each payment stuck in a non-final state, making the state change the callback
 * would have made.
 */
#[AsCommand(
    name: 'setono:sylius-quickpay:reconcile-payments',
    description: 'Reconciles payments stuck in a non-final state with their Quickpay payments',
)]
final class ReconcilePaymentsCommand extends Command
{
    public function __construct(
        private readonly PendingPaymentProviderInterface $pendingPaymentProvider,
        private readonly PaymentReconcilerInterface $reconciler,
        private readonly ClientFactoryInterface $clientFactory,
        private readonly GatewayConfigurationProviderInterface $gatewayConfigurationProvider,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('since', null, InputOption::VALUE_REQUIRED, 'Only reconcile payments created within this period', '7 days')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum number of payments to check', '100')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report the transition each payment would get, changing nothing')
            ->addOption('fraud-suspected', null, InputOption::VALUE_NONE, 'Report the payments Quickpay flags as fraud suspected instead of reconciling; no transition is applied')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var string $since */
        $since = $input->getOption('since');

        try {
            $createdSince = new \DateTimeImmutable(sprintf('-%s', ltrim($since, '+- ')));
        } catch (\Exception) {
            $io->error(sprintf('Cannot parse "%s" as a period. Use a relative format like "7 days" or "12 hours".', $since));

            return Command::INVALID;
        }

        $limit = (int) $input->getOption('limit');
        $dryRun = (bool) $input->getOption('dry-run');

        if (true === $input->getOption('fraud-suspected')) {
            return $this->reportFraudSuspected($io, $createdSince, $limit);
        }

        $checked = $transitioned = $unchanged = $skipped = $errored = 0;

        foreach ($this->pendingPaymentProvider->findPending($createdSince, $limit) as $payment) {
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
                        ++$skipped;
                        $io->writeln(self::describe($payment, 'skipped, it is locked by a callback being handled or another run'));

                        break;
                }
            } catch (\Exception $e) {
                ++$errored;
                $io->warning(self::describe($payment, $e->getMessage()));
            }
        }

        $io->success(sprintf(
            '%d checked, %d transitioned%s, %d unchanged, %d skipped, %d errored',
            $checked,
            $transitioned,
            $dryRun ? ' (dry run)' : '',
            $unchanged,
            $skipped,
            $errored,
        ));

        return $errored > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Unlike reconciliation, which walks the local pending payments, this asks Quickpay directly:
     * every configured Quickpay gateway is queried for payments flagged fraud suspected within the
     * period, regardless of their local state. Report only — nothing is transitioned or persisted.
     * A gateway that can't be queried, its gateway configuration unusable included, is reported and
     * fails the run, so a gap in the report is never silent.
     */
    private function reportFraudSuspected(SymfonyStyle $io, \DateTimeImmutable $createdSince, int $limit): int
    {
        $rows = [];
        $errored = false;

        foreach ($this->gatewayConfigurationProvider->all() as $gatewayConfiguration) {
            try {
                $apiKey = $gatewayConfiguration->options()->apiKey;
                $payments = $this->clientFactory->create($apiKey)->payments()->paginate(new PaymentsQuery(
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

                    if (\count($rows) >= $limit) {
                        break 2;
                    }
                }
            } catch (UnusableGatewayConfiguration|QuickpayException $e) {
                $errored = true;
                $io->warning(sprintf('Gateway "%s": %s', $gatewayConfiguration->gatewayName, $e->getMessage()));
            }
        }

        if ([] === $rows) {
            $io->success('Quickpay reports no fraud suspected payments in the period.');
        } else {
            $io->table(['Gateway', 'Quickpay id', 'Order id', 'State', 'Created', 'Test mode'], $rows);
            $io->warning(sprintf('%d payment(s) flagged as fraud suspected. Review them in the Quickpay manager before capturing.', \count($rows)));
        }

        return $errored ? Command::FAILURE : Command::SUCCESS;
    }

    private static function describe(PaymentInterface $payment, string $message): string
    {
        $id = $payment->getId();

        return sprintf('Payment %s: %s', is_scalar($id) ? (string) $id : '?', $message);
    }
}
