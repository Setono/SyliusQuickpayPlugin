<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Tests\Command;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nyholm\Psr7\Response;
use Payum\Core\GatewayInterface;
use Payum\Core\Payum;
use Payum\Core\Request\GetHumanStatus;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Setono\Payum\Quickpay\QuickpayGatewayFactory;
use Setono\Quickpay\Client\Client;
use Setono\Quickpay\Exception\ValidationException;
use Setono\SyliusQuickpayPlugin\Command\ReconcilePaymentsCommand;
use Setono\SyliusQuickpayPlugin\Provider\PendingPaymentProviderInterface;
use Setono\SyliusQuickpayPlugin\Quickpay\ClientFactoryInterface;
use Setono\SyliusQuickpayPlugin\Tests\Quickpay\FixedResponseHttpClient;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\PayumBundle\Model\GatewayConfigInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Resource\Repository\RepositoryInterface;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class ReconcilePaymentsCommandTest extends TestCase
{
    use ProphecyTrait;

    /** @var ObjectProphecy<PendingPaymentProviderInterface> */
    private ObjectProphecy $pendingPaymentProvider;

    /** @var ObjectProphecy<Payum> */
    private ObjectProphecy $payum;

    /** @var ObjectProphecy<GatewayInterface> */
    private ObjectProphecy $gateway;

    /** @var ObjectProphecy<StateMachineInterface> */
    private ObjectProphecy $stateMachine;

    /** @var ObjectProphecy<ClientFactoryInterface> */
    private ObjectProphecy $clientFactory;

    /** @var ObjectProphecy<RepositoryInterface<GatewayConfigInterface>> */
    private ObjectProphecy $gatewayConfigRepository;

    /** @var ObjectProphecy<EntityManagerInterface> */
    private ObjectProphecy $entityManager;

    /** @var ObjectProphecy<ManagerRegistry> */
    private ObjectProphecy $managerRegistry;

    protected function setUp(): void
    {
        $this->pendingPaymentProvider = $this->prophesize(PendingPaymentProviderInterface::class);
        $this->payum = $this->prophesize(Payum::class);
        $this->gateway = $this->prophesize(GatewayInterface::class);
        $this->stateMachine = $this->prophesize(StateMachineInterface::class);
        $this->clientFactory = $this->prophesize(ClientFactoryInterface::class);

        /** @var ObjectProphecy<RepositoryInterface<GatewayConfigInterface>> $gatewayConfigRepository */
        $gatewayConfigRepository = $this->prophesize(RepositoryInterface::class);
        $this->gatewayConfigRepository = $gatewayConfigRepository;
        $this->entityManager = $this->prophesize(EntityManagerInterface::class);
        $this->managerRegistry = $this->prophesize(ManagerRegistry::class);
        $this->managerRegistry->getManagerForClass(Argument::type('string'))->willReturn($this->entityManager);

        $this->payum->getGateway('quickpay_credit_card')->willReturn($this->gateway);
    }

    /**
     * @test
     */
    public function it_applies_the_transition_matching_the_quickpay_status(): void
    {
        $payment = $this->createPayment();
        $this->pendingPaymentProvider->findPending(Argument::type(\DateTimeImmutable::class), 100)->willReturn([$payment]);

        $this->gateway->execute(Argument::type(GetHumanStatus::class))->will(static function (array $args): void {
            $request = $args[0];
            if ($request instanceof GetHumanStatus) {
                $request->markCaptured();
            }
        });

        $this->stateMachine->can($payment, 'sylius_payment', 'complete')->willReturn(true);
        $this->stateMachine->apply($payment, 'sylius_payment', 'complete')->shouldBeCalledOnce();
        $this->entityManager->flush()->shouldBeCalledOnce();

        $tester = $this->executeCommand([]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('applied "complete"', $tester->getDisplay());
        self::assertStringContainsString('1 checked, 1 transitioned, 0 unchanged, 0 errored', $tester->getDisplay());
    }

    /**
     * @test
     */
    public function it_does_not_apply_anything_on_a_dry_run(): void
    {
        $payment = $this->createPayment();
        $this->pendingPaymentProvider->findPending(Argument::type(\DateTimeImmutable::class), 100)->willReturn([$payment]);

        $this->gateway->execute(Argument::type(GetHumanStatus::class))->will(static function (array $args): void {
            $request = $args[0];
            if ($request instanceof GetHumanStatus) {
                $request->markCanceled();
            }
        });

        $this->stateMachine->can($payment, 'sylius_payment', 'cancel')->willReturn(true);
        $this->stateMachine->apply(Argument::cetera())->shouldNotBeCalled();
        $this->entityManager->flush()->shouldNotBeCalled();

        $tester = $this->executeCommand(['--dry-run' => true]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('would apply "cancel"', $tester->getDisplay());
    }

    /**
     * @test
     */
    public function it_leaves_payments_with_a_pending_quickpay_status_unchanged(): void
    {
        $payment = $this->createPayment();
        $this->pendingPaymentProvider->findPending(Argument::type(\DateTimeImmutable::class), 100)->willReturn([$payment]);

        $this->gateway->execute(Argument::type(GetHumanStatus::class))->will(static function (array $args): void {
            $request = $args[0];
            if ($request instanceof GetHumanStatus) {
                $request->markPending();
            }
        });

        $this->stateMachine->apply(Argument::cetera())->shouldNotBeCalled();
        // The re-fetched details are persisted even when no transition applies
        $this->entityManager->flush()->shouldBeCalledOnce();

        $tester = $this->executeCommand([]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('1 checked, 0 transitioned, 1 unchanged, 0 errored', $tester->getDisplay());
    }

    /**
     * @test
     */
    public function it_leaves_payments_whose_transition_is_not_applicable_unchanged(): void
    {
        $payment = $this->createPayment();
        $this->pendingPaymentProvider->findPending(Argument::type(\DateTimeImmutable::class), 100)->willReturn([$payment]);

        $this->gateway->execute(Argument::type(GetHumanStatus::class))->will(static function (array $args): void {
            $request = $args[0];
            if ($request instanceof GetHumanStatus) {
                $request->markAuthorized();
            }
        });

        $this->stateMachine->can($payment, 'sylius_payment', 'authorize')->willReturn(false);
        $this->stateMachine->apply(Argument::cetera())->shouldNotBeCalled();
        $this->entityManager->flush()->shouldBeCalledOnce();

        $tester = $this->executeCommand([]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('1 checked, 0 transitioned, 1 unchanged, 0 errored', $tester->getDisplay());
    }

    /**
     * @test
     */
    public function it_reports_failures_without_aborting_the_run(): void
    {
        $erroring = $this->createPayment(21);
        $succeeding = $this->createPayment(22);
        $this->pendingPaymentProvider->findPending(Argument::type(\DateTimeImmutable::class), 100)->willReturn([$erroring, $succeeding]);

        $this->gateway->execute(Argument::type(GetHumanStatus::class))->will(static function (array $args) use ($erroring): void {
            $request = $args[0];
            if (!$request instanceof GetHumanStatus) {
                return;
            }

            if ($request->getFirstModel() === $erroring) {
                throw new ValidationException(new Response(400), 'Invalid API key');
            }

            $request->markCaptured();
        });

        $this->stateMachine->can($succeeding, 'sylius_payment', 'complete')->willReturn(true);
        $this->stateMachine->apply($succeeding, 'sylius_payment', 'complete')->shouldBeCalledOnce();
        $this->entityManager->flush()->shouldBeCalledTimes(2);

        $tester = $this->executeCommand([]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Payment 21: Invalid API key', $tester->getDisplay());
        self::assertStringContainsString('2 checked, 1 transitioned, 0 unchanged, 1 errored', $tester->getDisplay());
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
        $gatewayConfig = $this->prophesize(GatewayConfigInterface::class);
        $gatewayConfig->getConfig()->willReturn(['api_key' => 'the-api-key']);
        $gatewayConfig->getGatewayName()->willReturn('quickpay_credit_card');

        $this->gatewayConfigRepository
            ->findBy(['factoryName' => QuickpayGatewayFactory::NAME])
            ->willReturn([$gatewayConfig->reveal()])
        ;

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
        $this->stateMachine->apply(Argument::cetera())->shouldNotBeCalled();

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
        $gatewayConfig = $this->prophesize(GatewayConfigInterface::class);
        $gatewayConfig->getConfig()->willReturn(['api_key' => 'the-api-key']);
        $gatewayConfig->getGatewayName()->willReturn('quickpay_credit_card');

        $this->gatewayConfigRepository
            ->findBy(['factoryName' => QuickpayGatewayFactory::NAME])
            ->willReturn([$gatewayConfig->reveal()])
        ;

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
     */
    public function it_skips_gateways_without_an_api_key_in_the_fraud_report(): void
    {
        $gatewayConfig = $this->prophesize(GatewayConfigInterface::class);
        $gatewayConfig->getConfig()->willReturn([]);
        $gatewayConfig->getGatewayName()->willReturn('quickpay_credit_card');

        $this->gatewayConfigRepository
            ->findBy(['factoryName' => QuickpayGatewayFactory::NAME])
            ->willReturn([$gatewayConfig->reveal()])
        ;

        $this->clientFactory->create(Argument::any())->shouldNotBeCalled();

        $tester = $this->executeCommand(['--fraud-suspected' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('no fraud suspected payments', $tester->getDisplay());
    }

    /**
     * @test
     */
    public function it_reports_an_error_when_a_gateway_cannot_be_queried_for_fraud(): void
    {
        $gatewayConfig = $this->prophesize(GatewayConfigInterface::class);
        $gatewayConfig->getConfig()->willReturn(['api_key' => 'the-api-key']);
        $gatewayConfig->getGatewayName()->willReturn('quickpay_credit_card');

        $this->gatewayConfigRepository
            ->findBy(['factoryName' => QuickpayGatewayFactory::NAME])
            ->willReturn([$gatewayConfig->reveal()])
        ;

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
            $this->payum->reveal(),
            $this->stateMachine->reveal(),
            $this->clientFactory->reveal(),
            $this->gatewayConfigRepository->reveal(),
            $this->managerRegistry->reveal(),
        ));

        $tester = new CommandTester($application->find('setono:sylius-quickpay:reconcile-payments'));
        $tester->execute($input);

        return $tester;
    }

    private function createPayment(int $id = 1): PaymentInterface
    {
        $gatewayConfig = $this->prophesize(GatewayConfigInterface::class);
        $gatewayConfig->getGatewayName()->willReturn('quickpay_credit_card');

        $method = $this->prophesize(PaymentMethodInterface::class);
        $method->getGatewayConfig()->willReturn($gatewayConfig);

        $payment = $this->prophesize(PaymentInterface::class);
        $payment->getId()->willReturn($id);
        $payment->getMethod()->willReturn($method);
        $payment->getDetails()->willReturn(['quickpayPaymentId' => 999999]);

        return $payment->reveal();
    }
}
