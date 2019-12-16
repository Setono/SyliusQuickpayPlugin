<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\FactoryBuilder;

use Payum\Core\Bridge\Symfony\Builder\GatewayFactoryBuilder;
use Payum\Core\GatewayFactoryInterface;
use Setono\SyliusQuickpayPlugin\Guesser\QuickpayLanguageGuesserInterface;

class QuickpayGatewayFactoryBuilder extends GatewayFactoryBuilder
{
    /** @var QuickpayLanguageGuesserInterface */
    protected $languageGuesser;

    /**
     * @param string $gatewayFactoryClass
     */
    public function __construct(
        $gatewayFactoryClass,
        QuickpayLanguageGuesserInterface $languageGuesser
    ) {
        parent::__construct($gatewayFactoryClass);

        $this->languageGuesser = $languageGuesser;
    }

    public function build(array $defaultConfig, GatewayFactoryInterface $coreGatewayFactory)
    {
        $defaultConfig['language'] = $this->languageGuesser->guess();

        return parent::build($defaultConfig, $coreGatewayFactory);
    }
}
