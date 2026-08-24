<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Command;

use Doctrine\Persistence\ManagerRegistry;
use Payum\Core\Exception\ExceptionInterface;
use Payum\Core\Payum;
use Payum\Core\Request\GetHumanStatus;
use Setono\Doctrine\ORMTrait;
use Setono\Payum\Quickpay\QuickpayGatewayFactory;
use Setono\Quickpay\Exception\QuickpayException;
use Setono\Quickpay\Request\Payment\PaymentsQuery;
use Setono\SyliusQuickpayPlugin\Provider\PendingPaymentProviderInterface;
use Setono\SyliusQuickpayPlugin\Quickpay\ApiKeyResolver;
use Setono\SyliusQuickpayPlugin\Quickpay\ClientFactoryInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\PayumBundle\Model\GatewayConfigInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Payment\PaymentTransitions;
use Sylius\Component\Resource\Repository\RepositoryInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The Quickpay callback is normally the only path by which a payment learns about a state
 * change at Quickpay. This command closes the gap left by callbacks that never arrive: it
 * polls Quickpay for payments stuck in a non-final state and applies the matching transition.
 */
#[AsCommand(
    name: 'setono:sylius-quickpay:reconcile-payments',
    description: 'Polls Quickpay for payments stuck in a non-final state and applies the matching payment transition',
)]
final class ReconcilePaymentsCommand extends Command
{
    use ORMTrait;

    /**
     * @param RepositoryInterface<GatewayConfigInterface> $gatewayConfigRepository
     */
    public function __construct(
        private readonly PendingPaymentProviderInterface $pendingPaymentProvider,
        private readonly Payum $payum,
        private readonly StateMachineInterface $stateMachine,
        private readonly ClientFactoryInterface $clientFactory,
        private readonly RepositoryInterface $gatewayConfigRepository,
        ManagerRegistry $managerRegistry,
    ) {
        parent::__construct();

        $this->managerRegistry = $managerRegistry;
    }

    protected function configure(): void
    {
        $this
            ->addOption('since', null, InputOption::VALUE_REQUIRED, 'Only reconcile payments created within this period', '7 days')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum number of payments to check', '100')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would happen without applying any transition')
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

        $checked = $transitioned = $unchanged = $errored = 0;

        foreach ($this->pendingPaymentProvider->findPending($createdSince, $limit) as $payment) {
            ++$checked;

            try {
                $gateway = $this->payum->getGateway(self::resolveGatewayName($payment));
                $gateway->execute($status = new GetHumanStatus($payment));

                $transition = self::resolveTransition($status);
                if (null === $transition || !$this->stateMachine->can($payment, PaymentTransitions::GRAPH, $transition)) {
                    ++$unchanged;
                } elseif ($dryRun) {
                    ++$transitioned;
                    $io->writeln(self::describe($payment, sprintf('would apply "%s" (Quickpay status "%s")', $transition, self::statusValue($status))));
                } else {
                    $this->stateMachine->apply($payment, PaymentTransitions::GRAPH, $transition);

                    ++$transitioned;
                    $io->writeln(self::describe($payment, sprintf('applied "%s" (Quickpay status "%s")', $transition, self::statusValue($status))));
                }
            } catch (ExceptionInterface|QuickpayException $e) {
                ++$errored;
                $io->warning(self::describe($payment, $e->getMessage()));
            }

            // The status poll writes re-fetched details (e.g. the Quickpay balance) back onto the
            // payment even when no transition applies, so every checked payment is persisted
            if (!$dryRun) {
                $this->getManager($payment)->flush();
            }
        }

        $io->success(sprintf(
            '%d checked, %d transitioned%s, %d unchanged, %d errored',
            $checked,
            $transitioned,
            $dryRun ? ' (dry run)' : '',
            $unchanged,
            $errored,
        ));

        return $errored > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Unlike reconciliation, which walks the local pending payments, this asks Quickpay directly:
     * every configured Quickpay gateway is queried for payments flagged fraud suspected within the
     * period, regardless of their local state. Report only — nothing is transitioned or persisted.
     */
    private function reportFraudSuspected(SymfonyStyle $io, \DateTimeImmutable $createdSince, int $limit): int
    {
        $rows = [];
        $errored = false;

        /** @var GatewayConfigInterface $gatewayConfig */
        foreach ($this->gatewayConfigRepository->findBy(['factoryName' => QuickpayGatewayFactory::NAME]) as $gatewayConfig) {
            $apiKey = ApiKeyResolver::fromGatewayConfig($gatewayConfig->getConfig());
            if (null === $apiKey) {
                continue;
            }

            try {
                $payments = $this->clientFactory->create($apiKey)->payments()->paginate(new PaymentsQuery(
                    minTime: $createdSince,
                    fraudSuspected: true,
                    sortBy: 'created_at',
                    sortDir: 'desc',
                ));

                foreach ($payments as $payment) {
                    $rows[] = [
                        (string) $gatewayConfig->getGatewayName(),
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
            } catch (QuickpayException $e) {
                $errored = true;
                $io->warning(sprintf('Gateway "%s": %s', (string) $gatewayConfig->getGatewayName(), $e->getMessage()));
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

    private static function resolveGatewayName(PaymentInterface $payment): string
    {
        /** @var PaymentMethodInterface $method */
        $method = $payment->getMethod();

        /** @var GatewayConfigInterface $gatewayConfig */
        $gatewayConfig = $method->getGatewayConfig();

        return $gatewayConfig->getGatewayName();
    }

    /**
     * The transitions it maps to are exactly the ones the notify flow would have applied had
     * the callback arrived; anything Quickpay reports as pending or unknown is left untouched.
     */
    private static function resolveTransition(GetHumanStatus $status): ?string
    {
        return match (true) {
            $status->isCaptured() => PaymentTransitions::TRANSITION_COMPLETE,
            $status->isAuthorized() => PaymentTransitions::TRANSITION_AUTHORIZE,
            $status->isRefunded() => PaymentTransitions::TRANSITION_REFUND,
            $status->isCanceled() => PaymentTransitions::TRANSITION_CANCEL,
            $status->isFailed(), $status->isExpired() => PaymentTransitions::TRANSITION_FAIL,
            default => null,
        };
    }

    private static function describe(PaymentInterface $payment, string $message): string
    {
        $id = $payment->getId();

        return sprintf('Payment %s: %s', is_scalar($id) ? (string) $id : '?', $message);
    }

    private static function statusValue(GetHumanStatus $status): string
    {
        $value = $status->getValue();

        return is_scalar($value) ? (string) $value : 'unknown';
    }
}
