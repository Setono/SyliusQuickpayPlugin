<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Tests\Controller;

use Payum\Core\Action\ActionInterface;
use Payum\Core\Request\Notify;

/**
 * Stands in for the gateway's NotifyAction: records how many callbacks were actually processed and
 * lets a test deliver a concurrent callback at the exact moment the first one is being handled
 */
final class NotifyHandlerSpy implements ActionInterface
{
    public int $processed = 0;

    public ?\Closure $onFirstExecute = null;

    /**
     * @param mixed $request
     */
    public function execute($request): void
    {
        ++$this->processed;

        if (1 === $this->processed && null !== $this->onFirstExecute) {
            ($this->onFirstExecute)();
        }
    }

    /**
     * @param mixed $request
     */
    public function supports($request): bool
    {
        return $request instanceof Notify && $request->getModel() instanceof \ArrayAccess;
    }
}
