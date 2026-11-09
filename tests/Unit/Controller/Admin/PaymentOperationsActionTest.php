<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Tests\Unit\Controller\Admin;

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
use Setono\SyliusQuickpayPlugin\GatewayConfiguration\GatewayConfiguration;
use Setono\SyliusQuickpayPlugin\GatewayConfiguration\GatewayConfigurationProviderInterface;
use Setono\SyliusQuickpayPlugin\Quickpay\ClientFactoryInterface;
use Setono\SyliusQuickpayPlugin\Tests\GatewayConfiguration\GatewayConfigurations;
use Sylius\Component\Core\Model\Payment;
use Sylius\Component\Core\Model\PaymentInterface;
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
     *
     * @dataProvider unusableGatewayConfigurations
     */
    public function it_renders_an_error_when_the_payment_s_gateway_configuration_is_unusable(GatewayConfiguration $gatewayConfiguration): void
    {
        $sdkClient = $this->prophesize(ClientInterface::class);
        $sdkClient->get(Argument::cetera())->shouldNotBeCalled();

        $twig = $this->prophesize(Environment::class);
        $twig->render('@SetonoSyliusQuickpayPlugin/admin/order/show/payment/_operations_error.html.twig')
            ->willReturn('<div>error</div>');

        $response = ($this->createAction($sdkClient, $twig, $gatewayConfiguration))(1);

        self::assertSame(502, $response->getStatusCode());
        self::assertSame('<div>error</div>', $response->getContent());
    }

    /**
     * @return iterable<string, array{GatewayConfiguration}>
     */
    public static function unusableGatewayConfigurations(): iterable
    {
        yield 'invalid' => [GatewayConfigurations::of(['api_key' => 'the-api-key', 'private_key' => 'the-private-key', 'branding_id' => 'my branding'])];

        yield 'encrypted' => [GatewayConfigurations::encrypted()];
    }

    /**
     * @test
     */
    public function it_throws_a_not_found_exception_for_an_unknown_payment(): void
    {
        $paymentRepository = $this->prophesize(PaymentRepositoryInterface::class);
        $paymentRepository->find(1)->willReturn(null);

        $this->expectException(NotFoundHttpException::class);

        $this->createActionFor($paymentRepository->reveal(), GatewayConfigurations::of())(1);
    }

    /**
     * @test
     */
    public function it_throws_a_not_found_exception_for_a_payment_without_a_quickpay_payment_id(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $this->createActionFor($this->createPaymentRepository(details: []), GatewayConfigurations::of())(1);
    }

    /**
     * @test
     */
    public function it_throws_a_not_found_exception_for_a_payment_without_a_quickpay_gateway_configuration(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $this->createActionFor($this->createPaymentRepository(['quickpayPaymentId' => 999999]), null)(1);
    }

    /**
     * @param \Prophecy\Prophecy\ObjectProphecy<ClientInterface> $sdkClient
     * @param \Prophecy\Prophecy\ObjectProphecy<Environment> $twig
     */
    private function createAction(
        object $sdkClient,
        object $twig,
        ?GatewayConfiguration $gatewayConfiguration = null,
    ): PaymentOperationsAction {
        $clientFactory = $this->prophesize(ClientFactoryInterface::class);
        $clientFactory->create('the-api-key')->willReturn($sdkClient);

        return new PaymentOperationsAction(
            $this->createPaymentRepository(['quickpayPaymentId' => 999999]),
            // A usable gateway configuration unless another is given
            $this->createGatewayConfigurationProvider($gatewayConfiguration ?? GatewayConfigurations::of()),
            $clientFactory->reveal(),
            $twig->reveal(),
        );
    }

    /**
     * An action that must not reach Quickpay or render anything
     *
     * @param PaymentRepositoryInterface<PaymentInterface> $paymentRepository
     */
    private function createActionFor(PaymentRepositoryInterface $paymentRepository, ?GatewayConfiguration $gatewayConfiguration): PaymentOperationsAction
    {
        $clientFactory = $this->prophesize(ClientFactoryInterface::class);
        $clientFactory->create(Argument::any())->shouldNotBeCalled();

        return new PaymentOperationsAction(
            $paymentRepository,
            $this->createGatewayConfigurationProvider($gatewayConfiguration),
            $clientFactory->reveal(),
            $this->prophesize(Environment::class)->reveal(),
        );
    }

    private function createGatewayConfigurationProvider(?GatewayConfiguration $gatewayConfiguration): GatewayConfigurationProviderInterface
    {
        $gatewayConfigurationProvider = $this->prophesize(GatewayConfigurationProviderInterface::class);
        $gatewayConfigurationProvider->forPayment(Argument::type(PaymentInterface::class))->willReturn($gatewayConfiguration);

        return $gatewayConfigurationProvider->reveal();
    }

    /**
     * @param array<string, mixed> $details
     *
     * @return PaymentRepositoryInterface<PaymentInterface>
     */
    private function createPaymentRepository(array $details): PaymentRepositoryInterface
    {
        $payment = new Payment();
        $payment->setDetails($details);

        $paymentRepository = $this->prophesize(PaymentRepositoryInterface::class);
        $paymentRepository->find(1)->willReturn($payment);

        return $paymentRepository->reveal();
    }
}
