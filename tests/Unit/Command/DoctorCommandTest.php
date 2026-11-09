<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Tests\Unit\Command;

use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Setono\Quickpay\Client\Client;
use Setono\Quickpay\Client\ClientInterface;
use Setono\Quickpay\Exception\ForbiddenException;
use Setono\Quickpay\Exception\NotFoundException;
use Setono\SyliusQuickpayPlugin\Command\DoctorCommand;
use Setono\SyliusQuickpayPlugin\GatewayConfiguration\GatewayConfiguration;
use Setono\SyliusQuickpayPlugin\GatewayConfiguration\GatewayConfigurationProviderInterface;
use Setono\SyliusQuickpayPlugin\Quickpay\ApiKeyVerifier;
use Setono\SyliusQuickpayPlugin\Quickpay\ClientFactoryInterface;
use Setono\SyliusQuickpayPlugin\Tests\GatewayConfiguration\GatewayConfigurations;
use Setono\SyliusQuickpayPlugin\Tests\Quickpay\QueuedResponsesHttpClient;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\RouterInterface;

final class DoctorCommandTest extends TestCase
{
    use ProphecyTrait;

    /** @var ObjectProphecy<ClientFactoryInterface> */
    private ObjectProphecy $clientFactory;

    /** @var ObjectProphecy<GatewayConfigurationProviderInterface> */
    private ObjectProphecy $gatewayConfigurationProvider;

    /** @var ObjectProphecy<RouterInterface> */
    private ObjectProphecy $router;

    protected function setUp(): void
    {
        $this->clientFactory = $this->prophesize(ClientFactoryInterface::class);

        $this->gatewayConfigurationProvider = $this->prophesize(GatewayConfigurationProviderInterface::class);
        $this->gatewayConfigurationProvider->all()->willReturn([]);

        $this->router = $this->prophesize(RouterInterface::class);
        $this->router->getRouteCollection()->willReturn(self::routes(true));
    }

    /**
     * @test
     */
    public function it_passes_a_healthy_configuration(): void
    {
        $this->configureGateways(GatewayConfigurations::of(self::healthyConfig()));

        $client = $this->prophesize(ClientInterface::class);
        $client->ping()->willReturn(true);
        $this->clientFactory->create('the-api-key')->willReturn($client->reveal());

        $tester = $this->executeCommand();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('accepted by Quickpay', $tester->getDisplay());
        self::assertStringContainsString('All checks passed', $tester->getDisplay());
        self::assertStringNotContainsString('deprecated gateway option', $tester->getDisplay());
        self::assertStringNotContainsString('capture mode is not set', $tester->getDisplay());
    }

    /**
     * @test
     */
    public function it_fails_when_quickpay_rejects_the_api_key(): void
    {
        $this->configureGateways(GatewayConfigurations::of(self::healthyConfig()));

        $this->clientFactory->create('the-api-key')->willReturn(new Client('the-api-key', new QueuedResponsesHttpClient(
            new Response(401, [], '{"message": "Invalid API key"}'),   // ping
            new Response(401, [], '{"message": "Invalid API key"}'),   // the /payments fallback
        )));

        $tester = $this->executeCommand();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Quickpay rejects the api key', $tester->getDisplay());
    }

    /**
     * Quickpay answers 401 on /ping both for an invalid key and for a valid key whose api user
     * lacks the /ping permission (verified live), so the doctor falls back to a /payments read
     *
     * @test
     */
    public function it_accepts_an_api_key_whose_api_user_lacks_the_ping_permission(): void
    {
        $this->configureGateways(GatewayConfigurations::of(self::healthyConfig()));

        $this->clientFactory->create('the-api-key')->willReturn(new Client('the-api-key', new QueuedResponsesHttpClient(
            new Response(401, [], '{"message": "Invalid API key"}'),   // ping without the permission
            new Response(200, [], '[]'),                               // the /payments fallback
        )));

        $tester = $this->executeCommand();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('lacks the /ping permission', $tester->getDisplay());
    }

    /**
     * @test
     */
    public function it_fails_a_check_for_every_gateway_option_the_gateway_rejects(): void
    {
        // Each of these passed the doctor's own rules before: the agreement as id 12, the branding id unchecked
        $this->configureGateways(GatewayConfigurations::of([
            'private_key' => 'the-private-key',
            'agreement_id' => 12.0,
            'branding_id' => 'Our brand',
            'use_authorize' => true,
        ]));

        $tester = $this->executeCommand();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('The "api_key" option is required', $tester->getDisplay());
        self::assertStringContainsString('The "agreement_id" option must be a positive integer', $tester->getDisplay());
        self::assertStringContainsString('The "branding_id" option must be a positive integer', $tester->getDisplay());
        self::assertStringContainsString('3 check(s) failed', $tester->getDisplay());
    }

    /**
     * @test
     */
    public function it_fails_an_encrypted_gateway_configuration_as_unsupported(): void
    {
        $this->configureGateways(GatewayConfigurations::encrypted());

        $tester = $this->executeCommand();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('encrypting gateway configurations is not supported', $tester->getDisplay());
        self::assertStringContainsString('1 check(s) failed', $tester->getDisplay());
        // Its stored options can't be read, so nothing is said about them
        self::assertStringNotContainsString('capture mode', $tester->getDisplay());
    }

    /**
     * They say what is stored, whether or not the gateway can be built from it
     *
     * @test
     */
    public function it_warns_about_the_stored_options_of_an_invalid_gateway_configuration_too(): void
    {
        $this->configureGateways(GatewayConfigurations::of(['apikey' => 'the-api-key']));

        $tester = $this->executeCommand();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('The "private_key" option is required', $tester->getDisplay());
        self::assertStringContainsString('re-save the payment method', $tester->getDisplay());
        self::assertStringContainsString('checkout captures immediately', $tester->getDisplay());
        self::assertStringContainsString('1 check(s) failed', $tester->getDisplay());
    }

    /**
     * @test
     *
     * @dataProvider deprecatedGatewayOptions
     *
     * @param array<array-key, mixed> $config
     */
    public function it_warns_without_failing_when_a_deprecated_gateway_option_is_stored(array $config): void
    {
        $this->configureGateways(GatewayConfigurations::of($config));

        $client = $this->prophesize(ClientInterface::class);
        $client->ping()->willReturn(true);
        $this->clientFactory->create('the-api-key')->willReturn($client->reveal());

        $tester = $this->executeCommand();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('re-save the payment method', $tester->getDisplay());
    }

    /**
     * @return iterable<string, array{array<array-key, mixed>}>
     */
    public static function deprecatedGatewayOptions(): iterable
    {
        yield 'a 1.x option name' => [['apikey' => 'the-api-key', 'private_key' => 'the-private-key', 'use_authorize' => true]];
        yield 'auto_capture on' => [self::healthyConfig(['auto_capture' => true])];
    }

    /**
     * As fixtures and the Admin API may store it
     *
     * @test
     */
    public function it_warns_without_failing_when_the_capture_mode_is_not_set(): void
    {
        $this->configureGateways(GatewayConfigurations::of(GatewayConfigurations::USABLE));

        $client = $this->prophesize(ClientInterface::class);
        $client->ping()->willReturn(true);
        $this->clientFactory->create('the-api-key')->willReturn($client->reveal());

        $tester = $this->executeCommand();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('checkout captures immediately', $tester->getDisplay());
    }

    /**
     * Their errors would only follow from the configuration's
     *
     * @test
     */
    public function it_skips_the_checks_against_quickpay_for_an_unusable_gateway_configuration(): void
    {
        $this->configureGateways(GatewayConfigurations::of(['api_key' => 'the-api-key', 'agreement_id' => 12345, 'use_authorize' => true]));

        $this->clientFactory->create(Argument::any())->shouldNotBeCalled();

        $tester = $this->executeCommand(['--live' => true]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('The "private_key" option is required', $tester->getDisplay());
        self::assertStringContainsString('Skipped the other checks of this gateway', $tester->getDisplay());
        self::assertStringContainsString('1 check(s) failed', $tester->getDisplay());
    }

    /**
     * @test
     */
    public function it_verifies_a_configured_agreement(): void
    {
        $this->configureGateways(GatewayConfigurations::of(self::healthyConfig(['agreement_id' => 12345])));

        $client = $this->prophesize(ClientInterface::class);
        $client->ping()->willReturn(true);
        $client->get('agreements/12345')->willReturn(['id' => 12345]);
        $this->clientFactory->create('the-api-key')->willReturn($client->reveal());

        $tester = $this->executeCommand();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Agreement 12345 exists', $tester->getDisplay());
    }

    /**
     * @test
     */
    public function it_verifies_the_agreement_stored_under_its_1x_name(): void
    {
        $this->configureGateways(GatewayConfigurations::of(self::healthyConfig(['agreement_id' => null, 'agreement' => '7'])));

        $client = $this->prophesize(ClientInterface::class);
        $client->ping()->willReturn(true);
        $client->get('agreements/7')->shouldBeCalled()->willReturn(['id' => 7]);
        $this->clientFactory->create('the-api-key')->willReturn($client->reveal());

        $tester = $this->executeCommand();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Agreement 7 exists', $tester->getDisplay());
    }

    /**
     * The gateway sends no agreement id for a blank one, so Quickpay uses the default agreement
     *
     * @test
     */
    public function it_passes_a_blank_agreement_id_as_the_default_agreement(): void
    {
        $this->configureGateways(GatewayConfigurations::of(self::healthyConfig(['agreement_id' => '   '])));

        $client = $this->prophesize(ClientInterface::class);
        $client->ping()->willReturn(true);
        $client->get(Argument::any())->shouldNotBeCalled();
        $this->clientFactory->create('the-api-key')->willReturn($client->reveal());

        $tester = $this->executeCommand();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('default Payment Window agreement', $tester->getDisplay());
    }

    /**
     * @test
     */
    public function it_fails_when_the_configured_agreement_does_not_exist(): void
    {
        $this->configureGateways(GatewayConfigurations::of(self::healthyConfig(['agreement_id' => 12345])));

        $client = $this->prophesize(ClientInterface::class);
        $client->ping()->willReturn(true);
        $client->get('agreements/12345')->willThrow(new NotFoundException(new Response(404), 'Not found'));
        $this->clientFactory->create('the-api-key')->willReturn($client->reveal());

        $tester = $this->executeCommand();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Agreement 12345 does not exist', $tester->getDisplay());
    }

    /**
     * @test
     */
    public function it_fails_when_the_order_prefix_is_too_long(): void
    {
        $this->configureGateways(GatewayConfigurations::of(self::healthyConfig(['order_prefix' => 'qp_way_too_long_'])));

        $client = $this->prophesize(ClientInterface::class);
        $client->ping()->willReturn(true);
        $this->clientFactory->create('the-api-key')->willReturn($client->reveal());

        $tester = $this->executeCommand();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('keep it to 11 or less', $tester->getDisplay());
    }

    /**
     * @test
     */
    public function it_warns_when_two_gateways_share_an_order_prefix(): void
    {
        $this->configureGateways(
            GatewayConfigurations::of(self::healthyConfig(), 'quickpay_a'),
            GatewayConfigurations::of(self::healthyConfig(), 'quickpay_b'),
        );

        $client = $this->prophesize(ClientInterface::class);
        $client->ping()->willReturn(true);
        $this->clientFactory->create('the-api-key')->willReturn($client->reveal());

        $tester = $this->executeCommand();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('share the order prefix "qp_"', $tester->getDisplay());
    }

    /**
     * @test
     */
    public function it_fails_when_the_notify_route_is_not_registered(): void
    {
        $this->router->getRouteCollection()->willReturn(self::routes(false));

        $tester = $this->executeCommand();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('The notify route is not registered', $tester->getDisplay());
    }

    /**
     * @test
     */
    public function it_probes_the_payment_link_permission_in_live_mode(): void
    {
        $this->configureGateways(GatewayConfigurations::of(self::healthyConfig()));

        $this->clientFactory->create('the-api-key')->willReturn(new Client('the-api-key', new QueuedResponsesHttpClient(
            new Response(200, [], '{}'),                                    // ping
            new Response(201, [], (string) json_encode(self::payment())),   // create test payment
            new Response(200, [], '{"url": "https://payment.quickpay.net/x"}'), // link PUT
            new Response(204, [], ''),                                      // link cleanup
        )));

        $tester = $this->executeCommand(['--live' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('may create payment links', $tester->getDisplay());
        self::assertStringContainsString('Test payment 999', $tester->getDisplay());
    }

    /**
     * @test
     */
    public function it_fails_when_the_api_user_may_not_create_payment_links(): void
    {
        $this->configureGateways(GatewayConfigurations::of(self::healthyConfig()));

        $this->clientFactory->create('the-api-key')->willReturn(new Client('the-api-key', new QueuedResponsesHttpClient(
            new Response(200, [], '{}'),                                    // ping
            new Response(201, [], (string) json_encode(self::payment())),   // create test payment
            new Response(403, [], '{"message": "Not authorized"}'),         // link PUT rejected
            new Response(204, [], ''),                                      // link cleanup attempt
        )));

        $tester = $this->executeCommand(['--live' => true]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Create or update payment link', $tester->getDisplay());
    }

    /**
     * @test
     */
    public function it_warns_when_quickpay_does_not_answer_the_ping(): void
    {
        $this->configureGateways(GatewayConfigurations::of(self::healthyConfig()));

        $client = $this->prophesize(ClientInterface::class);
        $client->ping()->willThrow(new \RuntimeException('Connection timed out'));
        $this->clientFactory->create('the-api-key')->willReturn($client->reveal());

        $tester = $this->executeCommand();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Quickpay did not answer', $tester->getDisplay());
    }

    /**
     * @test
     */
    public function it_warns_when_quickpay_does_not_answer_the_payments_fallback(): void
    {
        $this->configureGateways(GatewayConfigurations::of(self::healthyConfig()));

        $this->clientFactory->create('the-api-key')->willReturn(new Client('the-api-key', new QueuedResponsesHttpClient(
            new Response(401, [], '{"message": "Invalid API key"}'),
            new Response(500, [], '{"message": "boom"}'),
        )));

        $tester = $this->executeCommand();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Quickpay did not answer', $tester->getDisplay());
    }

    /**
     * @test
     */
    public function it_skips_the_agreement_check_when_quickpay_rejects_the_api_key(): void
    {
        $this->configureGateways(GatewayConfigurations::of(self::healthyConfig(['agreement_id' => 12345])));

        $this->clientFactory->create('the-api-key')->willReturn(new Client('the-api-key', new QueuedResponsesHttpClient(
            new Response(401, [], '{"message": "Invalid API key"}'),   // ping
            new Response(401, [], '{"message": "Invalid API key"}'),   // the /payments fallback
        )));

        $tester = $this->executeCommand();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Skipped verifying agreement 12345', $tester->getDisplay());
    }

    /**
     * @test
     */
    public function it_warns_when_the_agreement_cannot_be_verified(): void
    {
        $this->configureGateways(GatewayConfigurations::of(self::healthyConfig(['agreement_id' => 12345])));

        $client = $this->prophesize(ClientInterface::class);
        $client->ping()->willReturn(true);
        $client->get('agreements/12345')->willThrow(new ForbiddenException(new Response(403), 'Not authorized'));
        $this->clientFactory->create('the-api-key')->willReturn($client->reveal());

        $tester = $this->executeCommand();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('/agreements permission', $tester->getDisplay());
    }

    /**
     * @test
     */
    public function it_warns_when_the_agreement_check_errors(): void
    {
        $this->configureGateways(GatewayConfigurations::of(self::healthyConfig(['agreement_id' => 12345])));

        $client = $this->prophesize(ClientInterface::class);
        $client->ping()->willReturn(true);
        $client->get('agreements/12345')->willThrow(new \RuntimeException('Connection timed out'));
        $this->clientFactory->create('the-api-key')->willReturn($client->reveal());

        $tester = $this->executeCommand();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Could not verify agreement 12345', $tester->getDisplay());
    }

    /**
     * @test
     */
    public function it_accepts_an_empty_order_prefix(): void
    {
        $this->configureGateways(GatewayConfigurations::of(['api_key' => 'the-api-key', 'private_key' => 'the-private-key']));

        $client = $this->prophesize(ClientInterface::class);
        $client->ping()->willReturn(true);
        $this->clientFactory->create('the-api-key')->willReturn($client->reveal());

        $tester = $this->executeCommand();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('No order prefix configured', $tester->getDisplay());
    }

    /**
     * @test
     */
    public function it_fails_when_the_api_user_may_not_create_payments(): void
    {
        $this->configureGateways(GatewayConfigurations::of(self::healthyConfig()));

        $this->clientFactory->create('the-api-key')->willReturn(new Client('the-api-key', new QueuedResponsesHttpClient(
            new Response(200, [], '{}'),
            new Response(403, [], '{"message": "Not authorized"}'),
        )));

        $tester = $this->executeCommand(['--live' => true]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('may not create payments', $tester->getDisplay());
    }

    /**
     * @test
     */
    public function it_warns_when_the_test_payment_cannot_be_created(): void
    {
        $this->configureGateways(GatewayConfigurations::of(self::healthyConfig()));

        $this->clientFactory->create('the-api-key')->willReturn(new Client('the-api-key', new QueuedResponsesHttpClient(
            new Response(200, [], '{}'),
            new Response(500, [], '{"message": "boom"}'),
        )));

        $tester = $this->executeCommand(['--live' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Could not create a test payment', $tester->getDisplay());
    }

    /**
     * @test
     */
    public function it_warns_when_the_link_probe_errors(): void
    {
        $this->configureGateways(GatewayConfigurations::of(self::healthyConfig()));

        $this->clientFactory->create('the-api-key')->willReturn(new Client('the-api-key', new QueuedResponsesHttpClient(
            new Response(200, [], '{}'),
            new Response(201, [], (string) json_encode(self::payment())),
            new Response(500, [], '{"message": "boom"}'),
            new Response(204, [], ''),
        )));

        $tester = $this->executeCommand(['--live' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Could not create a payment link', $tester->getDisplay());
        self::assertStringContainsString('Test payment 999', $tester->getDisplay());
    }

    /**
     * @test
     */
    public function it_ignores_a_failing_link_cleanup(): void
    {
        $this->configureGateways(GatewayConfigurations::of(self::healthyConfig()));

        $this->clientFactory->create('the-api-key')->willReturn(new Client('the-api-key', new QueuedResponsesHttpClient(
            new Response(200, [], '{}'),
            new Response(201, [], (string) json_encode(self::payment())),
            new Response(200, [], '{"url": "https://payment.quickpay.net/x"}'),
            new Response(500, [], '{"message": "boom"}'),
        )));

        $tester = $this->executeCommand(['--live' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('may create payment links', $tester->getDisplay());
    }

    /**
     * @test
     */
    public function it_warns_when_no_quickpay_gateway_is_configured(): void
    {
        $tester = $this->executeCommand();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('No Quickpay payment methods are configured', $tester->getDisplay());
    }

    private function configureGateways(GatewayConfiguration ...$configurations): void
    {
        $this->gatewayConfigurationProvider->all()->willReturn($configurations);
    }

    /**
     * @param array<array-key, mixed> $overrides
     *
     * @return array<array-key, mixed>
     */
    private static function healthyConfig(array $overrides = []): array
    {
        return $overrides + GatewayConfigurations::USABLE + [
            'order_prefix' => 'qp_',
            'use_authorize' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function payment(): array
    {
        return [
            'id' => 999,
            'order_id' => 'qp_dr123456',
            'currency' => 'DKK',
            'state' => 'initial',
            'merchant_id' => 1,
        ];
    }

    private static function routes(bool $withNotifyRoute): RouteCollection
    {
        $routes = new RouteCollection();

        if ($withNotifyRoute) {
            $routes->add('setono_sylius_quickpay_notify', new Route('/payment/quickpay/notify'));
        }

        return $routes;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function executeCommand(array $input = []): CommandTester
    {
        $application = new Application();
        // A real verifier over the same client factory keeps the probe part of what is tested
        $application->add(new DoctorCommand(
            $this->clientFactory->reveal(),
            new ApiKeyVerifier($this->clientFactory->reveal()),
            $this->gatewayConfigurationProvider->reveal(),
            $this->router->reveal(),
        ));

        $tester = new CommandTester($application->find('setono:sylius-quickpay:doctor'));
        $tester->execute($input);

        return $tester;
    }
}
