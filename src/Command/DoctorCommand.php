<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Command;

use Setono\Payum\Quickpay\Exception\InvalidOrderId;
use Setono\Payum\Quickpay\GatewayOptions;
use Setono\Payum\Quickpay\OrderId;
use Setono\SyliusQuickpayPlugin\GatewayConfiguration\GatewayConfiguration;
use Setono\SyliusQuickpayPlugin\GatewayConfiguration\GatewayConfigurationProviderInterface;
use Setono\SyliusQuickpayPlugin\GatewayConfiguration\UnusableGatewayConfiguration;
use Setono\SyliusQuickpayPlugin\OrderId\LatestOrderNumberProviderInterface;
use Setono\SyliusQuickpayPlugin\Quickpay\GatewayCheck\GatewayChecksInterface;
use Setono\SyliusQuickpayPlugin\Quickpay\GatewayCheck\Verdict;
use Setono\SyliusQuickpayPlugin\Quickpay\GatewayCheck\VerdictOutcome;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Routing\RouterInterface;

/**
 * Runs the checks that otherwise surface as support cases (see the README's Troubleshooting
 * section) against every configured Quickpay gateway: its gateway options, read through the
 * gateway configuration module by the rules the gateway is built from, then the api key, the api
 * user's permissions, the agreement, the order id the order prefix gives the latest order, and the
 * notify route. The private key can't be checked: the doctor says where a wrong one shows.
 * Read-only unless --live is passed.
 */
#[AsCommand(
    name: 'setono:sylius-quickpay:doctor',
    description: 'Verifies the configuration of every Quickpay gateway against the Quickpay API',
)]
final class DoctorCommand extends Command
{
    private int $failures = 0;

    public function __construct(
        private readonly GatewayChecksInterface $gatewayChecks,
        private readonly GatewayConfigurationProviderInterface $gatewayConfigurationProvider,
        private readonly RouterInterface $router,
        private readonly LatestOrderNumberProviderInterface $latestOrderNumberProvider,
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

        $gateways = [];
        foreach ($this->gatewayConfigurationProvider->all() as $gatewayConfiguration) {
            $gateways[] = [$gatewayConfiguration, self::optionsOrWhyUnusable($gatewayConfiguration)];
        }

        if ([] === $gateways) {
            $this->warn($io, 'No Quickpay payment methods are configured yet — nothing else to check');
        }

        // The order id check of every gateway uses the same order: the store numbers its orders one way
        $latestOrderNumber = [] === $gateways ? null : $this->latestOrderNumberProvider->getLatestOrderNumber();

        foreach ($gateways as [$gatewayConfiguration, $options]) {
            $io->section(sprintf('Gateway "%s"', $gatewayConfiguration->gatewayName));

            if ($options instanceof UnusableGatewayConfiguration) {
                $this->reportUnusable($io, $gatewayConfiguration, $options, $latestOrderNumber);

                continue;
            }

            $this->checkStoredOptions($io, $gatewayConfiguration);

            // The checks below that ask Quickpay need an api key it accepts, or their answers would prove nothing
            $apiKeyAccepted = $this->report(
                $io,
                $this->gatewayChecks->apiKey($gatewayConfiguration),
                'The api key is accepted by Quickpay',
                'Could not check the api key',
            );
            $this->hintAtPrivateKey($io);
            $this->checkAgreement($io, $gatewayConfiguration, $apiKeyAccepted);
            $this->checkOrderId($io, $options->orderPrefix, $latestOrderNumber);

            if ($live) {
                $this->checkPaymentLinkPermission($io, $gatewayConfiguration, $apiKeyAccepted);
            } elseif ($apiKeyAccepted) {
                $this->warn($io, 'Run with --live to check the payment link permission (creates a harmless test payment)');
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
     * The gateway options by the rules the gateway is built from, or why the gateway can't be built
     */
    private static function optionsOrWhyUnusable(GatewayConfiguration $gatewayConfiguration): GatewayOptions|UnusableGatewayConfiguration
    {
        try {
            return $gatewayConfiguration->options();
        } catch (UnusableGatewayConfiguration $e) {
            return $e;
        }
    }

    /**
     * One failed check per gateway option the gateway rejects, and the order id check, which needs no gateway.
     * The checks against Quickpay are skipped: the gateway can't be built, and their errors would only follow from
     * the configuration's.
     */
    private function reportUnusable(
        SymfonyStyle $io,
        GatewayConfiguration $gatewayConfiguration,
        UnusableGatewayConfiguration $unusable,
        ?string $latestOrderNumber,
    ): void {
        // Nothing can be said about the stored options of an unreadable configuration
        if ($unusable->isUnreadable()) {
            $this->fail($io, sprintf('%s See the README\'s Configuration section.', $unusable->getMessage()));
        } else {
            foreach ($unusable->getViolations() as $violation) {
                $this->fail($io, $violation);
            }

            $this->checkStoredOptions($io, $gatewayConfiguration);

            $orderPrefix = $gatewayConfiguration->orderPrefix();
            if (null !== $orderPrefix) {
                $this->checkOrderId($io, $orderPrefix, $latestOrderNumber);
            }
        }

        $this->warn($io, 'Skipped the checks against Quickpay of this gateway — they need a gateway configuration the gateway can be built from');
    }

    /**
     * What the stored configuration says that works but surprises people. Neither fails the run.
     */
    private function checkStoredOptions(SymfonyStyle $io, GatewayConfiguration $gatewayConfiguration): void
    {
        if ($gatewayConfiguration->usesDeprecatedOptions()) {
            $this->warn($io, 'The gateway configuration uses a deprecated gateway option (a 1.x option name, or auto_capture) — re-save the payment method in the admin to replace it before 3.0 stops reading it');
        }

        if (!$gatewayConfiguration->isCaptureModeSet()) {
            $this->warn($io, 'The capture mode is not set, so checkout captures immediately — choose a capture mode on the payment method in the admin');
        }
    }

    /**
     * Nothing the doctor may ask proves that the private key is the account's: Quickpay serves it only to an api user
     * allowed to read it, which a store's api user needn't be. A callback's checksum proves it, and the plugin answers
     * a wrong one with 400, which the operations panel shows as the callback's outcome.
     */
    private function hintAtPrivateKey(SymfonyStyle $io): void
    {
        $this->warn($io, 'Only a callback can confirm the private key — a callback answered 400 in an order\'s "Quickpay operations" panel means it isn\'t the account\'s (Settings → Integration in the Quickpay manager)');
    }

    /**
     * The agreement the gateway sends. Without one there is nothing to ask Quickpay, so that passes whatever the api
     * key's verdict.
     */
    private function checkAgreement(SymfonyStyle $io, GatewayConfiguration $gatewayConfiguration, bool $apiKeyAccepted): void
    {
        $agreementId = $gatewayConfiguration->options()->agreementId;

        if (null === $agreementId) {
            $this->ok($io, 'No agreement id configured — Quickpay uses the account\'s default Payment Window agreement');

            return;
        }

        if (!$apiKeyAccepted) {
            $this->warn($io, sprintf('Skipped checking agreement %d — no usable api key', $agreementId));

            return;
        }

        $this->report(
            $io,
            $this->gatewayChecks->agreement($gatewayConfiguration),
            sprintf('Agreement %d exists on the account', $agreementId),
            sprintf('Could not check agreement %d', $agreementId),
        );
    }

    /**
     * The Quickpay order id the order prefix gives the store's latest order, by payum-quickpay's rule
     * (`OrderId::of()`), so an order number format of the store's own counts too. Without an order yet, Sylius'
     * default first order number stands in.
     */
    private function checkOrderId(SymfonyStyle $io, string $orderPrefix, ?string $latestOrderNumber): void
    {
        $orderNumber = $latestOrderNumber ?? LatestOrderNumberProviderInterface::STAND_IN_ORDER_NUMBER;

        try {
            $orderId = OrderId::of($orderPrefix, $orderNumber);
        } catch (InvalidOrderId $e) {
            $this->fail($io, sprintf(
                '%s %s, and checkout fails until the two fit.',
                $e->getMessage(),
                null === $latestOrderNumber
                    ? 'There are no orders yet, so the number is Sylius\' default first order number'
                    : 'The number is the latest order\'s',
            ));

            return;
        }

        $this->ok($io, null === $latestOrderNumber
            ? sprintf('No orders yet: a Sylius order number like "%s" gives the order id "%s", which Quickpay accepts', $orderNumber, $orderId)
            : sprintf('The order id of the latest order, "%s", is one Quickpay accepts', $orderId));

        if ('' === $orderPrefix) {
            $this->ok($io, 'No order prefix configured — make sure order numbers are unique on the Quickpay account');
        }
    }

    /**
     * The one check that writes to the account (a money-less test payment, which the verdict's note names), surfacing
     * the "Not authorized to PUT /payments/:id/link" case before a customer does
     */
    private function checkPaymentLinkPermission(SymfonyStyle $io, GatewayConfiguration $gatewayConfiguration, bool $apiKeyAccepted): void
    {
        if (!$apiKeyAccepted) {
            $this->warn($io, 'Skipped checking the payment link permission — no usable api key');

            return;
        }

        $this->report(
            $io,
            $this->gatewayChecks->paymentLinkPermission($gatewayConfiguration),
            'The api user may create payment links',
            'Could not check the payment link permission',
        );
    }

    /**
     * Prints a gateway check's verdict: passed as ✔, failed as ✖ (failing the run) with its reason, undetermined as
     * a warning with its reason, each with the verdict's note
     *
     * @param string $passed what a pass means
     * @param string $undetermined what couldn't be found out, followed by the reason Quickpay's answer proves nothing
     *
     * @return bool whether the check passed
     */
    private function report(SymfonyStyle $io, Verdict $verdict, string $passed, string $undetermined): bool
    {
        $note = null === $verdict->note ? '' : sprintf(' (%s)', $verdict->note);

        if (VerdictOutcome::Failed === $verdict->outcome) {
            $this->fail($io, (string) $verdict->reason . $note);

            return false;
        }

        if (VerdictOutcome::Undetermined === $verdict->outcome) {
            $this->warn($io, sprintf('%s: %s%s', $undetermined, (string) $verdict->reason, $note));

            return false;
        }

        $this->ok($io, $passed . $note);

        return true;
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
