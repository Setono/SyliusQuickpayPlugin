<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\GatewayConfiguration;

/**
 * The gateway options of a readable gateway configuration, even one payum-quickpay rejects: each option it accepts
 * as it reads it, each one it rejects as stored. Read by {@see GatewayConfiguration::optionsWithRejectedAsStored()},
 * for whoever shows or checks a configuration that may still need fixing.
 */
final class GatewayOptionValues
{
    /**
     * @param array<string, mixed> $values every gateway option payum-quickpay reads, keyed by its current name
     * @param list<string> $rejected the options whose value is as stored, because payum-quickpay rejects them
     * @param CaptureMode $captureMode the capture mode, with the deprecated `auto_capture` folded in unless it is
     *                                 rejected
     */
    public function __construct(
        public readonly array $values,
        public readonly array $rejected,
        public readonly CaptureMode $captureMode,
    ) {
    }

    /**
     * Whether the option's value is as stored, rather than as payum-quickpay reads it
     */
    public function isRejected(string $option): bool
    {
        return \in_array($option, $this->rejected, true);
    }
}
