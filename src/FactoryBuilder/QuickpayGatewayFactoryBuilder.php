<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\FactoryBuilder;

use Payum\Core\Bridge\Symfony\Builder\GatewayFactoryBuilder;
use Payum\Core\GatewayFactoryInterface;
use Setono\SyliusQuickpayPlugin\Guesser\LanguageGuesserInterface;

final class QuickpayGatewayFactoryBuilder extends GatewayFactoryBuilder
{
    public function __construct(string $gatewayFactoryClass, private readonly LanguageGuesserInterface $languageGuesser)
    {
        parent::__construct($gatewayFactoryClass);
    }

    /**
     * @param array<mixed> $defaultConfig
     */
    public function build(array $defaultConfig, GatewayFactoryInterface $coreGatewayFactory): GatewayFactoryInterface
    {
        $defaultConfig['language'] = $this->languageGuesser->guess();

        return parent::build($defaultConfig, $coreGatewayFactory);
    }
}
