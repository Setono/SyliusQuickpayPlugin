<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Quickpay;

use Setono\Quickpay\Response\Payment\Operation;
use Setono\Quickpay\Response\Payment\Payment;

/**
 * How Quickpay's callback about an operation was answered, as Quickpay records it on the operation: not sent yet,
 * answered with a status code (a success or a failure, as Quickpay judged it), or sent without an answer
 */
final class CallbackOutcome
{
    private function __construct(
        /** Whether Quickpay judged the callback a success; null when it wasn't sent */
        public readonly ?bool $success,
        /** The status code the store answered with; null when the callback wasn't sent or got no answer */
        public readonly ?string $responseCode,
        public readonly ?\DateTimeImmutable $at,
        /** Where the callback went: the payment's own callback url or the account-wide one; null when it wasn't sent */
        public readonly ?string $url,
    ) {
    }

    public static function notSent(): self
    {
        return new self(null, null, null, null);
    }

    public static function answered(string $responseCode, bool $success, ?\DateTimeImmutable $at, ?string $url): self
    {
        return new self($success, $responseCode, $at, $url);
    }

    /**
     * Quickpay counts a callback the store never answered as failed
     */
    public static function noAnswer(?\DateTimeImmutable $at, ?string $url): self
    {
        return new self(false, null, $at, $url);
    }

    /**
     * The callback outcome of the payment's operation, or null when the payment doesn't record one readably. The SDK
     * models only the operation's callback url, so the rest is read from the payment's raw operations, matched by id.
     * A callback time that can't be read is left out.
     */
    public static function of(Payment $payment, Operation $operation): ?self
    {
        $raw = self::rawOperation($payment, $operation->id);
        if (null === $raw || !array_key_exists('callback_success', $raw)) {
            return null;
        }

        $success = $raw['callback_success'];
        $responseCode = $raw['callback_response_code'] ?? null;
        $at = self::time($raw['callback_at'] ?? null);

        if (null === $success) {
            return self::notSent();
        }

        if (!is_bool($success)) {
            return null;
        }

        if (null === $responseCode) {
            // A success without an answer is no outcome Quickpay records
            return $success ? null : self::noAnswer($at, $operation->callbackUrl);
        }

        if (!is_string($responseCode) || 1 !== preg_match('/^\d{3}$/', $responseCode)) {
            return null;
        }

        return self::answered($responseCode, $success, $at, $operation->callbackUrl);
    }

    public function isSent(): bool
    {
        return null !== $this->success;
    }

    public function isAnswered(): bool
    {
        return null !== $this->responseCode;
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private static function rawOperation(Payment $payment, int $id): ?array
    {
        $operations = $payment->raw['operations'] ?? null;
        if (!is_array($operations)) {
            return null;
        }

        foreach ($operations as $operation) {
            if (is_array($operation) && $id === ($operation['id'] ?? null)) {
                return $operation;
            }
        }

        return null;
    }

    /**
     * Read as the SDK reads Quickpay's times
     */
    private static function time(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value)) {
            return null;
        }

        foreach (['Y-m-d\TH:i:sP', 'Y-m-d\TH:i:s.uP'] as $format) {
            $time = \DateTimeImmutable::createFromFormat($format, $value);
            if (false !== $time) {
                return $time;
            }
        }

        return null;
    }
}
