<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Tests\Functional\Controller;

use Psr\Log\LogLevel;
use Setono\SyliusQuickpayPlugin\Controller\NotifyAction;
use Setono\SyliusQuickpayPlugin\Controller\PayumNotifyController;
use Setono\SyliusQuickpayPlugin\Tests\Functional\FunctionalTestCase;
use Setono\SyliusQuickpayPlugin\Tests\Log\RecordingLogger;
use Setono\SyliusQuickpayPlugin\Tests\Payum\OfflineNotifyAction;
use Sylius\Component\Core\Factory\PaymentMethodFactoryInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
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
    public function the_shared_endpoint_answers_a_500_and_changes_nothing_while_a_quickpay_gateway_configuration_is_unusable(): void
    {
        self::bootKernel();

        $payment = $this->createSyliusPayment(PaymentInterface::STATE_AUTHORIZED, OrderPaymentStates::STATE_AUTHORIZED, quickpayPaymentId: 501);
        $this->quickpay()->respond('GET', '/payments/501', self::quickpayPayment(501, 'processed', ['authorize', 'capture'], balance: 10_000));
        // Another Quickpay payment method, stored by fixtures or the database directly with a branding name where
        // the branding id belongs: its order prefix can't be read, so the callback's order can't be resolved
        $this->createPaymentMethod('quickpay_se', ['api_key' => 'the-se-api-key', 'private_key' => self::PRIVATE_KEY, 'branding_id' => 'Our brand']);

        $response = self::$kernel->handle(self::quickpayCallback(501));

        // Quickpay retries the callback, so it isn't lost once the configuration is fixed
        self::assertSame(500, $response->getStatusCode());
        self::assertSame([], $this->quickpay()->sentRequests());
        $payment = $this->reload($payment);
        self::assertSame(PaymentInterface::STATE_AUTHORIZED, $payment->getState());
        self::assertSame(OrderPaymentStates::STATE_AUTHORIZED, $payment->getOrder()?->getPaymentState());
    }

    /**
     * One gateway's order prefix can start another's order id: under the prefix "q", the order id "qp_000000001" reads
     * as order "p_000000001". Each candidate order is only a guess until it holds the payment.
     *
     * @test
     */
    public function the_shared_endpoint_tries_every_candidate_order_until_one_holds_the_payment(): void
    {
        self::bootKernel();

        // Listed before the payment's own gateway configuration, so its candidate is tried first
        $this->createPaymentMethod('quickpay_a', ['api_key' => 'the-a-api-key', 'private_key' => self::PRIVATE_KEY, 'order_prefix' => 'q']);
        $payment = $this->createSyliusPayment(PaymentInterface::STATE_AUTHORIZED, OrderPaymentStates::STATE_AUTHORIZED, quickpayPaymentId: 501);
        $this->createOrder($this->channel(), OrderPaymentStates::STATE_AWAITING_PAYMENT, 'p_000000001');
        $this->entityManager()->flush();
        $this->quickpay()->respond('GET', '/payments/501', self::quickpayPayment(501, 'processed', ['authorize', 'capture'], balance: 10_000));

        $response = self::$kernel->handle(self::quickpayCallback(501));

        self::assertSame(204, $response->getStatusCode(), (string) $response->getContent());
        $payment = $this->reload($payment);
        self::assertSame(PaymentInterface::STATE_COMPLETED, $payment->getState());
        self::assertSame(OrderPaymentStates::STATE_PAID, $payment->getOrder()?->getPaymentState());
    }

    /**
     * A payment made under an order prefix since changed: no candidate order holds it. Retrying can't help, so it is
     * answered 204, and the store's log says which payment was left out.
     *
     * @test
     */
    public function the_shared_endpoint_answers_a_callback_no_payment_matches_with_a_204_and_a_warning(): void
    {
        self::bootKernel();

        $payment = $this->createSyliusPayment(PaymentInterface::STATE_AUTHORIZED, OrderPaymentStates::STATE_AUTHORIZED, quickpayPaymentId: 501);
        // The payment method's order prefix, changed since the payment was made
        $paymentMethod = $payment->getMethod();
        self::assertInstanceOf(PaymentMethodInterface::class, $paymentMethod);
        $paymentMethod->getGatewayConfig()?->setConfig(['api_key' => 'the-api-key', 'private_key' => self::PRIVATE_KEY, 'order_prefix' => 'shop_']);
        $this->entityManager()->flush();
        $this->quickpay()->respond('GET', '/payments/501', self::quickpayPayment(501, 'processed', ['authorize', 'capture'], balance: 10_000));
        $transitions = $this->recordPaymentTransitions();

        $logger = new RecordingLogger();
        $notifyAction = self::getContainer()->get(NotifyAction::class);
        self::assertInstanceOf(NotifyAction::class, $notifyAction);
        $notifyAction->setLogger($logger);

        $response = self::$kernel->handle(self::quickpayCallback(501));

        self::assertSame(204, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame([[
            LogLevel::WARNING,
            'No Sylius payment matches the callback of Quickpay payment 501 with order id "qp_000000001", so nothing was reconciled. Was the order prefix of its payment method changed?',
            ['quickpayPaymentId' => 501, 'orderId' => 'qp_000000001'],
        ]], $logger->records);
        self::assertSame([], $this->quickpay()->sentRequests());
        self::assertSame([], $transitions->getArrayCopy());
        self::assertSame(PaymentInterface::STATE_AUTHORIZED, $this->reload($payment)->getState());
    }

    /**
     * Building the gateway of the payment's own unusable gateway configuration throws on the payment's callback url, and
     * the shared endpoint can't read that configuration's order prefix: both answer a 500, so Quickpay retries
     *
     * @test
     *
     * @dataProvider callbackEndpoints
     */
    public function a_callback_for_a_payment_on_an_unusable_gateway_configuration_answers_a_500_and_changes_nothing(string $endpoint): void
    {
        self::bootKernel();

        $payment = $this->createSyliusPayment(PaymentInterface::STATE_AUTHORIZED, OrderPaymentStates::STATE_AUTHORIZED, quickpayPaymentId: 501);
        // A branding name where the branding id belongs, stored by fixtures or the database directly
        $payment->setMethod($this->createPaymentMethod('quickpay_se', ['api_key' => 'the-se-api-key', 'private_key' => self::PRIVATE_KEY, 'order_prefix' => self::ORDER_PREFIX, 'branding_id' => 'Our brand']));
        $this->entityManager()->flush();

        $response = $this->deliver(self::quickpayCallback(501), $endpoint, $payment);

        self::assertSame(500, $response->getStatusCode());
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
     * The customer opened the Quickpay window, switched the order to bank transfer in another tab, then paid in the
     * still-open window. Sylius kept the payment, its details and the callback url its Quickpay payment was given, but
     * the payment is the bank transfer's now: neither endpoint reconciles it, both answer 204 so Quickpay stops
     * retrying, and both log why
     *
     * @test
     *
     * @dataProvider callbackEndpoints
     */
    public function a_callback_for_a_payment_switched_to_another_payment_method_is_answered_with_a_204_and_a_warning(string $endpoint): void
    {
        self::bootKernel();

        $payment = $this->createSyliusPayment(PaymentInterface::STATE_NEW, OrderPaymentStates::STATE_AWAITING_PAYMENT, quickpayPaymentId: 501);
        // Minted while the payment was the Quickpay gateway's
        $callbackUrl = $this->callbackUrlOf($payment);
        $payment->setMethod($this->createPaymentMethod('bank_transfer', [], factoryName: 'offline'));
        $this->entityManager()->flush();
        $details = $payment->getDetails();
        $this->quickpay()->respond('GET', '/payments/501', self::quickpayPayment(501, 'new', ['authorize']));
        $transitions = $this->recordPaymentTransitions();

        $logger = new RecordingLogger();
        $controller = self::getContainer()->get(self::PAYMENT_CALLBACK_URL === $endpoint ? PayumNotifyController::class : NotifyAction::class);
        self::assertTrue($controller instanceof PayumNotifyController || $controller instanceof NotifyAction);
        $controller->setLogger($logger);

        $callback = self::quickpayCallback(501);
        $response = self::$kernel->handle(self::PAYMENT_CALLBACK_URL === $endpoint ? self::sentTo($callbackUrl, $callback) : $callback);

        self::assertSame(204, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame([[
            LogLevel::WARNING,
            ...(self::PAYMENT_CALLBACK_URL === $endpoint ? [
                sprintf('The callback of Quickpay payment 501 was not reconciled: its Sylius payment %d is no longer paid with a Quickpay gateway but with the payment method "bank_transfer". Retrying can\'t help; handle any money Quickpay holds for it in the Quickpay manager.', (int) $payment->getId()),
                ['paymentId' => $payment->getId(), 'paymentMethod' => 'bank_transfer', 'quickpayPaymentId' => 501],
            ] : [
                'No Sylius payment matches the callback of Quickpay payment 501 with order id "qp_000000001", so nothing was reconciled. Was the order prefix of its payment method changed?',
                ['quickpayPaymentId' => 501, 'orderId' => 'qp_000000001'],
            ]),
        ]], $logger->records);
        self::assertSame([], $this->quickpay()->sentRequests());
        self::assertSame([], $transitions->getArrayCopy());
        $payment = $this->reload($payment);
        self::assertSame(PaymentInterface::STATE_NEW, $payment->getState());
        self::assertSame($details, $payment->getDetails());
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
