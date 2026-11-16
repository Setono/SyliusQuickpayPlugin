<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\OrderDescription;

use Composer\InstalledVersions;
use Setono\Payum\Quickpay\Model\OrderDescription;
use Setono\Quickpay\Request\Payment\Address;
use Setono\Quickpay\Request\Payment\BasketItem;
use Setono\Quickpay\Request\Payment\Shipping;
use Setono\Quickpay\Request\Payment\Shopsystem;
use Setono\SyliusQuickpayPlugin\Taxation\VatRateResolverInterface;
use Sylius\Component\Core\Model\AddressInterface;
use Sylius\Component\Core\Model\AdjustmentInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Order\Model\AdjustmentInterface as BaseAdjustmentInterface;
use Symfony\Component\Intl\Countries;
use Symfony\Component\Intl\Exception\MissingResourceException;
use Webmozart\Assert\Assert;

/**
 * Describes the order so that its basket and shipping add up to the order total, as Klarna requires of the payment's
 * amount: a line per distinct unit price of each item, VAT included as Quickpay's `item_price` is, and a line per
 * adjustment outside the units and shipping
 */
final class OrderDescriber implements OrderDescriberInterface
{
    private const PACKAGE = 'setono/sylius-quickpay-plugin';

    /**
     * The order's adjustments `Order::getShippingTotal()` counts, so they are described as shipping, not as lines
     */
    private const SHIPPING_ADJUSTMENT_TYPES = [
        AdjustmentInterface::SHIPPING_ADJUSTMENT,
        AdjustmentInterface::ORDER_SHIPPING_PROMOTION_ADJUSTMENT,
        AdjustmentInterface::TAX_ADJUSTMENT,
    ];

    public function __construct(private readonly VatRateResolverInterface $vatRateResolver)
    {
    }

    public function describe(OrderInterface $order): OrderDescription
    {
        $email = $order->getCustomer()?->getEmail();

        return new OrderDescription(
            invoiceAddress: self::describeAddress($order->getBillingAddress(), $email),
            shippingAddress: self::describeAddress($order->getShippingAddress(), $email),
            basket: $this->describeBasket($order),
            shipping: new Shipping(
                amount: $order->getShippingTotal(),
                vatRate: $this->vatRateResolver->forShipping($order),
            ),
            shopsystem: new Shopsystem(name: self::PACKAGE, version: self::version()),
        );
    }

    /**
     * The installed version of this plugin, for the `shopsystem` Quickpay records on the payment
     */
    private static function version(): string
    {
        return InstalledVersions::isInstalled(self::PACKAGE)
            ? (InstalledVersions::getPrettyVersion(self::PACKAGE) ?? 'unknown')
            : 'unknown';
    }

    private static function describeAddress(?AddressInterface $address, ?string $email): ?Address
    {
        if (null === $address) {
            return null;
        }

        $countryCode = $address->getCountryCode();
        Assert::notNull($countryCode);

        $street = $address->getStreet();
        Assert::notNull($street);

        return new Address(
            name: sprintf('%s %s', (string) $address->getFirstName(), (string) $address->getLastName()),
            companyName: $address->getCompany(),
            street: $street,
            city: $address->getCity(),
            zipCode: $address->getPostcode(),
            region: $address->getProvinceName() ?? $address->getProvinceCode(),
            countryCode: self::alpha3CodeOf($countryCode),
            phoneNumber: $address->getPhoneNumber(),
            mobileNumber: $address->getPhoneNumber(),
            email: $email,
        );
    }

    /**
     * The country's ISO 3166-1 alpha-3 code, which Quickpay takes, or null for a code Symfony Intl has none for (e.g.
     * Kosovo's XK), so the address is sent without a country
     */
    private static function alpha3CodeOf(string $countryCode): ?string
    {
        try {
            return Countries::getAlpha3Code($countryCode);
        } catch (MissingResourceException) {
            return null;
        }
    }

    /**
     * Each item's lines, then a line per adjustment on the order that isn't shipping's
     *
     * @return list<BasketItem>
     */
    private function describeBasket(OrderInterface $order): array
    {
        $basket = [];
        foreach ($order->getItems() as $item) {
            array_push($basket, ...$this->describeItem($item));
        }

        foreach ($order->getAdjustments() as $adjustment) {
            if (in_array($adjustment->getType(), self::SHIPPING_ADJUSTMENT_TYPES, true)) {
                continue;
            }

            $line = $this->describeAdjustment($adjustment);
            if (null !== $line) {
                $basket[] = $line;
            }
        }

        return $basket;
    }

    /**
     * A line per distinct unit total, in the order the units come, then a line per adjustment on the item itself. A
     * unit's total is its price with its promotions and the tax added on top (tax included in the price is neutral), so
     * it includes the VAT either way, and units an order promotion's odd cents reached are lines of their own
     *
     * @return list<BasketItem>
     */
    private function describeItem(OrderItemInterface $item): array
    {
        $variant = $item->getVariant();
        Assert::isInstanceOf($variant, ProductVariantInterface::class);

        /** @var array<int, int<1, max>> $quantityPerUnitTotal */
        $quantityPerUnitTotal = [];
        foreach ($item->getUnits() as $unit) {
            $unitTotal = $unit->getTotal();
            $quantityPerUnitTotal[$unitTotal] = ($quantityPerUnitTotal[$unitTotal] ?? 0) + 1;
        }

        $itemName = self::nameOf($item, $variant);
        $vatRate = $this->vatRateResolver->forOrderItem($item);

        $lines = [];
        foreach ($quantityPerUnitTotal as $unitTotal => $quantity) {
            $lines[] = new BasketItem(
                qty: $quantity,
                itemNo: (string) $variant->getCode(),
                itemName: $itemName,
                itemPrice: $unitTotal,
                vatRate: $vatRate,
            );
        }

        foreach ($item->getAdjustments() as $adjustment) {
            $line = $this->describeAdjustment($adjustment);
            if (null !== $line) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * The product name and the variant name, joined by a space only when both are set (an empty name counts as unset),
     * or the variant's code when neither is, so a line is never sent without a name
     */
    private static function nameOf(OrderItemInterface $item, ProductVariantInterface $variant): string
    {
        $names = array_filter(
            [$item->getProductName(), $item->getVariantName()],
            static fn (?string $name): bool => null !== $name && '' !== $name,
        );

        return [] === $names ? (string) $variant->getCode() : implode(' ', $names);
    }

    /**
     * One line named by the adjustment's label, or else its type, and never by its origin code, which can be a gift
     * card's or a coupon's code. None for an adjustment that changes no total: a neutral one or one of 0
     */
    private function describeAdjustment(BaseAdjustmentInterface $adjustment): ?BasketItem
    {
        if ($adjustment->isNeutral() || 0 === $adjustment->getAmount()) {
            return null;
        }

        $type = (string) $adjustment->getType();
        $label = $adjustment->getLabel();

        return new BasketItem(
            qty: 1,
            itemNo: $type,
            itemName: null === $label || '' === $label ? $type : $label,
            itemPrice: $adjustment->getAmount(),
            vatRate: $this->vatRateResolver->forAdjustment($adjustment),
        );
    }
}
