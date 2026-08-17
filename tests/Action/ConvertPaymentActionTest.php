<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Tests\Action;

use Payum\Core\Exception\LogicException;
use Payum\Core\Model\Payment;
use Payum\Core\Model\Token;
use Payum\Core\Payum;
use Payum\Core\Request\Convert;
use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;
use Setono\SyliusQuickpayPlugin\Action\ConvertPaymentAction;
use Setono\SyliusQuickpayPlugin\Taxation\VatRateResolverInterface;

/**
 * Covers the path for a payment that already exists at Quickpay (details carry a quickpayPaymentId),
 * which needs neither the API nor the Payum storage. The create path is exercised by the integration
 * suite against the test application.
 */
final class ConvertPaymentActionTest extends TestCase
{
    use ProphecyTrait;

    /**
     * @test
     */
    public function it_sends_the_customer_back_to_the_token_target_url_and_cancels_to_the_after_url(): void
    {
        $token = $this->createToken();
        $request = new Convert($this->createPayment(['quickpayPaymentId' => 123, 'currency' => 'DKK']), 'array', $token);

        $this->createAction()->execute($request);

        /** @var array<string, mixed> $result */
        $result = $request->getResult();

        self::assertSame('https://shop.test/payment/capture/abc', $result['continue_url']);
        self::assertSame('https://shop.test/order/after-pay/xyz', $result['cancel_url']);
        self::assertSame(123, $result['quickpayPaymentId']);
    }

    /**
     * @test
     */
    public function it_keeps_the_currency_when_it_is_unchanged_and_backfills_it_when_missing(): void
    {
        $unchanged = new Convert($this->createPayment(['quickpayPaymentId' => 123, 'currency' => 'DKK']), 'array', $this->createToken());
        $missing = new Convert($this->createPayment(['quickpayPaymentId' => 123]), 'array', $this->createToken());

        $action = $this->createAction();
        $action->execute($unchanged);
        $action->execute($missing);

        /** @var array<string, mixed> $unchangedResult */
        $unchangedResult = $unchanged->getResult();
        /** @var array<string, mixed> $missingResult */
        $missingResult = $missing->getResult();

        self::assertSame('DKK', $unchangedResult['currency']);
        self::assertSame('DKK', $missingResult['currency']);
    }

    /**
     * @test
     */
    public function it_throws_when_the_currency_drifts_after_the_quickpay_payment_was_created(): void
    {
        $request = new Convert($this->createPayment(['quickpayPaymentId' => 123, 'currency' => 'EUR']), 'array', $this->createToken());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The Quickpay payment 123 was created in EUR, but the Payum payment now says DKK');

        $this->createAction()->execute($request);
    }

    private function createAction(): ConvertPaymentAction
    {
        return new ConvertPaymentAction(
            $this->prophesize(Payum::class)->reveal(),
            $this->prophesize(VatRateResolverInterface::class)->reveal(),
        );
    }

    /**
     * @param array<string, mixed> $details
     */
    private function createPayment(array $details): Payment
    {
        $payment = new Payment();
        $payment->setNumber('000000042');
        $payment->setCurrencyCode('DKK');
        $payment->setTotalAmount(24999);
        $payment->setDetails($details);

        return $payment;
    }

    private function createToken(): Token
    {
        $token = new Token();
        $token->setGatewayName('quickpay');
        $token->setTargetUrl('https://shop.test/payment/capture/abc');
        $token->setAfterUrl('https://shop.test/order/after-pay/xyz');

        return $token;
    }
}
