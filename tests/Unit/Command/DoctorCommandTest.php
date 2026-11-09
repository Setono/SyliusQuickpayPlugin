<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Tests\Unit\Command;

use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Setono\SyliusQuickpayPlugin\Command\DoctorCommand;
use Setono\SyliusQuickpayPlugin\GatewayConfiguration\GatewayConfiguration;
use Setono\SyliusQuickpayPlugin\GatewayConfiguration\GatewayConfigurationProviderInterface;
use Setono\SyliusQuickpayPlugin\Quickpay\GatewayCheck\GatewayChecksInterface;
use Setono\SyliusQuickpayPlugin\Quickpay\GatewayCheck\Verdict;
use Setono\SyliusQuickpayPlugin\Tests\GatewayConfiguration\GatewayConfigurations;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\RouterInterface;

final class DoctorCommandTest extends TestCase
{
    use ProphecyTrait;

    private const TEST_PAYMENT_NOTE = 'test payment 999 with order id "qp_dr1a2b3c" remains on the account — it never carries money';

    /** @var ObjectProphecy<GatewayChecksInterface> */
    private ObjectProphecy $gatewayChecks;

    /** @var ObjectProphecy<GatewayConfigurationProviderInterface> */
    private ObjectProphecy $gatewayConfigurationProvider;

    /** @var ObjectProphecy<RouterInterface> */
    private ObjectProphecy $router;

    protected function setUp(): void
    {
        $this->gatewayChecks = $this->prophesize(GatewayChecksInterface::class);
        $this->gatewayChecks->apiKey(Argument::any())->willReturn(Verdict::passed());
        $this->gatewayChecks->agreement(Argument::any())->willReturn(Verdict::passed());

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

        $tester = $this->executeCommand();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('All checks passed', $tester->getDisplay());
        self::assertStringNotContainsString('deprecated gateway option', $tester->getDisplay());
        self::assertStringNotContainsString('capture mode is not set', $tester->getDisplay());
    }

    /**
     * @test
     */
    public function it_runs_the_api_key_check_on_each_usable_gateway_configuration(): void
    {
        $configuration = GatewayConfigurations::of(self::healthyConfig(), 'quickpay_dk');
        $this->configureGateways($configuration);

        $this->gatewayChecks->apiKey($configuration)->shouldBeCalledOnce()->willReturn(Verdict::passed());

        $this->executeCommand();
    }

    /**
     * @test
     */
    public function it_prints_a_passed_api_key_check(): void
    {
        $this->configureGateways(GatewayConfigurations::of(self::healthyConfig()));

        $tester = $this->executeCommand();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertDisplayHasLine('✔ The api key is accepted by Quickpay', $tester);
    }

    /**
     * @test
     */
    public function it_prints_a_passed_api_key_check_with_its_note(): void
    {
        $this->configureGateways(GatewayConfigurations::of(self::healthyConfig()));
        $this->gatewayChecks->apiKey(Argument::any())->willReturn(Verdict::passed('verified through /payments: the api user lacks the /ping permission, which is harmless'));

        $tester = $this->executeCommand();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertDisplayHasLine('✔ The api key is accepted by Quickpay (verified through /payments: the api user lacks the /ping permission, which is harmless)', $tester);
    }

    /**
     * @test
     */
    public function it_fails_on_a_failed_api_key_check(): void
    {
        $this->configureGateways(GatewayConfigurations::of(self::healthyConfig()));
        $this->gatewayChecks->apiKey(Argument::any())->willReturn(Verdict::failed('Quickpay rejects the api key'));

        $tester = $this->executeCommand();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertDisplayHasLine('✖ Quickpay rejects the api key', $tester);
        self::assertStringContainsString('1 check(s) failed', $tester->getDisplay());
    }

    /**
     * Quickpay's answer proved nothing, so nothing is known to be wrong
     *
     * @test
     */
    public function it_warns_without_failing_on_an_undetermined_api_key_check(): void
    {
        $this->configureGateways(GatewayConfigurations::of(self::healthyConfig()));
        $this->gatewayChecks->apiKey(Argument::any())->willReturn(Verdict::undetermined('The request could not be sent [GET https://api.quickpay.net/ping]: Idle timeout reached'));

        $tester = $this->executeCommand();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertDisplayHasLine('! Could not verify the api key: The request could not be sent [GET https://api.quickpay.net/ping]: Idle timeout reached', $tester);
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
        self::assertStringContainsString('reads as empty, as an encrypted one does before Payum decrypts it. Encrypting gateway configurations is not supported.', $tester->getDisplay());
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

        $this->gatewayChecks->apiKey(Argument::any())->shouldNotBeCalled();
        $this->gatewayChecks->agreement(Argument::any())->shouldNotBeCalled();
        $this->gatewayChecks->paymentLinkPermission(Argument::any())->shouldNotBeCalled();

        $tester = $this->executeCommand(['--live' => true]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('The "private_key" option is required', $tester->getDisplay());
        self::assertStringContainsString('Skipped the checks against Quickpay of this gateway', $tester->getDisplay());
        self::assertStringContainsString('1 check(s) failed', $tester->getDisplay());
    }

    /**
     * The order prefix checks need no gateway, only the order prefix
     *
     * @test
     */
    public function it_checks_the_order_prefix_of_an_unusable_gateway_configuration(): void
    {
        $this->configureGateways(GatewayConfigurations::of(['api_key' => 'the-api-key', 'order_prefix' => 'qp_way_too_long_', 'use_authorize' => true]));

        $tester = $this->executeCommand();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('The "private_key" option is required', $tester->getDisplay());
        self::assertStringContainsString('The order prefix "qp_way_too_long_" is 16 characters', $tester->getDisplay());
        self::assertStringContainsString('2 check(s) failed', $tester->getDisplay());
    }

    /**
     * @test
     */
    public function it_warns_when_a_usable_and_an_unusable_gateway_configuration_share_an_order_prefix(): void
    {
        $this->configureGateways(
            GatewayConfigurations::of(self::healthyConfig(), 'quickpay_a'),
            GatewayConfigurations::of(['api_key' => 'the-api-key', 'order_prefix' => 'qp_', 'use_authorize' => true], 'quickpay_b'),
            GatewayConfigurations::encrypted('quickpay_c'),
        );

        $tester = $this->executeCommand();

        self::assertStringContainsString('Gateways "quickpay_a", "quickpay_b" share the order prefix "qp_"', $tester->getDisplay());
    }

    /**
     * @test
     */
    public function it_runs_the_agreement_check_on_each_usable_gateway_configuration(): void
    {
        $configuration = GatewayConfigurations::of(self::healthyConfig(['agreement_id' => 12345]), 'quickpay_dk');
        $this->configureGateways($configuration);

        $this->gatewayChecks->agreement($configuration)->shouldBeCalledOnce()->willReturn(Verdict::passed());

        $this->executeCommand();
    }

    /**
     * @test
     */
    public function it_prints_a_passed_agreement_check(): void
    {
        $this->configureGateways(GatewayConfigurations::of(self::healthyConfig(['agreement_id' => 12345])));
        $this->gatewayChecks->agreement(Argument::any())->willReturn(Verdict::passed());

        $tester = $this->executeCommand();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertDisplayHasLine('✔ Agreement 12345 exists on the account', $tester);
    }

    /**
     * @test
     */
    public function it_names_the_agreement_the_gateway_sends(): void
    {
        $this->configureGateways(GatewayConfigurations::of(self::healthyConfig(['agreement_id' => null, 'agreement' => '7'])));
        $this->gatewayChecks->agreement(Argument::any())->willReturn(Verdict::passed());

        $tester = $this->executeCommand();

        self::assertDisplayHasLine('✔ Agreement 7 exists on the account', $tester);
    }

    /**
     * Quickpay then uses the account's default agreement, and there is nothing to ask it, so the api key's verdict
     * doesn't matter. The gateway sends no agreement id for a blank one either.
     *
     * @test
     *
     * @dataProvider withoutAgreementIdUnderEachApiKeyVerdict
     */
    public function it_passes_the_agreement_when_no_agreement_id_is_set_whatever_the_api_keys_verdict(string $agreementId, Verdict $apiKeyVerdict): void
    {
        $this->configureGateways(GatewayConfigurations::of(self::healthyConfig(['agreement_id' => $agreementId])));
        $this->gatewayChecks->apiKey(Argument::any())->willReturn($apiKeyVerdict);

        $tester = $this->executeCommand();

        self::assertDisplayHasLine('✔ No agreement id configured — Quickpay uses the account\'s default Payment Window agreement', $tester);
        self::assertStringNotContainsString('agreement — no usable api key', $tester->getDisplay());
    }

    /**
     * @return iterable<string, array{string, Verdict}>
     */
    public static function withoutAgreementIdUnderEachApiKeyVerdict(): iterable
    {
        foreach (['none' => '', 'a blank one' => '   '] as $name => $agreementId) {
            yield sprintf('%s, api key passed', $name) => [$agreementId, Verdict::passed()];
            yield sprintf('%s, api key failed', $name) => [$agreementId, Verdict::failed('Quickpay rejects the api key')];
            yield sprintf('%s, api key undetermined', $name) => [$agreementId, Verdict::undetermined('The request could not be sent')];
        }
    }

    /**
     * @test
     */
    public function it_fails_on_a_failed_agreement_check(): void
    {
        $this->configureGateways(GatewayConfigurations::of(self::healthyConfig(['agreement_id' => 12345])));
        $this->gatewayChecks->agreement(Argument::any())->willReturn(Verdict::failed('Agreement 12345 does not exist on this Quickpay account'));

        $tester = $this->executeCommand();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertDisplayHasLine('✖ Agreement 12345 does not exist on this Quickpay account', $tester);
        self::assertStringContainsString('1 check(s) failed', $tester->getDisplay());
    }

    /**
     * Quickpay's answer proved nothing, so nothing is known to be wrong
     *
     * @test
     */
    public function it_warns_without_failing_on_an_undetermined_agreement_check(): void
    {
        $this->configureGateways(GatewayConfigurations::of(self::healthyConfig(['agreement_id' => 12345])));
        $this->gatewayChecks->agreement(Argument::any())->willReturn(Verdict::undetermined('the api user may lack the /agreements permission'));

        $tester = $this->executeCommand();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertDisplayHasLine('! Could not verify agreement 12345: the api user may lack the /agreements permission', $tester);
    }

    /**
     * @test
     */
    public function it_fails_when_the_order_prefix_is_too_long(): void
    {
        $this->configureGateways(GatewayConfigurations::of(self::healthyConfig(['order_prefix' => 'qp_way_too_long_'])));

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
    public function it_prints_a_passed_payment_link_permission_check_with_its_note_in_live_mode(): void
    {
        $configuration = GatewayConfigurations::of(self::healthyConfig(), 'quickpay_dk');
        $this->configureGateways($configuration);
        $this->gatewayChecks->paymentLinkPermission($configuration)->shouldBeCalledOnce()->willReturn(Verdict::passed(self::TEST_PAYMENT_NOTE));

        $tester = $this->executeCommand(['--live' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertDisplayHasLine(sprintf('✔ The api user may create payment links (%s)', self::TEST_PAYMENT_NOTE), $tester);
    }

    /**
     * @test
     */
    public function it_fails_on_a_failed_payment_link_permission_check(): void
    {
        $this->configureGateways(GatewayConfigurations::of(self::healthyConfig()));
        $this->gatewayChecks->paymentLinkPermission(Argument::any())->willReturn(Verdict::failed('The api user may not create payment links', self::TEST_PAYMENT_NOTE));

        $tester = $this->executeCommand(['--live' => true]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertDisplayHasLine(sprintf('✖ The api user may not create payment links (%s)', self::TEST_PAYMENT_NOTE), $tester);
        self::assertStringContainsString('1 check(s) failed', $tester->getDisplay());
    }

    /**
     * @test
     */
    public function it_warns_without_failing_on_an_undetermined_payment_link_permission_check(): void
    {
        $this->configureGateways(GatewayConfigurations::of(self::healthyConfig()));
        $this->gatewayChecks->paymentLinkPermission(Argument::any())->willReturn(Verdict::undetermined('The request could not be sent [PUT https://api.quickpay.net/payments/999/link]: Idle timeout reached', self::TEST_PAYMENT_NOTE));

        $tester = $this->executeCommand(['--live' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertDisplayHasLine(sprintf('! Could not verify the payment link permission: The request could not be sent [PUT https://api.quickpay.net/payments/999/link]: Idle timeout reached (%s)', self::TEST_PAYMENT_NOTE), $tester);
    }

    /**
     * It writes to the Quickpay account, so only on request
     *
     * @test
     */
    public function it_probes_the_payment_link_permission_only_in_live_mode(): void
    {
        $this->configureGateways(GatewayConfigurations::of(self::healthyConfig()));
        $this->gatewayChecks->paymentLinkPermission(Argument::any())->shouldNotBeCalled();

        $tester = $this->executeCommand();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertDisplayHasLine('! Run with --live to probe the payment link permission (creates a harmless test payment)', $tester);
    }

    /**
     * Without an api key Quickpay accepts, their answers would prove nothing
     *
     * @test
     *
     * @dataProvider apiKeyVerdictsOtherThanPassed
     */
    public function it_skips_the_agreement_and_live_checks_unless_the_api_key_check_passed(Verdict $apiKeyVerdict): void
    {
        $this->configureGateways(GatewayConfigurations::of(self::healthyConfig(['agreement_id' => 12345])));
        $this->gatewayChecks->apiKey(Argument::any())->willReturn($apiKeyVerdict);

        $this->gatewayChecks->agreement(Argument::any())->shouldNotBeCalled();
        $this->gatewayChecks->paymentLinkPermission(Argument::any())->shouldNotBeCalled();

        $tester = $this->executeCommand(['--live' => true]);

        self::assertDisplayHasLine('! Skipped verifying agreement 12345 — no usable api key', $tester);
        self::assertDisplayHasLine('! Skipped probing the payment link permission — no usable api key', $tester);
    }

    /**
     * @return iterable<string, array{Verdict}>
     */
    public static function apiKeyVerdictsOtherThanPassed(): iterable
    {
        yield 'failed' => [Verdict::failed('Quickpay rejects the api key')];
        yield 'undetermined' => [Verdict::undetermined('The request could not be sent')];
    }

    /**
     * @test
     */
    public function it_accepts_an_empty_order_prefix(): void
    {
        $this->configureGateways(GatewayConfigurations::of(['api_key' => 'the-api-key', 'private_key' => 'the-private-key']));

        $tester = $this->executeCommand();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('No order prefix configured', $tester->getDisplay());
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
     * The doctor printed the line, its status mark included, exactly
     */
    private static function assertDisplayHasLine(string $line, CommandTester $tester): void
    {
        self::assertContains($line, array_map(trim(...), explode("\n", $tester->getDisplay())), sprintf("The doctor did not print\n%s\nin\n%s", $line, $tester->getDisplay()));
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
        $application->add(new DoctorCommand(
            $this->gatewayChecks->reveal(),
            $this->gatewayConfigurationProvider->reveal(),
            $this->router->reveal(),
        ));

        $tester = new CommandTester($application->find('setono:sylius-quickpay:doctor'));
        $tester->execute($input);

        return $tester;
    }
}
