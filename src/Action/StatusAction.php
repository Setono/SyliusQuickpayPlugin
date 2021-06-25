<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Action;

use ArrayAccess;
use Payum\Core\Action\ActionInterface;
use Payum\Core\ApiAwareInterface;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\GatewayAwareInterface;
use Payum\Core\GatewayAwareTrait;
use Payum\Core\Request\GetStatusInterface;
use Setono\Payum\QuickPay\Action\Api\ApiAwareTrait;
use Setono\Payum\QuickPay\Model\QuickPayPayment;
use Setono\Payum\QuickPay\Model\QuickPayPaymentOperation;

class StatusAction implements ActionInterface, ApiAwareInterface, GatewayAwareInterface
{
    use GatewayAwareTrait;
    use ApiAwareTrait;

    /**
     * @param GetStatusInterface $request
     */
    public function execute($request): void
    {
        RequestNotSupportedException::assertSupports($this, $request);

        $model = ArrayObject::ensureArrayObject($request->getModel());

        if (!$model->offsetExists('quickpayPaymentId') && !$model->offsetExists('quickpayPayment')) {
            $request->markNew();

            return;
        }

        $quickpayPayment = $this->api->getPayment($model);
        $latestOperation = $quickpayPayment->getLatestOperation();

        // default state is new to reuse the payment for further operations
        $request->markNew();

        if ($quickpayPayment->getState() === QuickPayPayment::STATE_NEW
            && $this->isOperationApproved($latestOperation, QuickPayPaymentOperation::TYPE_AUTHORIZE)) {
            $request->markAuthorized();

            return;
        }

        if ($quickpayPayment->getState() === QuickPayPayment::STATE_PROCESSED) {
            if ($this->isOperationApproved($latestOperation, QuickPayPaymentOperation::TYPE_CAPTURE)) {
                $request->markCaptured();

                return;
            }

            if ($this->isOperationApproved($latestOperation, QuickPayPaymentOperation::TYPE_REFUND)) {
                $request->markRefunded();

                return;
            }

            if ($this->isOperationApproved($latestOperation, QuickPayPaymentOperation::TYPE_CANCEL)) {
                $request->markCanceled();

                return;
            }
        }
    }

    public function supports($request): bool
    {
        return $request instanceof GetStatusInterface && $request->getModel() instanceof ArrayAccess;
    }

    private function isOperationApproved(?QuickPayPaymentOperation $operation, string $state): bool
    {
        if (null === $operation) {
            return false;
        }

        return $operation->getType() === $state && $operation->isApproved();
    }
}
