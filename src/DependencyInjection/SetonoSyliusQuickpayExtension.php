<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\XmlFileLoader;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;

class SetonoSyliusQuickpayExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $loader = new XmlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.xml');

        $configuration = $this->getConfiguration($configs, $container);
        $config = $this->processConfiguration($configuration, $configs);

        if ($this->isConfigEnabled($container, $config['sylius_refund_plugin'])) {
            $gateways = $container->getParameter('sylius_refund.supported_gateways');
            $gateways[] = 'quickpay';
            $container->setParameter('sylius_refund.supported_gateways', $gateways);

            $loader->load('sylius_refund_plugin.xml');
        }
    }
}
