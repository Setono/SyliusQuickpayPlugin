<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\EventListener;

use Payum\Core\Registry\RegistryInterface;
use Payum\Core\Request\Refund;
use Setono\SyliusQuickpayPlugin\Event\UnitsRefunded;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Repository\PaymentRepositoryInterface;

final class RefundListener
{
    /**
     * @var RegistryInterface
     */
    private $payum;

    /**
     * @var PaymentRepositoryInterface
     */
    private $paymentRepository;

    public function __construct(
        RegistryInterface $payum,
        PaymentRepositoryInterface $paymentRepository
    ) {
        $this->payum = $payum;
        $this->paymentRepository = $paymentRepository;
    }

    public function __invoke(UnitsRefunded $event): void
    {
        if (null === $event->paymentId()) {
            return;
        }

        $payment = $this->paymentRepository->find($event->paymentId());

        if (!($payment instanceof PaymentInterface)) {
            return;
        }

        if (!isset($payment->getDetails()['quickpayPaymentId'])) {
            return;
        }

        if (null === $paymentMethod = $payment->getMethod()) {
            return;
        }

        $gatewayConfig = $paymentMethod->getGatewayConfig();

        if (null === $gatewayConfig || $gatewayConfig->getFactoryName() !== 'quickpay') {
            return;
        }

        $params = [
            'quickpayPaymentId' => $payment->getDetails()['quickpayPaymentId'],
            'amount' => $event->amount(),
        ];

        $gatewayFactory = $this->payum->getGatewayFactory('quickpay');
        $gateway = $gatewayFactory->create($gatewayConfig->getConfig());
        $gateway->execute(new Refund($params));
    }
}
