<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\FactoryBuilder;

use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Bridge\Symfony\Builder\GatewayFactoryBuilder;
use Payum\Core\GatewayFactoryInterface;
use Setono\Quickpay\Client\ClientInterface;
use Setono\SyliusQuickpayPlugin\GatewayConfiguration\GatewayConfiguration;
use Setono\SyliusQuickpayPlugin\Guesser\LanguageGuesserInterface;
use Setono\SyliusQuickpayPlugin\Quickpay\QuickpayClientProviderInterface;

final class QuickpayGatewayFactoryBuilder extends GatewayFactoryBuilder
{
    public function __construct(
        string $gatewayFactoryClass,
        private readonly LanguageGuesserInterface $languageGuesser,
        private readonly QuickpayClientProviderInterface $clientProvider,
    ) {
        parent::__construct($gatewayFactoryClass);
    }

    /**
     * @param array<mixed> $defaultConfig
     */
    public function build(array $defaultConfig, GatewayFactoryInterface $coreGatewayFactory): GatewayFactoryInterface
    {
        $defaultConfig['language'] = $this->languageGuesser->guess();

        // Every gateway gets its SDK client from the client provider, through payum-quickpay's `quickpay.client`
        // gateway option, so its requests follow the plugin's one HTTP policy. Payum resolves a closure option with
        // the gateway's own config, before payum-quickpay builds its Api from the client (the builder's defaults come
        // first), so the client carries the gateway's api key and `synchronized`. payum-quickpay still checks that
        // the client agrees with `synchronized`.
        $clientProvider = $this->clientProvider;
        $defaultConfig['quickpay.client'] = static fn (ArrayObject $config): ClientInterface => $clientProvider->forGatewayConfiguration(
            GatewayConfiguration::fromConfig($config->getArrayCopy()),
        );

        return parent::build($defaultConfig, $coreGatewayFactory);
    }
}
