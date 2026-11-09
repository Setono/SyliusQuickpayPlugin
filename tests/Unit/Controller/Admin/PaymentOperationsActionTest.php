<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Tests\Unit\Controller\Admin;

use Nyholm\Psr7\Response as PsrResponse;
use Payum\Core\Exception\LogicException;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Setono\Quickpay\Exception\UnauthorizedException;
use Setono\Quickpay\Response\Payment\Payment as QuickpayPayment;
use Setono\SyliusQuickpayPlugin\Controller\Admin\PaymentOperationsAction;
use Setono\SyliusQuickpayPlugin\GatewayConfiguration\UnusableGatewayConfiguration;
use Setono\SyliusQuickpayPlugin\Quickpay\QuickpayClientProviderInterface;
use Setono\SyliusQuickpayPlugin\Tests\GatewayConfiguration\GatewayConfigurations;
use Sylius\Component\Core\Model\Payment;
use Sylius\Component\Core\Model\PaymentMethod;
use Sylius\Component\Core\Repository\PaymentRepositoryInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Twig\Environment;

final class PaymentOperationsActionTest extends TestCase
{
    use ProphecyTrait;

    /**
     * @test
     */
    public function it_renders_the_operations_of_the_quickpay_payment(): void
    {
        $quickpayPayment = new QuickpayPayment(id: 999999, orderId: 'qp_000000021', currency: 'EUR', state: 'processed', merchantId: 1);

        $twig = $this->prophesize(Environment::class);
        $twig->render('@SetonoSyliusQuickpayPlugin/admin/order/show/payment/_operations.html.twig', ['quickpay_payment' => $quickpayPayment])
            ->willReturn('<table>rendered</table>');

        $response = $this->createAction($quickpayPayment, $twig->reveal())(1);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('<table>rendered</table>', $response->getContent());
    }

    /**
     * No Quickpay gateway configuration, or no Quickpay payment id: the panel doesn't apply
     *
     * @test
     */
    public function it_throws_a_not_found_exception_for_a_payment_without_a_quickpay_payment(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $this->createAction(null, $this->prophesize(Environment::class)->reveal())(1);
    }

    /**
     * @test
     */
    public function it_throws_a_not_found_exception_for_an_unknown_payment(): void
    {
        $paymentRepository = $this->prophesize(PaymentRepositoryInterface::class);
        $paymentRepository->find(1)->willReturn(null);

        $quickpayClientProvider = $this->prophesize(QuickpayClientProviderInterface::class);
        $quickpayClientProvider->quickpayPaymentOf(Argument::any())->shouldNotBeCalled();

        $this->expectException(NotFoundHttpException::class);

        (new PaymentOperationsAction($paymentRepository->reveal(), $quickpayClientProvider->reveal(), $this->prophesize(Environment::class)->reveal()))(1);
    }

    /**
     * The order page stays usable, and the log says why the panel failed
     *
     * @test
     *
     * @dataProvider unreadableQuickpayPayments
     */
    public function it_renders_an_inline_notice_and_logs_a_warning_when_the_quickpay_payment_cannot_be_read(\Throwable $error): void
    {
        $twig = $this->prophesize(Environment::class);
        $twig->render('@SetonoSyliusQuickpayPlugin/admin/order/show/payment/_operations_error.html.twig')
            ->willReturn('<div>error</div>');

        $logger = self::recordingLogger();
        $action = $this->createAction($error, $twig->reveal());
        $action->setLogger($logger);

        $response = $action(1);

        self::assertSame(502, $response->getStatusCode());
        self::assertSame('<div>error</div>', $response->getContent());

        self::assertCount(1, $logger->records);
        [$level, $message, $context] = $logger->records[0];
        self::assertSame(LogLevel::WARNING, $level);
        self::assertSame('Could not load the operation history from Quickpay: ' . $error->getMessage(), $message);
        self::assertSame(['paymentId' => 1, 'gatewayName' => 'quickpay_dk', 'exception' => $error], $context);
    }

    /**
     * @return iterable<string, array{\Throwable}>
     */
    public static function unreadableQuickpayPayments(): iterable
    {
        yield 'an unusable gateway configuration' => [UnusableGatewayConfiguration::unreadable('quickpay_dk')];

        yield 'an unusable Quickpay payment id' => [new LogicException('The payment has not been created at Quickpay')];

        yield 'Quickpay refusing' => [new UnauthorizedException(new PsrResponse(401))];
    }

    /**
     * An action for payment 1, paid through the Quickpay gateway "quickpay_dk", whose client provider answers its
     * Quickpay payment with the given one (null: the payment has no Quickpay payment), or throws the given error
     */
    private function createAction(QuickpayPayment|\Throwable|null $quickpayPayment, Environment $twig): PaymentOperationsAction
    {
        $paymentMethod = new PaymentMethod();
        $paymentMethod->setGatewayConfig(GatewayConfigurations::entity(gatewayName: 'quickpay_dk'));

        $payment = new Payment();
        $payment->setMethod($paymentMethod);
        $payment->setDetails(['quickpayPaymentId' => 999999]);

        $paymentRepository = $this->prophesize(PaymentRepositoryInterface::class);
        $paymentRepository->find(1)->willReturn($payment);

        $quickpayClientProvider = $this->prophesize(QuickpayClientProviderInterface::class);
        $quickpayPaymentOf = $quickpayClientProvider->quickpayPaymentOf($payment);
        if ($quickpayPayment instanceof \Throwable) {
            $quickpayPaymentOf->willThrow($quickpayPayment);
        } else {
            $quickpayPaymentOf->willReturn($quickpayPayment);
        }

        return new PaymentOperationsAction($paymentRepository->reveal(), $quickpayClientProvider->reveal(), $twig);
    }

    /**
     * @return AbstractLogger&object{records: list<array{mixed, string, array<array-key, mixed>}>}
     */
    private static function recordingLogger(): AbstractLogger
    {
        return new class() extends AbstractLogger {
            /** @var list<array{mixed, string, array<array-key, mixed>}> */
            public array $records = [];

            /**
             * @param mixed $level
             * @param string|\Stringable $message
             * @param array<array-key, mixed> $context
             */
            public function log($level, $message, array $context = []): void
            {
                $this->records[] = [$level, (string) $message, $context];
            }
        };
    }
}
