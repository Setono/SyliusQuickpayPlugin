<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Tests\Functional\Controller\Admin;

use Nyholm\Psr7\Response as PsrResponse;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Setono\Quickpay\Exception\QuickpayException;
use Setono\SyliusQuickpayPlugin\Controller\Admin\PaymentOperationsAction;
use Setono\SyliusQuickpayPlugin\Tests\Functional\FunctionalTestCase;
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

/**
 * The operations panel on the admin's order page: the Quickpay payment's operation history, which the order page
 * fetches from this route once it has rendered
 */
final class PaymentOperationsPanelTest extends FunctionalTestCase
{
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
     *
     * @return object{records: list<array{mixed, string, array<array-key, mixed>}>}
     */
    private function recordPanelLog(): object
    {
        $logger = new class() extends AbstractLogger {
            /** @var list<array{mixed, string, array<array-key, mixed>}> */
            public array $records = [];

            /**
             * @param mixed $level
             * @param string|\Stringable $message
             * @param array<array-key, mixed> $context
             */
            public function log($level, $message, array $context = []): void
            {
                $this->records[] = [$level, (string) $message, $context];
            }
        };

        $panel = self::getContainer()->get(PaymentOperationsAction::class);
        self::assertInstanceOf(PaymentOperationsAction::class, $panel);
        $panel->setLogger($logger);

        return $logger;
    }
}
