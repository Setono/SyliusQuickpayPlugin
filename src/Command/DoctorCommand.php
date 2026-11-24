<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Command;

use Setono\SyliusQuickpayPlugin\Diagnosis\DiagnoserInterface;
use Setono\SyliusQuickpayPlugin\Diagnosis\FindingOutcome;
use Setono\SyliusQuickpayPlugin\GatewayConfiguration\GatewayConfigurationProviderInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Routing\RouterInterface;

/**
 * Runs the checks that otherwise surface as support cases (see the README's Troubleshooting section): the notify
 * route, then the diagnosis of every configured Quickpay gateway (its gateway options by the rules the gateway is built
 * from, the order id its order prefix gives the latest order, the api key, the agreement and, with --live, the payment
 * link permission). The private key can't be checked: the doctor says where a wrong one shows. Read-only unless --live
 * is passed.
 */
#[AsCommand(
    name: 'setono:sylius-quickpay:doctor',
    description: 'Verifies the configuration of every Quickpay gateway against the Quickpay API',
)]
final class DoctorCommand extends Command
{
    private int $failures = 0;

    public function __construct(
        private readonly DiagnoserInterface $diagnoser,
        private readonly GatewayConfigurationProviderInterface $gatewayConfigurationProvider,
        private readonly RouterInterface $router,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'live',
            null,
            InputOption::VALUE_NONE,
            'Also check the payment link permission by creating a test payment (leaves a harmless, money-less test payment on the account)',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $live = (bool) $input->getOption('live');
        $this->failures = 0;

        $this->checkNotifyRoute($io);

        $gatewayConfigurations = $this->gatewayConfigurationProvider->all();

        if ([] === $gatewayConfigurations) {
            $this->warn($io, 'No Quickpay payment methods are configured yet — nothing else to check');
        } else {
            $this->hint($io, $live);
        }

        foreach ($gatewayConfigurations as $gatewayConfiguration) {
            $io->section(sprintf('Gateway "%s"', $gatewayConfiguration->gatewayName));

            foreach ($this->diagnoser->diagnose($gatewayConfiguration, $live) as $finding) {
                match ($finding->outcome) {
                    FindingOutcome::Passed => $this->ok($io, $finding->text),
                    FindingOutcome::Failed => $this->fail($io, $finding->text),
                    FindingOutcome::Warning => $this->warn($io, $finding->text),
                };
            }
        }

        if ($this->failures > 0) {
            $io->error(sprintf('%d check(s) failed.', $this->failures));

            return Command::FAILURE;
        }

        $io->success('All checks passed.');

        return Command::SUCCESS;
    }

    private function checkNotifyRoute(SymfonyStyle $io): void
    {
        $route = $this->router->getRouteCollection()->get('setono_sylius_quickpay_notify');

        if (null === $route) {
            $this->fail($io, 'The notify route is not registered — import "@SetonoSyliusQuickpayPlugin/Resources/config/routes.yaml" (installation step 4 in the README)');

            return;
        }

        $this->ok($io, sprintf('The notify endpoint for operations made outside the store is registered at "%s"', $route->getPath()));
    }

    /**
     * What the doctor can't check, said once for every gateway. Nothing the doctor may ask proves that the private key
     * is the account's: Quickpay serves it only to an api user allowed to read it, which a store's api user needn't
     * be. A callback's checksum proves it, and the plugin answers a wrong one with 400, which the operations panel
     * shows as the callback's outcome. The payment link permission is checked only on request, since it writes to the
     * account.
     */
    private function hint(SymfonyStyle $io, bool $live): void
    {
        $this->warn($io, 'Only a callback can confirm the private key — a callback answered 400 in an order\'s "Quickpay operations" panel means it isn\'t the account\'s (Settings → Integration in the Quickpay manager)');

        if (!$live) {
            $this->warn($io, 'Run with --live to check the payment link permission (creates a harmless test payment)');
        }
    }

    private function ok(SymfonyStyle $io, string $message): void
    {
        $io->writeln(sprintf(' <info>✔</info> %s', $message));
    }

    private function warn(SymfonyStyle $io, string $message): void
    {
        $io->writeln(sprintf(' <comment>!</comment> %s', $message));
    }

    private function fail(SymfonyStyle $io, string $message): void
    {
        ++$this->failures;

        $io->writeln(sprintf(' <error>✖</error> %s', $message));
    }
}
