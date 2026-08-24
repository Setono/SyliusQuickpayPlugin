<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Command;

use Setono\Payum\Quickpay\QuickpayGatewayFactory;
use Setono\Quickpay\Callback\CallbackValidator;
use Setono\Quickpay\Client\ClientInterface;
use Setono\Quickpay\Exception\ForbiddenException;
use Setono\Quickpay\Exception\NotFoundException;
use Setono\Quickpay\Exception\UnauthorizedException;
use Setono\Quickpay\Request\Payment\CreateLinkRequest;
use Setono\Quickpay\Request\Payment\CreatePaymentRequest;
use Setono\SyliusQuickpayPlugin\Quickpay\ApiKeyResolver;
use Setono\SyliusQuickpayPlugin\Quickpay\ApiKeyVerifierInterface;
use Setono\SyliusQuickpayPlugin\Quickpay\ClientFactoryInterface;
use Sylius\Bundle\PayumBundle\Model\GatewayConfigInterface;
use Sylius\Component\Resource\Repository\RepositoryInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Routing\RouterInterface;

/**
 * Runs the checks that otherwise surface as support cases (see the README's Troubleshooting
 * section) against every configured Quickpay gateway: credentials, permissions, agreement,
 * order prefix, and the notify route. Read-only unless --live is passed.
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

    /**
     * @param RepositoryInterface<GatewayConfigInterface> $gatewayConfigRepository
     */
    public function __construct(
        private readonly ClientFactoryInterface $clientFactory,
        private readonly ApiKeyVerifierInterface $apiKeyVerifier,
        private readonly RepositoryInterface $gatewayConfigRepository,
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

        /** @var list<GatewayConfigInterface> $gatewayConfigs */
        $gatewayConfigs = $this->gatewayConfigRepository->findBy(['factoryName' => QuickpayGatewayFactory::NAME]);

        if ([] === $gatewayConfigs) {
            $this->warn($io, 'No Quickpay payment methods are configured yet — nothing else to check');
        }

        $this->checkDuplicateOrderPrefixes($io, $gatewayConfigs);

        foreach ($gatewayConfigs as $gatewayConfig) {
            $io->section(sprintf('Gateway "%s"', (string) $gatewayConfig->getGatewayName()));

            $config = $gatewayConfig->getConfig();

            $client = $this->checkApiKey($io, $config);
            $this->checkPrivateKey($io, $config);
            $this->checkAgreement($io, $config, $client);
            $this->checkOrderPrefix($io, $config);

            if (null !== $client) {
                if ($live) {
                    $this->checkPaymentLinkPermission($io, $config, $client);
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
     * Two gateways sharing a prefix on the same account produce the same Quickpay order id for the
     * same order, and "order_id already exists" for whichever payment reaches Quickpay second
     *
     * @param list<GatewayConfigInterface> $gatewayConfigs
     */
    private function checkDuplicateOrderPrefixes(SymfonyStyle $io, array $gatewayConfigs): void
    {
        $byPrefix = [];
        foreach ($gatewayConfigs as $gatewayConfig) {
            $prefix = self::orderPrefix($gatewayConfig->getConfig());
            if ('' !== $prefix) {
                $byPrefix[$prefix][] = (string) $gatewayConfig->getGatewayName();
            }
        }

        foreach ($byPrefix as $prefix => $gateways) {
            if (\count($gateways) > 1) {
                $this->warn($io, sprintf(
                    'Gateways %s share the order prefix "%s" — their Quickpay order ids can collide',
                    implode(', ', array_map(static fn (string $gateway): string => sprintf('"%s"', $gateway), $gateways)),
                    $prefix,
                ));
            }
        }
    }

    /**
     * @param array<array-key, mixed> $config
     */
    private function checkApiKey(SymfonyStyle $io, array $config): ?ClientInterface
    {
        $apiKey = ApiKeyResolver::fromGatewayConfig($config);
        if (null === $apiKey) {
            $this->fail($io, 'No api key is configured');

            return null;
        }

        try {
            $pingPermission = $this->apiKeyVerifier->verify($apiKey);
        } catch (UnauthorizedException|ForbiddenException) {
            $this->fail($io, 'Quickpay rejects the api key — use the API user\'s key (Settings → Users in the Quickpay manager), not a Payment Window agreement\'s');

            return null;
        } catch (\Throwable $e) {
            $this->warn($io, sprintf('Could not verify the api key, Quickpay did not answer: %s', $e->getMessage()));

            return null;
        }

        $this->ok($io, $pingPermission
            ? 'The api key is accepted by Quickpay'
            : 'The api key is accepted by Quickpay (verified via /payments — the api user lacks the /ping permission, which is harmless)');

        return $this->clientFactory->create($apiKey);
    }

    /**
     * @param array<array-key, mixed> $config
     */
    private function checkPrivateKey(SymfonyStyle $io, array $config): void
    {
        // Configurations written by the 1.x form may still carry the old key
        $privateKey = $config['private_key'] ?? $config['privatekey'] ?? null;

        if (!is_string($privateKey) || '' === $privateKey) {
            $this->fail($io, 'No private key is configured — every callback would be rejected as unsigned');

            return;
        }

        // Exercise the sign/verify path the callbacks depend on; a sign-then-verify roundtrip with
        // the same key cannot report false, so the self-test's failure mode is an exception
        $validator = new CallbackValidator($privateKey);
        $payload = '{"doctor": "self-test"}';
        $validator->isValid($payload, $validator->sign($payload));

        $this->ok($io, 'The private key signs and verifies callbacks (only a real callback proves it matches the account)');
    }

    /**
     * @param array<array-key, mixed> $config
     */
    private function checkAgreement(SymfonyStyle $io, array $config, ?ClientInterface $client): void
    {
        // Configurations written by the 1.x form may still carry the old key
        $agreementId = $config['agreement_id'] ?? $config['agreement'] ?? null;

        if (null === $agreementId || '' === $agreementId) {
            $this->ok($io, 'No agreement id configured — Quickpay uses the account\'s default Payment Window agreement');

            return;
        }

        if (!is_numeric($agreementId)) {
            $this->fail($io, sprintf('The configured agreement id is not a number (got "%s")', get_debug_type($agreementId)));

            return;
        }

        if (null === $client) {
            $this->warn($io, sprintf('Skipped verifying agreement %d — no usable api key', (int) $agreementId));

            return;
        }

        try {
            $client->get(sprintf('agreements/%d', (int) $agreementId));
        } catch (NotFoundException) {
            $this->fail($io, sprintf('Agreement %d does not exist on this Quickpay account', (int) $agreementId));

            return;
        } catch (UnauthorizedException|ForbiddenException) {
            $this->warn($io, sprintf('Could not verify agreement %d — the api user may not have the /agreements permission', (int) $agreementId));

            return;
        } catch (\Throwable $e) {
            $this->warn($io, sprintf('Could not verify agreement %d: %s', (int) $agreementId, $e->getMessage()));

            return;
        }

        $this->ok($io, sprintf('Agreement %d exists on the account', (int) $agreementId));
    }

    /**
     * @param array<array-key, mixed> $config
     */
    private function checkOrderPrefix(SymfonyStyle $io, array $config): void
    {
        $prefix = self::orderPrefix($config);

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
     *
     * @param array<array-key, mixed> $config
     */
    private function checkPaymentLinkPermission(SymfonyStyle $io, array $config, ClientInterface $client): void
    {
        $orderId = self::orderPrefix($config) . 'dr' . bin2hex(random_bytes(3));

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
     * @param array<array-key, mixed> $config
     */
    private static function orderPrefix(array $config): string
    {
        $prefix = $config['order_prefix'] ?? null;

        return is_string($prefix) ? $prefix : '';
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
