<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\Tests\Unit\FactoryBuilder;

use Payum\Core\CoreGatewayFactory;
use Payum\Core\Exception\LogicException;
use Payum\Core\GatewayFactoryInterface;
use Payum\Core\GatewayInterface;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Setono\Payum\Quickpay\QuickpayGatewayFactory;
use Setono\Quickpay\Client\Client;
use Setono\SyliusQuickpayPlugin\FactoryBuilder\QuickpayGatewayFactoryBuilder;
use Setono\SyliusQuickpayPlugin\GatewayConfiguration\GatewayConfiguration;
use Setono\SyliusQuickpayPlugin\Guesser\LanguageGuesserInterface;
use Setono\SyliusQuickpayPlugin\Quickpay\QuickpayClientProviderInterface;
use Setono\SyliusQuickpayPlugin\Tests\GatewayConfiguration\GatewayConfigurations;

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

        $builder = new QuickpayGatewayFactoryBuilder(QuickpayGatewayFactory::class, $languageGuesser->reveal(), $this->prophesize(QuickpayClientProviderInterface::class)->reveal());

        $factory = $builder->build([], $coreGatewayFactory->reveal());

        self::assertInstanceOf(QuickpayGatewayFactory::class, $factory);

        $config = $factory->createConfig();
        self::assertSame('da', $config['language'] ?? null);
    }

    /**
     * @test
     */
    public function each_gateway_gets_its_client_from_the_client_provider_built_from_its_own_gateway_configuration(): void
    {
        $clientProvider = $this->prophesize(QuickpayClientProviderInterface::class);
        $clientProvider
            ->forGatewayConfiguration(Argument::that(static fn (GatewayConfiguration $configuration): bool => 'the-dk-api-key' === $configuration->options()->apiKey))
            ->shouldBeCalledOnce()
            ->willReturn(new Client('the-dk-api-key'))
        ;

        $gateway = $this->buildFactory($clientProvider->reveal())->create([...GatewayConfigurations::USABLE, 'api_key' => 'the-dk-api-key']);

        self::assertInstanceOf(GatewayInterface::class, $gateway);
    }

    /**
     * payum-quickpay checks that the client it is given agrees with the gateway's `synchronized` gateway option, and the
     * client the provider builds is given to it
     *
     * @test
     */
    public function payum_quickpay_refuses_a_client_disagreeing_with_the_gateways_synchronized_gateway_option(): void
    {
        $clientProvider = $this->prophesize(QuickpayClientProviderInterface::class);
        $clientProvider->forGatewayConfiguration(Argument::any())->willReturn(new Client('the-api-key', synchronized: false));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The injected "quickpay.client" is not synchronized, but the gateway is configured with "synchronized" = true');

        $this->buildFactory($clientProvider->reveal())->create([...GatewayConfigurations::USABLE, 'synchronized' => true]);
    }

    private function buildFactory(QuickpayClientProviderInterface $clientProvider): GatewayFactoryInterface
    {
        $languageGuesser = $this->prophesize(LanguageGuesserInterface::class);
        $languageGuesser->guess()->willReturn('da');

        return (new QuickpayGatewayFactoryBuilder(QuickpayGatewayFactory::class, $languageGuesser->reveal(), $clientProvider))
            ->build([], new CoreGatewayFactory())
        ;
    }
}
