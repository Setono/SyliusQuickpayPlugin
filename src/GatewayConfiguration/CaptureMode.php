<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\GatewayConfiguration;

/**
 * When a Quickpay payment's money is captured (see GLOSSARY.md)
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
}
