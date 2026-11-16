<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Controller;

use Payum\Bundle\PayumBundle\Controller\NotifyController;
use Payum\Core\Payum;
use Payum\Core\Security\TokenInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Setono\SyliusQuickpayPlugin\GatewayConfiguration\GatewayConfigurationProviderInterface;
use Setono\SyliusQuickpayPlugin\Reconciliation\PaymentReconcilerInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Symfony\Component\HttpFoundation\Exception\JsonException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Decorates Payum's notify controller, whose token url is the callback url the gateway library gives each
 * Quickpay payment, the one Quickpay normally calls. A callback on a Quickpay gateway's token reconciles the
 * payment from the callback (see GLOSSARY.md), exactly as on the plugin's shared callback endpoint
 * ({@see NotifyAction}). Every other token, and Payum's token-less notify url, is left to Payum's controller.
 *
 * A Quickpay token never reaches Payum's controller: it would run Payum's Notify on the Sylius payment without
 * the payment's lock, and even for a callback whose HMAC doesn't match, Sylius' payment-state extension would
 * apply the transition and Payum's storage persist it before the callback is rejected.
 *
 * A Quickpay token can outlive its payment's link to Quickpay: Sylius keeps a payment, and the callback url its
 * Quickpay payment was given, when the customer switches it to another payment method. Its callback is then answered
 * 204 and logged, since the payment is that method's gateway's now and retrying can't change that.
 */
final class PayumNotifyController implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    public function __construct(
        private readonly NotifyController $decorated,
        private readonly Payum $payum,
        private readonly PaymentReconcilerInterface $reconciler,
        private readonly GatewayConfigurationProviderInterface $gatewayConfigurationProvider,
    ) {
    }

    public function doAction(Request $request): Response
    {
        // As Payum's controller does: an unknown token, or one for another url, is answered with an error
        $token = $this->payum->getHttpRequestVerifier()->verify($request);

        // No gateway configuration found: the token's gateway is not a Quickpay gateway, so Payum's controller handles it
        if (null === $this->gatewayConfigurationProvider->forGatewayName($token->getGatewayName())) {
            return $this->decorated->doAction($request);
        }

        $payment = $this->paymentOf($token);

        // The customer switched the payment to another method after its Quickpay payment was created, say to bank
        // transfer in another tab, and then paid in the still-open payment window. Nothing is reconciled (the
        // checksum isn't checked either): any money Quickpay holds for it is the merchant's to handle
        if (null === $this->gatewayConfigurationProvider->forPayment($payment)) {
            $paymentMethod = $payment->getMethod()?->getCode();
            $quickpayPaymentId = self::quickpayPaymentIdIn($request);

            $this->logger?->warning(sprintf(
                'The callback of Quickpay payment %s was not reconciled: its Sylius payment %s is no longer paid with a Quickpay gateway but with the payment method "%s". Retrying can\'t help; handle any money Quickpay holds for it in the Quickpay manager.',
                $quickpayPaymentId ?? '(unknown)',
                (string) $payment->getId(),
                (string) $paymentMethod,
            ), ['paymentId' => $payment->getId(), 'paymentMethod' => $paymentMethod, 'quickpayPaymentId' => $quickpayPaymentId]);

            return new Response('', 204);
        }

        // A payment skipped because its lock is held is answered like a reconciled one, and errors propagate
        // to an error response, as on the shared callback endpoint
        $this->reconciler->reconcileFromCallback($payment);

        return new Response('', 204);
    }

    public function doUnsafeAction(Request $request): Response
    {
        return $this->decorated->doUnsafeAction($request);
    }

    /**
     * The token's details are the Sylius payment the library minted the token for, when it created the
     * Quickpay payment's link
     */
    private function paymentOf(TokenInterface $token): PaymentInterface
    {
        $identity = $token->getDetails();
        $payment = $this->payum->getStorage($identity->getClass())->find($identity);
        if (!$payment instanceof PaymentInterface) {
            throw new NotFoundHttpException(sprintf('The payment of the token "%s" could not be found', $token->getHash()));
        }

        return $payment;
    }

    /**
     * The id of the Quickpay payment the callback is about, as its body names it, for the log: the body is unverified
     */
    private static function quickpayPaymentIdIn(Request $request): ?int
    {
        try {
            $id = $request->toArray()['id'] ?? null;
        } catch (JsonException) {
            return null;
        }

        return is_int($id) || (is_string($id) && ctype_digit($id)) ? (int) $id : null;
    }
}
