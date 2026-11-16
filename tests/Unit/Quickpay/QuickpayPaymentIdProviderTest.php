<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Tests\Unit\Quickpay;

use Payum\Core\Exception\LogicException;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Setono\SyliusQuickpayPlugin\GatewayConfiguration\GatewayConfigurationProviderInterface;
use Setono\SyliusQuickpayPlugin\Quickpay\QuickpayPaymentIdProvider;
use Setono\SyliusQuickpayPlugin\Tests\GatewayConfiguration\GatewayConfigurations;
use Sylius\Component\Core\Model\Payment;
use Sylius\Component\Core\Model\PaymentInterface;

/**
 * A Sylius payment's Quickpay payment id: read by payum-quickpay's `Details` rule, and only while the payment's method
 * has a Quickpay gateway configuration
 */
final class QuickpayPaymentIdProviderTest extends TestCase
{
    use ProphecyTrait;

    /**
     * @test
     *
     * @dataProvider readableIds
     */
    public function it_reads_the_quickpay_payment_id_of_a_quickpay_gateways_payment(mixed $storedId, int $id): void
    {
        $payment = self::payment(['quickpayPaymentId' => $storedId]);
        $provider = $this->provider(quickpayGateway: true);

        self::assertTrue($provider->has($payment));
        self::assertSame($id, $provider->idOf($payment));
        self::assertSame($id, $provider->tryIdOf($payment));
    }

    /**
     * @return iterable<string, array{mixed, int}>
     */
    public static function readableIds(): iterable
    {
        yield 'an int' => [501, 501];

        yield 'a digit string' => ['501', 501];
    }

    /**
     * A payment that never got a payment link has no Quickpay payment yet
     *
     * @test
     *
     * @dataProvider missingIds
     *
     * @param array<string, mixed> $details
     */
    public function it_gives_no_quickpay_payment_id_to_a_payment_without_one(array $details): void
    {
        $payment = self::payment($details);
        $provider = $this->provider(quickpayGateway: true);

        self::assertFalse($provider->has($payment));
        self::assertNull($provider->tryIdOf($payment));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('has no Quickpay payment yet');

        $provider->idOf($payment);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function missingIds(): iterable
    {
        yield 'no details' => [[]];

        yield 'a null id' => [['quickpayPaymentId' => null]];
    }

    /**
     * An id payum-quickpay can't read is still a Quickpay payment's, so it fails where the id is needed and never
     * matches where payments are matched by it
     *
     * @test
     *
     * @dataProvider unreadableIds
     */
    public function it_fails_to_read_an_unreadable_quickpay_payment_id_and_never_matches_it(mixed $storedId): void
    {
        $payment = self::payment(['quickpayPaymentId' => $storedId]);
        $provider = $this->provider(quickpayGateway: true);

        self::assertTrue($provider->has($payment));
        self::assertNull($provider->tryIdOf($payment));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('no usable "quickpayPaymentId"');

        $provider->idOf($payment);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function unreadableIds(): iterable
    {
        yield 'a word' => ['abc'];

        yield 'zero' => [0];

        yield 'a negative id' => [-1];

        yield 'exponent notation' => ['1e3'];

        yield 'a fraction' => [12.5];

        yield 'true' => [true];
    }

    /**
     * Sylius keeps a payment's details when the customer switches it to another payment method, so a bank transfer may
     * carry the id of the Quickpay payment the customer first opened
     *
     * @test
     *
     * @dataProvider otherPayments
     */
    public function it_gives_no_quickpay_payment_id_to_a_payment_that_is_no_quickpay_gateways(PaymentInterface $payment): void
    {
        $provider = $this->provider(quickpayGateway: false);

        self::assertFalse($provider->has($payment));
        self::assertNull($provider->tryIdOf($payment));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('its payment method has no Quickpay gateway configuration');

        $provider->idOf($payment);
    }

    /**
     * @return iterable<string, array{PaymentInterface}>
     */
    public static function otherPayments(): iterable
    {
        yield 'another gateway\'s payment carrying an id' => [self::payment(['quickpayPaymentId' => 501])];

        yield 'another gateway\'s payment carrying an unreadable id' => [self::payment(['quickpayPaymentId' => 'abc'])];

        yield 'another gateway\'s payment without an id' => [self::payment([])];
    }

    /**
     * A provider whose gateway configuration module finds a Quickpay gateway configuration for every Sylius payment, or
     * none (no payment method, or another gateway's)
     */
    private function provider(bool $quickpayGateway): QuickpayPaymentIdProvider
    {
        $gatewayConfigurationProvider = $this->prophesize(GatewayConfigurationProviderInterface::class);
        $gatewayConfigurationProvider->forPayment(Argument::type(PaymentInterface::class))
            ->willReturn($quickpayGateway ? GatewayConfigurations::of() : null);

        return new QuickpayPaymentIdProvider($gatewayConfigurationProvider->reveal());
    }

    /**
     * @param array<string, mixed> $details
     */
    private static function payment(array $details): PaymentInterface
    {
        $payment = new Payment();
        $payment->setDetails($details);

        return $payment;
    }
}
