<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\GatewayConfiguration;

/**
 * When a Quickpay payment's money is captured (see GLOSSARY.md). It is stored as Sylius' `use_authorize`
 * gateway option, which picks the checkout flow: Authorize, or Capture.
 */
enum CaptureMode
{
    /**
     * Authorized at checkout, captured when the Sylius payment completes
     */
    case OnCompletion;

    /**
     * Captured by Quickpay at authorization
     */
    case Immediately;

    /**
     * The capture mode a stored `use_authorize` stands for, read as Sylius' checkout reads it: it authorizes
     * when the option is set and true under PHP's bool cast, and executes `Capture` otherwise
     */
    public static function fromUseAuthorize(mixed $useAuthorize): self
    {
        return (bool) $useAuthorize ? self::OnCompletion : self::Immediately;
    }

    /**
     * The `use_authorize` that stores this capture mode
     */
    public function useAuthorize(): bool
    {
        return self::OnCompletion === $this;
    }
}
