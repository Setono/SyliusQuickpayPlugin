<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Checkout;

use Payum\Core\Payum;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Setono\Quickpay\Enum\PaymentState;
use Setono\SyliusQuickpayPlugin\Quickpay\QuickpayClientProviderInterface;
use Setono\SyliusQuickpayPlugin\Quickpay\QuickpayPaymentIdProviderInterface;
use Sylius\Bundle\CoreBundle\Provider\FlashBagProvider;
use Sylius\Component\Core\Model\PaymentInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Tells a customer who comes back from the payment window after a declined attempt (see GLOSSARY.md) that their
 * payment was declined. Quickpay sends a customer who leaves the window to its cancel url, Sylius' after-pay page,
 * which brings the Sylius payment in line with its Quickpay payment's status and, for a Sylius payment still new, shows
 * the order with its Pay button but no message.
 *
 * Only a Sylius payment the after-pay page left new can have a declined attempt, so no other return reads the Quickpay
 * payment again. The flash is a courtesy: when the Quickpay payment can't be read, the customer still reaches the
 * order, and a warning is logged.
 */
final class DeclinedAttemptFlashSubscriber implements EventSubscriberInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    public const MESSAGE = 'setono_sylius_quickpay.payment_declined';

    private const AFTER_PAY_ROUTE = 'sylius_shop_order_after_pay';

    private const PAYMENT_ATTRIBUTE = '_setono_sylius_quickpay_returning_payment';

    public function __construct(
        private readonly Payum $payum,
        private readonly QuickpayClientProviderInterface $quickpayClientProvider,
        private readonly QuickpayPaymentIdProviderInterface $quickpayPaymentIdProvider,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::CONTROLLER => 'rememberReturningPayment',
            KernelEvents::RESPONSE => 'addFlash',
        ];
    }

    /**
     * Sylius' after-pay page invalidates its token, so the payment the token names is read before the page runs. The
     * token is verified as the page's first step verifies it, so one the page can't use fails here the same way
     */
    public function rememberReturningPayment(ControllerEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || self::AFTER_PAY_ROUTE !== $request->attributes->get('_route')) {
            return;
        }

        $identity = $this->payum->getHttpRequestVerifier()->verify($request)->getDetails();
        $request->attributes->set(self::PAYMENT_ATTRIBUTE, $this->payum->getStorage($identity->getClass())->find($identity));
    }

    public function addFlash(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        $payment = $request->attributes->get(self::PAYMENT_ATTRIBUTE);
        if (!$payment instanceof PaymentInterface || PaymentInterface::STATE_NEW !== $payment->getState()) {
            return;
        }

        try {
            // Null for a Sylius payment that isn't a Quickpay payment or has no Quickpay payment id: nothing was declined
            $quickpayPayment = $this->quickpayClientProvider->quickpayPaymentOf($payment);
        } catch (\Throwable $e) {
            // An unreachable Quickpay, an unusable gateway configuration and an unreadable id alike
            $this->logger?->warning(sprintf('Could not read the Quickpay payment to tell the returning customer whether their payment was declined: %s', $e->getMessage()), [
                'paymentId' => $payment->getId(),
                'quickpayPaymentId' => $this->quickpayPaymentIdProvider->tryIdOf($payment),
                'exception' => $e,
            ]);

            return;
        }

        if (PaymentState::Rejected === $quickpayPayment?->state()) {
            FlashBagProvider::getFlashBag($request->getSession())->add('error', self::MESSAGE);
        }
    }
}
