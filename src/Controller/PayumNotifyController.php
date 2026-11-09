<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Controller;

use Payum\Bundle\PayumBundle\Controller\NotifyController;
use Payum\Core\Payum;
use Payum\Core\Security\TokenInterface;
use Setono\SyliusQuickpayPlugin\GatewayConfiguration\GatewayConfigurationProviderInterface;
use Setono\SyliusQuickpayPlugin\Reconciliation\PaymentReconcilerInterface;
use Sylius\Component\Core\Model\PaymentInterface;
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
 */
final class PayumNotifyController
{
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

        // Not a Quickpay gateway's token
        if (null === $this->gatewayConfigurationProvider->forGatewayName($token->getGatewayName())) {
            return $this->decorated->doAction($request);
        }

        // A payment skipped because its lock is held is answered like a reconciled one, and errors propagate
        // to an error response, as on the shared callback endpoint
        $this->reconciler->reconcileFromCallback($this->paymentOf($token));

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
}
