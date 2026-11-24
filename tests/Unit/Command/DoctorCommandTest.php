<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Tests\Unit\Command;

use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Setono\SyliusQuickpayPlugin\Command\DoctorCommand;
use Setono\SyliusQuickpayPlugin\Diagnosis\DiagnoserInterface;
use Setono\SyliusQuickpayPlugin\Diagnosis\Finding;
use Setono\SyliusQuickpayPlugin\Diagnosis\FindingKind;
use Setono\SyliusQuickpayPlugin\Diagnosis\FindingOutcome;
use Setono\SyliusQuickpayPlugin\GatewayConfiguration\GatewayConfiguration;
use Setono\SyliusQuickpayPlugin\GatewayConfiguration\GatewayConfigurationProviderInterface;
use Setono\SyliusQuickpayPlugin\Tests\GatewayConfiguration\GatewayConfigurations;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\RouterInterface;

/**
 * The doctor prints each gateway's diagnosis and what it can't check; which check runs when is the diagnoser's
 * (tests/Unit/Diagnosis/DiagnoserTest)
 */
final class DoctorCommandTest extends TestCase
{
    use ProphecyTrait;

    private const PRIVATE_KEY_HINT = '! Only a callback can confirm the private key — a callback answered 400 in an order\'s "Quickpay operations" panel means it isn\'t the account\'s (Settings → Integration in the Quickpay manager)';

    private const LIVE_HINT = '! Run with --live to check the payment link permission (creates a harmless test payment)';

    /** @var ObjectProphecy<DiagnoserInterface> */
    private ObjectProphecy $diagnoser;

    /** @var ObjectProphecy<GatewayConfigurationProviderInterface> */
    private ObjectProphecy $gatewayConfigurationProvider;

    /** @var ObjectProphecy<RouterInterface> */
    private ObjectProphecy $router;

    protected function setUp(): void
    {
        $this->diagnoser = $this->prophesize(DiagnoserInterface::class);
        $this->diagnoser->diagnose(Argument::cetera())->willReturn([]);

        $this->gatewayConfigurationProvider = $this->prophesize(GatewayConfigurationProviderInterface::class);
        $this->gatewayConfigurationProvider->all()->willReturn([]);

        $this->router = $this->prophesize(RouterInterface::class);
        $this->router->getRouteCollection()->willReturn(self::routes(true));
    }

    /**
     * @test
     */
    public function it_passes_when_no_finding_failed(): void
    {
        $configuration = $this->configureGateway('quickpay');
        $this->diagnoser->diagnose($configuration, false)->willReturn([
            self::finding(FindingOutcome::Passed, 'The api key is accepted by Quickpay'),
            self::finding(FindingOutcome::Warning, 'The capture mode is not set, so checkout captures immediately'),
        ]);

        $tester = $this->executeCommand();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertDisplayHasLine('✔ The api key is accepted by Quickpay', $tester);
        self::assertDisplayHasLine('! The capture mode is not set, so checkout captures immediately', $tester);
        self::assertStringContainsString('All checks passed', $tester->getDisplay());
    }

    /**
     * @test
     */
    public function it_fails_with_the_number_of_failed_findings(): void
    {
        $configuration = $this->configureGateway('quickpay');
        $this->diagnoser->diagnose($configuration, false)->willReturn([
            self::finding(FindingOutcome::Failed, 'The "private_key" option is required'),
            self::finding(FindingOutcome::Warning, 'Skipped the checks against Quickpay of this gateway'),
            self::finding(FindingOutcome::Failed, 'The "branding_id" option must be a positive integer'),
        ]);

        $tester = $this->executeCommand();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertDisplayHasLine('✖ The "private_key" option is required', $tester);
        self::assertDisplayHasLine('! Skipped the checks against Quickpay of this gateway', $tester);
        self::assertDisplayHasLine('✖ The "branding_id" option must be a positive integer', $tester);
        self::assertStringContainsString('2 check(s) failed', $tester->getDisplay());
    }

    /**
     * Each gateway's findings are printed under its name, in the diagnosis' order, and a failing one doesn't keep the
     * doctor from the gateways after it
     *
     * @test
     */
    public function it_prints_each_gateways_findings_under_its_own_name(): void
    {
        $a = GatewayConfigurations::of(gatewayName: 'quickpay_a');
        $b = GatewayConfigurations::of(gatewayName: 'quickpay_b');
        $this->gatewayConfigurationProvider->all()->willReturn([$a, $b]);
        $this->diagnoser->diagnose($a, false)->shouldBeCalledOnce()->willReturn([
            self::finding(FindingOutcome::Failed, 'The "private_key" option is required'),
        ]);
        $this->diagnoser->diagnose($b, false)->shouldBeCalledOnce()->willReturn([
            self::finding(FindingOutcome::Passed, 'The order id of the latest order, "qp_000000123", is one Quickpay accepts'),
            self::finding(FindingOutcome::Passed, 'The api key is accepted by Quickpay'),
        ]);

        $tester = $this->executeCommand();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        $lines = self::lines($tester);
        $gatewayA = array_search('Gateway "quickpay_a"', $lines, true);
        $gatewayB = array_search('Gateway "quickpay_b"', $lines, true);
        self::assertIsInt($gatewayA, 'The doctor did not print a section for the gateway "quickpay_a"');
        self::assertIsInt($gatewayB, 'The doctor did not print a section for the gateway "quickpay_b"');

        self::assertContains('✖ The "private_key" option is required', array_slice($lines, $gatewayA, $gatewayB - $gatewayA));
        $linesOfB = array_values(array_filter(array_slice($lines, $gatewayB), static fn (string $line): bool => str_starts_with($line, '✔')));
        self::assertSame([
            '✔ The order id of the latest order, "qp_000000123", is one Quickpay accepts',
            '✔ The api key is accepted by Quickpay',
        ], $linesOfB);
        self::assertStringContainsString('1 check(s) failed', $tester->getDisplay());
    }

    /**
     * @test
     */
    public function it_diagnoses_with_the_payment_link_permission_in_live_mode(): void
    {
        $configuration = $this->configureGateway('quickpay');
        $this->diagnoser->diagnose($configuration, true)->shouldBeCalledOnce()->willReturn([]);

        $tester = $this->executeCommand(['--live' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertNotContains(self::LIVE_HINT, self::lines($tester));
    }

    /**
     * It writes to the Quickpay account, so only on request; said once, however many gateways there are
     *
     * @test
     */
    public function it_hints_at_the_live_mode_once(): void
    {
        $a = GatewayConfigurations::of(gatewayName: 'quickpay_a');
        $b = GatewayConfigurations::of(gatewayName: 'quickpay_b');
        $this->gatewayConfigurationProvider->all()->willReturn([$a, $b]);
        $this->diagnoser->diagnose($a, false)->shouldBeCalledOnce()->willReturn([]);
        $this->diagnoser->diagnose($b, false)->shouldBeCalledOnce()->willReturn([]);

        $tester = $this->executeCommand();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame(1, self::countLines(self::LIVE_HINT, $tester));
    }

    /**
     * Nothing the doctor can ask proves the private key is the account's, so it says where the proof shows instead of
     * passing a check that can't fail; once, however many gateways there are
     *
     * @test
     */
    public function it_points_to_the_callback_outcome_for_the_private_key_once(): void
    {
        $this->gatewayConfigurationProvider->all()->willReturn([
            GatewayConfigurations::of(gatewayName: 'quickpay_a'),
            GatewayConfigurations::of(gatewayName: 'quickpay_b'),
        ]);

        $tester = $this->executeCommand(['--live' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame(1, self::countLines(self::PRIVATE_KEY_HINT, $tester));
    }

    /**
     * @test
     */
    public function it_prints_the_registered_notify_route(): void
    {
        $tester = $this->executeCommand();

        self::assertDisplayHasLine('✔ The notify endpoint for operations made outside the store is registered at "/payment/quickpay/notify"', $tester);
    }

    /**
     * @test
     */
    public function it_fails_when_the_notify_route_is_not_registered(): void
    {
        $this->router->getRouteCollection()->willReturn(self::routes(false));

        $tester = $this->executeCommand();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertDisplayHasLine('✖ The notify route is not registered — import "@SetonoSyliusQuickpayPlugin/Resources/config/routes.yaml" (installation step 4 in the README)', $tester);
        self::assertStringContainsString('1 check(s) failed', $tester->getDisplay());
    }

    /**
     * @test
     */
    public function it_warns_when_no_quickpay_gateway_is_configured(): void
    {
        $this->diagnoser->diagnose(Argument::cetera())->shouldNotBeCalled();

        $tester = $this->executeCommand();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertDisplayHasLine('! No Quickpay payment methods are configured yet — nothing else to check', $tester);
        self::assertNotContains(self::PRIVATE_KEY_HINT, self::lines($tester));
        self::assertNotContains(self::LIVE_HINT, self::lines($tester));
    }

    private function configureGateway(string $gatewayName): GatewayConfiguration
    {
        $configuration = GatewayConfigurations::of(gatewayName: $gatewayName);
        $this->gatewayConfigurationProvider->all()->willReturn([$configuration]);

        return $configuration;
    }

    private static function finding(FindingOutcome $outcome, string $text): Finding
    {
        return new Finding(FindingKind::ApiKey, $outcome, $text);
    }

    /**
     * The doctor printed the line, its status mark included, exactly
     */
    private static function assertDisplayHasLine(string $line, CommandTester $tester): void
    {
        self::assertContains($line, self::lines($tester), sprintf("The doctor did not print\n%s\nin\n%s", $line, $tester->getDisplay()));
    }

    private static function countLines(string $line, CommandTester $tester): int
    {
        return count(array_keys(self::lines($tester), $line, true));
    }

    /**
     * @return list<string>
     */
    private static function lines(CommandTester $tester): array
    {
        return array_map(trim(...), explode("\n", $tester->getDisplay()));
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
            $this->diagnoser->reveal(),
            $this->gatewayConfigurationProvider->reveal(),
            $this->router->reveal(),
        ));

        $tester = new CommandTester($application->find('setono:sylius-quickpay:doctor'));
        $tester->execute($input);

        return $tester;
    }
}
