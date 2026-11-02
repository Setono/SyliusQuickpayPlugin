<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Tests\Functional\Controller;

use Setono\SyliusQuickpayPlugin\Tests\Functional\FunctionalTestCase;
use Setono\SyliusQuickpayPlugin\Tests\Payum\OfflineNotifyAction;
use Sylius\Component\Core\Factory\PaymentMethodFactoryInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\OrderPaymentStates;
use Sylius\Component\Payment\PaymentTransitions;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Quickpay's callbacks reconcile the payment they are about, the same way on both callback endpoints: the
 * payment's own callback url (Payum's notify url, which the library mints for each payment and Quickpay
 * normally calls) and the plugin's shared callback endpoint
 */
final class CallbackEndpointsTest extends FunctionalTestCase
{
    private const SHARED_ENDPOINT = 'the shared callback endpoint';

    private const PAYMENT_CALLBACK_URL = 'the payment\'s callback url';

    /**
     * @return iterable<string, array{string}>
     */
    public static function callbackEndpoints(): iterable
    {
        yield self::PAYMENT_CALLBACK_URL => [self::PAYMENT_CALLBACK_URL];
        yield self::SHARED_ENDPOINT => [self::SHARED_ENDPOINT];
    }

    /**
     * @test
     *
     * @dataProvider callbackEndpoints
     */
    public function a_callback_completes_the_payment_once(string $endpoint): void
    {
        self::bootKernel();

        $payment = $this->createSyliusPayment(PaymentInterface::STATE_AUTHORIZED, OrderPaymentStates::STATE_AUTHORIZED, quickpayPaymentId: 501);
        $this->quickpay()->respond('GET', '/payments/501', self::quickpayPayment(501, 'processed', ['authorize', 'capture'], balance: 10_000));
        $transitions = $this->recordPaymentTransitions();

        $response = $this->deliver(self::quickpayCallback(501), $endpoint, $payment);

        self::assertTrue($response->isSuccessful(), (string) $response->getContent());
        self::assertSame([PaymentTransitions::TRANSITION_COMPLETE], $transitions->getArrayCopy());
        $payment = $this->reload($payment);
        self::assertSame(PaymentInterface::STATE_COMPLETED, $payment->getState());
        self::assertSame(OrderPaymentStates::STATE_PAID, $payment->getOrder()?->getPaymentState());
    }

    /**
     * @test
     *
     * @dataProvider callbackEndpoints
     */
    public function a_duplicate_arriving_while_the_callback_is_handled_is_answered_with_a_2xx_and_changes_nothing(string $endpoint): void
    {
        self::bootKernel();

        $payment = $this->createSyliusPayment(PaymentInterface::STATE_AUTHORIZED, OrderPaymentStates::STATE_AUTHORIZED, quickpayPaymentId: 501);
        $this->quickpay()->respond('GET', '/payments/501', self::quickpayPayment(501, 'processed', ['authorize', 'capture'], balance: 10_000));

        // The original callback, being handled elsewhere, holds the payment's lock
        $this->holdLockOf(501);

        $response = $this->deliver(self::quickpayCallback(501), $endpoint, $payment);

        // Quickpay considers it delivered and stops retrying
        self::assertTrue($response->isSuccessful(), (string) $response->getContent());
        self::assertSame([], $this->quickpay()->sentRequests());
        $payment = $this->reload($payment);
        self::assertSame(PaymentInterface::STATE_AUTHORIZED, $payment->getState());
        self::assertSame(OrderPaymentStates::STATE_AUTHORIZED, $payment->getOrder()?->getPaymentState());
    }

    /**
     * @test
     *
     * @dataProvider callbackEndpoints
     */
    public function a_forged_callback_is_answered_with_a_400_and_changes_nothing(string $endpoint): void
    {
        self::bootKernel();

        $payment = $this->createSyliusPayment(PaymentInterface::STATE_AUTHORIZED, OrderPaymentStates::STATE_AUTHORIZED, quickpayPaymentId: 501);
        $this->quickpay()->respond('GET', '/payments/501', self::quickpayPayment(501, 'processed', ['authorize', 'capture'], balance: 10_000));

        $response = $this->deliver(self::quickpayCallback(501, privateKey: 'not-the-private-key'), $endpoint, $payment);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame([], $this->quickpay()->sentRequests());
        $payment = $this->reload($payment);
        self::assertSame(PaymentInterface::STATE_AUTHORIZED, $payment->getState());
        self::assertSame(OrderPaymentStates::STATE_AUTHORIZED, $payment->getOrder()?->getPaymentState());
    }

    /**
     * @test
     */
    public function a_notify_for_another_gateways_payment_is_left_to_payum(): void
    {
        self::bootKernel();

        $payment = $this->createOfflinePayment();
        $transitions = $this->recordPaymentTransitions();

        $response = self::$kernel->handle(Request::create($this->callbackUrlOf($payment), 'POST'));

        // Payum ran the offline gateway's notify, and Sylius applied the status it left
        self::assertSame(204, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame([PaymentTransitions::TRANSITION_COMPLETE], $transitions->getArrayCopy());
        $payment = $this->reload($payment);
        self::assertSame(PaymentInterface::STATE_COMPLETED, $payment->getState());
        self::assertSame(OrderPaymentStates::STATE_PAID, $payment->getOrder()?->getPaymentState());
        self::assertSame([], $this->quickpay()->sentRequests());
    }

    /**
     * @test
     */
    public function payums_token_less_notify_url_is_left_to_payum(): void
    {
        self::bootKernel();

        $payment = $this->createOfflinePayment();

        $router = self::getContainer()->get('router');
        self::assertInstanceOf(UrlGeneratorInterface::class, $router);
        $response = self::$kernel->handle(Request::create($router->generate('payum_notify_do_unsafe', ['gateway' => self::gatewayNameOf($payment)]), 'POST'));

        // Payum ran the gateway's notify without a payment, and sent its answer
        self::assertSame(202, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(OfflineNotifyAction::TOKENLESS_NOTIFY_ANSWER, $response->getContent());
        self::assertSame(PaymentInterface::STATE_NEW, $this->reload($payment)->getState());
        self::assertSame([], $this->quickpay()->sentRequests());
    }

    /**
     * @test
     */
    public function a_callback_on_the_callback_url_of_a_payment_that_no_longer_exists_is_answered_with_a_404(): void
    {
        self::bootKernel();

        $payment = $this->createSyliusPayment(PaymentInterface::STATE_AUTHORIZED, OrderPaymentStates::STATE_AUTHORIZED, quickpayPaymentId: 501);
        $this->quickpay()->respond('GET', '/payments/501', self::quickpayPayment(501, 'processed', ['authorize', 'capture'], balance: 10_000));
        $callbackUrl = $this->callbackUrlOf($payment);

        // E.g. deleted with its order, after Quickpay was given the url
        $payment->getOrder()?->removePayment($payment);
        $this->entityManager()->remove($payment);
        $this->entityManager()->flush();

        $response = self::$kernel->handle(self::sentTo($callbackUrl, self::quickpayCallback(501)));

        // The token is genuine, its payment is what's missing
        self::assertSame(404, $response->getStatusCode());
        self::assertStringContainsString('The payment of the token', (string) $response->getContent());
        self::assertSame([], $this->quickpay()->sentRequests());
    }

    /**
     * Delivers Quickpay's callback about the payment to one of the callback endpoints
     */
    private function deliver(Request $callback, string $endpoint, PaymentInterface $payment): Response
    {
        if (self::PAYMENT_CALLBACK_URL === $endpoint) {
            $callback = self::sentTo($this->callbackUrlOf($payment), $callback);
        }

        return self::$kernel->handle($callback);
    }

    /**
     * The callback, sent to the given url instead
     */
    private static function sentTo(string $url, Request $callback): Request
    {
        return Request::create($url, 'POST', server: $callback->server->all(), content: $callback->getContent());
    }

    /**
     * The payment's own callback url, as the library mints it when it creates the payment link: a Payum
     * notify token for the payment's gateway
     */
    private function callbackUrlOf(PaymentInterface $payment): string
    {
        return $this->payum()->getTokenFactory()->createNotifyToken(self::gatewayNameOf($payment), $payment)->getTargetUrl();
    }

    /**
     * A new Sylius payment of an order, paid with a payment method on Payum's offline gateway
     */
    private function createOfflinePayment(): PaymentInterface
    {
        $payment = $this->createSyliusPayment(PaymentInterface::STATE_NEW, OrderPaymentStates::STATE_AWAITING_PAYMENT, quickpayPaymentId: 501);

        $paymentMethodFactory = self::getContainer()->get('sylius.factory.payment_method');
        self::assertInstanceOf(PaymentMethodFactoryInterface::class, $paymentMethodFactory);
        $paymentMethod = $paymentMethodFactory->createWithGateway('offline');
        $paymentMethod->setCode('bank_transfer');
        $paymentMethod->getGatewayConfig()?->setGatewayName('bank_transfer');

        $payment->setMethod($paymentMethod);
        $payment->setDetails([]);
        $this->entityManager()->persist($paymentMethod);
        $this->entityManager()->flush();

        return $payment;
    }
}
