<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Tests\Unit\Fraud;

use Nyholm\Psr7\Response;
use Payum\Core\Exception\LogicException;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Setono\Quickpay\Exception\InternalServerErrorException;
use Setono\Quickpay\Response\Payment\Metadata;
use Setono\Quickpay\Response\Payment\Payment as QuickpayPayment;
use Setono\SyliusQuickpayPlugin\Fraud\FraudChecker;
use Setono\SyliusQuickpayPlugin\GatewayConfiguration\UnusableGatewayConfiguration;
use Setono\SyliusQuickpayPlugin\Quickpay\QuickpayClientProviderInterface;
use Sylius\Component\Core\Model\Payment;
use Sylius\Component\Core\Model\PaymentInterface;

final class FraudCheckerTest extends TestCase
{
    use ProphecyTrait;

    /**
     * @test
     */
    public function it_reports_fraud_when_quickpay_flags_the_payment(): void
    {
        $checker = $this->createChecker(self::quickpayPayment(new Metadata(fraudSuspected: true)));

        self::assertTrue($checker->isFraudSuspected(self::createPayment()));
    }

    /**
     * @test
     */
    public function it_reports_clean_when_quickpay_does_not_flag_the_payment(): void
    {
        $checker = $this->createChecker(self::quickpayPayment(new Metadata(fraudSuspected: false)));

        self::assertFalse($checker->isFraudSuspected(self::createPayment()));
    }

    /**
     * @test
     */
    public function it_reports_clean_when_the_payment_carries_no_fraud_metadata(): void
    {
        $checker = $this->createChecker(self::quickpayPayment(null));

        self::assertFalse($checker->isFraudSuspected(self::createPayment()));
    }

    /**
     * No Quickpay gateway configuration, or no Quickpay payment id
     *
     * @test
     */
    public function it_reports_clean_when_the_payment_has_no_quickpay_payment(): void
    {
        $checker = $this->createChecker(null);

        self::assertFalse($checker->isFraudSuspected(self::createPayment()));
    }

    /**
     * The question can't be answered, and a check that can't be made never blocks a capture
     *
     * @test
     *
     * @dataProvider unreadableQuickpayPayments
     */
    public function it_fails_open_with_a_warning_when_the_quickpay_payment_cannot_be_read(\Throwable $error): void
    {
        $logger = self::recordingLogger();
        $checker = $this->createChecker($error);
        $checker->setLogger($logger);

        self::assertFalse($checker->isFraudSuspected(self::createPayment()));

        self::assertCount(1, $logger->records);
        [$level, $message, $context] = $logger->records[0];
        self::assertSame(LogLevel::WARNING, $level);
        self::assertSame('Could not check the Quickpay payment for suspected fraud: ' . $error->getMessage(), $message);
        self::assertSame(['quickpayPaymentId' => 501, 'paymentId' => null], $context);
    }

    /**
     * @return iterable<string, array{\Throwable}>
     */
    public static function unreadableQuickpayPayments(): iterable
    {
        yield 'an unusable gateway configuration' => [UnusableGatewayConfiguration::unreadable('quickpay_credit_card')];

        yield 'an unusable Quickpay payment id' => [new LogicException('The payment has not been created at Quickpay')];

        yield 'Quickpay failing' => [new InternalServerErrorException(new Response(500, [], '{"message": "boom"}'))];
    }

    /**
     * A fraud checker whose client provider answers the Quickpay payment of any Sylius payment with the given
     * one (null: the payment has no Quickpay payment), or throws the given error
     */
    private function createChecker(QuickpayPayment|\Throwable|null $quickpayPayment): FraudChecker
    {
        $quickpayClientProvider = $this->prophesize(QuickpayClientProviderInterface::class);
        $quickpayPaymentOf = $quickpayClientProvider->quickpayPaymentOf(Argument::type(PaymentInterface::class));
        if ($quickpayPayment instanceof \Throwable) {
            $quickpayPaymentOf->willThrow($quickpayPayment);
        } else {
            $quickpayPaymentOf->willReturn($quickpayPayment);
        }

        return new FraudChecker($quickpayClientProvider->reveal());
    }

    private static function quickpayPayment(?Metadata $metadata): QuickpayPayment
    {
        return new QuickpayPayment(id: 501, orderId: 'qp_000000123', currency: 'DKK', state: 'new', merchantId: 1, metadata: $metadata);
    }

    private static function createPayment(): PaymentInterface
    {
        $payment = new Payment();
        $payment->setDetails(['quickpayPaymentId' => 501]);

        return $payment;
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
