<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Action;

use Payum\Core\Action\ActionInterface;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\GatewayAwareInterface;
use Payum\Core\GatewayAwareTrait;
use Payum\Core\Request\Convert;
use Setono\Payum\Quickpay\Model\DescribedPayment;
use Setono\SyliusQuickpayPlugin\OrderDescription\OrderDescriberInterface;
use Sylius\Bundle\PayumBundle\Provider\PaymentDescriptionProviderInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Webmozart\Assert\Assert;

/**
 * Converts a Sylius payment by describing its order to payum-quickpay. Sylius' capture and authorize actions first
 * convert the Sylius payment itself, and fall back to a synthetic Payum payment only when no action supports that;
 * this action supports it, so the fallback never runs for a Quickpay gateway. It builds payum-quickpay's described
 * payment the way that fallback builds its synthetic one, gives it the order describer's description of the payment's
 * order, and has payum-quickpay's convert, which owns the order id, finding or creating the Quickpay payment, the
 * currency check and the return urls, convert it.
 */
final class ConvertPaymentAction implements ActionInterface, GatewayAwareInterface
{
    use GatewayAwareTrait;

    public function __construct(
        private readonly OrderDescriberInterface $orderDescriber,
        private readonly PaymentDescriptionProviderInterface $paymentDescriptionProvider,
    ) {
    }

    /**
     * @param mixed|Convert $request
     */
    public function execute($request): void
    {
        RequestNotSupportedException::assertSupports($this, $request);
        Assert::isInstanceOf($request, Convert::class);

        $payment = $request->getSource();
        Assert::isInstanceOf($payment, PaymentInterface::class);

        $order = $payment->getOrder();
        Assert::isInstanceOf($order, OrderInterface::class);

        $customer = $order->getCustomer();
        $customerId = $customer?->getId();

        $describedPayment = new DescribedPayment();
        $describedPayment->setNumber((string) $order->getNumber());
        $describedPayment->setTotalAmount((int) $payment->getAmount());
        $describedPayment->setCurrencyCode((string) $order->getCurrencyCode());
        $describedPayment->setClientEmail((string) $customer?->getEmail());
        $describedPayment->setClientId(is_scalar($customerId) ? (string) $customerId : '');
        $describedPayment->setDescription($this->paymentDescriptionProvider->getPaymentDescription($payment));
        $describedPayment->setDetails($payment->getDetails());
        $describedPayment->setOrderDescription($this->orderDescriber->describe($order));

        $this->gateway->execute($convert = new Convert($describedPayment, 'array', $request->getToken()));

        $request->setResult($convert->getResult());
    }

    public function supports($request): bool
    {
        return
            $request instanceof Convert &&
            $request->getSource() instanceof PaymentInterface &&
            $request->getTo() === 'array'
        ;
    }
}
