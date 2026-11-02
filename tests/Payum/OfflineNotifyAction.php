<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Tests\Payum;

use Payum\Core\Action\ActionInterface;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\Request\Notify;
use Payum\Offline\Constants;

/**
 * Gives Payum's offline gateway, which handles no notify of its own, the simplest one in the test
 * application: it marks the payment captured. So a payment of a gateway other than Quickpay can be sent
 * to Payum's notify url, and whether Payum handled it shows in the payment's state.
 */
final class OfflineNotifyAction implements ActionInterface
{
    /**
     * @param mixed|Notify $request
     */
    public function execute($request): void
    {
        RequestNotSupportedException::assertSupports($this, $request);
        \assert($request instanceof Notify);

        $details = ArrayObject::ensureArrayObject($request->getModel());
        $details[Constants::FIELD_STATUS] = Constants::STATUS_CAPTURED;
    }

    public function supports($request): bool
    {
        return $request instanceof Notify && $request->getModel() instanceof \ArrayAccess;
    }
}
