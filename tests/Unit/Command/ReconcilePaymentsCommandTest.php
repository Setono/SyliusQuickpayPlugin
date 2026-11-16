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
use Setono\SyliusQuickpayPlugin\GatewayConfiguration\GatewayConfigurationProviderInterface;
use Setono\SyliusQuickpayPlugin\GatewayConfiguration\UnusableGatewayConfiguration;
use Setono\SyliusQuickpayPlugin\Provider\PendingPaymentProviderInterface;
use Setono\SyliusQuickpayPlugin\Quickpay\QuickpayClientProviderInterface;
use Setono\SyliusQuickpayPlugin\Reconciliation\PaymentReconcilerInterface;
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

    /** @var ObjectProphecy<PendingPaymentProviderInterface> */
    private ObjectProphecy $pendingPaymentProvider;

    /** @var ObjectProphecy<PaymentReconcilerInterface> */
    private ObjectProphecy $reconciler;

    /** @var ObjectProphecy<QuickpayClientProviderInterface> */
    private ObjectProphecy $clientProvider;

    /** @var ObjectProphecy<GatewayConfigurationProviderInterface> */
    private ObjectProphecy $gatewayConfigurationProvider;

    protected function setUp(): void
    {
        $this->pendingPaymentProvider = $this->prophesize(PendingPaymentProviderInterface::class);
        $this->reconciler = $this->prophesize(PaymentReconcilerInterface::class);
        $this->clientProvider = $this->prophesize(QuickpayClientProviderInterface::class);
        $this->gatewayConfigurationProvider = $this->prophesize(GatewayConfigurationProviderInterface::class);
    }

    /**
     * @test
     */
    public function it_passes_the_parsed_options_to_the_provider(): void
    {
        $this->pendingPaymentProvider->findPending(
            Argument::that(static function (\DateTimeImmutable $since): bool {
                $expected = new \DateTimeImmutable('-12 hours');

                return abs($since->getTimestamp() - $expected->getTimestamp()) < 60;
            }),
            25,
        )->shouldBeCalledOnce()->willReturn([]);

        $tester = $this->executeCommand(['--since' => '12 hours', '--limit' => '25']);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('0 checked', $tester->getDisplay());
    }

    /**
     * @test
     */
    public function it_rejects_an_unparsable_since_option(): void
    {
        $this->pendingPaymentProvider->findPending(Argument::cetera())->shouldNotBeCalled();

        $tester = $this->executeCommand(['--since' => 'not-a-period']);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString('Cannot parse "not-a-period"', $tester->getDisplay());
    }

    /**
     * @test
     */
    public function it_reports_fraud_suspected_payments_without_touching_any_payment(): void
    {
        $gatewayConfiguration = GatewayConfigurations::of(gatewayName: 'quickpay_credit_card');
        $this->gatewayConfigurationProvider->all()->willReturn([$gatewayConfiguration]);
        $this->clientProvider->forGatewayConfiguration($gatewayConfiguration)->willReturn(self::clientAnswering('qp_000000123'));

        $this->pendingPaymentProvider->findPending(Argument::cetera())->shouldNotBeCalled();
        $this->reconciler->reconcile(Argument::cetera())->shouldNotBeCalled();

        $tester = $this->executeCommand(['--fraud-suspected' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('qp_000000123', $tester->getDisplay());
        self::assertStringContainsString('1 payment(s) flagged as fraud suspected', $tester->getDisplay());
        self::assertStringNotContainsString('stopped at the limit', $tester->getDisplay());
    }

    /**
     * @test
     */
    public function it_caps_the_fraud_report_at_the_limit_and_says_it_may_be_incomplete(): void
    {
        $gatewayConfiguration = GatewayConfigurations::of(gatewayName: 'quickpay_credit_card');
        $this->gatewayConfigurationProvider->all()->willReturn([$gatewayConfiguration]);
        $this->clientProvider->forGatewayConfiguration($gatewayConfiguration)->willReturn(self::clientAnswering('qp_000000123', 'qp_000000124'));

        $tester = $this->executeCommand(['--fraud-suspected' => true, '--limit' => '1']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $display = self::normalized($tester->getDisplay());
        self::assertStringContainsString('qp_000000123', $display);
        self::assertStringNotContainsString('qp_000000124', $display);
        self::assertStringContainsString('The report stopped at the limit of 1 payment(s) and may be incomplete. Raise --limit to see more.', $display);
    }

    /**
     * @test
     */
    public function it_names_every_gateway_the_fraud_report_did_not_query_once_it_reached_the_limit(): void
    {
        $creditCard = GatewayConfigurations::of(gatewayName: 'quickpay_credit_card');
        $mobilePay = GatewayConfigurations::of(gatewayName: 'quickpay_mobilepay');
        $viaBill = GatewayConfigurations::of(gatewayName: 'quickpay_viabill');
        $this->gatewayConfigurationProvider->all()->willReturn([$creditCard, $mobilePay, $viaBill]);
        $this->clientProvider->forGatewayConfiguration($creditCard)->willReturn(self::clientAnswering('qp_000000123', 'qp_000000124'));
        $this->clientProvider->forGatewayConfiguration($mobilePay)->shouldNotBeCalled();
        $this->clientProvider->forGatewayConfiguration($viaBill)->shouldNotBeCalled();

        $tester = $this->executeCommand(['--fraud-suspected' => true, '--limit' => '2']);

        // Reaching the limit isn't a failure
        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $display = self::normalized($tester->getDisplay());
        self::assertStringContainsString('2 payment(s) flagged as fraud suspected', $display);
        self::assertStringContainsString(
            'The report stopped at the limit of 2 payment(s) and didn\'t query the gateway(s) "quickpay_mobilepay", "quickpay_viabill". Raise --limit to see more.',
            $display,
        );
    }

    /**
     * @test
     */
    public function it_reports_a_gateway_with_an_unusable_gateway_configuration_in_the_fraud_report(): void
    {
        $unusable = GatewayConfigurations::encrypted('quickpay_credit_card');
        $usable = GatewayConfigurations::of(gatewayName: 'quickpay_mobilepay');
        $this->gatewayConfigurationProvider->all()->willReturn([$unusable, $usable]);
        $this->clientProvider->forGatewayConfiguration($unusable)->willThrow(UnusableGatewayConfiguration::unreadable('quickpay_credit_card'));
        $this->clientProvider->forGatewayConfiguration($usable)->willReturn(self::clientAnswering())->shouldBeCalledOnce();

        $tester = $this->executeCommand(['--fraud-suspected' => true]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        $display = self::normalized($tester->getDisplay());
        self::assertStringContainsString('Gateway "quickpay_credit_card": The gateway configuration of the Quickpay gateway "quickpay_credit_card" reads as empty', $display);
        // The other gateway is still queried
        self::assertStringContainsString('no fraud suspected payments', $display);
    }

    /**
     * @test
     */
    public function it_reports_a_gateway_quickpay_fails_to_answer_and_still_queries_the_others(): void
    {
        $failing = GatewayConfigurations::of(gatewayName: 'quickpay_credit_card');
        $answering = GatewayConfigurations::of(gatewayName: 'quickpay_mobilepay');
        $this->gatewayConfigurationProvider->all()->willReturn([$failing, $answering]);
        $this->clientProvider->forGatewayConfiguration($failing)->willReturn(self::client(new MockResponse('{"message": "boom"}', [
            'http_code' => 500,
            'response_headers' => ['Content-Type' => 'application/json'],
        ])));
        $this->clientProvider->forGatewayConfiguration($answering)->willReturn(self::clientAnswering('qp_000000123'));

        $tester = $this->executeCommand(['--fraud-suspected' => true]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        $display = self::normalized($tester->getDisplay());
        self::assertStringContainsString('Gateway "quickpay_credit_card":', $display);
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
            $this->pendingPaymentProvider->reveal(),
            $this->reconciler->reveal(),
            $this->clientProvider->reveal(),
            $this->gatewayConfigurationProvider->reveal(),
        ));

        $tester = new CommandTester($application->find('setono:sylius-quickpay:reconcile-payments'));
        $tester->execute($input);

        return $tester;
    }
}
