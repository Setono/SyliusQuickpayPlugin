<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Tests\Unit\Command;

use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Setono\Quickpay\Client\Client;
use Setono\SyliusQuickpayPlugin\Command\ReconcilePaymentsCommand;
use Setono\SyliusQuickpayPlugin\GatewayConfiguration\GatewayConfiguration;
use Setono\SyliusQuickpayPlugin\GatewayConfiguration\GatewayConfigurationProviderInterface;
use Setono\SyliusQuickpayPlugin\Provider\PendingPaymentProviderInterface;
use Setono\SyliusQuickpayPlugin\Quickpay\ClientFactoryInterface;
use Setono\SyliusQuickpayPlugin\Reconciliation\PaymentReconcilerInterface;
use Setono\SyliusQuickpayPlugin\Tests\GatewayConfiguration\GatewayConfigurations;
use Setono\SyliusQuickpayPlugin\Tests\Quickpay\FixedResponseHttpClient;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

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

    /** @var ObjectProphecy<ClientFactoryInterface> */
    private ObjectProphecy $clientFactory;

    /** @var ObjectProphecy<GatewayConfigurationProviderInterface> */
    private ObjectProphecy $gatewayConfigurationProvider;

    protected function setUp(): void
    {
        $this->pendingPaymentProvider = $this->prophesize(PendingPaymentProviderInterface::class);
        $this->reconciler = $this->prophesize(PaymentReconcilerInterface::class);
        $this->clientFactory = $this->prophesize(ClientFactoryInterface::class);
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
        $this->gatewayConfigurationProvider->all()->willReturn([GatewayConfigurations::of(gatewayName: 'quickpay_credit_card')]);

        $response = new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([[
            'id' => 501,
            'order_id' => 'qp_000000123',
            'currency' => 'DKK',
            'state' => 'new',
            'merchant_id' => 1,
            'test_mode' => true,
            'metadata' => ['fraud_suspected' => true],
        ]]));

        $this->clientFactory
            ->create('the-api-key')
            ->willReturn(new Client('the-api-key', new FixedResponseHttpClient($response)))
        ;

        $this->pendingPaymentProvider->findPending(Argument::cetera())->shouldNotBeCalled();
        $this->reconciler->reconcile(Argument::cetera())->shouldNotBeCalled();

        $tester = $this->executeCommand(['--fraud-suspected' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('qp_000000123', $tester->getDisplay());
        self::assertStringContainsString('1 payment(s) flagged as fraud suspected', $tester->getDisplay());
    }

    /**
     * @test
     */
    public function it_caps_the_fraud_report_at_the_limit(): void
    {
        $this->gatewayConfigurationProvider->all()->willReturn([GatewayConfigurations::of(gatewayName: 'quickpay_credit_card')]);

        $payment = [
            'id' => 501,
            'order_id' => 'qp_000000123',
            'currency' => 'DKK',
            'state' => 'new',
            'merchant_id' => 1,
            'metadata' => ['fraud_suspected' => true],
        ];
        $response = new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
            $payment,
            ['order_id' => 'qp_000000124', 'id' => 502] + $payment,
        ]));

        $this->clientFactory
            ->create('the-api-key')
            ->willReturn(new Client('the-api-key', new FixedResponseHttpClient($response)))
        ;

        $tester = $this->executeCommand(['--fraud-suspected' => true, '--limit' => '1']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('qp_000000123', $tester->getDisplay());
        self::assertStringNotContainsString('qp_000000124', $tester->getDisplay());
    }

    /**
     * @test
     *
     * @dataProvider unusableGatewayConfigurations
     */
    public function it_reports_a_gateway_with_an_unusable_gateway_configuration_in_the_fraud_report(GatewayConfiguration $unusable): void
    {
        $this->gatewayConfigurationProvider->all()->willReturn([
            $unusable,
            GatewayConfigurations::of(gatewayName: 'quickpay_mobilepay'),
        ]);

        $this->clientFactory
            ->create('the-api-key')
            ->willReturn(new Client('the-api-key', new FixedResponseHttpClient(new Response(200, ['Content-Type' => 'application/json'], '[]'))))
        ;

        $tester = $this->executeCommand(['--fraud-suspected' => true]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Gateway "quickpay_credit_card"', $tester->getDisplay());
        // The other gateway is still queried
        self::assertStringContainsString('no fraud suspected payments', $tester->getDisplay());
    }

    /**
     * @return iterable<string, array{GatewayConfiguration}>
     */
    public static function unusableGatewayConfigurations(): iterable
    {
        yield 'without an api key' => [GatewayConfigurations::of(['private_key' => 'the-private-key'], 'quickpay_credit_card')];

        yield 'encrypted' => [GatewayConfigurations::encrypted('quickpay_credit_card')];
    }

    /**
     * @test
     */
    public function it_reports_an_error_when_a_gateway_cannot_be_queried_for_fraud(): void
    {
        $this->gatewayConfigurationProvider->all()->willReturn([GatewayConfigurations::of(gatewayName: 'quickpay_credit_card')]);

        $this->clientFactory
            ->create('the-api-key')
            ->willReturn(new Client('the-api-key', new FixedResponseHttpClient(new Response(500, [], '{"message": "boom"}'))))
        ;

        $tester = $this->executeCommand(['--fraud-suspected' => true]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('quickpay_credit_card', $tester->getDisplay());
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
            $this->clientFactory->reveal(),
            $this->gatewayConfigurationProvider->reveal(),
        ));

        $tester = new CommandTester($application->find('setono:sylius-quickpay:reconcile-payments'));
        $tester->execute($input);

        return $tester;
    }
}
