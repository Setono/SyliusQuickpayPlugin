<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Action;

use Doctrine\Common\Collections\Collection;
use Payum\Core\Action\ActionInterface;
use Payum\Core\ApiAwareInterface;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\GatewayAwareInterface;
use Payum\Core\GatewayAwareTrait;
use Payum\Core\Model\PaymentInterface as PayumPaymentInterface;
use Payum\Core\Payum;
use Payum\Core\Request\Convert;
use Payum\Core\Security\TokenInterface;
use function Safe\sprintf;
use Setono\Payum\QuickPay\Action\Api\ApiAwareTrait;
use Setono\Payum\QuickPay\Model\QuickPayPayment;
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
class ConvertPaymentAction implements ActionInterface, ApiAwareInterface, GatewayAwareInterface
{
    use GatewayAwareTrait;
    use ApiAwareTrait;

    protected Payum $payum;

    public function __construct(Payum $payum)
    {
        $this->payum = $payum;
    }

    /**
     * @param Convert|mixed $request
     */
    public function execute($request): void
    {
        /** @var PayumPaymentInterface $payumPayment */
        $payumPayment = $request->getSource();

        $details = ArrayObject::ensureArrayObject($payumPayment->getDetails());
        $details['amount'] = $payumPayment->getTotalAmount();
        $details['payment'] = $payumPayment;

        $token = $request->getToken();
        if (!isset($details['quickpayPayment']) || !$details['quickpayPayment'] instanceof QuickPayPayment) {
            $details->replace(
                $this->getRelatedOrderDetails($token)
            );

            $details['quickpayPayment'] = $this->api->getPayment($details);
            $details['quickpayPaymentId'] = $details['quickpayPayment']->getId();
        }

        $details['continue_url'] = $details['cancel_url'] = $token->getAfterUrl();

        $request->setResult((array) $details);
    }

    protected function getRelatedOrderDetails(TokenInterface $token): array
    {
        $details = [];

        $identity = $token->getDetails();
        $syliusPayment = $this->payum->getStorage($identity->getClass())->find($identity);
        Assert::isInstanceOf($syliusPayment, SyliusPaymentInterface::class);

        $order = $syliusPayment->getOrder();
        Assert::isInstanceOf($order, OrderInterface::class);

        $customer = $order->getCustomer();
        Assert::isInstanceOf($customer, CustomerInterface::class);

        $shippingAddress = $order->getShippingAddress();
        Assert::isInstanceOf($shippingAddress, AddressInterface::class);

        $billingAddress = $order->getBillingAddress();
        Assert::isInstanceOf($billingAddress, AddressInterface::class);

        $details['shipping_address'] = $this->convertAddress($shippingAddress, $customer);
        $details['invoice_address'] = $this->convertAddress($billingAddress, $customer);
        $details['shipping']['amount'] = $order->getShippingTotal();
        $details['basket'] = $this->convertOrderItems($order->getItems());
        $details['customer_email'] = $customer->getEmail();

        return $details;
    }

    protected function convertAddress(AddressInterface $address, CustomerInterface $customer): array
    {
        $countryCode = $address->getCountryCode();
        Assert::notNull($countryCode);

        $street = $address->getStreet();
        Assert::notNull($street);

        $details = [];
        switch (mb_strtoupper($countryCode)) {
            case 'DE':
                try {
                    $splittedStreet = AddressSplitter::splitAddress($street);

                    $details['street'] = $splittedStreet['streetName'];
                    $details['house_number'] = $splittedStreet['houseNumber'];
                } catch (SplittingException $e) {
                    $details['street'] = $street;
                    $details['house_number'] = '';
                }

                break;
            case 'NL':
                try {
                    $splittedStreet = AddressSplitter::splitAddress($street);

                    $details['street'] = $splittedStreet['streetName'];
                    $details['house_number'] = $splittedStreet['houseNumberParts']['base'];
                    $details['house_extension'] = $splittedStreet['houseNumberParts']['extension'];
                } catch (SplittingException $e) {
                    $details['street'] = $street;
                    $details['house_number'] = '';
                    $details['house_extension'] = '';
                }

                break;
            default:
                $details['street'] = $street;
        }

        $details['name'] = sprintf(
            '%s %s',
            $address->getFirstName(),
            $address->getLastName()
        );
        $details['city'] = $address->getCity();
        $details['zip_code'] = $address->getPostcode();
        $details['region'] = $address->getProvinceName() ?? $address->getProvinceCode();
        $details['country_code'] = Countries::getAlpha3Code($countryCode);
        $details['phone_number'] = $address->getPhoneNumber();
        $details['email'] = $customer->getEmail();

        return $details;
    }

    /**
     * @param Collection|OrderItemInterface[] $items
     */
    protected function convertOrderItems(Collection $items): array
    {
        return $items->map(function (OrderItemInterface $orderItem): array {
            $variant = $orderItem->getVariant();
            Assert::isInstanceOf($variant, ProductVariantInterface::class);

            return [
                'qty' => $orderItem->getQuantity(),
                'item_no' => $variant->getCode(),
                'item_name' => sprintf(
                    '%s %s',
                    $orderItem->getProductName(),
                    $orderItem->getVariantName()
                ),
                'item_price' => $orderItem->getFullDiscountedUnitPrice(),
                'vat_rate' => 25 / 100, // @todo Fix
            ];
        })->toArray();
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
