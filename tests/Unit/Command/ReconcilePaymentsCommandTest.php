<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Tests\Unit\Command;

use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Setono\Quickpay\Client\Client;
use Setono\Quickpay\Client\ClientInterface;
use Setono\SyliusQuickpayPlugin\Command\ReconcilePaymentsCommand;
use Setono\SyliusQuickpayPlugin\GatewayConfiguration\GatewayConfiguration;
use Setono\SyliusQuickpayPlugin\GatewayConfiguration\GatewayConfigurationProviderInterface;
use Setono\SyliusQuickpayPlugin\GatewayConfiguration\QuickpayAccounts;
use Setono\SyliusQuickpayPlugin\Quickpay\QuickpayClientProviderInterface;
use Setono\SyliusQuickpayPlugin\Reconciliation\PaymentReconcilerInterface;
use Setono\SyliusQuickpayPlugin\Reconciliation\PendingPaymentsInterface;
use Setono\SyliusQuickpayPlugin\Tests\GatewayConfiguration\GatewayConfigurations;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The command's options and its fraud report. Reconciling payments is covered against the real gateway,
 * state machine and database in {@see \Setono\SyliusQuickpayPlugin\Tests\Functional\Command\ReconcilePaymentsCommandTest}.
 */
final class ReconcilePaymentsCommandTest extends TestCase
{
    use ProphecyTrait;

    /** @var ObjectProphecy<PendingPaymentsInterface> */
    private ObjectProphecy $pendingPayments;

    /** @var ObjectProphecy<PaymentReconcilerInterface> */
    private ObjectProphecy $reconciler;

    /** @var ObjectProphecy<QuickpayClientProviderInterface> */
    private ObjectProphecy $clientProvider;

    /** @var ObjectProphecy<GatewayConfigurationProviderInterface> */
    private ObjectProphecy $gatewayConfigurationProvider;

    protected function setUp(): void
    {
        $this->pendingPayments = $this->prophesize(PendingPaymentsInterface::class);
        $this->reconciler = $this->prophesize(PaymentReconcilerInterface::class);
        $this->clientProvider = $this->prophesize(QuickpayClientProviderInterface::class);
        $this->gatewayConfigurationProvider = $this->prophesize(GatewayConfigurationProviderInterface::class);
    }

    /**
     * @test
     */
    public function it_rejects_an_unparsable_since_option(): void
    {
        $this->pendingPayments->start(Argument::cetera())->shouldNotBeCalled();

        $tester = $this->executeCommand(['--since' => 'not-a-period']);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString('Cannot parse "not-a-period"', $tester->getDisplay());
    }

    /**
     * @test
     */
    public function it_rejects_a_fraud_report_limit_that_is_no_whole_number_of_payments(): void
    {
        $this->gatewayConfigurationProvider->accounts()->shouldNotBeCalled();

        foreach (['0', 'ten', '2.5'] as $limit) {
            $tester = $this->executeCommand(['--fraud-suspected' => true, '--limit' => $limit]);

            self::assertSame(Command::INVALID, $tester->getStatusCode());
            self::assertStringContainsString(sprintf('Cannot use "%s" as --limit', $limit), $tester->getDisplay());
        }
    }

    /**
     * @test
     */
    public function it_reports_fraud_suspected_payments_without_touching_any_payment(): void
    {
        $gatewayConfiguration = GatewayConfigurations::of(gatewayName: 'quickpay_credit_card');
        $this->configureGateways($gatewayConfiguration);
        $this->clientProvider->forGatewayConfiguration($gatewayConfiguration)->willReturn(self::clientAnswering('qp_000000123'));

        $this->pendingPayments->start(Argument::cetera())->shouldNotBeCalled();
        $this->reconciler->reconcile(Argument::cetera())->shouldNotBeCalled();

        $tester = $this->executeCommand(['--fraud-suspected' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $display = self::normalized($tester->getDisplay());
        self::assertStringContainsString('Gateways Quickpay id Order id State Created Test mode', $display);
        self::assertStringContainsString('quickpay_credit_card 501 qp_000000123 new 2026-11-16 10:00:00 yes', $display);
        self::assertStringContainsString('1 payment(s) flagged as fraud suspected', $display);
        self::assertStringNotContainsString('stopped at the limit', $display);
    }

    /**
     * @test
     */
    public function it_caps_the_fraud_report_at_the_limit_and_says_it_may_be_incomplete(): void
    {
        $gatewayConfiguration = GatewayConfigurations::of(gatewayName: 'quickpay_credit_card');
        $this->configureGateways($gatewayConfiguration);
        $this->clientProvider->forGatewayConfiguration($gatewayConfiguration)->willReturn(self::clientAnswering('qp_000000123', 'qp_000000124'));

        $tester = $this->executeCommand(['--fraud-suspected' => true, '--limit' => '1']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $display = self::normalized($tester->getDisplay());
        self::assertStringContainsString('qp_000000123', $display);
        self::assertStringNotContainsString('qp_000000124', $display);
        self::assertStringContainsString('The report stopped at the limit of 1 payment(s) and may be incomplete. Raise --limit to see more.', $display);
    }

    /**
     * Quickpay lists an account's payments together, so one query reports the payments of every gateway sharing its
     * api key, each once
     *
     * @test
     */
    public function it_queries_a_quickpay_account_once_however_many_gateways_share_its_api_key(): void
    {
        $creditCard = GatewayConfigurations::of(gatewayName: 'quickpay_credit_card');
        $mobilePay = GatewayConfigurations::of(gatewayName: 'quickpay_mobilepay');
        $viaBill = GatewayConfigurations::of(gatewayName: 'quickpay_viabill');
        $this->configureGateways($creditCard, $mobilePay, $viaBill);
        $this->clientProvider->forGatewayConfiguration($creditCard)->shouldBeCalledOnce()->willReturn(self::clientAnswering('qp_000000123'));
        $this->clientProvider->forGatewayConfiguration($mobilePay)->shouldNotBeCalled();
        $this->clientProvider->forGatewayConfiguration($viaBill)->shouldNotBeCalled();

        $tester = $this->executeCommand(['--fraud-suspected' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $display = self::normalized($tester->getDisplay());
        self::assertStringContainsString('quickpay_credit_card, quickpay_mobilepay, quickpay_viabill 501 qp_000000123 new', $display);
        self::assertSame(1, substr_count($display, 'qp_000000123'));
        self::assertStringContainsString('1 payment(s) flagged as fraud suspected', $display);
    }

    /**
     * @test
     */
    public function it_names_every_gateway_of_the_accounts_the_fraud_report_did_not_query_once_it_reached_the_limit(): void
    {
        $creditCard = GatewayConfigurations::of(gatewayName: 'quickpay_credit_card');
        $mobilePay = GatewayConfigurations::of(self::withApiKey('another-api-key'), 'quickpay_mobilepay');
        $viaBill = GatewayConfigurations::of(self::withApiKey('another-api-key'), 'quickpay_viabill');
        $anyday = GatewayConfigurations::of(self::withApiKey('a-third-api-key'), 'quickpay_anyday');
        $this->configureGateways($creditCard, $mobilePay, $viaBill, $anyday);
        $this->clientProvider->forGatewayConfiguration($creditCard)->willReturn(self::clientAnswering('qp_000000123', 'qp_000000124'));
        $this->clientProvider->forGatewayConfiguration($mobilePay)->shouldNotBeCalled();
        $this->clientProvider->forGatewayConfiguration($viaBill)->shouldNotBeCalled();
        $this->clientProvider->forGatewayConfiguration($anyday)->shouldNotBeCalled();

        $tester = $this->executeCommand(['--fraud-suspected' => true, '--limit' => '2']);

        // Reaching the limit isn't a failure
        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $display = self::normalized($tester->getDisplay());
        self::assertStringContainsString('2 payment(s) flagged as fraud suspected', $display);
        self::assertStringContainsString(
            'The report stopped at the limit of 2 payment(s) and didn\'t query the gateway(s) "quickpay_mobilepay", "quickpay_viabill", "quickpay_anyday". Raise --limit to see more.',
            $display,
        );
    }

    /**
     * Its api key can't be read, so nothing says which account it belongs to: its payments may be missing from the report
     *
     * @test
     */
    public function it_reports_a_gateway_with_an_unusable_gateway_configuration_in_the_fraud_report(): void
    {
        $unusable = GatewayConfigurations::encrypted('quickpay_credit_card');
        $usable = GatewayConfigurations::of(gatewayName: 'quickpay_mobilepay');
        $this->configureGateways($unusable, $usable);
        $this->clientProvider->forGatewayConfiguration($unusable)->shouldNotBeCalled();
        $this->clientProvider->forGatewayConfiguration($usable)->willReturn(self::clientAnswering())->shouldBeCalledOnce();

        $tester = $this->executeCommand(['--fraud-suspected' => true]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        $display = self::normalized($tester->getDisplay());
        self::assertStringContainsString('Gateway(s) "quickpay_credit_card": The gateway configuration of the Quickpay gateway "quickpay_credit_card" reads as empty', $display);
        // The other gateway is still queried
        self::assertStringContainsString('no fraud suspected payments', $display);
    }

    /**
     * Reaching the limit isn't a failure, but a gateway the report could never have queried is
     *
     * @test
     */
    public function it_reports_a_gateway_with_an_unusable_gateway_configuration_whatever_the_limit(): void
    {
        $usable = GatewayConfigurations::of(gatewayName: 'quickpay_mobilepay');
        $this->configureGateways($usable, GatewayConfigurations::encrypted('quickpay_credit_card'));
        $this->clientProvider->forGatewayConfiguration($usable)->willReturn(self::clientAnswering('qp_000000123'));

        $tester = $this->executeCommand(['--fraud-suspected' => true, '--limit' => '1']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        $display = self::normalized($tester->getDisplay());
        self::assertStringContainsString('Gateway(s) "quickpay_credit_card": The gateway configuration of the Quickpay gateway "quickpay_credit_card" reads as empty', $display);
        self::assertStringContainsString('The report stopped at the limit of 1 payment(s) and may be incomplete.', $display);
    }

    /**
     * @test
     */
    public function it_reports_a_gateway_quickpay_fails_to_answer_and_still_queries_the_others(): void
    {
        $failing = GatewayConfigurations::of(gatewayName: 'quickpay_credit_card');
        $answering = GatewayConfigurations::of(self::withApiKey('another-api-key'), 'quickpay_mobilepay');
        $this->configureGateways($failing, $answering);
        $this->clientProvider->forGatewayConfiguration($failing)->willReturn(self::client(new MockResponse('{"message": "boom"}', [
            'http_code' => 500,
            'response_headers' => ['Content-Type' => 'application/json'],
        ])));
        $this->clientProvider->forGatewayConfiguration($answering)->willReturn(self::clientAnswering('qp_000000123'));

        $tester = $this->executeCommand(['--fraud-suspected' => true]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        $display = self::normalized($tester->getDisplay());
        self::assertStringContainsString('Gateway(s) "quickpay_credit_card":', $display);
        self::assertStringContainsString('quickpay_mobilepay 501 qp_000000123', $display);
    }

    /**
     * An SDK client whose fraud report query Quickpay answers with a page of fraud suspected payments, one per order id
     */
    private static function clientAnswering(string ...$orderIds): ClientInterface
    {
        $payments = [];
        foreach (array_values($orderIds) as $i => $orderId) {
            $payments[] = [
                'id' => 501 + $i,
                'order_id' => $orderId,
                'currency' => 'DKK',
                'state' => 'new',
                'merchant_id' => 1,
                'test_mode' => true,
                'metadata' => ['fraud_suspected' => true],
                'created_at' => '2026-11-16T10:00:00+00:00',
            ];
        }

        return self::client(new MockResponse(json_encode($payments, \JSON_THROW_ON_ERROR), [
            'response_headers' => ['Content-Type' => 'application/json'],
        ]));
    }

    private static function client(MockResponse $response): ClientInterface
    {
        return new Client('the-api-key', new Psr18Client(new MockHttpClient($response, 'https://api.quickpay.net')));
    }

    private function configureGateways(GatewayConfiguration ...$gatewayConfigurations): void
    {
        $this->gatewayConfigurationProvider->accounts()->willReturn(QuickpayAccounts::of(array_values($gatewayConfigurations)));
    }

    /**
     * @return array<string, mixed>
     */
    private static function withApiKey(string $apiKey): array
    {
        return ['api_key' => $apiKey] + GatewayConfigurations::USABLE;
    }

    private static function normalized(string $display): string
    {
        return (string) preg_replace('/\s+/', ' ', $display);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function executeCommand(array $input): CommandTester
    {
        $application = new Application();
        $application->add(new ReconcilePaymentsCommand(
            $this->pendingPayments->reveal(),
            $this->reconciler->reveal(),
            $this->clientProvider->reveal(),
            $this->gatewayConfigurationProvider->reveal(),
        ));

        $tester = new CommandTester($application->find('setono:sylius-quickpay:reconcile-payments'));
        $tester->execute($input);

        return $tester;
    }
}
