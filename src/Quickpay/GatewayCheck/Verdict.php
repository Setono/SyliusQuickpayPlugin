<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Quickpay\GatewayCheck;

/**
 * The outcome of a gateway check, with why it failed or proves nothing. The reason and the note are English, for the
 * console and logs: a caller showing a verdict to an admin words it in their language itself.
 */
final class Verdict
{
    private function __construct(
        public readonly VerdictOutcome $outcome,

        /**
         * Why the check failed, or why Quickpay's answer proves nothing; null when it passed
         */
        public readonly ?string $reason,

        /**
         * A caveat worth knowing whatever the outcome, e.g. how a pass was proven, or what the check left on the
         * Quickpay account
         */
        public readonly ?string $note,
    ) {
    }

    public static function passed(?string $note = null): self
    {
        return new self(VerdictOutcome::Passed, null, $note);
    }

    public static function failed(string $reason, ?string $note = null): self
    {
        return new self(VerdictOutcome::Failed, $reason, $note);
    }

    public static function undetermined(string $reason, ?string $note = null): self
    {
        return new self(VerdictOutcome::Undetermined, $reason, $note);
    }
}
