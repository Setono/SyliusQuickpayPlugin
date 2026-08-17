<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Controller\Admin;

use Setono\SyliusQuickpayPlugin\Mailer\PaymentLinkEmailManagerInterface;
use Setono\SyliusQuickpayPlugin\PaymentLink\PaymentLinkProviderInterface;
use Sylius\Bundle\CoreBundle\Provider\FlashBagProvider;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Repository\PaymentRepositoryInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Emails the customer the link that pays a Quickpay payment still awaiting payment — the same
 * link the admin order view shows for copying — and returns to the order. Shaped like Sylius'
 * own ResendOrderConfirmationEmailAction: a GET carrying a `_csrf_token` query parameter, a
 * flash, and a redirect back to the order.
 */
final class SendPaymentLinkAction
{
    /**
     * @param PaymentRepositoryInterface<PaymentInterface> $paymentRepository
     */
    public function __construct(
        private readonly PaymentRepositoryInterface $paymentRepository,
        private readonly PaymentLinkProviderInterface $paymentLinkProvider,
        private readonly PaymentLinkEmailManagerInterface $paymentLinkEmailManager,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly RequestStack $requestStack,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function __invoke(Request $request, int $id): Response
    {
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken((string) $id, (string) $request->query->get('_csrf_token', '')))) {
            throw new HttpException(Response::HTTP_FORBIDDEN, 'Invalid csrf token.');
        }

        $payment = $this->paymentRepository->find($id);
        if (!$payment instanceof PaymentInterface) {
            throw new NotFoundHttpException(sprintf('Payment %d does not exist', $id));
        }

        $order = $payment->getOrder();
        if (!$order instanceof OrderInterface) {
            throw new NotFoundHttpException(sprintf('Payment %d belongs to no order', $id));
        }

        $flashBag = FlashBagProvider::getFlashBag($this->requestStack);

        $paymentLink = $this->paymentLinkProvider->provide($payment);
        $customer = $order->getCustomer();
        $email = $customer instanceof CustomerInterface ? $customer->getEmail() : null;

        if (null === $paymentLink || null === $email || '' === $email) {
            $flashBag->add('error', 'setono_sylius_quickpay.payment_link_not_sent');
        } else {
            $this->paymentLinkEmailManager->sendPaymentLinkEmail($payment, $paymentLink);

            $flashBag->add('success', [
                'message' => 'setono_sylius_quickpay.payment_link_sent',
                'parameters' => ['%email%' => $email],
            ]);
        }

        return new RedirectResponse($this->urlGenerator->generate('sylius_admin_order_show', ['id' => $order->getId()]));
    }
}
