<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Payum\Extension;

use Payum\Core\Action\ActionInterface;
use Payum\Core\Request\Notify;

/**
 * Does deliberately nothing. NotifyIdempotencyExtension pre-sets it on the execution context when
 * another callback for the same payment is already being processed: Gateway::execute() skips action
 * resolution when the context already carries an action, so the Notify completes without touching
 * anything and the controller answers its normal 2xx — Quickpay considers the callback delivered and
 * stops retrying. supports() is never consulted for a pre-set action; it is implemented for
 * completeness should the action ever be registered conventionally.
 */
final class AcknowledgeNotifyAction implements ActionInterface
{
    /**
     * @param mixed $request
     */
    public function execute($request): void
    {
    }

    /**
     * @param mixed $request
     */
    public function supports($request): bool
    {
        return $request instanceof Notify;
    }
}
