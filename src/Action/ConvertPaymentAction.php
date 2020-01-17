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

class ConvertPaymentAction implements ActionInterface, ApiAwareInterface, GatewayAwareInterface
{
    use GatewayAwareTrait;
    use ApiAwareTrait;

    /** @var Payum */
    protected $payum;

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
        assert($syliusPayment instanceof SyliusPaymentInterface);

        $order = $syliusPayment->getOrder();
        assert($order instanceof OrderInterface);

        $customer = $order->getCustomer();
        assert($customer instanceof CustomerInterface);

        $shippingAddress = $order->getShippingAddress();
        assert($shippingAddress instanceof AddressInterface);

        $billingAddress = $order->getBillingAddress();
        assert($billingAddress instanceof AddressInterface);

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
        assert(null !== $countryCode);

        $details = [];
        $details['name'] = sprintf(
            '%s %s',
            $address->getFirstName(),
            $address->getLastName()
        );
        $details['street'] = $address->getStreet();
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
            assert($variant instanceof ProductVariantInterface);

            return [
                'qty' => $orderItem->getQuantity(),
                'item_no' => $variant->getCode(),
                'item_name' => sprintf(
                    '%s %s',
                    $orderItem->getProductName(),
                    $orderItem->getVariantName()
                ),
                'item_price' => $orderItem->getUnitPrice(),
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
