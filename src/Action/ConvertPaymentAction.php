<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Action;

use Doctrine\Common\Collections\Collection;
use Payum\Core\Action\ActionInterface;
use Payum\Core\ApiAwareInterface;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\GatewayAwareInterface;
use Payum\Core\GatewayAwareTrait;
use Payum\Core\Model\PaymentInterface as PayumPaymentInterface;
use Payum\Core\Payum;
use Payum\Core\Request\Convert;
use Payum\Core\Security\TokenInterface;
use Setono\Payum\Quickpay\Action\Api\ApiAwareTrait;
use Setono\Quickpay\Request\Payment\Address;
use Setono\Quickpay\Request\Payment\BasketItem;
use Setono\Quickpay\Request\Payment\CreatePaymentRequest;
use Setono\Quickpay\Request\Payment\Shipping;
use function sprintf;
use Sylius\Component\Core\Model\AddressInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Core\Model\PaymentInterface as SyliusPaymentInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Symfony\Component\Intl\Countries;
use VIISON\AddressSplitter\AddressSplitter;
use VIISON\AddressSplitter\Exceptions\SplittingException;
use Webmozart\Assert\Assert;

/**
 * @see https://learn.quickpay.net/tech-talk/payments/form/#quickpay-form for field names reference
 */
final class ConvertPaymentAction implements ActionInterface, ApiAwareInterface, GatewayAwareInterface
{
    use GatewayAwareTrait;
    use ApiAwareTrait;

    public function __construct(private readonly Payum $payum)
    {
    }

    /**
     * @param mixed|Convert $request
     */
    public function execute($request): void
    {
        RequestNotSupportedException::assertSupports($this, $request);
        Assert::isInstanceOf($request, Convert::class);

        $payumPayment = $request->getSource();
        Assert::isInstanceOf($payumPayment, PayumPaymentInterface::class);

        $details = ArrayObject::ensureArrayObject($payumPayment->getDetails());
        $details['amount'] = $payumPayment->getTotalAmount();
        $details['currency'] = $payumPayment->getCurrencyCode();

        $token = $request->getToken();
        Assert::notNull($token);

        // Only scalars are stored in the details: quickpayPaymentId is the single source of truth
        // and the payment is re-fetched from Quickpay when needed
        if (!isset($details['quickpayPaymentId'])) {
            $number = $payumPayment->getNumber();
            Assert::stringNotEmpty($number);

            $currency = $payumPayment->getCurrencyCode();
            Assert::stringNotEmpty($currency);

            $orderId = $this->api->getOrderPrefix() . $number;
            Assert::lengthBetween($orderId, 4, 20, sprintf(
                'The Quickpay order id "%s" must be between 4 and 20 characters. Adjust the order_prefix gateway option accordingly.',
                $orderId,
            ));

            $order = $this->getRelatedOrder($token);

            $customer = $order->getCustomer();
            Assert::isInstanceOf($customer, CustomerInterface::class);

            $shippingAddress = $order->getShippingAddress();
            Assert::isInstanceOf($shippingAddress, AddressInterface::class);

            $billingAddress = $order->getBillingAddress();
            Assert::isInstanceOf($billingAddress, AddressInterface::class);

            $quickpayPayment = $this->api->payments()->create(new CreatePaymentRequest(
                orderId: $orderId,
                currency: $currency,
                invoiceAddress: $this->convertAddress($billingAddress, $customer),
                shippingAddress: $this->convertAddress($shippingAddress, $customer),
                basket: $this->convertOrderItems($order->getItems()),
                shipping: new Shipping(amount: $order->getShippingTotal()),
            ));

            $details['quickpayPaymentId'] = $quickpayPayment->id;
            $details['order_id'] = $quickpayPayment->orderId;
        }

        $details['continue_url'] = $details['cancel_url'] = $token->getAfterUrl();

        $request->setResult((array) $details);
    }

    private function getRelatedOrder(TokenInterface $token): OrderInterface
    {
        $identity = $token->getDetails();
        $syliusPayment = $this->payum->getStorage($identity->getClass())->find($identity);
        Assert::isInstanceOf($syliusPayment, SyliusPaymentInterface::class);

        $order = $syliusPayment->getOrder();
        Assert::isInstanceOf($order, OrderInterface::class);

        return $order;
    }

    private function convertAddress(AddressInterface $address, CustomerInterface $customer): Address
    {
        $countryCode = $address->getCountryCode();
        Assert::notNull($countryCode);

        $street = $address->getStreet();
        Assert::notNull($street);

        $houseNumber = null;
        $houseExtension = null;
        switch (mb_strtoupper($countryCode)) {
            case 'DE':
                try {
                    /** @var array<string, string> $splittedStreet */
                    $splittedStreet = AddressSplitter::splitAddress($street);

                    $street = $splittedStreet['streetName'];
                    $houseNumber = $splittedStreet['houseNumber'];
                } catch (SplittingException) {
                    $houseNumber = '';
                }

                break;
            case 'NL':
                try {
                    /** @var array{streetName: string, houseNumberParts: array{base: string, extension: string}} $splittedStreet */
                    $splittedStreet = AddressSplitter::splitAddress($street);

                    $street = $splittedStreet['streetName'];
                    $houseNumber = $splittedStreet['houseNumberParts']['base'];
                    $houseExtension = $splittedStreet['houseNumberParts']['extension'];
                } catch (SplittingException) {
                    $houseNumber = '';
                    $houseExtension = '';
                }

                break;
        }

        return new Address(
            name: sprintf('%s %s', (string) $address->getFirstName(), (string) $address->getLastName()),
            companyName: $address->getCompany(),
            street: $street,
            houseNumber: $houseNumber,
            houseExtension: $houseExtension,
            city: $address->getCity(),
            zipCode: $address->getPostcode(),
            region: $address->getProvinceName() ?? $address->getProvinceCode(),
            countryCode: Countries::getAlpha3Code($countryCode),
            phoneNumber: $address->getPhoneNumber(),
            mobileNumber: $address->getPhoneNumber(),
            email: $customer->getEmail(),
        );
    }

    /**
     * @param Collection<array-key, OrderItemInterface> $items
     *
     * @return list<BasketItem>
     */
    private function convertOrderItems(Collection $items): array
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
                vatRate: 25 / 100, // @todo Derive the real VAT rate from the order's tax adjustments
            );
        })->toArray());
    }

    public function supports($request): bool
    {
        return
            $request instanceof Convert &&
            $request->getSource() instanceof PayumPaymentInterface &&
            $request->getTo() === 'array'
        ;
    }
}
