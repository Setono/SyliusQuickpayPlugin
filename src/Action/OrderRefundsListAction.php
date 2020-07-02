<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Action;

use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Core\Repository\OrderRepositoryInterface;
use Sylius\RefundPlugin\Checker\OrderRefundingAvailabilityCheckerInterface;
use Sylius\RefundPlugin\Provider\RefundPaymentMethodsProviderInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

final class OrderRefundsListAction
{
    /** @var OrderRepositoryInterface */
    private $orderRepository;

    /** @var OrderRefundingAvailabilityCheckerInterface */
    private $orderRefundsListAvailabilityChecker;

    /** @var RefundPaymentMethodsProviderInterface */
    private $refundPaymentMethodsProvider;

    /** @var Environment */
    private $twig;

    /** @var Session */
    private $session;

    /** @var UrlGeneratorInterface */
    private $router;

    public function __construct(
        OrderRepositoryInterface $orderRepository,
        OrderRefundingAvailabilityCheckerInterface $orderRefundsListAvailabilityChecker,
        RefundPaymentMethodsProviderInterface $refundPaymentMethodsProvider,
        Environment $twig,
        Session $session,
        UrlGeneratorInterface $router
    ) {
        $this->orderRepository = $orderRepository;
        $this->orderRefundsListAvailabilityChecker = $orderRefundsListAvailabilityChecker;
        $this->refundPaymentMethodsProvider = $refundPaymentMethodsProvider;
        $this->twig = $twig;
        $this->session = $session;
        $this->router = $router;
    }

    public function __invoke(Request $request): Response
    {
        /** @var OrderInterface $order */
        $order = $this->orderRepository->findOneByNumber($request->attributes->get('orderNumber'));

        if (!$this->orderRefundsListAvailabilityChecker->__invoke($request->attributes->get('orderNumber'))) {
            if ($order->getTotal() === 0) {
                return $this->redirectToReferer($order, 'sylius_refund.free_order_should_not_be_refund');
            }

            return $this->redirectToReferer($order, 'sylius_refund.order_should_be_paid');
        }

        $paymentMethods = $this->refundPaymentMethodsProvider->findForChannel($order->getChannel());
        $quickpayPayments = $this->getQuickpayPayments($order);

        if (empty($quickpayPayments)) {
            $paymentMethods = array_filter($paymentMethods, static function (PaymentMethodInterface $method) {
                return $method->getGatewayConfig()->getFactoryName() !== 'quickpay';
            });
        }

        return new Response(
            $this->twig->render('@SetonoSyliusQuickpayPlugin/SyliusRefundPlugin/orderRefunds.html.twig', [
                'order' => $order,
                'payment_methods' => $paymentMethods,
                'quickpay_payments' => $quickpayPayments,
            ])
        );
    }

    private function redirectToReferer(OrderInterface $order, string $message): Response
    {
        $this->session->getFlashBag()->add('error', $message);

        return new RedirectResponse($this->router->generate('sylius_admin_order_show', ['id' => $order->getId()]));
    }

    /**
     * @param OrderInterface $order
     * @return PaymentInterface[]
     */
    private function getQuickpayPayments(OrderInterface $order): array
    {
        return $order
            ->getPayments()
            ->filter(
                static function (PaymentInterface $payment) {
                    $method = $payment->getMethod();

                    if (!($method instanceof PaymentMethodInterface)) {
                        return false;
                    }

                    return $method->getGatewayConfig()->getFactoryName() === 'quickpay'
                        && $payment->getState() === $payment::STATE_COMPLETED
                        && !empty($payment->getDetails()['quickpayPaymentId']);
                }
            )
            ->toArray();
    }
}
