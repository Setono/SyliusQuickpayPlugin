<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Tests\Functional\Action;

use Composer\InstalledVersions;
use Nyholm\Psr7\Response;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\Reply\HttpRedirect;
use Payum\Core\Request\Capture;
use Payum\Core\Request\Convert;
use Payum\Core\Security\TokenInterface;
use Setono\SyliusQuickpayPlugin\Action\ConvertPaymentAction;
use Setono\SyliusQuickpayPlugin\Tests\Functional\FunctionalTestCase;
use Sylius\Component\Addressing\Model\ZoneInterface;
use Sylius\Component\Core\Model\AddressInterface;
use Sylius\Component\Core\Model\AdjustmentInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\ShipmentInterface;
use Sylius\Component\Core\Model\ShippingMethodInterface;
use Sylius\Component\Core\OrderPaymentStates;
use Sylius\Component\Order\Factory\AdjustmentFactoryInterface;
use Sylius\Component\Payment\Factory\PaymentFactoryInterface;

/**
 * A checkout's Sylius `Capture`, with a token, on a new Sylius payment, through the real gateway against the scripted
 * Quickpay: Sylius' capture action converts the Sylius payment, the plugin's convert describes its order, and
 * payum-quickpay's convert creates (or picks up) the Quickpay payment before its capture sends the customer to the
 * payment window
 */
final class ConvertPaymentActionTest extends FunctionalTestCase
{
    private const LINK_URL = 'https://payment.quickpay.net/payments/the-link';

    /**
     * @test
     */
    public function a_checkout_creates_the_quickpay_payment_with_the_order_described(): void
    {
        self::bootKernel();

        $payment = $this->createCheckoutPayment();
        $token = $this->createCaptureToken($payment);

        $this->quickpay()->respond('GET', '/payments', self::json([]));
        $this->quickpay()->respond('POST', '/payments', self::quickpayPayment(1001, 'initial', [], balance: 0));
        $this->quickpay()->respond('GET', '/payments/1001', self::quickpayPayment(1001, 'initial', [], balance: 0));
        $this->quickpay()->respond('PUT', '/payments/1001/link', self::json(['url' => self::LINK_URL]));

        $redirect = $this->capture($token);

        self::assertSame(self::LINK_URL, $redirect->getUrl());
        self::assertSame(
            ['GET /payments', 'POST /payments', 'GET /payments/1001', 'PUT /payments/1001/link'],
            $this->quickpay()->sentRequests(),
        );
        self::assertSame([
            'order_id' => 'qp_000000001',
            'currency' => 'DKK',
            'invoice_address' => [
                'name' => 'Jane Doe',
                'company_name' => 'Acme ApS',
                'street' => 'Vestergade 1',
                'city' => 'Aarhus C',
                'zip_code' => '8000',
                'region' => 'Midtjylland',
                'country_code' => 'DNK',
                'phone_number' => '+4512345678',
                'mobile_number' => '+4512345678',
                'email' => 'jane@example.com',
            ],
            'shipping_address' => [
                'name' => 'John Doe',
                'street' => 'Nørregade 2',
                'city' => 'København K',
                'zip_code' => '1165',
                'region' => 'DK-84',
                'country_code' => 'DNK',
                'phone_number' => '+4587654321',
                'mobile_number' => '+4587654321',
                'email' => 'jane@example.com',
            ],
            'basket' => [
                ['qty' => 2, 'item_no' => 'MUG_RED', 'item_name' => 'Mug Red', 'item_price' => 10_000, 'vat_rate' => 0.25],
                ['qty' => 1, 'item_no' => 'BOOK_HC', 'item_name' => 'Book Hardcover', 'item_price' => 15_000, 'vat_rate' => 0.12],
            ],
            'shipping' => ['amount' => 4_900, 'vat_rate' => 0.25],
            'shopsystem' => [
                'name' => 'setono/sylius-quickpay-plugin',
                'version' => InstalledVersions::getPrettyVersion('setono/sylius-quickpay-plugin'),
            ],
        ], $this->sentBody('POST /payments'));

        $payment = $this->reload($payment);
        $callbackUrl = $payment->getDetails()['callback_url'] ?? null;
        self::assertIsString($callbackUrl);
        self::assertMatchesRegularExpression('#^http://localhost/payment/notify/[\w-]+$#', $callbackUrl);
        self::assertDetailsUnchanged([
            'amount' => 39_900,
            'quickpayPaymentId' => 1001,
            'order_id' => 'qp_000000001',
            'currency' => 'DKK',
            'continue_url' => $token->getTargetUrl(),
            'cancel_url' => $token->getAfterUrl(),
            'balance' => 0,
            'callback_url' => $callbackUrl,
        ], $payment);
    }

    /**
     * Klarna requires a payment's basket and shipping to add up to its amount. In a channel that adds the tax on top of
     * the prices, with an order promotion whose cents split unevenly over the units and a plugin's fee on the order,
     * each distinct unit price is a line with its tax, and the fee a line of its own
     *
     * @test
     */
    public function a_checkout_creates_the_quickpay_payment_with_a_basket_and_shipping_that_add_up_to_its_amount(): void
    {
        self::bootKernel();

        $payment = $this->createTaxOnTopCheckoutPayment();
        $token = $this->createCaptureToken($payment);

        $this->quickpay()->respond('GET', '/payments', self::json([]));
        $this->quickpay()->respond('POST', '/payments', self::quickpayPayment(1001, 'initial', [], balance: 0));
        $this->quickpay()->respond('GET', '/payments/1001', self::quickpayPayment(1001, 'initial', [], balance: 0));
        $this->quickpay()->respond('PUT', '/payments/1001/link', self::json(['url' => self::LINK_URL]));

        $this->capture($token);

        $body = $this->sentBody('POST /payments');
        self::assertSame([
            ['qty' => 1, 'item_no' => 'MUG_RED', 'item_name' => 'Mug Red', 'item_price' => 833, 'vat_rate' => 0.25],
            ['qty' => 2, 'item_no' => 'MUG_RED', 'item_name' => 'Mug Red', 'item_price' => 834, 'vat_rate' => 0.25],
            ['qty' => 1, 'item_no' => 'payment_fee', 'item_name' => 'Payment fee', 'item_price' => 1_000, 'vat_rate' => 0],
        ], $body['basket'] ?? null);
        self::assertSame(['amount' => 4_900, 'vat_rate' => 0.25], $body['shipping'] ?? null);
        self::assertSame(833 + 2 * 834 + 1_000 + 4_900, $payment->getAmount());
    }

    /**
     * Quickpay takes every address field as optional, so an order without a shipping address still reaches the payment
     * window: its Quickpay payment is created without one
     *
     * @test
     */
    public function a_checkout_creates_the_quickpay_payment_without_a_shipping_address_the_order_lacks(): void
    {
        self::bootKernel();

        $payment = $this->createCheckoutPayment(withShippingAddress: false);
        $token = $this->createCaptureToken($payment);

        $this->quickpay()->respond('GET', '/payments', self::json([]));
        $this->quickpay()->respond('POST', '/payments', self::quickpayPayment(1001, 'initial', [], balance: 0));
        $this->quickpay()->respond('GET', '/payments/1001', self::quickpayPayment(1001, 'initial', [], balance: 0));
        $this->quickpay()->respond('PUT', '/payments/1001/link', self::json(['url' => self::LINK_URL]));

        $redirect = $this->capture($token);

        self::assertSame(self::LINK_URL, $redirect->getUrl());
        self::assertSame(
            ['GET /payments', 'POST /payments', 'GET /payments/1001', 'PUT /payments/1001/link'],
            $this->quickpay()->sentRequests(),
        );

        $body = $this->sentBody('POST /payments');
        self::assertArrayNotHasKey('shipping_address', $body);
        self::assertSame('Jane Doe', ((array) ($body['invoice_address'] ?? null))['name'] ?? null);
        self::assertSame(1001, $this->reload($payment)->getDetails()['quickpayPaymentId'] ?? null);
    }

    /**
     * A customer who was declined and pays the same order again: the order id already belongs to a Quickpay payment
     * nobody paid, which is picked up as it is
     *
     * @test
     */
    public function a_checkout_picks_up_a_never_paid_quickpay_payment_of_the_same_order_id(): void
    {
        self::bootKernel();

        $payment = $this->createCheckoutPayment();
        $token = $this->createCaptureToken($payment);

        $neverPaid = json_decode((string) self::quickpayPayment(1001, 'new', [], balance: 0)->getBody(), true, flags: \JSON_THROW_ON_ERROR);
        $this->quickpay()->respond('GET', '/payments', self::json([$neverPaid]));
        $this->quickpay()->respond('GET', '/payments/1001', self::quickpayPayment(1001, 'new', [], balance: 0));
        $this->quickpay()->respond('PUT', '/payments/1001/link', self::json(['url' => self::LINK_URL]));

        $redirect = $this->capture($token);

        self::assertSame(self::LINK_URL, $redirect->getUrl());
        self::assertSame(['GET /payments', 'GET /payments/1001', 'PUT /payments/1001/link'], $this->quickpay()->sentRequests());

        $details = $this->reload($payment)->getDetails();
        self::assertSame(1001, $details['quickpayPaymentId'] ?? null);
        self::assertSame('qp_000000001', $details['order_id'] ?? null);
    }

    /**
     * The plugin's convert reads the order from the Sylius payment it is given, so it needs no token: a Convert of the
     * Sylius payment without one creates the described Quickpay payment, with no return urls
     *
     * @test
     */
    public function a_sylius_payment_converts_without_a_token(): void
    {
        self::bootKernel();

        $payment = $this->createCheckoutPayment();

        $this->quickpay()->respond('GET', '/payments', self::json([]));
        $this->quickpay()->respond('POST', '/payments', self::quickpayPayment(1001, 'initial', [], balance: 0));

        $convert = new Convert($payment, 'array');
        $this->gatewayOf($payment)->execute($convert);

        self::assertSame(['GET /payments', 'POST /payments'], $this->quickpay()->sentRequests());
        self::assertSame(['name' => 'setono/sylius-quickpay-plugin'], array_intersect_key((array) ($this->sentBody('POST /payments')['shopsystem'] ?? null), ['name' => true]));
        self::assertSame(
            ['amount' => 39_900, 'quickpayPaymentId' => 1001, 'order_id' => 'qp_000000001', 'currency' => 'DKK'],
            $convert->getResult(),
        );
    }

    /**
     * Quickpay takes every address field as optional, so an order without a customer still reaches the payment window:
     * its Quickpay payment is created with addresses without an email
     *
     * @test
     */
    public function a_sylius_payment_of_an_order_without_a_customer_converts(): void
    {
        self::bootKernel();

        $payment = $this->createCheckoutPayment(withCustomer: false);

        $this->quickpay()->respond('GET', '/payments', self::json([]));
        $this->quickpay()->respond('POST', '/payments', self::quickpayPayment(1001, 'initial', [], balance: 0));

        $convert = new Convert($payment, 'array');
        $this->gatewayOf($payment)->execute($convert);

        self::assertSame(['GET /payments', 'POST /payments'], $this->quickpay()->sentRequests());
        $invoiceAddress = (array) ($this->sentBody('POST /payments')['invoice_address'] ?? null);
        self::assertSame('Jane Doe', $invoiceAddress['name'] ?? null);
        self::assertArrayNotHasKey('email', $invoiceAddress);
        $result = $convert->getResult();
        self::assertIsArray($result);
        self::assertSame(1001, $result['quickpayPaymentId'] ?? null);
    }

    /**
     * A customer back at checkout to pay a new Sylius payment again, whose details already carry its Quickpay payment
     * id: payum-quickpay keeps that Quickpay payment, so nothing is asked of Quickpay
     *
     * @test
     */
    public function a_sylius_payment_with_a_quickpay_payment_converts_without_asking_quickpay(): void
    {
        self::bootKernel();

        $payment = $this->createSyliusPayment(PaymentInterface::STATE_NEW, OrderPaymentStates::STATE_AWAITING_PAYMENT, 1001);

        $convert = new Convert($payment, 'array');
        $this->gatewayOf($payment)->execute($convert);

        self::assertSame([], $this->quickpay()->sentRequests());
        self::assertSame(
            ['quickpayPaymentId' => 1001, 'order_id' => 'qp_000000001', 'amount' => 10_000, 'currency' => 'DKK'],
            $convert->getResult(),
        );
    }

    /**
     * Like every Payum action, it refuses a request it doesn't support when executed with one: a Convert of a Sylius
     * payment to anything but an array
     *
     * @test
     */
    public function it_refuses_to_convert_a_sylius_payment_to_anything_but_an_array(): void
    {
        self::bootKernel();

        $payment = $this->createCheckoutPayment();
        $action = self::getContainer()->get(ConvertPaymentAction::class);
        self::assertInstanceOf(ConvertPaymentAction::class, $action);

        $this->expectException(RequestNotSupportedException::class);

        $action->execute(new Convert($payment, 'json'));
    }

    /**
     * Runs the capture as Payum's capture controller does, which ends in the redirect to the payment window
     */
    private function capture(TokenInterface $token): HttpRedirect
    {
        try {
            $this->payum()->getGateway($token->getGatewayName())->execute(new Capture($token));
        } catch (HttpRedirect $redirect) {
            return $redirect;
        }

        self::fail('The capture did not redirect the customer to the payment window');
    }

    /**
     * The token Sylius' checkout sends the customer to Payum's capture with
     */
    private function createCaptureToken(PaymentInterface $payment): TokenInterface
    {
        return $this->payum()->getTokenFactory()->createCaptureToken(self::gatewayNameOf($payment), $payment, 'sylius_shop_order_after_pay', ['_locale' => self::LOCALE]);
    }

    /**
     * The new Sylius payment of a checked-out order of 2 mugs at 100.00 and a book at 150.00, with 25 % and 12 % VAT
     * included, shipped for 49.00 with 25 % VAT included, all persisted. The order totals 399.00, and the payment
     * pays it in full. It is billed to Jane and, unless told otherwise, shipped to John and placed by Jane, a customer
     * with an email.
     */
    private function createCheckoutPayment(bool $withShippingAddress = true, bool $withCustomer = true): PaymentInterface
    {
        $order = $this->createCheckoutOrder($withShippingAddress, $withCustomer);
        $order->addItem($this->createTaxedOrderItem('MUG_RED', 'Mug', 'Red', unitPrice: 10_000, quantity: 2, taxPerUnit: 2_000, vatRate: 0.25));
        $order->addItem($this->createTaxedOrderItem('BOOK_HC', 'Book', 'Hardcover', unitPrice: 15_000, quantity: 1, taxPerUnit: 1_607, vatRate: 0.12));
        $this->ship($order, price: 4_900, tax: 980, taxIncluded: true);

        self::assertSame(39_900, $order->getTotal());

        return $this->createPaymentOf($order);
    }

    /**
     * The new Sylius payment of a checked-out order in a channel adding 25 % VAT on top of the prices: 3 mugs at 10.00
     * less an order promotion of 10.01 (3.34, 3.33 and 3.33 per mug) plus VAT (1.67 each), shipped for 39.20 plus 9.80
     * VAT, and a plugin's payment fee of 10.00 on the order. The order totals 84.01, and the payment pays it in full.
     */
    private function createTaxOnTopCheckoutPayment(): PaymentInterface
    {
        $order = $this->createCheckoutOrder();

        $item = $this->createOrderItem($this->createTrackedVariant('MUG_RED', onHand: 10, onHold: 0), 1_000, 3);
        $item->setProductName('Mug');
        $item->setVariantName('Red');
        foreach (array_values($item->getUnits()->toArray()) as $i => $unit) {
            $unit->addAdjustment($this->adjustmentFactory()->createWithData(AdjustmentInterface::ORDER_PROMOTION_ADJUSTMENT, 'Black Friday', [-334, -333, -333][$i]));
            $unit->addAdjustment($this->adjustmentFactory()->createWithData(AdjustmentInterface::TAX_ADJUSTMENT, 'VAT', 167, false, ['taxRateAmount' => 0.25]));
        }
        $order->addItem($item);

        $this->ship($order, price: 3_920, tax: 980, taxIncluded: false);
        $order->addAdjustment($this->adjustmentFactory()->createWithData('payment_fee', 'Payment fee', 1_000));

        self::assertSame(8_401, $order->getTotal());

        return $this->createPaymentOf($order);
    }

    /**
     * A checked-out order without items or shipment, billed to Jane and, unless told otherwise, shipped to John and
     * placed by Jane
     */
    private function createCheckoutOrder(bool $withShippingAddress = true, bool $withCustomer = true): OrderInterface
    {
        $order = $this->createOrder($this->channel(), OrderPaymentStates::STATE_AWAITING_PAYMENT, '000000001');

        if ($withCustomer) {
            $customer = $this->createResource('sylius.factory.customer');
            self::assertInstanceOf(CustomerInterface::class, $customer);
            $customer->setEmail('jane@example.com');
            $order->setCustomer($customer);
        }

        $order->setBillingAddress($this->createAddress('Jane', 'Doe', 'Vestergade 1', 'Aarhus C', '8000', company: 'Acme ApS', provinceName: 'Midtjylland', phoneNumber: '+4512345678'));
        if ($withShippingAddress) {
            $order->setShippingAddress($this->createAddress('John', 'Doe', 'Nørregade 2', 'København K', '1165', provinceCode: 'DK-84', phoneNumber: '+4587654321'));
        }

        return $order;
    }

    /**
     * Ships the order by courier, taxed with 25 % VAT as Sylius' taxation taxes shipping: a tax adjustment on the
     * shipment carrying the rate, neutral when the price includes the tax
     */
    private function ship(OrderInterface $order, int $price, int $tax, bool $taxIncluded): void
    {
        $shipment = $this->createResource('sylius.factory.shipment');
        self::assertInstanceOf(ShipmentInterface::class, $shipment);
        $shipment->setMethod($this->createShippingMethod());
        $order->addShipment($shipment);
        $shipment->addAdjustment($this->adjustmentFactory()->createWithData(AdjustmentInterface::SHIPPING_ADJUSTMENT, 'Courier', $price));
        $shipment->addAdjustment($this->adjustmentFactory()->createWithData(AdjustmentInterface::TAX_ADJUSTMENT, 'VAT', $tax, $taxIncluded, ['taxRateAmount' => 0.25]));
    }

    /**
     * The order's new Sylius payment, paying its total in full, persisted
     */
    private function createPaymentOf(OrderInterface $order): PaymentInterface
    {
        $paymentFactory = self::getContainer()->get('sylius.factory.payment');
        self::assertInstanceOf(PaymentFactoryInterface::class, $paymentFactory);

        $payment = $paymentFactory->createWithAmountAndCurrencyCode($order->getTotal(), self::CURRENCY);
        self::assertInstanceOf(PaymentInterface::class, $payment);
        $payment->setMethod($this->quickpayPaymentMethod());
        $payment->setState(PaymentInterface::STATE_NEW);
        $order->addPayment($payment);

        $this->entityManager()->persist($payment);
        $this->entityManager()->flush();

        return $payment;
    }

    private function createAddress(
        string $firstName,
        string $lastName,
        string $street,
        string $city,
        string $postcode,
        ?string $company = null,
        ?string $provinceName = null,
        ?string $provinceCode = null,
        ?string $phoneNumber = null,
    ): AddressInterface {
        $address = $this->createResource('sylius.factory.address');
        self::assertInstanceOf(AddressInterface::class, $address);
        $address->setFirstName($firstName);
        $address->setLastName($lastName);
        $address->setCompany($company);
        $address->setStreet($street);
        $address->setCity($city);
        $address->setPostcode($postcode);
        $address->setCountryCode('DK');
        $address->setProvinceName($provinceName);
        $address->setProvinceCode($provinceCode);
        $address->setPhoneNumber($phoneNumber);

        return $address;
    }

    /**
     * An order item with the product and variant names checkout stores on it, and the VAT included in each unit's
     * price as Sylius' taxation stores it: a neutral tax adjustment carrying the rate
     */
    private function createTaxedOrderItem(string $variantCode, string $productName, string $variantName, int $unitPrice, int $quantity, int $taxPerUnit, float $vatRate): OrderItemInterface
    {
        $item = $this->createOrderItem($this->createTrackedVariant($variantCode, onHand: 10, onHold: 0), $unitPrice, $quantity);
        $item->setProductName($productName);
        $item->setVariantName($variantName);

        foreach ($item->getUnits() as $unit) {
            $unit->addAdjustment($this->adjustmentFactory()->createWithData(AdjustmentInterface::TAX_ADJUSTMENT, 'VAT', $taxPerUnit, true, ['taxRateAmount' => $vatRate]));
        }

        return $item;
    }

    private function createShippingMethod(): ShippingMethodInterface
    {
        $zone = $this->createResource('sylius.factory.zone');
        self::assertInstanceOf(ZoneInterface::class, $zone);
        $zone->setCode('DK');
        $zone->setName('Denmark');
        $zone->setType(ZoneInterface::TYPE_COUNTRY);

        $shippingMethod = $this->createResource('sylius.factory.shipping_method');
        self::assertInstanceOf(ShippingMethodInterface::class, $shippingMethod);
        $shippingMethod->setCode('courier');
        $shippingMethod->setCalculator('flat_rate');
        $shippingMethod->setZone($zone);
        $shippingMethod->setCurrentLocale(self::LOCALE);
        $shippingMethod->setFallbackLocale(self::LOCALE);
        $shippingMethod->setName('Courier');

        $this->entityManager()->persist($zone);
        $this->entityManager()->persist($shippingMethod);

        return $shippingMethod;
    }

    /**
     * @return AdjustmentFactoryInterface<AdjustmentInterface>
     */
    private function adjustmentFactory(): AdjustmentFactoryInterface
    {
        $adjustmentFactory = self::getContainer()->get('sylius.factory.adjustment');
        self::assertInstanceOf(AdjustmentFactoryInterface::class, $adjustmentFactory);

        return $adjustmentFactory;
    }

    /**
     * The JSON body the request was sent with, decoded
     *
     * @return array<array-key, mixed>
     */
    private function sentBody(string $request): array
    {
        $index = array_search($request, $this->quickpay()->sentRequests(), true);
        self::assertIsInt($index, sprintf('No "%s" was sent', $request));

        $body = $this->quickpay()->sentRequestOptions()[$index]['body'] ?? null;
        self::assertIsString($body);

        $decoded = json_decode($body, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private static function json(mixed $data): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode($data, \JSON_THROW_ON_ERROR));
    }
}
