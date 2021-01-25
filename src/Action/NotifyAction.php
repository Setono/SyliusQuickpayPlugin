<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Action;

use Payum\Core\Action\ActionInterface;
use Payum\Core\ApiAwareInterface;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\GatewayAwareInterface;
use Payum\Core\GatewayAwareTrait;
use Payum\Core\Reply\HttpResponse;
use Payum\Core\Request\GetHttpRequest;
use Payum\Core\Request\Notify;
use Safe\Exceptions\JsonException;
use Setono\Payum\QuickPay\Action\Api\ApiAwareTrait;
use Setono\Payum\QuickPay\Model\QuickPayPayment;
use Sylius\Component\Core\Model\PaymentInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class NotifyAction implements ActionInterface, ApiAwareInterface, GatewayAwareInterface
{
    use ApiAwareTrait;
    use GatewayAwareTrait;

    /**
     * @param Notify|mixed $request
     */
    public function execute($request): void
    {
        RequestNotSupportedException::assertSupports($this, $request);

        $this->gateway->execute($httpRequest = new GetHttpRequest());

        $checksum = $httpRequest->headers['quickpay-checksum-sha256'] ?? '';

        if (is_array($checksum)) {
            $checksum = current($checksum);
        }

        if (!$this->api->validateChecksum($httpRequest->content, (string) $checksum)) {
            throw new HttpResponse('', 400);
        }

        try {
            // https://learn.quickpay.net/tech-talk/api/callback/#request-example
            $data = \Safe\json_decode($httpRequest->content);
        } catch (JsonException $e) {
            throw new BadRequestHttpException();
        }

        /** @var PaymentInterface $payment */
        $payment = $request->getModel();
        $details = $payment->getDetails();
        $details['quickpayPayment'] = QuickPayPayment::createFromObject($data);
        $payment->setDetails($details);
    }

    public function supports($request): bool
    {
        return $request instanceof Notify && $request->getModel() instanceof PaymentInterface;
    }
}
