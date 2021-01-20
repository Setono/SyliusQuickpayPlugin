<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\FactoryBuilder;

use Payum\Core\Bridge\Symfony\Builder\GatewayFactoryBuilder;
use Payum\Core\GatewayFactoryInterface;
use Setono\SyliusQuickpayPlugin\Guesser\QuickpayLanguageGuesserInterface;

class QuickpayGatewayFactoryBuilder extends GatewayFactoryBuilder
{
    protected QuickpayLanguageGuesserInterface $languageGuesser;

    public function __construct(string $gatewayFactoryClass, QuickpayLanguageGuesserInterface $languageGuesser)
    {
        parent::__construct($gatewayFactoryClass);

        $this->languageGuesser = $languageGuesser;
    }

    public function build(array $defaultConfig, GatewayFactoryInterface $coreGatewayFactory): GatewayFactoryInterface
    {
        $defaultConfig['language'] = $this->languageGuesser->guess();

        return parent::build($defaultConfig, $coreGatewayFactory);
    }
}
