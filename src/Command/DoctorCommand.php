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
use Setono\SyliusQuickpayPlugin\Quickpay\ApiKeyVerification;
use Setono\SyliusQuickpayPlugin\Quickpay\ApiKeyVerifierInterface;
use Setono\SyliusQuickpayPlugin\Quickpay\ClientFactoryInterface;
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
        private readonly ClientFactoryInterface $clientFactory,
        private readonly ApiKeyVerifierInterface $apiKeyVerifier,
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

            $client = $this->checkApiKey($io, $options->apiKey);
            $this->checkPrivateKey($io, $options->privateKey);
            $this->checkAgreement($io, $options->agreementId, $client);
            $this->checkOrderPrefix($io, $options->orderPrefix);

            if (null !== $client) {
                if ($live) {
                    $this->checkPaymentLinkPermission($io, $options->orderPrefix, $client);
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
     * One failed check per gateway option the gateway rejects. The checks against Quickpay are skipped: the
     * gateway can't be built, and their errors would only follow from the configuration's.
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
        }

        $this->warn($io, 'Skipped the other checks of this gateway — they need a gateway configuration the gateway can be built from');
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
     * same order, and "order_id already exists" for whichever payment reaches Quickpay second
     *
     * @param list<array{GatewayConfiguration, GatewayOptions|UnusableGatewayConfiguration}> $gateways
     */
    private function checkDuplicateOrderPrefixes(SymfonyStyle $io, array $gateways): void
    {
        $byPrefix = [];
        foreach ($gateways as [$gatewayConfiguration, $options]) {
            if ($options instanceof GatewayOptions && '' !== $options->orderPrefix) {
                $byPrefix[$options->orderPrefix][] = $gatewayConfiguration->gatewayName;
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

    private function checkApiKey(SymfonyStyle $io, string $apiKey): ?ClientInterface
    {
        try {
            $verification = $this->apiKeyVerifier->verify($apiKey);
        } catch (UnauthorizedException|ForbiddenException) {
            $this->fail($io, 'Quickpay rejects the api key — use the API user\'s key (Settings → Users in the Quickpay manager), not a Payment Window agreement\'s');

            return null;
        } catch (\Throwable $e) {
            $this->warn($io, sprintf('Could not verify the api key, Quickpay did not answer: %s', $e->getMessage()));

            return null;
        }

        $this->ok($io, match ($verification) {
            ApiKeyVerification::ViaPing => 'The api key is accepted by Quickpay',
            ApiKeyVerification::ViaPayments => 'The api key is accepted by Quickpay (verified via /payments — the api user lacks the /ping permission, which is harmless)',
        });

        return $this->clientFactory->create($apiKey);
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
    private function checkAgreement(SymfonyStyle $io, ?int $agreementId, ?ClientInterface $client): void
    {
        if (null === $agreementId) {
            $this->ok($io, 'No agreement id configured — Quickpay uses the account\'s default Payment Window agreement');

            return;
        }

        if (null === $client) {
            $this->warn($io, sprintf('Skipped verifying agreement %d — no usable api key', $agreementId));

            return;
        }

        try {
            $client->get(sprintf('agreements/%d', $agreementId));
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
