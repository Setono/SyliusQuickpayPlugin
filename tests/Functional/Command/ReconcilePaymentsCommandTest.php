<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Tests\Functional\Command;

use Nyholm\Psr7\Response;
use Setono\SyliusQuickpayPlugin\Tests\Functional\FunctionalTestCase;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\OrderPaymentStates;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class ReconcilePaymentsCommandTest extends FunctionalTestCase
{
    /**
     * @test
     */
    public function it_reconciles_each_pending_payment_and_reports_a_truthful_run(): void
    {
        self::bootKernel();

        $authorized = [PaymentInterface::STATE_AUTHORIZED, OrderPaymentStates::STATE_AUTHORIZED];
        $erroring = $this->createSyliusPayment(...$authorized, quickpayPaymentId: 501, orderNumber: '000000001');
        $transitioning = $this->createSyliusPayment(...$authorized, quickpayPaymentId: 502, orderNumber: '000000002');
        $unchanged = $this->createSyliusPayment(...$authorized, quickpayPaymentId: 503, orderNumber: '000000003');
        $locked = $this->createSyliusPayment(...$authorized, quickpayPaymentId: 504, orderNumber: '000000004');

        $this->quickpay()->respond('GET', '/payments/501', new Response(500, ['Content-Type' => 'application/json'], '{"message": "Quickpay is down"}'));
        $this->quickpay()->respond('GET', '/payments/502', self::quickpayPayment(502, 'processed', ['authorize', 'capture'], balance: 10_000));
        $this->quickpay()->respond('GET', '/payments/503', self::quickpayPayment(503, 'new', ['authorize'], balance: 0));
        $this->quickpay()->respond('GET', '/payments/504', self::quickpayPayment(504, 'processed', ['authorize', 'capture'], balance: 10_000));

        // A callback for this one is being handled right now
        $this->holdLockOf(504);

        $tester = $this->executeCommand([]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        $display = $tester->getDisplay();
        self::assertStringContainsString(sprintf('Payment %s:', (string) $erroring->getId()), $display);
        self::assertStringContainsString(
            sprintf('Payment %s: "authorized" -> "completed" (Quickpay → "completed")', (string) $transitioning->getId()),
            $display,
        );
        self::assertStringNotContainsString(sprintf('Payment %s:', (string) $unchanged->getId()), $display);
        self::assertStringContainsString(sprintf('Payment %s: skipped', (string) $locked->getId()), $display);
        self::assertStringContainsString('4 checked, 1 transitioned, 1 unchanged, 1 skipped, 1 errored', $display);

        // The run kept going after the error, and the transitioned payment was persisted with its order
        $transitioning = $this->reload($transitioning);
        self::assertSame(PaymentInterface::STATE_COMPLETED, $transitioning->getState());
        self::assertSame(OrderPaymentStates::STATE_PAID, $transitioning->getOrder()?->getPaymentState());

        foreach ([$erroring, $unchanged, $locked] as $payment) {
            self::assertSame(PaymentInterface::STATE_AUTHORIZED, $this->reload($payment)->getState());
        }
    }

    /**
     * @test
     *
     * @dataProvider stateMachineAdapters
     */
    public function a_payment_failing_mid_transition_is_saved_as_it_was_and_the_run_goes_on(string $stateMachineAdapter): void
    {
        self::bootKernelWithStateMachineAdapter($stateMachineAdapter);

        // Both orders hold a unit of the same variant. The first order's other unit is no longer on hold, so
        // when `complete` pays that order, Sylius sells the shared unit and then fails on the other one: by
        // then the payment, the order and the shared variant have moved on
        $shared = $this->createTrackedVariant('t_shirt', onHand: 5, onHold: 2);
        $unheld = $this->createTrackedVariant('mug', onHand: 5, onHold: 0);
        $failing = $this->createSyliusPayment(PaymentInterface::STATE_AUTHORIZED, OrderPaymentStates::STATE_AUTHORIZED, quickpayPaymentId: 501, orderNumber: '000000001', orderedVariants: [$shared, $unheld]);
        $next = $this->createSyliusPayment(PaymentInterface::STATE_AUTHORIZED, OrderPaymentStates::STATE_AUTHORIZED, quickpayPaymentId: 502, orderNumber: '000000002', orderedVariants: [$shared]);
        $this->quickpay()->respond('GET', '/payments/501', self::quickpayPayment(501, 'processed', ['authorize', 'capture'], balance: 10_000));
        $this->quickpay()->respond('GET', '/payments/502', self::quickpayPayment(502, 'processed', ['authorize', 'capture'], balance: 10_000));

        $tester = $this->executeCommand([]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString(sprintf('Payment %s: Not enough units', (string) $failing->getId()), $tester->getDisplay());
        self::assertStringContainsString('2 checked, 1 transitioned, 0 unchanged, 0 skipped, 1 errored', $tester->getDisplay());

        // Reported as errored and saved as it was, so the next run retries it
        $failing = $this->reload($failing);
        self::assertSame(PaymentInterface::STATE_AUTHORIZED, $failing->getState());
        self::assertSame(OrderPaymentStates::STATE_AUTHORIZED, $failing->getOrder()?->getPaymentState());
        self::assertSame(['onHand' => 5, 'onHold' => 0], $this->inventoryOf($unheld));

        // The run went on with the next payment, selling its unit of the shared variant from the committed counts
        $next = $this->reload($next);
        self::assertSame(PaymentInterface::STATE_COMPLETED, $next->getState());
        self::assertSame(OrderPaymentStates::STATE_PAID, $next->getOrder()?->getPaymentState());
        self::assertSame(['onHand' => 4, 'onHold' => 1], $this->inventoryOf($shared));

        // The next run, once the other unit is back on hold
        $this->entityManager()->getConnection()->executeStatement('UPDATE sylius_product_variant SET on_hold = 1 WHERE id = ?', [$unheld->getId()]);

        $tester = $this->executeCommand([]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('1 checked, 1 transitioned, 0 unchanged, 0 skipped, 0 errored', $tester->getDisplay());
        $failing = $this->reload($failing);
        self::assertSame(PaymentInterface::STATE_COMPLETED, $failing->getState());
        self::assertSame(OrderPaymentStates::STATE_PAID, $failing->getOrder()?->getPaymentState());
        self::assertSame(['onHand' => 3, 'onHold' => 0], $this->inventoryOf($shared));
        self::assertSame(['onHand' => 4, 'onHold' => 0], $this->inventoryOf($unheld));
    }

    /**
     * @test
     */
    public function a_payment_whose_changes_cannot_be_saved_does_not_stop_the_run(): void
    {
        self::bootKernel();

        $variant = $this->createTrackedVariant('t_shirt', onHand: 5, onHold: 1);
        $failing = $this->createSyliusPayment(PaymentInterface::STATE_AUTHORIZED, OrderPaymentStates::STATE_AUTHORIZED, quickpayPaymentId: 501, orderNumber: '000000001', orderedVariants: [$variant]);
        $next = $this->createSyliusPayment(PaymentInterface::STATE_AUTHORIZED, OrderPaymentStates::STATE_AUTHORIZED, quickpayPaymentId: 502, orderNumber: '000000002');
        $this->quickpay()->respond('GET', '/payments/501', self::quickpayPayment(501, 'processed', ['authorize', 'capture'], balance: 10_000));
        $this->quickpay()->respond('GET', '/payments/502', self::quickpayPayment(502, 'processed', ['authorize', 'capture'], balance: 10_000));

        // The variant's stock is edited behind the back of the entity manager that loaded it, so saving the
        // sale fails Sylius' optimistic lock, and Doctrine closes the entity manager
        $this->entityManager()->getConnection()->executeStatement('UPDATE sylius_product_variant SET version = version + 1 WHERE id = ?', [$variant->getId()]);

        $tester = $this->executeCommand([]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString(sprintf('Payment %s:', (string) $failing->getId()), $tester->getDisplay());
        self::assertStringContainsString('2 checked, 1 transitioned, 0 unchanged, 0 skipped, 1 errored', $tester->getDisplay());

        $failing = $this->reload($failing);
        self::assertSame(PaymentInterface::STATE_AUTHORIZED, $failing->getState());
        self::assertSame(OrderPaymentStates::STATE_AUTHORIZED, $failing->getOrder()?->getPaymentState());
        self::assertSame(['onHand' => 5, 'onHold' => 1], $this->inventoryOf($variant));

        $next = $this->reload($next);
        self::assertSame(PaymentInterface::STATE_COMPLETED, $next->getState());
        self::assertSame(OrderPaymentStates::STATE_PAID, $next->getOrder()?->getPaymentState());
    }

    /**
     * @test
     */
    public function a_dry_run_reports_the_transition_sylius_would_apply_and_writes_nothing(): void
    {
        self::bootKernel();

        $payment = $this->createSyliusPayment(PaymentInterface::STATE_AUTHORIZED, OrderPaymentStates::STATE_AUTHORIZED, quickpayPaymentId: 501);
        $details = $payment->getDetails();
        $this->quickpay()->respond('GET', '/payments/501', self::quickpayPayment(501, 'processed', ['authorize', 'capture'], balance: 10_000));

        $tester = $this->executeCommand(['--dry-run' => true]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString(
            sprintf('Payment %s: "authorized", would apply "complete" (Quickpay → "completed")', (string) $payment->getId()),
            $tester->getDisplay(),
        );
        self::assertStringContainsString('1 checked, 1 transitioned (dry run), 0 unchanged, 0 skipped, 0 errored', $tester->getDisplay());

        $payment = $this->reload($payment);
        self::assertSame(PaymentInterface::STATE_AUTHORIZED, $payment->getState());
        self::assertSame(OrderPaymentStates::STATE_AUTHORIZED, $payment->getOrder()?->getPaymentState());
        self::assertSame($details, $payment->getDetails());
    }

    /**
     * @param array<string, mixed> $input
     */
    private function executeCommand(array $input): CommandTester
    {
        $kernel = self::$kernel;
        self::assertNotNull($kernel);

        $tester = new CommandTester((new Application($kernel))->find('setono:sylius-quickpay:reconcile-payments'));
        $tester->execute($input);

        return $tester;
    }
}
