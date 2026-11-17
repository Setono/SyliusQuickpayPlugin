<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Tests\Unit\Quickpay;

use PHPUnit\Framework\TestCase;
use Setono\Quickpay\Response\Payment\Operation;
use Setono\Quickpay\Response\Payment\Payment;
use Setono\SyliusQuickpayPlugin\Quickpay\CallbackOutcome;

/**
 * How Quickpay's callback about an operation was answered, as Quickpay records it on the operation: the SDK models only
 * its url, so the rest is read from the payment's raw operations
 */
final class CallbackOutcomeTest extends TestCase
{
    private const CALLBACK_URL = 'https://shop.example.com/payment/notify/the-token';

    /**
     * @test
     *
     * @dataProvider answeredCallbacks
     */
    public function it_reads_a_callback_the_store_answered(bool $success, string $responseCode): void
    {
        [$payment, $operation] = self::paymentWithOperation([
            'callback_success' => $success,
            'callback_response_code' => $responseCode,
            'callback_at' => '2026-11-17T09:12:34.567Z',
        ]);

        $outcome = CallbackOutcome::of($payment, $operation);

        self::assertNotNull($outcome);
        self::assertTrue($outcome->isSent());
        self::assertTrue($outcome->isAnswered());
        self::assertSame($success, $outcome->success);
        self::assertSame($responseCode, $outcome->responseCode);
        self::assertEquals(new \DateTimeImmutable('2026-11-17 09:12:34.567 UTC'), $outcome->at);
        self::assertSame(self::CALLBACK_URL, $outcome->url);
    }

    /**
     * @return iterable<string, array{bool, string}>
     */
    public static function answeredCallbacks(): iterable
    {
        yield 'reconciled' => [true, '204'];

        yield 'refused, as Quickpay judged it' => [false, '401'];
    }

    /**
     * The store never answered: unreachable, or slower than Quickpay waits
     *
     * @test
     */
    public function it_reads_a_callback_that_got_no_answer(): void
    {
        [$payment, $operation] = self::paymentWithOperation([
            'callback_success' => false,
            'callback_response_code' => null,
            'callback_at' => '2026-11-17T09:12:34Z',
        ]);

        $outcome = CallbackOutcome::of($payment, $operation);

        self::assertNotNull($outcome);
        self::assertTrue($outcome->isSent());
        self::assertFalse($outcome->isAnswered());
        self::assertFalse($outcome->success);
        self::assertEquals(new \DateTimeImmutable('2026-11-17 09:12:34 UTC'), $outcome->at);
        self::assertSame(self::CALLBACK_URL, $outcome->url);
    }

    /**
     * An operation still pending, say: Quickpay sends its callback once the operation has an outcome
     *
     * @test
     */
    public function it_reads_a_callback_not_sent_yet(): void
    {
        [$payment, $operation] = self::paymentWithOperation([
            'callback_success' => null,
            'callback_response_code' => null,
            'callback_at' => null,
        ]);

        $outcome = CallbackOutcome::of($payment, $operation);

        self::assertNotNull($outcome);
        self::assertFalse($outcome->isSent());
        self::assertFalse($outcome->isAnswered());
        self::assertNull($outcome->at);
        self::assertNull($outcome->url);
    }

    /**
     * @test
     *
     * @dataProvider unreadableCallbackFields
     *
     * @param array<string, mixed> $callbackFields
     */
    public function it_reads_no_outcome_from_callback_fields_it_cannot_read(array $callbackFields): void
    {
        [$payment, $operation] = self::paymentWithOperation($callbackFields);

        self::assertNull(CallbackOutcome::of($payment, $operation));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function unreadableCallbackFields(): iterable
    {
        yield 'no callback fields' => [[]];

        yield 'no success' => [['callback_response_code' => '204', 'callback_at' => '2026-11-17T09:12:34Z']];

        yield 'a success that is no bool' => [['callback_success' => 'true', 'callback_response_code' => '204', 'callback_at' => '2026-11-17T09:12:34Z']];

        yield 'a response code that is no status code' => [['callback_success' => false, 'callback_response_code' => 'timeout', 'callback_at' => '2026-11-17T09:12:34Z']];

        yield 'a response code that is no string' => [['callback_success' => true, 'callback_response_code' => ['204'], 'callback_at' => '2026-11-17T09:12:34Z']];

        yield 'a success without a response code' => [['callback_success' => true, 'callback_response_code' => null, 'callback_at' => '2026-11-17T09:12:34Z']];
    }

    /**
     * @test
     *
     * @dataProvider unreadableOperations
     *
     * @param array<array-key, mixed> $raw
     */
    public function it_reads_no_outcome_when_the_payment_holds_no_readable_raw_operation(array $raw): void
    {
        [$payment, $operation] = self::paymentWithOperation(['callback_success' => true, 'callback_response_code' => '204']);
        $payment->raw = $raw;

        self::assertNull(CallbackOutcome::of($payment, $operation));
    }

    /**
     * @return iterable<string, array{array<array-key, mixed>}>
     */
    public static function unreadableOperations(): iterable
    {
        $callback = ['callback_success' => true, 'callback_response_code' => '204'];

        yield 'no raw payment, as on a payment built by hand' => [[]];

        yield 'operations that are no list' => [['operations' => 'none']];

        yield 'an operation that is no array' => [['operations' => ['the operation']]];

        yield 'only another operation' => [['operations' => [['id' => 2] + $callback]]];

        yield 'an operation without an id' => [['operations' => [$callback]]];
    }

    /**
     * @test
     */
    public function it_leaves_out_a_time_it_cannot_read(): void
    {
        [$payment, $operation] = self::paymentWithOperation([
            'callback_success' => true,
            'callback_response_code' => '204',
            'callback_at' => 'yesterday',
        ]);

        $outcome = CallbackOutcome::of($payment, $operation);

        self::assertNotNull($outcome);
        self::assertSame('204', $outcome->responseCode);
        self::assertNull($outcome->at);
    }

    /**
     * A payment with one operation carrying the given callback fields, as the SDK maps it: the typed operation (whose
     * callback url the SDK models) and the payment's raw operations
     *
     * @param array<string, mixed> $callbackFields
     *
     * @return array{Payment, Operation}
     */
    private static function paymentWithOperation(array $callbackFields, string $callbackUrl = self::CALLBACK_URL): array
    {
        $operation = new Operation(id: 1, type: 'authorize', amount: 10_000, qpStatusCode: '20000', callbackUrl: $callbackUrl);

        $payment = new Payment(id: 501, orderId: 'qp000000001', currency: 'DKK', state: 'new', merchantId: 1, operations: [$operation]);
        $payment->raw = [
            'id' => 501,
            'operations' => [
                ['id' => 1, 'type' => 'authorize', 'amount' => 10_000, 'qp_status_code' => '20000', 'callback_url' => $callbackUrl] + $callbackFields,
            ],
        ];

        return [$payment, $operation];
    }
}
