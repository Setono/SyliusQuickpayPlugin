<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\PaymentLink;

use Setono\Payum\Quickpay\QuickpayGatewayFactory;
use Sylius\Bundle\PayumBundle\Model\GatewayConfigInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Symfony\Component\HttpFoundation\UrlHelper;
use Symfony\Component\Routing\Exception\ExceptionInterface as RoutingException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The payment link is Sylius' own "pay for this order" url (`sylius_shop_order_pay`): opening it
 * mints a fresh Payum token for the payment awaiting payment and sends the customer through the
 * plugin's normal checkout flow — the Quickpay payment is created if checkout never got that far,
 * the payment window opens with the method's capture mode, and the return trip resolves the
 * payment state exactly as after checkout. Nothing happens at Quickpay until the customer clicks,
 * so the link can be shown, copied and emailed as often as needed.
 *
 * The url is built for the order's channel hostname (the way Sylius' own emails do it with
 * `sylius_channel_url`), so it is right even when generated from the admin.
 */
final class PaymentLinkProvider implements PaymentLinkProviderInterface
{
    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly UrlHelper $urlHelper,
        private readonly bool $unsecuredUrls = false,
    ) {
    }

    public function provide(PaymentInterface $payment): ?string
    {
        if (PaymentInterface::STATE_NEW !== $payment->getState()) {
            return null;
        }

        $method = $payment->getMethod();
        $gatewayConfig = $method instanceof PaymentMethodInterface ? $method->getGatewayConfig() : null;
        if (!$gatewayConfig instanceof GatewayConfigInterface || QuickpayGatewayFactory::NAME !== $gatewayConfig->getFactoryName()) {
            return null;
        }

        $order = $payment->getOrder();
        if (!$order instanceof OrderInterface || OrderInterface::STATE_CANCELLED === $order->getState()) {
            return null;
        }

        // The pay route always charges the order's LAST payment awaiting payment
        // (PayumController::prepareCaptureAction → getLastPayment(STATE_NEW)) — the plugin cannot
        // point it at another one. So the link is only offered on that payment: shown on any earlier
        // `new` payment it would silently pay a different one, possibly through another gateway.
        // Sylius core never leaves two `new` payments on an order (OrderPaymentProcessor reuses the
        // last one), so this only matters for customized setups — and there the link belongs to the
        // payment Sylius will actually charge, Quickpay or not.
        if ($order->getLastPayment(PaymentInterface::STATE_NEW) !== $payment) {
            return null;
        }

        $tokenValue = $order->getTokenValue();
        if (null === $tokenValue || '' === $tokenValue) {
            return null;
        }

        try {
            $path = $this->urlGenerator->generate('sylius_shop_order_pay', [
                'tokenValue' => $tokenValue,
                '_locale' => $order->getLocaleCode(),
            ]);
        } catch (RoutingException) {
            // A headless shop without the Sylius shop routes has no url to hand out
            return null;
        }

        $hostname = $order->getChannel()?->getHostname();
        if (null !== $hostname && '' !== $hostname) {
            return ($this->unsecuredUrls ? 'http://' : 'https://') . $hostname . $path;
        }

        return $this->urlHelper->getAbsoluteUrl($path);
    }
}
