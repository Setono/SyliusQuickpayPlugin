<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Tests\Action;

use CuyZ\Valinor\MapperBuilder;
use Payum\Core\Exception\LogicException;
use Payum\Core\Model\Identity;
use Payum\Core\Model\Payment;
use Payum\Core\Model\Token;
use Payum\Core\Payum;
use Payum\Core\Request\Convert;
use Payum\Core\Storage\StorageInterface;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Setono\Payum\Quickpay\Api;
use Setono\Quickpay\Client\Client;
use Setono\Quickpay\Client\ClientInterface;
use Setono\Quickpay\Client\Endpoint\PaymentsEndpoint;
use Setono\SyliusQuickpayPlugin\Action\ConvertPaymentAction;
use Setono\SyliusQuickpayPlugin\Taxation\VatRateResolverInterface;
use Sylius\Component\Core\Model\Address;
use Sylius\Component\Core\Model\Customer;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\Payment as SyliusPayment;

/**
 * Covers the path for a payment that already exists at Quickpay (details carry a quickpayPaymentId),
 * which needs neither the API nor the Payum storage. The create path is exercised by the integration
 * suite against the test application.
 */
final class ConvertPaymentActionTest extends TestCase
{
    use ProphecyTrait;

    /**
     * @test
     */
    public function it_sends_the_customer_back_to_the_token_target_url_and_cancels_to_the_after_url(): void
    {
        $token = $this->createToken();
        $request = new Convert($this->createPayment(['quickpayPaymentId' => 123, 'currency' => 'DKK']), 'array', $token);

        $this->createAction()->execute($request);

        /** @var array<string, mixed> $result */
        $result = $request->getResult();

        self::assertSame('https://shop.test/payment/capture/abc', $result['continue_url']);
        self::assertSame('https://shop.test/order/after-pay/xyz', $result['cancel_url']);
        self::assertSame(123, $result['quickpayPaymentId']);
    }

    /**
     * @test
     */
    public function it_keeps_the_currency_when_it_is_unchanged_and_backfills_it_when_missing(): void
    {
        $unchanged = new Convert($this->createPayment(['quickpayPaymentId' => 123, 'currency' => 'DKK']), 'array', $this->createToken());
        $missing = new Convert($this->createPayment(['quickpayPaymentId' => 123]), 'array', $this->createToken());

        $action = $this->createAction();
        $action->execute($unchanged);
        $action->execute($missing);

        /** @var array<string, mixed> $unchangedResult */
        $unchangedResult = $unchanged->getResult();
        /** @var array<string, mixed> $missingResult */
        $missingResult = $missing->getResult();

        self::assertSame('DKK', $unchangedResult['currency']);
        self::assertSame('DKK', $missingResult['currency']);
    }

    /**
     * @test
     */
    public function it_throws_when_the_currency_drifts_after_the_quickpay_payment_was_created(): void
    {
        $request = new Convert($this->createPayment(['quickpayPaymentId' => 123, 'currency' => 'EUR']), 'array', $this->createToken());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The Quickpay payment 123 was created in EUR, but the Payum payment now says DKK');

        $this->createAction()->execute($request);
    }

    /**
     * @test
     */
    public function it_adopts_an_existing_unpaid_quickpay_payment_with_the_same_order_id(): void
    {
        // The retry case: the customer was declined and pays the same order again — the order id
        // already exists at Quickpay, on a payment nobody ever paid
        $request = $this->createCreatePathRequest($this->createSdkClient([
            self::quickpayPayment(999, 'qp_000000042', 'DKK', operations: [
                ['id' => 1, 'type' => 'authorize', 'amount' => 24999, 'pending' => false, 'qp_status_code' => '40000', 'qp_status_msg' => 'Rejected by acquirer', 'created_at' => '2026-08-24T12:00:00+00:00'],
            ]),
        ]));

        $this->action->execute($request);

        /** @var array<string, mixed> $result */
        $result = $request->getResult();

        self::assertSame(999, $result['quickpayPaymentId']);
        self::assertSame('qp_000000042', $result['order_id']);
        self::assertSame('DKK', $result['currency']);
    }

    /**
     * @test
     */
    public function it_refuses_to_adopt_a_payment_with_an_approved_operation(): void
    {
        $request = $this->createCreatePathRequest($this->createSdkClient([
            self::quickpayPayment(999, 'qp_000000042', 'DKK', operations: [
                ['id' => 1, 'type' => 'authorize', 'amount' => 24999, 'pending' => false, 'qp_status_code' => '20000', 'qp_status_msg' => 'Approved', 'created_at' => '2026-08-24T12:00:00+00:00'],
            ]),
        ]));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('already exists (id 999, state new) and has an approved authorize');

        $this->action->execute($request);
    }

    /**
     * @test
     */
    public function it_refuses_to_adopt_a_payment_in_another_currency(): void
    {
        $request = $this->createCreatePathRequest($this->createSdkClient([
            self::quickpayPayment(999, 'qp_000000042', 'EUR'),
        ]));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('in EUR, but this payment is in DKK');

        $this->action->execute($request);
    }

    /**
     * @test
     */
    public function it_rejects_an_order_id_quickpay_would_not_accept(): void
    {
        $request = $this->createCreatePathRequest($this->createSdkClient([]), number: '42/A');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The Quickpay order id "qp_42/A"');

        $this->action->execute($request);
    }

    private ConvertPaymentAction $action;

    private function createAction(): ConvertPaymentAction
    {
        return new ConvertPaymentAction(
            $this->prophesize(Payum::class)->reveal(),
            $this->prophesize(VatRateResolverInterface::class)->reveal(),
        );
    }

    /**
     * A Convert on the create path: no quickpayPaymentId in the details, the Sylius order resolved
     * through the token identity. The prophesized SDK client answers the order-id lookup; a create
     * (POST) is deliberately not stubbed, so adopting must never fall through to creating.
     */
    private function createCreatePathRequest(ClientInterface $sdkClient, string $number = '000000042'): Convert
    {
        $payumPayment = $this->createPayment([]);
        $payumPayment->setNumber($number);

        $address = new Address();
        $address->setFirstName('Joe');
        $address->setLastName('Doe');
        $address->setStreet('Somestreet 1');
        $address->setCountryCode('DK');

        $customer = new Customer();
        $customer->setEmail('joe@example.com');

        $order = new Order();
        $order->setBillingAddress($address);
        $order->setShippingAddress(clone $address);
        $order->setCustomer($customer);

        $syliusPayment = new SyliusPayment();
        $order->addPayment($syliusPayment);

        $identity = new Identity(1, $syliusPayment);

        $storage = $this->prophesize(StorageInterface::class);
        $storage->find($identity)->willReturn($syliusPayment);

        $payum = $this->prophesize(Payum::class);
        $payum->getStorage(SyliusPayment::class)->willReturn($storage->reveal());

        $token = $this->createToken();
        $token->setDetails($identity);

        $this->action = new ConvertPaymentAction($payum->reveal(), $this->prophesize(VatRateResolverInterface::class)->reveal());
        $this->action->setApi(new Api($sdkClient, 'private-key', orderPrefix: 'qp_'));

        return new Convert($payumPayment, 'array', $token);
    }

    /**
     * @param list<array<string, mixed>> $paymentsListedByOrderIdLookup
     */
    private function createSdkClient(array $paymentsListedByOrderIdLookup): ClientInterface
    {
        $sdkClient = $this->prophesize(ClientInterface::class);
        $sdkClient->get(Argument::cetera())->willReturn($paymentsListedByOrderIdLookup);
        $sdkClient->payments()->will(fn (): PaymentsEndpoint => new PaymentsEndpoint($sdkClient->reveal(), Client::configureMapperBuilder(new MapperBuilder())));

        return $sdkClient->reveal();
    }

    /**
     * @param list<array<string, mixed>> $operations
     *
     * @return array<string, mixed>
     */
    private static function quickpayPayment(int $id, string $orderId, string $currency, array $operations = []): array
    {
        return [
            'id' => $id,
            'order_id' => $orderId,
            'currency' => $currency,
            'state' => 'new',
            'merchant_id' => 1,
            'accepted' => false,
            'test_mode' => true,
            'operations' => $operations,
        ];
    }

    /**
     * @param array<string, mixed> $details
     */
    private function createPayment(array $details): Payment
    {
        $payment = new Payment();
        $payment->setNumber('000000042');
        $payment->setCurrencyCode('DKK');
        $payment->setTotalAmount(24999);
        $payment->setDetails($details);

        return $payment;
    }

    private function createToken(): Token
    {
        $token = new Token();
        $token->setGatewayName('quickpay');
        $token->setTargetUrl('https://shop.test/payment/capture/abc');
        $token->setAfterUrl('https://shop.test/order/after-pay/xyz');

        return $token;
    }
}
