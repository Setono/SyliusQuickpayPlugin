<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Tests\Unit\OrderDescription;

use Composer\InstalledVersions;
use PHPUnit\Framework\TestCase;
use Setono\Payum\Quickpay\Model\OrderDescription;
use Setono\Quickpay\Request\Payment\Address as QuickpayAddress;
use Setono\Quickpay\Request\Payment\BasketItem;
use Setono\Quickpay\Request\Payment\Shipping;
use Setono\Quickpay\Request\Payment\Shopsystem;
use Setono\SyliusQuickpayPlugin\OrderDescription\OrderDescriber;
use Setono\SyliusQuickpayPlugin\Taxation\VatRateResolver;
use Sylius\Component\Core\Model\Address;
use Sylius\Component\Core\Model\AddressInterface;
use Sylius\Component\Core\Model\Adjustment;
use Sylius\Component\Core\Model\AdjustmentInterface;
use Sylius\Component\Core\Model\Customer;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItem;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Core\Model\OrderItemUnit;
use Sylius\Component\Core\Model\ProductVariant;
use Sylius\Component\Core\Model\Shipment;

/**
 * What the order describer tells Quickpay about a Sylius order, built from real Sylius models with the real VAT rate
 * resolver. The prices are pinned as they are today, including where the basket doesn't add up to the order total
 */
final class OrderDescriberTest extends TestCase
{
    /**
     * Each address is named "first last", with its province name rather than its code when it has both, its phone as
     * both phone and mobile, its country as alpha-3 and the customer's email. Each item is its quantity, variant code,
     * "product variant" name, price and VAT rate, and the plugin names itself and its installed version as the shopsystem
     *
     * @test
     */
    public function it_describes_an_order_whose_prices_include_the_tax(): void
    {
        $order = self::order();
        $order->addItem(self::item('MUG_RED', 'Mug', 'Red', unitPrice: 10_000, quantity: 2, taxPerUnit: 2_000, vatRate: 0.25, taxIncluded: true));
        $order->addItem(self::item('BOOK_HC', 'Book', 'Hardcover', unitPrice: 15_000, quantity: 1, taxPerUnit: 1_607, vatRate: 0.12, taxIncluded: true));
        self::ship($order, 4_900, tax: 980, vatRate: 0.25, taxIncluded: true);

        self::assertSame(39_900, $order->getTotal());
        self::assertEquals(new OrderDescription(
            invoiceAddress: new QuickpayAddress(
                name: 'Jane Doe',
                companyName: 'Acme ApS',
                street: 'Vestergade 1',
                city: 'Aarhus C',
                zipCode: '8000',
                region: 'Midtjylland',
                countryCode: 'DNK',
                phoneNumber: '+4512345678',
                mobileNumber: '+4512345678',
                email: 'jane@example.com',
            ),
            shippingAddress: new QuickpayAddress(
                name: 'John Doe',
                street: 'Nørregade 2',
                city: 'København K',
                zipCode: '1165',
                region: 'DK-84',
                countryCode: 'DNK',
                phoneNumber: '+4587654321',
                mobileNumber: '+4587654321',
                email: 'jane@example.com',
            ),
            basket: [
                new BasketItem(qty: 2, itemNo: 'MUG_RED', itemName: 'Mug Red', itemPrice: 10_000, vatRate: 0.25),
                new BasketItem(qty: 1, itemNo: 'BOOK_HC', itemName: 'Book Hardcover', itemPrice: 15_000, vatRate: 0.12),
            ],
            shipping: new Shipping(amount: 4_900, vatRate: 0.25),
            shopsystem: new Shopsystem(
                name: 'setono/sylius-quickpay-plugin',
                version: InstalledVersions::getPrettyVersion('setono/sylius-quickpay-plugin'),
            ),
        ), self::describe($order));
    }

    /**
     * Pinned as it is: each item's price is sent without the tax added on top, while shipping is sent with it, so the
     * basket and shipping add up to less than the order total
     *
     * @test
     */
    public function it_describes_an_order_whose_tax_is_added_on_top_of_the_prices(): void
    {
        $order = self::order();
        $order->addItem(self::item('MUG_RED', 'Mug', 'Red', unitPrice: 8_000, quantity: 2, taxPerUnit: 2_000, vatRate: 0.25, taxIncluded: false));
        self::ship($order, 3_920, tax: 980, vatRate: 0.25, taxIncluded: false);

        self::assertSame(24_900, $order->getTotal());

        $description = self::describe($order);
        self::assertEquals([new BasketItem(qty: 2, itemNo: 'MUG_RED', itemName: 'Mug Red', itemPrice: 8_000, vatRate: 0.25)], $description->basket);
        self::assertEquals(new Shipping(amount: 4_900, vatRate: 0.25), $description->shipping);
    }

    /**
     * Pinned as it is: an item's price is its first unit's price after unit and order promotions, so an order
     * promotion's cents spread unevenly across the units make the basket differ from the item's total
     *
     * @test
     */
    public function it_describes_an_items_price_as_its_first_units_price_after_unit_and_order_promotions(): void
    {
        $order = self::order();
        $order->addItem($item = self::item('MUG_RED', 'Mug', 'Red', unitPrice: 1_000, quantity: 3));
        self::discount($item, AdjustmentInterface::ORDER_UNIT_PROMOTION_ADJUSTMENT, -100, -100, -100);
        self::discount($item, AdjustmentInterface::ORDER_PROMOTION_ADJUSTMENT, -334, -333, -333);

        self::assertSame(1_700, $item->getTotal());
        self::assertEquals(
            [new BasketItem(qty: 3, itemNo: 'MUG_RED', itemName: 'Mug Red', itemPrice: 566, vatRate: 0.0)],
            self::describe($order)->basket,
        );
    }

    /**
     * @test
     */
    public function it_describes_shipping_for_an_order_without_a_shipment(): void
    {
        $order = self::order();
        $order->addItem(self::item('EBOOK', 'E-book', 'PDF', unitPrice: 5_000, quantity: 1, taxPerUnit: 1_000, vatRate: 0.25));

        self::assertEquals(new Shipping(amount: 0, vatRate: 0.0), self::describe($order)->shipping);
    }

    /**
     * @test
     *
     * @dataProvider customersWithoutAnEmail
     */
    public function it_describes_the_addresses_without_an_email_for_an_order_without_a_customers_email(?Customer $customer): void
    {
        $order = self::order();
        $order->setCustomer($customer);

        $description = self::describe($order);
        self::assertNull($description->invoiceAddress?->email);
        self::assertNull($description->shippingAddress?->email);
        self::assertSame('Jane Doe', $description->invoiceAddress?->name);
        self::assertSame('John Doe', $description->shippingAddress?->name);
    }

    /**
     * @return iterable<string, array{Customer|null}>
     */
    public static function customersWithoutAnEmail(): iterable
    {
        yield 'no customer' => [null];
        yield 'a customer without an email' => [new Customer()];
    }

    /**
     * @test
     */
    public function it_describes_no_invoice_address_for_an_order_without_a_billing_address(): void
    {
        $order = self::order();
        $order->setBillingAddress(null);

        $description = self::describe($order);
        self::assertNull($description->invoiceAddress);
        self::assertSame('John Doe', $description->shippingAddress?->name);
    }

    /**
     * @test
     */
    public function it_describes_no_shipping_address_for_an_order_without_one(): void
    {
        $order = self::order();
        $order->setShippingAddress(null);

        $description = self::describe($order);
        self::assertNull($description->shippingAddress);
        self::assertSame('Jane Doe', $description->invoiceAddress?->name);
    }

    /**
     * Kosovo's code, XK, is in use but not assigned by ISO 3166, and Symfony Intl has no alpha-3 code for it
     *
     * @test
     */
    public function it_describes_an_address_without_a_country_when_its_country_has_no_alpha3_code(): void
    {
        $order = self::order();
        $order->setShippingAddress(self::address('John', 'Doe', 'Rruga Agim Ramadani 1', 'Pristina', '10000', countryCode: 'XK'));

        $description = self::describe($order);
        self::assertEquals(new QuickpayAddress(
            name: 'John Doe',
            street: 'Rruga Agim Ramadani 1',
            city: 'Pristina',
            zipCode: '10000',
            email: 'jane@example.com',
        ), $description->shippingAddress);
        self::assertSame('DNK', $description->invoiceAddress?->countryCode);
    }

    /**
     * Sylius always sets an item's variant and an address' street and country, so an order lacking one is broken
     * rather than missing optional data, and describing it fails
     *
     * @test
     *
     * @dataProvider brokenOrders
     *
     * @param \Closure(Order): void $break
     */
    public function it_fails_to_describe_a_broken_order(\Closure $break): void
    {
        $order = self::order();
        $order->addItem(self::item('MUG_RED', 'Mug', 'Red', unitPrice: 10_000, quantity: 1));
        $break($order);

        $this->expectException(\InvalidArgumentException::class);

        self::describe($order);
    }

    /**
     * @return iterable<string, array{\Closure(Order): void}>
     */
    public static function brokenOrders(): iterable
    {
        yield 'an item without a variant' => [static function (Order $order): void {
            $item = new OrderItem();
            $item->setProductName('Mug');
            $item->setVariantName('Blue');
            $item->setUnitPrice(10_000);
            new OrderItemUnit($item);
            $order->addItem($item);
        }];
        yield 'an address without a street' => [static fn (Order $order) => $order->getBillingAddress()?->setStreet(null)];
        yield 'an address without a country' => [static fn (Order $order) => $order->getShippingAddress()?->setCountryCode(null)];
    }

    private static function describe(OrderInterface $order): OrderDescription
    {
        return (new OrderDescriber(new VatRateResolver()))->describe($order);
    }

    /**
     * An order by jane@example.com, billed to her company in Aarhus (a province name and code) and shipped to John in
     * Copenhagen (a province code only), with no items and no shipment
     */
    private static function order(): Order
    {
        $customer = new Customer();
        $customer->setEmail('jane@example.com');

        $order = new Order();
        $order->setCustomer($customer);
        $order->setBillingAddress(self::address('Jane', 'Doe', 'Vestergade 1', 'Aarhus C', '8000', company: 'Acme ApS', provinceName: 'Midtjylland', provinceCode: 'DK-82', phoneNumber: '+4512345678'));
        $order->setShippingAddress(self::address('John', 'Doe', 'Nørregade 2', 'København K', '1165', provinceCode: 'DK-84', phoneNumber: '+4587654321'));

        return $order;
    }

    private static function address(
        string $firstName,
        string $lastName,
        string $street,
        string $city,
        string $postcode,
        string $countryCode = 'DK',
        ?string $company = null,
        ?string $provinceName = null,
        ?string $provinceCode = null,
        ?string $phoneNumber = null,
    ): AddressInterface {
        $address = new Address();
        $address->setFirstName($firstName);
        $address->setLastName($lastName);
        $address->setCompany($company);
        $address->setStreet($street);
        $address->setCity($city);
        $address->setPostcode($postcode);
        $address->setCountryCode($countryCode);
        $address->setProvinceName($provinceName);
        $address->setProvinceCode($provinceCode);
        $address->setPhoneNumber($phoneNumber);

        return $address;
    }

    /**
     * An order item of the given number of units, each taxed, when given a VAT rate, as Sylius' taxation taxes it: a
     * tax adjustment per unit carrying the rate, neutral when the price includes the tax
     */
    private static function item(
        string $variantCode,
        string $productName,
        string $variantName,
        int $unitPrice,
        int $quantity,
        int $taxPerUnit = 0,
        ?float $vatRate = null,
        bool $taxIncluded = true,
    ): OrderItemInterface {
        $variant = new ProductVariant();
        $variant->setCode($variantCode);

        $item = new OrderItem();
        $item->setVariant($variant);
        $item->setProductName($productName);
        $item->setVariantName($variantName);
        $item->setUnitPrice($unitPrice);

        for ($i = 0; $i < $quantity; ++$i) {
            $unit = new OrderItemUnit($item);
            if (null !== $vatRate) {
                $unit->addAdjustment(self::adjustment(AdjustmentInterface::TAX_ADJUSTMENT, $taxPerUnit, $taxIncluded, ['taxRateAmount' => $vatRate]));
            }
        }

        return $item;
    }

    /**
     * Discounts the item's units as Sylius' promotions do: an adjustment of the given promotion type per unit, of the
     * given amount for each unit in turn
     */
    private static function discount(OrderItemInterface $item, string $promotionType, int ...$amountPerUnit): void
    {
        foreach (array_values($item->getUnits()->toArray()) as $i => $unit) {
            $unit->addAdjustment(self::adjustment($promotionType, $amountPerUnit[$i]));
        }
    }

    /**
     * Ships the order for the given price, taxed as Sylius' taxation taxes shipping: a tax adjustment on the shipment
     * carrying the rate, neutral when the price includes the tax
     */
    private static function ship(OrderInterface $order, int $price, int $tax, float $vatRate, bool $taxIncluded): void
    {
        $shipment = new Shipment();
        $order->addShipment($shipment);
        $shipment->addAdjustment(self::adjustment(AdjustmentInterface::SHIPPING_ADJUSTMENT, $price));
        $shipment->addAdjustment(self::adjustment(AdjustmentInterface::TAX_ADJUSTMENT, $tax, $taxIncluded, ['taxRateAmount' => $vatRate]));
    }

    /**
     * @param array<string, mixed> $details
     */
    private static function adjustment(string $type, int $amount, bool $neutral = false, array $details = []): AdjustmentInterface
    {
        $adjustment = new Adjustment();
        $adjustment->setType($type);
        $adjustment->setLabel($type);
        $adjustment->setAmount($amount);
        $adjustment->setNeutral($neutral);
        $adjustment->setDetails($details);

        return $adjustment;
    }
}
