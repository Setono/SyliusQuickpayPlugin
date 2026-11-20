<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Tests\Functional\Checkout;

use GuzzleHttp\Psr7\Response as Psr7Response;
use Psr\Log\LogLevel;
use Setono\Quickpay\Exception\QuickpayException;
use Setono\SyliusQuickpayPlugin\Checkout\DeclinedAttemptFlashSubscriber;
use Setono\SyliusQuickpayPlugin\Tests\Functional\FunctionalTestCase;
use Setono\SyliusQuickpayPlugin\Tests\Log\RecordingLogger;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\OrderPaymentStates;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Flash\FlashBagInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Translation\TranslatorBagInterface;

/**
 * A customer who leaves the payment window after a declined attempt (see GLOSSARY.md) comes back through Quickpay's
 * cancel url, Sylius' after-pay page. The payment is still new, so Sylius says nothing and shows the order with its
 * Pay button; the plugin tells the customer their payment was declined
 */
final class DeclinedAttemptFlashSubscriberTest extends FunctionalTestCase
{
    private const ORDER_TOKEN = 'declined-attempt-order';

    /**
     * @test
     */
    public function a_customer_returning_after_a_declined_attempt_is_told_their_payment_was_declined(): void
    {
        self::bootKernel();

        $payment = $this->createReturningPayment();
        $this->quickpay()->respond('GET', '/payments/501', self::quickpayPayment(501, 'rejected', [], declinedOperations: ['authorize']));

        [$response, $flashes] = $this->returnFromThePaymentWindow($payment);

        self::assertSame($this->orderPage(), $response->headers->get('Location'));
        self::assertSame(['setono_sylius_quickpay.payment_declined'], $flashes->peek('error'));
        self::assertSame(PaymentInterface::STATE_NEW, $this->reload($payment)->getState());
    }

    /**
     * A window the customer cancelled without trying to pay leaves the Quickpay payment initial: nothing was declined
     *
     * @test
     */
    public function a_customer_returning_without_having_tried_to_pay_is_told_nothing(): void
    {
        self::bootKernel();

        $payment = $this->createReturningPayment();
        $this->quickpay()->respond('GET', '/payments/501', self::quickpayPayment(501, 'initial', []));

        [$response, $flashes] = $this->returnFromThePaymentWindow($payment);

        self::assertSame($this->orderPage(), $response->headers->get('Location'));
        self::assertSame([], $flashes->peekAll());
    }

    /**
     * Only a payment still new after Sylius' status read can have been declined, so no other return asks Quickpay again
     *
     * @test
     */
    public function a_customer_returning_with_an_authorized_payment_costs_no_extra_read(): void
    {
        self::bootKernel();

        $payment = $this->createReturningPayment();
        $this->quickpay()->respond('GET', '/payments/501', self::quickpayPayment(501, 'new', ['authorize'], balance: 0, declinedOperations: ['authorize']));

        [, $flashes] = $this->returnFromThePaymentWindow($payment);

        // Sylius' own two: the page's status request, then its payment-state extension's
        self::assertSame(['GET /payments/501', 'GET /payments/501'], $this->quickpay()->sentRequests());
        self::assertSame([], $flashes->peek('error'));
        self::assertSame(PaymentInterface::STATE_AUTHORIZED, $this->reload($payment)->getState());
    }

    /**
     * Every checkout returns through Sylius' after-pay page, a bank transfer's too, and its payment stays new: there is
     * no Quickpay payment to ask
     *
     * @test
     */
    public function a_customer_returning_from_another_gateways_payment_is_told_nothing(): void
    {
        self::bootKernel();

        $bankTransfer = $this->createPaymentMethod('bank_transfer', [], factoryName: 'offline');
        $payment = $this->createSyliusPayment(PaymentInterface::STATE_NEW, OrderPaymentStates::STATE_AWAITING_PAYMENT, quickpayPaymentId: null, method: $bankTransfer);
        $payment->getOrder()?->setTokenValue(self::ORDER_TOKEN);
        $this->entityManager()->flush();

        [$response, $flashes] = $this->returnFromThePaymentWindow($payment);

        // Sylius thanks a bank transfer's customer, its payment still new
        self::assertSame($this->route('sylius_shop_order_thank_you'), $response->headers->get('Location'));
        self::assertSame([], $flashes->peek('error'));
        self::assertSame([], $this->quickpay()->sentRequests());
        self::assertSame(PaymentInterface::STATE_NEW, $this->reload($payment)->getState());
    }

    /**
     * @test
     */
    public function a_customer_returning_while_quickpay_cant_be_read_reaches_the_order_without_a_flash(): void
    {
        self::bootKernel();

        $payment = $this->createReturningPayment();
        $this->quickpay()->respond('GET', '/payments/501', self::quickpayPayment(501, 'rejected', [], declinedOperations: ['authorize']));
        $logger = new RecordingLogger();
        $subscriber = self::getContainer()->get(DeclinedAttemptFlashSubscriber::class);
        self::assertInstanceOf(DeclinedAttemptFlashSubscriber::class, $subscriber);
        $subscriber->setLogger($logger);

        // Quickpay fails after Sylius' status read, before the plugin's own
        $eventDispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $eventDispatcher);
        $eventDispatcher->addListener(KernelEvents::RESPONSE, function (): void {
            $this->quickpay()->respond('GET', '/payments/501', new Psr7Response(500, ['Content-Type' => 'application/json'], '{"message":"Internal error"}'));
        }, 1000);

        [$response, $flashes] = $this->returnFromThePaymentWindow($payment);

        self::assertSame($this->orderPage(), $response->headers->get('Location'));
        self::assertSame([], $flashes->peekAll());
        // Sylius' two status reads, then the plugin's own
        self::assertSame(['GET /payments/501', 'GET /payments/501', 'GET /payments/501'], $this->quickpay()->sentRequests());

        self::assertCount(1, $logger->records);
        [$level, $message, $context] = $logger->records[0];
        self::assertSame(LogLevel::WARNING, $level);
        self::assertStringStartsWith('Could not read the Quickpay payment to tell the returning customer whether their payment was declined: ', $message);
        self::assertSame($payment->getId(), $context['paymentId'] ?? null);
        self::assertSame(501, $context['quickpayPaymentId'] ?? null);
        self::assertInstanceOf(QuickpayException::class, $context['exception'] ?? null);
    }

    /**
     * @test
     *
     * @dataProvider locales
     */
    public function the_customer_is_told_in_their_language(string $locale): void
    {
        self::bootKernel();

        $translator = self::getContainer()->get('translator');
        self::assertInstanceOf(TranslatorBagInterface::class, $translator);

        self::assertTrue($translator->getCatalogue($locale)->defines(DeclinedAttemptFlashSubscriber::MESSAGE, 'flashes'), sprintf('"%s" is translated into "%s"', DeclinedAttemptFlashSubscriber::MESSAGE, $locale));
    }

    /**
     * A new Quickpay payment of an order the shop can show the customer
     */
    private function createReturningPayment(): PaymentInterface
    {
        $payment = $this->createSyliusPayment(PaymentInterface::STATE_NEW, OrderPaymentStates::STATE_AWAITING_PAYMENT, quickpayPaymentId: 501);
        $payment->getOrder()?->setTokenValue(self::ORDER_TOKEN);
        $this->entityManager()->flush();

        return $payment;
    }

    /**
     * Follows Quickpay's cancel url back to the shop: Sylius' after-pay page, on a Payum token for the payment, as
     * Sylius mints it when the customer pays
     *
     * @return array{Response, FlashBagInterface}
     */
    private function returnFromThePaymentWindow(PaymentInterface $payment): array
    {
        $token = $this->payum()->getTokenFactory()->createToken(self::gatewayNameOf($payment), $payment, 'sylius_shop_order_after_pay', ['_locale' => self::LOCALE]);
        $request = Request::create($token->getTargetUrl());

        $response = self::$kernel->handle($request);

        self::assertTrue($response->isRedirection(), (string) $response->getContent());
        $flashes = $request->getSession()->getBag('flashes');
        self::assertInstanceOf(FlashBagInterface::class, $flashes);

        return [$response, $flashes];
    }

    private function orderPage(): string
    {
        return $this->route('sylius_shop_order_show', ['tokenValue' => self::ORDER_TOKEN]);
    }

    /**
     * @param array<string, string> $parameters
     */
    private function route(string $name, array $parameters = []): string
    {
        $router = self::getContainer()->get('router');
        self::assertInstanceOf(UrlGeneratorInterface::class, $router);

        return $router->generate($name, $parameters + ['_locale' => self::LOCALE]);
    }
}
