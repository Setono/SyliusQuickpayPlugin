<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Tests\Log;

use Psr\Log\AbstractLogger;

/**
 * Keeps what it is given to log, so a test can assert on what a logger-aware service logged
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{mixed, string, array<array-key, mixed>}> each record as its level, message and context */
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
}
