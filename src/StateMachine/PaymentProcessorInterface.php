<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\StateMachine;

use Sylius\Component\Core\Model\PaymentInterface;

/**
 * Forwards a Sylius payment transition to Quickpay as the matching payment operation.
 *
 * Defined against the payment and the transition name rather than a state machine
 * implementation, so the contract survives a migration between state machine adapters.
 */
interface PaymentProcessorInterface
{
    public function __invoke(PaymentInterface $payment, string $transition): void;
}
