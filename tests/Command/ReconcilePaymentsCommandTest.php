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
use Setono\Quickpay\Exception\ValidationException;
use Setono\SyliusQuickpayPlugin\Command\ReconcilePaymentsCommand;
use Setono\SyliusQuickpayPlugin\Provider\PendingPaymentProviderInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\PayumBundle\Model\GatewayConfigInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
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
     * @param array<string, mixed> $input
     */
    private function executeCommand(array $input): CommandTester
    {
        $application = new Application();
        $application->add(new ReconcilePaymentsCommand(
            $this->pendingPaymentProvider->reveal(),
            $this->payum->reveal(),
            $this->stateMachine->reveal(),
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
