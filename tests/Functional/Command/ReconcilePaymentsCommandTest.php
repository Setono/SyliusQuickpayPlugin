<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Tests\Functional\Command;

use Setono\SyliusQuickpayPlugin\Tests\Functional\FunctionalTestCase;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\OrderPaymentStates;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class ReconcilePaymentsCommandTest extends FunctionalTestCase
{
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
            sprintf('Payment %s: "authorized", would apply "complete" (Quickpay status "completed")', (string) $payment->getId()),
            $tester->getDisplay(),
        );
        self::assertStringContainsString('1 checked, 1 transitioned (dry run), 0 unchanged, 0 errored', $tester->getDisplay());

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
