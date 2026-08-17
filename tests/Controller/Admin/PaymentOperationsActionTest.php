<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Tests\Controller\Admin;

use CuyZ\Valinor\MapperBuilder;
use Nyholm\Psr7\Response as PsrResponse;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Setono\Quickpay\Client\Client;
use Setono\Quickpay\Client\ClientInterface;
use Setono\Quickpay\Client\Endpoint\PaymentsEndpoint;
use Setono\Quickpay\Exception\UnauthorizedException;
use Setono\Quickpay\Response\Payment\Payment as QuickpayPayment;
use Setono\SyliusQuickpayPlugin\Controller\Admin\PaymentOperationsAction;
use Setono\SyliusQuickpayPlugin\Quickpay\ClientFactoryInterface;
use Sylius\Bundle\PayumBundle\Model\GatewayConfigInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Core\Repository\PaymentRepositoryInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Twig\Environment;

final class PaymentOperationsActionTest extends TestCase
{
    use ProphecyTrait;

    /**
     * @test
     */
    public function it_renders_the_operations_fetched_from_quickpay(): void
    {
        $sdkClient = $this->prophesize(ClientInterface::class);
        $sdkClient->get(Argument::cetera())->willReturn([
            'id' => 999999,
            'order_id' => 'qp_000000021',
            'currency' => 'EUR',
            'state' => 'processed',
            'merchant_id' => 1,
            'accepted' => true,
            'test_mode' => true,
            'balance' => 500,
            'operations' => [
                [
                    'id' => 1,
                    'type' => 'authorize',
                    'amount' => 1000,
                    'pending' => false,
                    'qp_status_code' => '20000',
                    'qp_status_msg' => 'Approved',
                    'created_at' => '2026-08-10T12:00:00+00:00',
                ],
            ],
        ]);
        $sdkClient->payments()->will(fn (): PaymentsEndpoint => new PaymentsEndpoint($sdkClient->reveal(), Client::configureMapperBuilder(new MapperBuilder())));

        $twig = $this->prophesize(Environment::class);
        $twig->render(
            '@SetonoSyliusQuickpayPlugin/admin/order/show/payment/_operations.html.twig',
            Argument::that(static fn (array $context): bool => ($context['quickpay_payment'] ?? null) instanceof QuickpayPayment &&
                999999 === $context['quickpay_payment']->id &&
                1 === \count($context['quickpay_payment']->operations)),
        )->willReturn('<table>rendered</table>');

        $response = ($this->createAction($sdkClient, $twig))(1);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('<table>rendered</table>', $response->getContent());
    }

    /**
     * @test
     */
    public function it_renders_an_error_when_quickpay_cannot_be_reached(): void
    {
        $sdkClient = $this->prophesize(ClientInterface::class);
        $sdkClient->get(Argument::cetera())->willThrow(new UnauthorizedException(new PsrResponse(401)));
        $sdkClient->payments()->will(fn (): PaymentsEndpoint => new PaymentsEndpoint($sdkClient->reveal(), Client::configureMapperBuilder(new MapperBuilder())));

        $twig = $this->prophesize(Environment::class);
        $twig->render('@SetonoSyliusQuickpayPlugin/admin/order/show/payment/_operations_error.html.twig')
            ->willReturn('<div>error</div>');

        $response = ($this->createAction($sdkClient, $twig))(1);

        self::assertSame(502, $response->getStatusCode());
        self::assertSame('<div>error</div>', $response->getContent());
    }

    /**
     * @test
     */
    public function it_throws_a_not_found_exception_for_an_unknown_payment(): void
    {
        $paymentRepository = $this->prophesize(PaymentRepositoryInterface::class);
        $paymentRepository->find(1)->willReturn(null);

        $action = new PaymentOperationsAction(
            $paymentRepository->reveal(),
            $this->prophesize(ClientFactoryInterface::class)->reveal(),
            $this->prophesize(Environment::class)->reveal(),
        );

        $this->expectException(NotFoundHttpException::class);

        $action(1);
    }

    /**
     * @test
     */
    public function it_throws_a_not_found_exception_for_a_payment_without_a_quickpay_payment_id(): void
    {
        $paymentRepository = $this->prophesize(PaymentRepositoryInterface::class);
        $paymentRepository->find(1)->willReturn($this->createPayment(details: []));

        $action = new PaymentOperationsAction(
            $paymentRepository->reveal(),
            $this->prophesize(ClientFactoryInterface::class)->reveal(),
            $this->prophesize(Environment::class)->reveal(),
        );

        $this->expectException(NotFoundHttpException::class);

        $action(1);
    }

    /**
     * @param \Prophecy\Prophecy\ObjectProphecy<ClientInterface> $sdkClient
     * @param \Prophecy\Prophecy\ObjectProphecy<Environment> $twig
     */
    private function createAction(object $sdkClient, object $twig): PaymentOperationsAction
    {
        $paymentRepository = $this->prophesize(PaymentRepositoryInterface::class);
        $paymentRepository->find(1)->willReturn($this->createPayment(['quickpayPaymentId' => 999999]));

        $clientFactory = $this->prophesize(ClientFactoryInterface::class);
        $clientFactory->create('the-api-key')->willReturn($sdkClient);

        return new PaymentOperationsAction(
            $paymentRepository->reveal(),
            $clientFactory->reveal(),
            $twig->reveal(),
        );
    }

    /**
     * @param array<string, mixed> $details
     */
    private function createPayment(array $details): PaymentInterface
    {
        $gatewayConfig = $this->prophesize(GatewayConfigInterface::class);
        $gatewayConfig->getConfig()->willReturn(['api_key' => 'the-api-key']);

        $method = $this->prophesize(PaymentMethodInterface::class);
        $method->getGatewayConfig()->willReturn($gatewayConfig);

        $payment = $this->prophesize(PaymentInterface::class);
        $payment->getDetails()->willReturn($details);
        $payment->getMethod()->willReturn($method);

        return $payment->reveal();
    }
}
