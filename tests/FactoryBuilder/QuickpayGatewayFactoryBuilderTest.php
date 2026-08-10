<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Tests\FactoryBuilder;

use Payum\Core\GatewayFactoryInterface;
use PHPUnit\Framework\TestCase;
use Setono\Payum\Quickpay\QuickpayGatewayFactory;
use Setono\SyliusQuickpayPlugin\FactoryBuilder\QuickpayGatewayFactoryBuilder;
use Setono\SyliusQuickpayPlugin\Guesser\QuickpayLanguageGuesserInterface;

final class QuickpayGatewayFactoryBuilderTest extends TestCase
{
    /**
     * @test
     */
    public function it_builds_the_gateway_factory_with_the_guessed_language(): void
    {
        $languageGuesser = $this->createMock(QuickpayLanguageGuesserInterface::class);
        $languageGuesser->expects(self::once())->method('guess')->willReturn('da');

        $coreGatewayFactory = $this->createMock(GatewayFactoryInterface::class);
        $coreGatewayFactory->method('createConfig')->willReturn([]);

        $builder = new QuickpayGatewayFactoryBuilder(QuickpayGatewayFactory::class, $languageGuesser);

        $factory = $builder->build([], $coreGatewayFactory);

        self::assertInstanceOf(QuickpayGatewayFactory::class, $factory);

        $config = $factory->createConfig();
        self::assertSame('da', $config['language'] ?? null);
    }
}
