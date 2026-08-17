<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Tests\Controller\Admin;

use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Setono\SyliusQuickpayPlugin\Controller\Admin\SendPaymentLinkAction;
use Setono\SyliusQuickpayPlugin\Mailer\PaymentLinkEmailManagerInterface;
use Setono\SyliusQuickpayPlugin\PaymentLink\PaymentLinkProviderInterface;
use Sylius\Component\Core\Model\Channel;
use Sylius\Component\Core\Model\Customer;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\Payment;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Repository\PaymentRepositoryInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class SendPaymentLinkActionTest extends TestCase
{
    use ProphecyTrait;

    /** @var ObjectProphecy<PaymentRepositoryInterface<PaymentInterface>> */
    private ObjectProphecy $paymentRepository;

    /** @var ObjectProphecy<PaymentLinkProviderInterface> */
    private ObjectProphecy $paymentLinkProvider;

    /** @var ObjectProphecy<PaymentLinkEmailManagerInterface> */
    private ObjectProphecy $emailManager;

    /** @var ObjectProphecy<CsrfTokenManagerInterface> */
    private ObjectProphecy $csrfTokenManager;

    private Session $session;

    private RequestStack $requestStack;

    protected function setUp(): void
    {
        /** @var ObjectProphecy<PaymentRepositoryInterface<PaymentInterface>> $paymentRepository */
        $paymentRepository = $this->prophesize(PaymentRepositoryInterface::class);
        $this->paymentRepository = $paymentRepository;
        $this->paymentLinkProvider = $this->prophesize(PaymentLinkProviderInterface::class);
        $this->emailManager = $this->prophesize(PaymentLinkEmailManagerInterface::class);
        $this->csrfTokenManager = $this->prophesize(CsrfTokenManagerInterface::class);
        $this->csrfTokenManager->isTokenValid(new CsrfToken('7', 'valid'))->willReturn(true);
        $this->csrfTokenManager->isTokenValid(Argument::any())->willReturn(false);
        $this->session = new Session(new MockArraySessionStorage());
        $this->requestStack = new RequestStack();
    }

    /**
     * @test
     */
    public function it_emails_the_payment_link_to_the_customer_and_returns_to_the_order(): void
    {
        [$payment] = $this->createPayment();
        $this->paymentRepository->find(7)->willReturn($payment);
        $this->paymentLinkProvider->provide($payment)->willReturn('https://shop.example/da_DK/order/tok3n/pay');

        $this->emailManager->sendPaymentLinkEmail($payment, 'https://shop.example/da_DK/order/tok3n/pay')->shouldBeCalledOnce();

        $response = $this->createAction()->__invoke($this->createRequest('valid'), 7);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/admin/orders/42', $response->getTargetUrl());
        self::assertSame([[
            'message' => 'setono_sylius_quickpay.payment_link_sent',
            'parameters' => ['%email%' => 'joe@example.com'],
        ]], $this->session->getFlashBag()->get('success'));
    }

    /**
     * @test
     */
    public function it_flashes_an_error_instead_of_sending_when_the_payment_has_no_link(): void
    {
        [$payment] = $this->createPayment();
        $this->paymentRepository->find(7)->willReturn($payment);
        $this->paymentLinkProvider->provide($payment)->willReturn(null);
        $this->emailManager->sendPaymentLinkEmail(Argument::cetera())->shouldNotBeCalled();

        $response = $this->createAction()->__invoke($this->createRequest('valid'), 7);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame(['setono_sylius_quickpay.payment_link_not_sent'], $this->session->getFlashBag()->get('error'));
    }

    /**
     * @test
     */
    public function it_flashes_an_error_when_the_customer_has_no_email(): void
    {
        [$payment, $order] = $this->createPayment();
        $order->setCustomer(null);
        $this->paymentRepository->find(7)->willReturn($payment);
        $this->paymentLinkProvider->provide($payment)->willReturn('https://shop.example/da_DK/order/tok3n/pay');
        $this->emailManager->sendPaymentLinkEmail(Argument::cetera())->shouldNotBeCalled();

        $this->createAction()->__invoke($this->createRequest('valid'), 7);

        self::assertSame(['setono_sylius_quickpay.payment_link_not_sent'], $this->session->getFlashBag()->get('error'));
    }

    /**
     * @test
     */
    public function it_rejects_an_invalid_csrf_token(): void
    {
        $this->paymentRepository->find(Argument::any())->shouldNotBeCalled();

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Invalid csrf token.');

        $this->createAction()->__invoke($this->createRequest('forged'), 7);
    }

    /**
     * @test
     */
    public function it_returns_404_for_an_unknown_payment(): void
    {
        $this->paymentRepository->find(7)->willReturn(null);

        $this->expectException(NotFoundHttpException::class);

        $this->createAction()->__invoke($this->createRequest('valid'), 7);
    }

    private function createAction(): SendPaymentLinkAction
    {
        $urlGenerator = $this->prophesize(UrlGeneratorInterface::class);
        $urlGenerator->generate('sylius_admin_order_show', ['id' => 42])->willReturn('/admin/orders/42');

        return new SendPaymentLinkAction(
            $this->paymentRepository->reveal(),
            $this->paymentLinkProvider->reveal(),
            $this->emailManager->reveal(),
            $this->csrfTokenManager->reveal(),
            $this->requestStack,
            $urlGenerator->reveal(),
        );
    }

    /**
     * A GET carrying the csrf token as a query parameter, like Sylius' resend-confirmation-email link
     */
    private function createRequest(string $csrfToken): Request
    {
        $request = Request::create('/admin/quickpay/payments/7/send-payment-link', 'GET', ['_csrf_token' => $csrfToken]);
        $request->setSession($this->session);
        $this->requestStack->push($request);

        return $request;
    }

    /**
     * @return array{0: PaymentInterface, 1: Order}
     */
    private function createPayment(): array
    {
        $customer = new Customer();
        $customer->setEmail('joe@example.com');

        $channel = new Channel();
        $channel->setCode('WEB');

        $order = new Order();
        $order->setLocaleCode('da_DK');
        $order->setChannel($channel);
        $order->setCustomer($customer);
        (new \ReflectionProperty($order, 'id'))->setValue($order, 42);

        $payment = new Payment();
        $order->addPayment($payment);

        return [$payment, $order];
    }
}
