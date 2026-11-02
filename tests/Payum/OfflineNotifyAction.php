<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Tests\Payum;

use Payum\Core\Action\ActionInterface;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\Reply\HttpResponse;
use Payum\Core\Request\Notify;
use Payum\Offline\Constants;

/**
 * Gives Payum's offline gateway, which handles no notify of its own, the simplest one in the test
 * application: it marks the payment captured. So a payment of a gateway other than Quickpay can be sent
 * to Payum's notify url, and whether Payum handled it shows in the payment's state.
 *
 * A notify without a payment, as Payum's token-less notify url sends it, has nothing to mark, so it is
 * answered with {@see self::TOKENLESS_NOTIFY_ANSWER}: whether Payum handled it shows in the response.
 */
final class OfflineNotifyAction implements ActionInterface
{
    public const TOKENLESS_NOTIFY_ANSWER = 'The offline gateway handled the notify';

    /**
     * @param mixed|Notify $request
     */
    public function execute($request): void
    {
        RequestNotSupportedException::assertSupports($this, $request);
        \assert($request instanceof Notify);

        if (null === $request->getModel()) {
            throw new HttpResponse(self::TOKENLESS_NOTIFY_ANSWER, 202);
        }

        $details = ArrayObject::ensureArrayObject($request->getModel());
        $details[Constants::FIELD_STATUS] = Constants::STATUS_CAPTURED;
    }

    public function supports($request): bool
    {
        return $request instanceof Notify && (null === $request->getModel() || $request->getModel() instanceof \ArrayAccess);
    }
}
