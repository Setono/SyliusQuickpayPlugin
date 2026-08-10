<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Tests\Provider;

use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;
use Setono\SyliusQuickpayPlugin\Provider\PaymentProvider;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;

final class PaymentProviderTest extends TestCase
{
    /**
     * @test
     */
    public function it_finds_the_payment_belonging_to_the_quickpay_payment_id(): void
    {
        $expected = $this->createPayment(['quickpayPaymentId' => 12345]);
        $order = $this->createOrder([
            $this->createPayment(['quickpayPaymentId' => 99999]),
            $expected,
        ]);

        self::assertSame($expected, (new PaymentProvider())->findByQuickpayPaymentId($order, 12345));
    }

    /**
     * @test
     */
    public function it_finds_the_latest_matching_payment(): void
    {
        $latest = $this->createPayment(['quickpayPaymentId' => '12345']);
        $order = $this->createOrder([
            $this->createPayment(['quickpayPaymentId' => 12345]),
            $latest,
        ]);

        self::assertSame($latest, (new PaymentProvider())->findByQuickpayPaymentId($order, 12345));
    }

    /**
     * @test
     */
    public function it_returns_null_when_no_payment_matches(): void
    {
        $order = $this->createOrder([
            $this->createPayment(['quickpayPaymentId' => 99999]),
            $this->createPayment([]),
            $this->createPayment(['quickpayPaymentId' => 'not-numeric']),
        ]);

        self::assertNull((new PaymentProvider())->findByQuickpayPaymentId($order, 12345));
    }

    /**
     * @param array<string, mixed> $details
     */
    private function createPayment(array $details): PaymentInterface
    {
        $payment = $this->createMock(PaymentInterface::class);
        $payment->method('getDetails')->willReturn($details);

        return $payment;
    }

    /**
     * @param list<PaymentInterface> $payments
     */
    private function createOrder(array $payments): OrderInterface
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getPayments')->willReturn(new ArrayCollection($payments));

        return $order;
    }
}
