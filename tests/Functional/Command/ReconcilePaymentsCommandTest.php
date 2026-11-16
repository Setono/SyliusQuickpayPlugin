<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Tests\Functional\Command;

use Nyholm\Psr7\Response;
use Setono\SyliusQuickpayPlugin\Tests\Cache\RefusingArrayAdapter;
use Setono\SyliusQuickpayPlugin\Tests\Functional\FunctionalTestCase;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\OrderPaymentStates;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TraceableAdapter;
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

        $this->quickpay()->respond('GET', '/payments', self::changedQuickpayPayments(501, 502, 503, 504));
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
     * Building the gateway of a payment whose gateway configuration is unusable throws, and the run counts that
     * payment as errored and goes on
     *
     * @test
     */
    public function a_payment_on_an_unusable_gateway_configuration_is_counted_as_errored(): void
    {
        self::bootKernel();

        $unusable = $this->createSyliusPayment(PaymentInterface::STATE_AUTHORIZED, OrderPaymentStates::STATE_AUTHORIZED, quickpayPaymentId: 501, orderNumber: '000000001');
        // A branding name where the branding id belongs, stored by fixtures or the database directly
        $unusable->setMethod($this->createPaymentMethod('quickpay_se', ['api_key' => 'the-se-api-key', 'private_key' => self::PRIVATE_KEY, 'branding_id' => 'Our brand']));
        $this->entityManager()->flush();
        $transitioning = $this->createSyliusPayment(PaymentInterface::STATE_AUTHORIZED, OrderPaymentStates::STATE_AUTHORIZED, quickpayPaymentId: 502, orderNumber: '000000002');
        // Listed by the other gateway's account all the same
        $this->quickpay()->respond('GET', '/payments', self::changedQuickpayPayments(501, 502));
        $this->quickpay()->respond('GET', '/payments/502', self::quickpayPayment(502, 'processed', ['authorize', 'capture'], balance: 10_000));

        $tester = $this->executeCommand([]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        $display = $tester->getDisplay();
        self::assertStringContainsString(sprintf('Payment %s:', (string) $unusable->getId()), $display);
        self::assertStringContainsString('The gateway configuration of a Quickpay gateway is invalid: The "branding_id" option', (string) preg_replace('/\s+/', ' ', $display));
        // Its account couldn't be listed either
        self::assertStringContainsString('Gateway(s) "quickpay_se": could not list the payments Quickpay changed: The gateway configuration of the Quickpay gateway "quickpay_se" is invalid', (string) preg_replace('/\s+/', ' ', $display));
        self::assertStringContainsString('2 checked, 1 transitioned, 0 unchanged, 0 skipped, 1 errored', $display);
        self::assertNotContains('GET /payments/501', $this->quickpay()->sentRequests());
        self::assertSame(PaymentInterface::STATE_AUTHORIZED, $this->reload($unusable)->getState());
        self::assertSame(PaymentInterface::STATE_COMPLETED, $this->reload($transitioning)->getState());
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
        $this->quickpay()->respond('GET', '/payments', self::changedQuickpayPayments(501, 502));
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

        // The next run, once the other unit is back on hold, retries it, although Quickpay changed nothing since
        $this->entityManager()->getConnection()->executeStatement('UPDATE sylius_product_variant SET on_hold = 1 WHERE id = ?', [$unheld->getId()]);
        $this->quickpay()->respond('GET', '/payments', self::changedQuickpayPayments());

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
    public function a_payment_whose_changes_cannot_be_saved_is_saved_as_it_was_and_the_rest_of_the_run_errors(): void
    {
        self::bootKernel();

        $variant = $this->createTrackedVariant('t_shirt', onHand: 5, onHold: 1);
        $failing = $this->createSyliusPayment(PaymentInterface::STATE_AUTHORIZED, OrderPaymentStates::STATE_AUTHORIZED, quickpayPaymentId: 501, orderNumber: '000000001', orderedVariants: [$variant]);
        $next = $this->createSyliusPayment(PaymentInterface::STATE_AUTHORIZED, OrderPaymentStates::STATE_AUTHORIZED, quickpayPaymentId: 502, orderNumber: '000000002');
        $this->quickpay()->respond('GET', '/payments', self::changedQuickpayPayments(501, 502));
        $this->quickpay()->respond('GET', '/payments/501', self::quickpayPayment(501, 'processed', ['authorize', 'capture'], balance: 10_000));
        $this->quickpay()->respond('GET', '/payments/502', self::quickpayPayment(502, 'processed', ['authorize', 'capture'], balance: 10_000));

        // The variant's stock is edited behind the back of the entity manager that loaded it, so saving the
        // sale fails Sylius' optimistic lock, and Doctrine closes the entity manager
        $this->entityManager()->getConnection()->executeStatement('UPDATE sylius_product_variant SET version = version + 1 WHERE id = ?', [$variant->getId()]);

        $tester = $this->executeCommand([]);

        // With the entity manager closed, nothing more can be reconciled in this run: every payment after the
        // failing one is reported as errored too
        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString(sprintf('Payment %s:', (string) $failing->getId()), $tester->getDisplay());
        self::assertStringContainsString(sprintf('Payment %s:', (string) $next->getId()), $tester->getDisplay());
        self::assertStringContainsString('2 checked, 0 transitioned, 0 unchanged, 0 skipped, 2 errored', $tester->getDisplay());

        // The next run is a new process, with an entity manager of its own
        self::bootKernel();

        // Nothing of the failing payment's transition was saved, and the run changed nothing else
        $failing = $this->reload($failing);
        self::assertSame(PaymentInterface::STATE_AUTHORIZED, $failing->getState());
        self::assertSame(OrderPaymentStates::STATE_AUTHORIZED, $failing->getOrder()?->getPaymentState());
        self::assertSame(['onHand' => 5, 'onHold' => 1], $this->inventoryOf($variant));
        $next = $this->reload($next);
        self::assertSame(PaymentInterface::STATE_AUTHORIZED, $next->getState());
        self::assertSame(OrderPaymentStates::STATE_AUTHORIZED, $next->getOrder()?->getPaymentState());

        // ...and that run reconciles both
        $this->quickpay()->respond('GET', '/payments', self::changedQuickpayPayments(501, 502));
        $this->quickpay()->respond('GET', '/payments/501', self::quickpayPayment(501, 'processed', ['authorize', 'capture'], balance: 10_000));
        $this->quickpay()->respond('GET', '/payments/502', self::quickpayPayment(502, 'processed', ['authorize', 'capture'], balance: 10_000));

        $tester = $this->executeCommand([]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('2 checked, 2 transitioned, 0 unchanged, 0 skipped, 0 errored', $tester->getDisplay());
        self::assertSame(PaymentInterface::STATE_COMPLETED, $this->reload($failing)->getState());
        self::assertSame(['onHand' => 4, 'onHold' => 0], $this->inventoryOf($variant));
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
        $this->quickpay()->respond('GET', '/payments', self::changedQuickpayPayments(501));
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
        self::assertDetailsUnchanged($details, $payment);
    }

    /**
     * @test
     */
    public function a_dry_run_counts_a_payment_already_in_line_with_quickpay_as_unchanged(): void
    {
        self::bootKernel();

        $payment = $this->createSyliusPayment(PaymentInterface::STATE_AUTHORIZED, OrderPaymentStates::STATE_AUTHORIZED, quickpayPaymentId: 501);
        $details = $payment->getDetails();
        $this->quickpay()->respond('GET', '/payments', self::changedQuickpayPayments(501));
        $this->quickpay()->respond('GET', '/payments/501', self::quickpayPayment(501, 'new', ['authorize'], balance: 0));

        $tester = $this->executeCommand(['--dry-run' => true]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString(
            sprintf('Payment %s: "authorized", no transition (Quickpay → "authorized")', (string) $payment->getId()),
            $tester->getDisplay(),
        );
        self::assertStringContainsString('1 checked, 0 transitioned (dry run), 1 unchanged, 0 skipped, 0 errored', $tester->getDisplay());

        $payment = $this->reload($payment);
        self::assertSame(PaymentInterface::STATE_AUTHORIZED, $payment->getState());
        self::assertDetailsUnchanged($details, $payment);
    }

    /**
     * @test
     */
    public function it_reconciles_a_stuck_payment_quickpay_changed_and_asks_nothing_about_one_quickpay_did_not_change(): void
    {
        self::bootKernel();

        $stuck = $this->createSyliusPayment(PaymentInterface::STATE_PROCESSING, OrderPaymentStates::STATE_AWAITING_PAYMENT, quickpayPaymentId: 501, orderNumber: '000000001');
        $unchanged = $this->createSyliusPayment(PaymentInterface::STATE_AUTHORIZED, OrderPaymentStates::STATE_AUTHORIZED, quickpayPaymentId: 502, orderNumber: '000000002');
        $this->quickpay()->respond('GET', '/payments', self::changedQuickpayPayments(501));
        $this->quickpay()->respond('GET', '/payments/501', self::quickpayPayment(501, 'processed', ['authorize', 'capture'], balance: 10_000));

        $tester = $this->executeCommand([]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString(
            sprintf('Payment %s: "processing" -> "completed" (Quickpay → "completed")', (string) $stuck->getId()),
            $tester->getDisplay(),
        );
        self::assertStringContainsString('1 checked, 1 transitioned, 0 unchanged, 0 skipped, 0 errored', $tester->getDisplay());
        self::assertContains('GET /payments/501', $this->quickpay()->sentRequests());
        self::assertNotContains('GET /payments/502', $this->quickpay()->sentRequests());
        self::assertSame(PaymentInterface::STATE_COMPLETED, $this->reload($stuck)->getState());
        self::assertSame(PaymentInterface::STATE_AUTHORIZED, $this->reload($unchanged)->getState());
    }

    /**
     * One payment that keeps failing costs one request per run, is reported every run, and never holds the other
     * changes back
     *
     * @test
     */
    public function an_errored_payment_is_retried_every_run_while_the_watermark_moves_on(): void
    {
        self::bootKernel();

        $payment = $this->createSyliusPayment(PaymentInterface::STATE_PROCESSING, OrderPaymentStates::STATE_AWAITING_PAYMENT, quickpayPaymentId: 501);
        $this->quickpay()->respond('GET', '/payments', self::changedQuickpayPayments(501));
        $this->quickpay()->respond('GET', '/payments/501', new Response(500, ['Content-Type' => 'application/json'], '{"message": "Quickpay is down"}'));

        $tester = $this->executeCommand([]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString(sprintf('Payment %s:', (string) $payment->getId()), $tester->getDisplay());
        self::assertStringNotContainsString('failing since', $tester->getDisplay());

        // Quickpay changed nothing since, and the payment still fails
        $this->quickpay()->respond('GET', '/payments', self::changedQuickpayPayments());
        $sent = \count($this->quickpay()->sentRequests());

        $tester = $this->executeCommand([]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        $display = self::normalized($tester->getDisplay());
        self::assertStringContainsString('(since the last run)', $display);
        self::assertStringContainsString('1 payment(s) to retry', $display);
        self::assertMatchesRegularExpression(sprintf('/Payment %s: .+ \(failing since \d{4}-\d{2}-\d{2} \d{2}:\d{2}\)/', (string) $payment->getId()), $display);
        self::assertStringContainsString('1 checked, 0 transitioned, 0 unchanged, 0 skipped, 1 errored', $display);
        // One request about it, besides the list
        self::assertSame(['GET /payments', 'GET /payments/501'], \array_slice($this->quickpay()->sentRequests(), $sent));

        // It reconciles at last, and is no payment to retry after that
        $this->quickpay()->respond('GET', '/payments/501', self::quickpayPayment(501, 'processed', ['authorize', 'capture'], balance: 10_000));

        $tester = $this->executeCommand([]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('1 checked, 1 transitioned, 0 unchanged, 0 skipped, 0 errored', $tester->getDisplay());
        self::assertSame(PaymentInterface::STATE_COMPLETED, $this->reload($payment)->getState());
        $sent = \count($this->quickpay()->sentRequests());

        $tester = $this->executeCommand([]);

        $tester->assertCommandIsSuccessful();
        self::assertStringNotContainsString('to retry', $tester->getDisplay());
        self::assertStringContainsString('0 checked', $tester->getDisplay());
        self::assertSame(['GET /payments'], \array_slice($this->quickpay()->sentRequests(), $sent));
    }

    /**
     * @test
     */
    public function each_run_says_which_payments_quickpay_changed_it_looks_at(): void
    {
        self::bootKernel();

        $this->quickpayPaymentMethod();
        $this->entityManager()->flush();
        $this->quickpay()->respond('GET', '/payments', self::changedQuickpayPayments());

        $before = new \DateTimeImmutable();
        $tester = $this->executeCommand(['--since' => '12 hours']);
        $after = new \DateTimeImmutable();

        $tester->assertCommandIsSuccessful();
        self::assertContains(self::windowLineIn($tester), [
            sprintf('Gateway(s) "quickpay": the payments Quickpay changed since %s (no earlier run: the last 12 hours)', $before->modify('-12 hours')->format('Y-m-d H:i')),
            sprintf('Gateway(s) "quickpay": the payments Quickpay changed since %s (no earlier run: the last 12 hours)', $after->modify('-12 hours')->format('Y-m-d H:i')),
        ]);

        $tester = $this->executeCommand(['--since' => '12 hours']);

        $tester->assertCommandIsSuccessful();
        // The previous run's start, less five minutes for clocks out of step
        self::assertContains(self::windowLineIn($tester), [
            sprintf('Gateway(s) "quickpay": the payments Quickpay changed since %s (since the last run)', $before->modify('-5 minutes')->format('Y-m-d H:i')),
            sprintf('Gateway(s) "quickpay": the payments Quickpay changed since %s (since the last run)', $after->modify('-5 minutes')->format('Y-m-d H:i')),
        ]);
    }

    /**
     * The previous run's watermark, its start less five minutes, lies before the last minute
     *
     * @test
     */
    public function a_run_whose_last_run_was_longer_ago_than_the_since_period_looks_back_the_since_period(): void
    {
        self::bootKernel();

        $this->quickpayPaymentMethod();
        $this->entityManager()->flush();
        $this->quickpay()->respond('GET', '/payments', self::changedQuickpayPayments());
        $this->executeCommand([])->assertCommandIsSuccessful();

        $tester = $this->executeCommand(['--since' => '1 minute']);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('(the last run was more than 1 minute ago: the last 1 minute)', self::windowLineIn($tester));
    }

    /**
     * @test
     */
    public function a_full_run_looks_back_the_since_period_whatever_the_last_run(): void
    {
        self::bootKernel();

        $this->quickpayPaymentMethod();
        $this->entityManager()->flush();
        $this->quickpay()->respond('GET', '/payments', self::changedQuickpayPayments());
        $this->executeCommand([])->assertCommandIsSuccessful();

        $before = new \DateTimeImmutable();
        $tester = $this->executeCommand(['--full' => true]);
        $after = new \DateTimeImmutable();

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('(--full: the last 7 days)', self::windowLineIn($tester));
        // Quickpay is asked to the second
        $query = $this->quickpay()->sentRequestOptions()[1]['query'] ?? null;
        self::assertIsArray($query);
        self::assertIsString($query['min_time'] ?? null);
        $minTime = new \DateTimeImmutable($query['min_time']);
        self::assertGreaterThanOrEqual($before->modify('-7 days -1 second'), $minTime);
        self::assertLessThanOrEqual($after->modify('-7 days'), $minTime);
    }

    /**
     * @test
     */
    public function a_dry_run_leaves_the_watermark_as_it_was(): void
    {
        self::bootKernel();

        $this->quickpayPaymentMethod();
        $this->entityManager()->flush();
        $this->quickpay()->respond('GET', '/payments', self::changedQuickpayPayments());
        $this->executeCommand(['--dry-run' => true])->assertCommandIsSuccessful();

        $tester = $this->executeCommand([]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('(no earlier run: the last 7 days)', self::windowLineIn($tester));
    }

    /**
     * @test
     */
    public function a_quickpay_account_quickpay_fails_to_list_fails_the_run(): void
    {
        self::bootKernel();

        $this->createSyliusPayment(PaymentInterface::STATE_NEW, OrderPaymentStates::STATE_AWAITING_PAYMENT, quickpayPaymentId: 501);
        $this->quickpay()->respond('GET', '/payments', new Response(500, ['Content-Type' => 'application/json'], '{"message": "Quickpay is down"}'));

        $tester = $this->executeCommand([]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        $display = self::normalized($tester->getDisplay());
        self::assertStringContainsString('Gateway(s) "quickpay": could not list the payments Quickpay changed:', $display);
        self::assertStringContainsString('0 checked, 0 transitioned, 0 unchanged, 0 skipped, 0 errored', $display);
        self::assertSame(['GET /payments'], $this->quickpay()->sentRequests());
    }

    /**
     * @test
     */
    public function a_run_whose_state_the_cache_refuses_to_save_fails(): void
    {
        self::bootKernel();
        self::getContainer()->set('setono_sylius_quickpay.reconcile', new RefusingArrayAdapter());

        $payment = $this->createSyliusPayment(PaymentInterface::STATE_PROCESSING, OrderPaymentStates::STATE_AWAITING_PAYMENT, quickpayPaymentId: 501);
        $this->quickpay()->respond('GET', '/payments', self::changedQuickpayPayments(501));
        $this->quickpay()->respond('GET', '/payments/501', self::quickpayPayment(501, 'processed', ['authorize', 'capture'], balance: 10_000));

        $tester = $this->executeCommand([]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        $display = self::normalized($tester->getDisplay());
        self::assertStringContainsString('Could not save the reconcile state; the next run lists the changes since the previous one again.', $display);
        self::assertStringContainsString('1 checked, 1 transitioned, 0 unchanged, 0 skipped, 0 errored', $display);
        // The payment was reconciled all the same
        self::assertSame(PaymentInterface::STATE_COMPLETED, $this->reload($payment)->getState());
    }

    /**
     * Overlapping runs, say cron starting one while the last is still going, neither ask Quickpay the same thing
     * twice nor move the watermark against each other. A skipped run is no failure: the run going on does the work.
     *
     * @test
     */
    public function a_run_started_while_another_is_reconciling_payments_skips_and_changes_nothing(): void
    {
        self::bootKernel();

        // An earlier run left a watermark and a payment to retry
        $payment = $this->createSyliusPayment(PaymentInterface::STATE_PROCESSING, OrderPaymentStates::STATE_AWAITING_PAYMENT, quickpayPaymentId: 501);
        $this->quickpay()->respond('GET', '/payments', self::changedQuickpayPayments(501));
        $this->quickpay()->respond('GET', '/payments/501', new Response(500, ['Content-Type' => 'application/json'], '{"message": "Quickpay is down"}'));
        self::assertSame(Command::FAILURE, $this->executeCommand([])->getStatusCode());
        $stored = $this->storedRunState();
        self::assertNotSame([], $stored);

        // Quickpay captured it since
        $this->quickpay()->respond('GET', '/payments/501', self::quickpayPayment(501, 'processed', ['authorize', 'capture'], balance: 10_000));
        $sent = \count($this->quickpay()->sentRequests());
        $this->holdRunLock();

        $tester = $this->executeCommand([]);

        $tester->assertCommandIsSuccessful();
        self::assertSame('Another run is reconciling payments; skipped.', trim($tester->getDisplay()));
        self::assertSame([], \array_slice($this->quickpay()->sentRequests(), $sent));
        self::assertSame(PaymentInterface::STATE_PROCESSING, $this->reload($payment)->getState());
        self::assertSame($stored, $this->storedRunState());
    }

    /**
     * However a run ends, the run after it isn't skipped
     *
     * @test
     */
    public function a_finished_run_releases_the_run_lock_so_the_next_run_reconciles(): void
    {
        self::bootKernel();

        $payment = $this->createSyliusPayment(PaymentInterface::STATE_PROCESSING, OrderPaymentStates::STATE_AWAITING_PAYMENT, quickpayPaymentId: 501);

        // A run that throws: nothing answers its list request
        try {
            $this->executeCommand([]);
            self::fail('The run did not throw');
        } catch (\LogicException $e) {
            self::assertStringContainsString('No response scripted for "GET /payments', $e->getMessage());
        }

        // A run that fails: a payment errors
        $this->quickpay()->respond('GET', '/payments', self::changedQuickpayPayments(501));
        $this->quickpay()->respond('GET', '/payments/501', new Response(500, ['Content-Type' => 'application/json'], '{"message": "Quickpay is down"}'));
        self::assertSame(Command::FAILURE, $this->executeCommand([])->getStatusCode());

        // A run that succeeds
        $this->quickpay()->respond('GET', '/payments/501', self::quickpayPayment(501, 'processed', ['authorize', 'capture'], balance: 10_000));
        $tester = $this->executeCommand([]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('1 checked, 1 transitioned, 0 unchanged, 0 skipped, 0 errored', $tester->getDisplay());
        self::assertSame(PaymentInterface::STATE_COMPLETED, $this->reload($payment)->getState());

        // ...and released it too
        $this->holdRunLock();
    }

    /**
     * A dry run and the fraud report change nothing, so a run reconciling payments never blocks them
     *
     * @test
     */
    public function a_dry_run_and_the_fraud_report_run_while_another_run_is_reconciling_payments(): void
    {
        self::bootKernel();

        $payment = $this->createSyliusPayment(PaymentInterface::STATE_AUTHORIZED, OrderPaymentStates::STATE_AUTHORIZED, quickpayPaymentId: 501);
        $this->quickpay()->respond('GET', '/payments', self::changedQuickpayPayments(501));
        $this->quickpay()->respond('GET', '/payments/501', self::quickpayPayment(501, 'processed', ['authorize', 'capture'], balance: 10_000));
        $this->holdRunLock();

        $tester = $this->executeCommand(['--dry-run' => true]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString(
            sprintf('Payment %s: "authorized", would apply "complete" (Quickpay → "completed")', (string) $payment->getId()),
            $tester->getDisplay(),
        );
        self::assertSame(['GET /payments', 'GET /payments/501'], $this->quickpay()->sentRequests());

        $this->quickpay()->respond('GET', '/payments', self::fraudSuspectedQuickpayPayments('000000001'));

        $tester = $this->executeCommand(['--fraud-suspected' => true]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('1 payment(s) flagged as fraud suspected', self::normalized($tester->getDisplay()));
        self::assertSame(['GET /payments', 'GET /payments/501', 'GET /payments'], $this->quickpay()->sentRequests());
    }

    /**
     * @test
     */
    public function the_limit_is_for_the_fraud_report_only(): void
    {
        self::bootKernel();

        $tester = $this->executeCommand(['--limit' => '10']);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString('--limit only applies to --fraud-suspected', self::normalized($tester->getDisplay()));
        self::assertSame([], $this->quickpay()->sentRequests());
    }

    /**
     * @test
     */
    public function the_fraud_report_without_a_limit_reports_every_flagged_payment(): void
    {
        self::bootKernel();

        $this->quickpayPaymentMethod();
        $this->entityManager()->flush();
        // More than the 100 the report used to stop at, a page of 20 at a time
        foreach (range(1, 5) as $page) {
            $this->quickpay()->respond('GET', '/payments', self::fraudSuspectedQuickpayPaymentsFrom(1000 + 20 * $page, 20), ['page' => $page]);
        }
        $this->quickpay()->respond('GET', '/payments', self::fraudSuspectedQuickpayPaymentsFrom(2000, 1), ['page' => 6]);

        $tester = $this->executeCommand(['--fraud-suspected' => true]);

        $tester->assertCommandIsSuccessful();
        $display = self::normalized($tester->getDisplay());
        self::assertStringContainsString('101 payment(s) flagged as fraud suspected', $display);
        self::assertStringNotContainsString('stopped at the limit', $display);
        self::assertCount(6, $this->quickpay()->sentRequests());
    }

    /**
     * `--limit` is the number of payments reported across gateways: once the report holds that many, the gateways
     * after it go unqueried, and the report says so
     *
     * @test
     */
    public function the_fraud_report_stops_at_the_limit_and_names_the_gateways_it_did_not_query(): void
    {
        self::bootKernel();

        $this->createPaymentMethod('quickpay_dk', ['api_key' => 'the-dk-api-key', 'private_key' => self::PRIVATE_KEY]);
        $this->createPaymentMethod('quickpay_se', ['api_key' => 'the-se-api-key', 'private_key' => self::PRIVATE_KEY]);
        $this->quickpay()->respond('GET', '/payments', self::fraudSuspectedQuickpayPayments('000000001'));

        $tester = $this->executeCommand(['--fraud-suspected' => true, '--limit' => '1']);

        // Reaching the limit isn't a failure
        $tester->assertCommandIsSuccessful();
        $display = (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
        self::assertSame(['GET /payments'], $this->quickpay()->sentRequests());

        // Nothing orders the gateway configurations, so whichever gateway was queried, the other one is named
        $gatewayByAuthorization = [
            'Authorization: Basic ' . base64_encode(':the-dk-api-key') => 'quickpay_dk',
            'Authorization: Basic ' . base64_encode(':the-se-api-key') => 'quickpay_se',
        ];
        $authorization = self::authorizationOf($this->quickpay()->sentRequestOptions()[0]);
        self::assertIsArray($authorization);
        self::assertCount(1, $authorization);
        $authorization = $authorization[0] ?? null;
        self::assertIsString($authorization);
        $queried = $gatewayByAuthorization[$authorization] ?? null;
        self::assertNotNull($queried, 'The report queried Quickpay with neither gateway\'s api key');
        $unqueried = 'quickpay_dk' === $queried ? 'quickpay_se' : 'quickpay_dk';

        self::assertStringContainsString(sprintf('%s 501 qp_000000001', $queried), $display);
        self::assertStringContainsString(sprintf('The report stopped at the limit of 1 payment(s) and didn\'t query the gateway(s) "%s".', $unqueried), $display);
    }

    /**
     * @test
     */
    public function the_fraud_report_names_a_gateway_with_an_unusable_gateway_configuration_and_still_queries_the_others(): void
    {
        self::bootKernel();

        // A branding name where the branding id belongs, stored by fixtures or the database directly
        $this->createPaymentMethod('quickpay_se', ['api_key' => 'the-se-api-key', 'private_key' => self::PRIVATE_KEY, 'branding_id' => 'Our brand']);
        $this->createPaymentMethod('quickpay_dk', ['api_key' => 'the-dk-api-key', 'private_key' => self::PRIVATE_KEY]);
        $this->quickpay()->respond('GET', '/payments', self::fraudSuspectedQuickpayPayments('000000001'));

        $tester = $this->executeCommand(['--fraud-suspected' => true]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        $display = (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
        self::assertStringContainsString('Gateway "quickpay_se": The gateway configuration of the Quickpay gateway "quickpay_se" is invalid: The "branding_id" option', $display);
        self::assertStringContainsString('quickpay_dk 501 qp_000000001', $display);
        self::assertSame(['GET /payments'], $this->quickpay()->sentRequests());
        self::assertSame(['Authorization: Basic ' . base64_encode(':the-dk-api-key')], self::authorizationOf($this->quickpay()->sentRequestOptions()[0]));
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

    /**
     * The line saying which payments Quickpay changed the run looks at, of its only Quickpay account
     */
    private static function windowLineIn(CommandTester $tester): string
    {
        $lines = array_values(array_filter(
            array_map(trim(...), explode("\n", $tester->getDisplay())),
            static fn (string $line): bool => str_starts_with($line, 'Gateway(s) '),
        ));
        self::assertCount(1, $lines);

        return $lines[0];
    }

    /**
     * What the runs so far stored for the next run to start from: each Quickpay account's watermark and the retry list
     *
     * @return array<mixed>
     */
    private function storedRunState(): array
    {
        $cache = self::getContainer()->get('setono_sylius_quickpay.reconcile');
        if ($cache instanceof TraceableAdapter) {
            $cache = $cache->getPool();
        }
        self::assertInstanceOf(ArrayAdapter::class, $cache);

        return $cache->getValues();
    }

    private static function normalized(string $display): string
    {
        return (string) preg_replace('/\s+/', ' ', $display);
    }

    /**
     * Quickpay's answer to a list of the payments it changed: a page of Quickpay payments with the given ids
     */
    private static function changedQuickpayPayments(int ...$ids): Response
    {
        $payments = [];
        foreach ($ids as $id) {
            $payments[] = [
                'id' => $id,
                'order_id' => self::ORDER_PREFIX . $id,
                'currency' => self::CURRENCY,
                'state' => 'new',
                'merchant_id' => 1,
            ];
        }

        return new Response(200, ['Content-Type' => 'application/json'], json_encode($payments, \JSON_THROW_ON_ERROR));
    }

    /**
     * Quickpay's answer to the fraud report's query: the given number of fraud suspected payments, numbered from the
     * given id on
     */
    private static function fraudSuspectedQuickpayPaymentsFrom(int $firstId, int $count): Response
    {
        $payments = [];
        foreach (range($firstId, $firstId + $count - 1) as $id) {
            $payments[] = [
                'id' => $id,
                'order_id' => self::ORDER_PREFIX . $id,
                'currency' => self::CURRENCY,
                'state' => 'new',
                'merchant_id' => 1,
                'test_mode' => true,
                'metadata' => ['fraud_suspected' => true],
            ];
        }

        return new Response(200, ['Content-Type' => 'application/json'], json_encode($payments, \JSON_THROW_ON_ERROR));
    }

    /**
     * Quickpay's answer to the fraud report's query: a page of fraud suspected payments, one per order number
     */
    private static function fraudSuspectedQuickpayPayments(string ...$orderNumbers): Response
    {
        $payments = [];
        foreach (array_values($orderNumbers) as $i => $orderNumber) {
            $payments[] = [
                'id' => 501 + $i,
                'order_id' => self::ORDER_PREFIX . $orderNumber,
                'currency' => self::CURRENCY,
                'state' => 'new',
                'merchant_id' => 1,
                'test_mode' => true,
                'metadata' => ['fraud_suspected' => true],
            ];
        }

        return new Response(200, ['Content-Type' => 'application/json'], json_encode($payments, \JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string, mixed> $options a request's options, as sent
     */
    private static function authorizationOf(array $options): mixed
    {
        $headers = $options['normalized_headers'] ?? null;
        self::assertIsArray($headers);

        return $headers['authorization'] ?? null;
    }
}
