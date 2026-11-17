<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Tests\Functional\Controller\Admin;

use Nyholm\Psr7\Response as PsrResponse;
use Psr\Log\LogLevel;
use Setono\Quickpay\Exception\QuickpayException;
use Setono\SyliusQuickpayPlugin\Controller\Admin\PaymentOperationsAction;
use Setono\SyliusQuickpayPlugin\Tests\Functional\FunctionalTestCase;
use Setono\SyliusQuickpayPlugin\Tests\Log\RecordingLogger;
use Sylius\Component\Core\Model\AdminUserInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\OrderPaymentStates;
use Sylius\Component\Resource\Factory\FactoryInterface;
use Symfony\Bundle\FrameworkBundle\Test\TestBrowserToken;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionFactoryInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Twig\Environment;

/**
 * The operations panel on the admin's order page: the Quickpay payment's operation history, which the order page
 * fetches from this route once it has rendered
 */
final class PaymentOperationsPanelTest extends FunctionalTestCase
{
    /**
     * The order page holds the panel only for a payment with a Quickpay payment, so it never asks the route for one
     * without
     *
     * @test
     *
     * @dataProvider paymentsOnTheOrderPage
     */
    public function the_order_page_holds_the_panel_only_for_a_payment_with_a_quickpay_payment(string $case, bool $panel): void
    {
        self::bootKernel();

        $payment = $this->createSyliusPayment(PaymentInterface::STATE_COMPLETED, OrderPaymentStates::STATE_PAID, quickpayPaymentId: 501);
        if ('a null Quickpay payment id' === $case) {
            $payment->setDetails(['quickpayPaymentId' => null]);
        }
        if ('switched to an offline method' === $case) {
            // The customer opened the Quickpay window, then switched to bank transfer: Sylius kept the details
            $payment->setMethod($this->createPaymentMethod('bank_transfer', [], factoryName: 'offline'));
        }
        $this->entityManager()->flush();

        $twig = self::getContainer()->get('twig');
        self::assertInstanceOf(Environment::class, $twig);
        $html = $twig->render('@SetonoSyliusQuickpayPlugin/admin/order/show/payment/_quickpay.html.twig', ['payment' => $payment]);

        self::assertSame($panel, str_contains($html, 'setono-quickpay-operations'), $html);
        self::assertSame([], $this->quickpay()->sentRequests());
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function paymentsOnTheOrderPage(): iterable
    {
        yield 'a Quickpay payment' => ['a Quickpay payment', true];

        yield 'a null Quickpay payment id' => ['a null Quickpay payment id', false];

        yield 'switched to an offline method' => ['switched to an offline method', false];
    }

    /**
     * @test
     */
    public function it_shows_the_operation_history_of_the_quickpay_payment_with_its_fraud_badge(): void
    {
        self::bootKernel();

        $payment = $this->createSyliusPayment(PaymentInterface::STATE_COMPLETED, OrderPaymentStates::STATE_PAID, quickpayPaymentId: 501);
        $this->quickpay()->respond('GET', '/payments/501', self::quickpayPayment(501, 'processed', ['authorize', 'capture'], balance: 10_000, fraudSuspected: true));

        $response = $this->requestPanelOf($payment);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
        $panel = (string) $response->getContent();
        self::assertStringContainsString('authorize', $panel);
        self::assertStringContainsString('capture', $panel);
        self::assertStringContainsString('Fraud suspected', $panel);
        self::assertSame(['GET /payments/501'], $this->quickpay()->sentRequests());
    }

    /**
     * Each operation shows how Quickpay's callback about it was answered, as Quickpay records it, and where it went
     *
     * @test
     */
    public function it_shows_the_callback_outcome_of_each_operation(): void
    {
        self::bootKernel();

        $payment = $this->createSyliusPayment(PaymentInterface::STATE_COMPLETED, OrderPaymentStates::STATE_PAID, quickpayPaymentId: 501);
        $this->quickpay()->respond('GET', '/payments/501', new PsrResponse(200, ['Content-Type' => 'application/json'], json_encode([
            'id' => 501,
            'order_id' => self::ORDER_PREFIX . '000000001',
            'currency' => self::CURRENCY,
            'state' => 'processed',
            'merchant_id' => 1,
            'balance' => 5_000,
            'operations' => [
                self::operationWithCallback(1, 'authorize', true, '204', '2026-11-17T09:12:34Z', 'https://shop.example.com/payment/notify/the-token'),
                self::operationWithCallback(2, 'capture', false, '500', '2026-11-17T10:00:00Z', 'https://shop.example.com/payment/quickpay/notify'),
                self::operationWithCallback(3, 'refund', false, null, '2026-11-17T11:00:00Z', 'https://shop.example.com/payment/quickpay/notify'),
                self::operationWithCallback(4, 'refund', null, null, null, null, pending: true),
            ],
        ], \JSON_THROW_ON_ERROR)));

        $response = $this->requestPanelOf($payment);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame([
            ['204 2026-11-17 09:12:34', 'green', 'https://shop.example.com/payment/notify/the-token'],
            ['500 2026-11-17 10:00:00', 'red', 'https://shop.example.com/payment/quickpay/notify'],
            ['No answer 2026-11-17 11:00:00', 'red', 'https://shop.example.com/payment/quickpay/notify'],
            ['Not sent', null, null],
        ], self::callbackCellsIn((string) $response->getContent()));
        self::assertStringContainsString('The store answers 204 when it reconciled the payment', (string) $response->getContent());
        self::assertSame(['GET /payments/501'], $this->quickpay()->sentRequests());
    }

    /**
     * @test
     */
    public function it_is_not_found_for_a_payment_that_is_not_paid_through_a_quickpay_gateway(): void
    {
        self::bootKernel();

        $payment = $this->createSyliusPayment(PaymentInterface::STATE_NEW, OrderPaymentStates::STATE_AWAITING_PAYMENT, quickpayPaymentId: 501);
        $payment->setMethod($this->createPaymentMethod('cash_on_delivery', [], factoryName: 'offline'));
        $this->entityManager()->flush();

        $response = $this->requestPanelOf($payment);

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        self::assertSame([], $this->quickpay()->sentRequests());
    }

    /**
     * @test
     */
    public function it_shows_an_inline_notice_and_logs_a_warning_when_quickpay_fails(): void
    {
        self::bootKernel();
        $logger = $this->recordPanelLog();

        $payment = $this->createSyliusPayment(PaymentInterface::STATE_COMPLETED, OrderPaymentStates::STATE_PAID, quickpayPaymentId: 501);
        $this->quickpay()->respond('GET', '/payments/501', new PsrResponse(500, ['Content-Type' => 'application/json'], '{"message": "Quickpay is down"}'));

        $response = $this->requestPanelOf($payment);

        self::assertSame(Response::HTTP_BAD_GATEWAY, $response->getStatusCode());
        self::assertStringContainsString('Could not load the operation history from Quickpay.', (string) $response->getContent());

        self::assertCount(1, $logger->records);
        [$level, $message, $context] = $logger->records[0];
        self::assertSame(LogLevel::WARNING, $level);
        self::assertStringStartsWith('Could not load the operation history from Quickpay: ', $message);
        self::assertStringContainsString('Quickpay is down', $message);
        self::assertSame($payment->getId(), $context['paymentId'] ?? null);
        self::assertSame('quickpay', $context['gatewayName'] ?? null);
        self::assertInstanceOf(QuickpayException::class, $context['exception'] ?? null);
    }

    /**
     * An operation as Quickpay returns it, with the callback fields it records about the operation's callback
     *
     * @return array<string, mixed>
     */
    private static function operationWithCallback(int $id, string $type, ?bool $success, ?string $responseCode, ?string $at, ?string $url, bool $pending = false): array
    {
        return [
            'id' => $id,
            'type' => $type,
            'amount' => 5_000,
            'pending' => $pending,
            'qp_status_code' => $pending ? null : '20000',
            'qp_status_msg' => $pending ? null : 'Approved',
            'callback_url' => $url,
            'callback_success' => $success,
            'callback_response_code' => $responseCode,
            'callback_at' => $at,
            'callback_duration' => null === $at ? null : 120,
        ];
    }

    /**
     * Each operation row's callback cell: its text with whitespace collapsed, the colour of its label (if any) and its
     * title (the url the callback went to)
     *
     * @return list<array{string, string|null, string|null}>
     */
    private static function callbackCellsIn(string $panel): array
    {
        $document = new \DOMDocument();
        $document->loadHTML('<?xml encoding="utf-8"?>' . $panel, \LIBXML_NOERROR);
        $xpath = new \DOMXPath($document);

        $callbackCells = $xpath->query('//tbody/tr/td[@class="setono-quickpay-operations__callback"]');
        self::assertInstanceOf(\DOMNodeList::class, $callbackCells);

        $cells = [];
        foreach ($callbackCells as $cell) {
            self::assertInstanceOf(\DOMElement::class, $cell);

            $labels = $xpath->query('.//span[contains(@class, "label")]', $cell);
            self::assertInstanceOf(\DOMNodeList::class, $labels);
            $label = $labels->item(0);
            $colour = $label instanceof \DOMElement ? (array_values(array_intersect(['green', 'red'], explode(' ', $label->getAttribute('class'))))[0] ?? null) : null;

            $cells[] = [
                trim((string) preg_replace('/\s+/', ' ', $cell->textContent)),
                $colour,
                $cell->hasAttribute('title') ? $cell->getAttribute('title') : null,
            ];
        }

        return $cells;
    }

    /**
     * Requests the payment's operations panel as a logged-in administrator
     */
    private function requestPanelOf(PaymentInterface $payment): Response
    {
        $router = self::getContainer()->get('router');
        self::assertInstanceOf(UrlGeneratorInterface::class, $router);

        $request = Request::create($router->generate('setono_sylius_quickpay_admin_payment_operations', ['id' => $payment->getId()]));
        [$sessionName, $sessionId] = $this->logInAsAdministrator();
        $request->cookies->set($sessionName, $sessionId);

        return self::$kernel->handle($request);
    }

    /**
     * Stores an administrator's security token in a new session, as Symfony's test browser logs a user in
     *
     * @return array{string, string} the session's cookie name and id
     */
    private function logInAsAdministrator(): array
    {
        $adminUserFactory = self::getContainer()->get('sylius.factory.admin_user');
        self::assertInstanceOf(FactoryInterface::class, $adminUserFactory);

        $administrator = $adminUserFactory->createNew();
        self::assertInstanceOf(AdminUserInterface::class, $administrator);
        self::assertInstanceOf(UserInterface::class, $administrator);
        $administrator->setUsername('administrator');
        $administrator->setEmail('administrator@example.com');
        $administrator->setPassword('not-a-password-hash');
        $administrator->setLocaleCode(self::LOCALE);
        $administrator->setEnabled(true);
        $this->entityManager()->persist($administrator);
        $this->entityManager()->flush();

        $sessionFactory = self::getContainer()->get('session.factory');
        self::assertInstanceOf(SessionFactoryInterface::class, $sessionFactory);

        $session = $sessionFactory->createSession();
        $session->set('_security_admin', serialize(new TestBrowserToken($administrator->getRoles(), $administrator, 'admin')));
        $session->save();

        return [$session->getName(), $session->getId()];
    }

    /**
     * Records what the container's operations panel logs from now on
     */
    private function recordPanelLog(): RecordingLogger
    {
        $logger = new RecordingLogger();

        $panel = self::getContainer()->get(PaymentOperationsAction::class);
        self::assertInstanceOf(PaymentOperationsAction::class, $panel);
        $panel->setLogger($logger);

        return $logger;
    }
}
