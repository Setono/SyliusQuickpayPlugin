<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Tests\Quickpay;

use Payum\Core\Model\GatewayConfigInterface;
use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;
use Setono\SyliusQuickpayPlugin\Quickpay\ApiKeyResolver;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;

final class ApiKeyResolverTest extends TestCase
{
    use ProphecyTrait;

    /**
     * @test
     *
     * @dataProvider gatewayConfigProvider
     *
     * @param array<array-key, mixed> $config
     */
    public function it_resolves_the_api_key_from_a_gateway_config(array $config, ?string $expected): void
    {
        self::assertSame($expected, ApiKeyResolver::fromGatewayConfig($config));
    }

    /**
     * @return iterable<string, array{array<array-key, mixed>, string|null}>
     */
    public static function gatewayConfigProvider(): iterable
    {
        yield 'the api_key option' => [['api_key' => 'the-api-key'], 'the-api-key'];

        yield 'the pre-2.0 apikey option' => [['apikey' => 'the-old-api-key'], 'the-old-api-key'];

        yield 'api_key wins over the pre-2.0 option' => [['api_key' => 'new', 'apikey' => 'old'], 'new'];

        yield 'no key stored' => [[], null];

        yield 'an empty key' => [['api_key' => ''], null];

        yield 'a non-string key' => [['api_key' => 123], null];
    }

    /**
     * @test
     */
    public function it_resolves_the_api_key_from_a_payment(): void
    {
        $config = $this->prophesize(GatewayConfigInterface::class);
        $config->getConfig()->willReturn(['api_key' => 'the-api-key']);

        $method = $this->prophesize(PaymentMethodInterface::class);
        $method->getGatewayConfig()->willReturn($config->reveal());

        $payment = $this->prophesize(PaymentInterface::class);
        $payment->getMethod()->willReturn($method->reveal());

        self::assertSame('the-api-key', ApiKeyResolver::fromPayment($payment->reveal()));
    }

    /**
     * @test
     */
    public function it_resolves_nothing_from_a_payment_without_a_method(): void
    {
        $payment = $this->prophesize(PaymentInterface::class);
        $payment->getMethod()->willReturn(null);

        self::assertNull(ApiKeyResolver::fromPayment($payment->reveal()));
    }

    /**
     * @test
     */
    public function it_resolves_nothing_from_a_payment_without_a_gateway_config(): void
    {
        $method = $this->prophesize(PaymentMethodInterface::class);
        $method->getGatewayConfig()->willReturn(null);

        $payment = $this->prophesize(PaymentInterface::class);
        $payment->getMethod()->willReturn($method->reveal());

        self::assertNull(ApiKeyResolver::fromPayment($payment->reveal()));
    }
}
