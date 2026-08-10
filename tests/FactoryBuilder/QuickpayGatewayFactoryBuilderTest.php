<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Tests\FactoryBuilder;

use Payum\Core\GatewayFactoryInterface;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Setono\Payum\Quickpay\QuickpayGatewayFactory;
use Setono\SyliusQuickpayPlugin\FactoryBuilder\QuickpayGatewayFactoryBuilder;
use Setono\SyliusQuickpayPlugin\Guesser\LanguageGuesserInterface;

final class QuickpayGatewayFactoryBuilderTest extends TestCase
{
    use ProphecyTrait;

    /**
     * @test
     */
    public function it_builds_the_gateway_factory_with_the_guessed_language(): void
    {
        $languageGuesser = $this->prophesize(LanguageGuesserInterface::class);
        $languageGuesser->guess()->shouldBeCalledOnce()->willReturn('da');

        $coreGatewayFactory = $this->prophesize(GatewayFactoryInterface::class);
        $coreGatewayFactory->createConfig(Argument::cetera())->willReturn([]);

        $builder = new QuickpayGatewayFactoryBuilder(QuickpayGatewayFactory::class, $languageGuesser->reveal());

        $factory = $builder->build([], $coreGatewayFactory->reveal());

        self::assertInstanceOf(QuickpayGatewayFactory::class, $factory);

        $config = $factory->createConfig();
        self::assertSame('da', $config['language'] ?? null);
    }
}
