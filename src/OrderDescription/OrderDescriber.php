<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\OrderDescription;

use Composer\InstalledVersions;
use Doctrine\Common\Collections\Collection;
use Setono\Payum\Quickpay\Model\OrderDescription;
use Setono\Quickpay\Request\Payment\Address;
use Setono\Quickpay\Request\Payment\BasketItem;
use Setono\Quickpay\Request\Payment\Shipping;
use Setono\Quickpay\Request\Payment\Shopsystem;
use Setono\SyliusQuickpayPlugin\Taxation\VatRateResolverInterface;
use Sylius\Component\Core\Model\AddressInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Symfony\Component\Intl\Countries;
use Symfony\Component\Intl\Exception\MissingResourceException;
use Webmozart\Assert\Assert;

/**
 * @see https://learn.quickpay.net/tech-talk/payments/form/#quickpay-form for field names reference
 */
final class OrderDescriber implements OrderDescriberInterface
{
    private const PACKAGE = 'setono/sylius-quickpay-plugin';

    public function __construct(private readonly VatRateResolverInterface $vatRateResolver)
    {
    }

    public function describe(OrderInterface $order): OrderDescription
    {
        $email = $order->getCustomer()?->getEmail();

        return new OrderDescription(
            invoiceAddress: self::describeAddress($order->getBillingAddress(), $email),
            shippingAddress: self::describeAddress($order->getShippingAddress(), $email),
            basket: $this->describeItems($order->getItems()),
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
     * @param Collection<array-key, OrderItemInterface> $items
     *
     * @return list<BasketItem>
     */
    private function describeItems(Collection $items): array
    {
        return array_values($items->map(function (OrderItemInterface $orderItem): BasketItem {
            $variant = $orderItem->getVariant();
            Assert::isInstanceOf($variant, ProductVariantInterface::class);

            return new BasketItem(
                qty: $orderItem->getQuantity(),
                itemNo: (string) $variant->getCode(),
                itemName: sprintf(
                    '%s %s',
                    (string) $orderItem->getProductName(),
                    (string) $orderItem->getVariantName(),
                ),
                itemPrice: $orderItem->getFullDiscountedUnitPrice(),
                vatRate: $this->vatRateResolver->forOrderItem($orderItem),
            );
        })->toArray());
    }
}
