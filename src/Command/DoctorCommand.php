<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Command;

use Setono\Payum\Quickpay\GatewayOptions;
use Setono\Quickpay\Callback\CallbackValidator;
use Setono\Quickpay\Client\ClientInterface;
use Setono\Quickpay\Exception\ForbiddenException;
use Setono\Quickpay\Exception\NotFoundException;
use Setono\Quickpay\Exception\UnauthorizedException;
use Setono\Quickpay\Request\Payment\CreateLinkRequest;
use Setono\Quickpay\Request\Payment\CreatePaymentRequest;
use Setono\SyliusQuickpayPlugin\GatewayConfiguration\GatewayConfiguration;
use Setono\SyliusQuickpayPlugin\GatewayConfiguration\GatewayConfigurationProviderInterface;
use Setono\SyliusQuickpayPlugin\GatewayConfiguration\UnusableGatewayConfiguration;
use Setono\SyliusQuickpayPlugin\Quickpay\GatewayCheck\GatewayChecksInterface;
use Setono\SyliusQuickpayPlugin\Quickpay\GatewayCheck\Verdict;
use Setono\SyliusQuickpayPlugin\Quickpay\GatewayCheck\VerdictOutcome;
use Setono\SyliusQuickpayPlugin\Quickpay\QuickpayClientProviderInterface;
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
 * gateway configuration module by the rules the gateway is built from, then the api key and
 * private key, the api user's permissions, the agreement, the order prefix, and the notify route.
 * Read-only unless --live is passed.
 */
#[AsCommand(
    name: 'setono:sylius-quickpay:doctor',
    description: 'Verifies the configuration of every Quickpay gateway against the Quickpay API',
)]
final class DoctorCommand extends Command
{
    /**
     * Quickpay limits the order id to 20 characters and Sylius order numbers use 9, so a longer
     * prefix eventually produces "order_id must have length between 4 and 20" at checkout
     */
    private const MAX_ORDER_PREFIX_LENGTH = 11;

    private int $failures = 0;

    public function __construct(
        private readonly QuickpayClientProviderInterface $clientProvider,
        private readonly GatewayChecksInterface $gatewayChecks,
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
            'Also probe the payment link permission by creating a test payment (leaves a harmless, money-less test payment on the account)',
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

        $this->checkDuplicateOrderPrefixes($io, $gateways);

        foreach ($gateways as [$gatewayConfiguration, $options]) {
            $io->section(sprintf('Gateway "%s"', $gatewayConfiguration->gatewayName));

            if ($options instanceof UnusableGatewayConfiguration) {
                $this->reportUnusable($io, $gatewayConfiguration, $options);

                continue;
            }

            $this->checkStoredOptions($io, $gatewayConfiguration);

            // The checks below that ask Quickpay need an api key it accepts, or their answers would prove nothing
            $apiKeyAccepted = $this->report(
                $io,
                $this->gatewayChecks->apiKey($gatewayConfiguration),
                'The api key is accepted by Quickpay',
                'Could not verify the api key',
            );
            $this->checkPrivateKey($io, $options->privateKey);
            $this->checkAgreement($io, $gatewayConfiguration, $options->agreementId, $apiKeyAccepted);
            $this->checkOrderPrefix($io, $options->orderPrefix);

            if ($apiKeyAccepted) {
                if ($live) {
                    $this->checkPaymentLinkPermission($io, $options->orderPrefix, $this->clientProvider->forGatewayConfiguration($gatewayConfiguration));
                } else {
                    $this->warn($io, 'Run with --live to probe the payment link permission (creates a harmless test payment)');
                }
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
     * One failed check per gateway option the gateway rejects, and the order prefix checks, which need no gateway.
     * The checks against Quickpay are skipped: the gateway can't be built, and their errors would only follow from
     * the configuration's.
     */
    private function reportUnusable(
        SymfonyStyle $io,
        GatewayConfiguration $gatewayConfiguration,
        UnusableGatewayConfiguration $unusable,
    ): void {
        // Nothing can be said about the stored options of an unreadable configuration
        if ($unusable->isUnreadable()) {
            $this->fail($io, sprintf('%s See the README\'s Configuration section.', $unusable->getMessage()));
        } else {
            foreach ($unusable->getViolations() as $violation) {
                $this->fail($io, $violation);
            }

            $this->checkStoredOptions($io, $gatewayConfiguration);

            $orderPrefix = self::orderPrefixOf($gatewayConfiguration);
            if (null !== $orderPrefix) {
                $this->checkOrderPrefix($io, $orderPrefix);
            }
        }

        $this->warn($io, 'Skipped the checks against Quickpay of this gateway — they need a gateway configuration the gateway can be built from');
    }

    /**
     * The order prefix the gateway sends, read even from a configuration the gateway rejects. Null when the
     * configuration is unreadable, or the gateway rejects the order prefix itself, which its violation reports.
     */
    private static function orderPrefixOf(GatewayConfiguration $gatewayConfiguration): ?string
    {
        try {
            $options = $gatewayConfiguration->optionsWithRejectedAsStored();
        } catch (UnusableGatewayConfiguration) {
            return null;
        }

        $orderPrefix = $options->values['order_prefix'] ?? null;

        return !$options->isRejected('order_prefix') && is_string($orderPrefix) ? $orderPrefix : null;
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
     * Two gateways sharing a prefix on the same account produce the same Quickpay order id for the
     * same order, and "order_id already exists" for whichever payment reaches Quickpay second. Compared across every
     * readable configuration, usable or not: an unusable one keeps its order prefix once it is fixed.
     *
     * @param list<array{GatewayConfiguration, GatewayOptions|UnusableGatewayConfiguration}> $gateways
     */
    private function checkDuplicateOrderPrefixes(SymfonyStyle $io, array $gateways): void
    {
        $byPrefix = [];
        foreach ($gateways as [$gatewayConfiguration]) {
            $orderPrefix = self::orderPrefixOf($gatewayConfiguration);
            if (null !== $orderPrefix && '' !== $orderPrefix) {
                $byPrefix[$orderPrefix][] = $gatewayConfiguration->gatewayName;
            }
        }

        foreach ($byPrefix as $prefix => $gatewayNames) {
            if (\count($gatewayNames) > 1) {
                $this->warn($io, sprintf(
                    'Gateways %s share the order prefix "%s" — their Quickpay order ids can collide',
                    implode(', ', array_map(static fn (string $gatewayName): string => sprintf('"%s"', $gatewayName), $gatewayNames)),
                    $prefix,
                ));
            }
        }
    }

    private function checkPrivateKey(SymfonyStyle $io, string $privateKey): void
    {
        // Exercise the sign/verify path the callbacks depend on; a sign-then-verify roundtrip with
        // the same key cannot report false, so the self-test's failure mode is an exception
        $validator = new CallbackValidator($privateKey);
        $payload = '{"doctor": "self-test"}';
        $validator->isValid($payload, $validator->sign($payload));

        $this->ok($io, 'The private key signs and verifies callbacks (only a real callback proves it matches the account)');
    }

    /**
     * @param int|null $agreementId the agreement id the gateway sends, null for none
     */
    private function checkAgreement(SymfonyStyle $io, GatewayConfiguration $gatewayConfiguration, ?int $agreementId, bool $apiKeyAccepted): void
    {
        if (null === $agreementId) {
            $this->ok($io, 'No agreement id configured — Quickpay uses the account\'s default Payment Window agreement');

            return;
        }

        if (!$apiKeyAccepted) {
            $this->warn($io, sprintf('Skipped verifying agreement %d — no usable api key', $agreementId));

            return;
        }

        try {
            $this->clientProvider->forGatewayConfiguration($gatewayConfiguration)->get(sprintf('agreements/%d', $agreementId));
        } catch (NotFoundException) {
            $this->fail($io, sprintf('Agreement %d does not exist on this Quickpay account', $agreementId));

            return;
        } catch (UnauthorizedException|ForbiddenException) {
            $this->warn($io, sprintf('Could not verify agreement %d — the api user may not have the /agreements permission', $agreementId));

            return;
        } catch (\Throwable $e) {
            $this->warn($io, sprintf('Could not verify agreement %d: %s', $agreementId, $e->getMessage()));

            return;
        }

        $this->ok($io, sprintf('Agreement %d exists on the account', $agreementId));
    }

    private function checkOrderPrefix(SymfonyStyle $io, string $prefix): void
    {
        if (\strlen($prefix) > self::MAX_ORDER_PREFIX_LENGTH) {
            $this->fail($io, sprintf(
                'The order prefix "%s" is %d characters — keep it to %d or less, or Quickpay rejects the order id at checkout',
                $prefix,
                \strlen($prefix),
                self::MAX_ORDER_PREFIX_LENGTH,
            ));

            return;
        }

        $this->ok($io, '' === $prefix
            ? 'No order prefix configured — make sure order numbers are unique on the Quickpay account'
            : sprintf('The order prefix "%s" is within Quickpay\'s length limit', $prefix));
    }

    /**
     * The one check that needs a write: create a money-less test payment and attempt the link PUT
     * the checkout depends on, surfacing the "Not authorized to PUT /payments/:id/link" case
     * before a customer does
     */
    private function checkPaymentLinkPermission(SymfonyStyle $io, string $orderPrefix, ClientInterface $client): void
    {
        $orderId = $orderPrefix . 'dr' . bin2hex(random_bytes(3));

        try {
            $payment = $client->payments()->create(new CreatePaymentRequest(orderId: $orderId, currency: 'DKK'));
        } catch (UnauthorizedException|ForbiddenException) {
            $this->fail($io, 'The api user may not create payments — check the POST permission for /payments (Settings → Users → User permissions)');

            return;
        } catch (\Throwable $e) {
            $this->warn($io, sprintf('Could not create a test payment: %s', $e->getMessage()));

            return;
        }

        try {
            $client->payments()->createLink($payment->id, new CreateLinkRequest(amount: 100));
            $this->ok($io, 'The api user may create payment links (PUT /payments/:id/link)');
        } catch (UnauthorizedException|ForbiddenException) {
            $this->fail($io, 'The api user may not create payment links — check the PUT checkbox for "Create or update payment link" (Settings → Users → User permissions)');
        } catch (\Throwable $e) {
            $this->warn($io, sprintf('Could not create a payment link: %s', $e->getMessage()));
        }

        try {
            $client->payments()->deleteLink($payment->id);
        } catch (\Throwable) {
            // Cleaning up the link is best effort; the test payment stays either way
        }

        $this->warn($io, sprintf('Test payment %d (order id "%s") remains on the account — it never carries money', $payment->id, $orderId));
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
