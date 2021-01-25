<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\XmlFileLoader;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;

class SetonoSyliusQuickpayExtension extends Extension
{
    public function load(array $config, ContainerBuilder $container): void
    {
        $config = $this->processConfiguration($this->getConfiguration([], $container), $config);
        $loader = new XmlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));

        $container->setParameter('setono_sylius_quickpay.disable_capture', $config['disable_capture']);
        $container->setParameter('setono_sylius_quickpay.disable_refund', $config['disable_refund']);
        $container->setParameter('setono_sylius_quickpay.disable_cancel', $config['disable_cancel']);

        $loader->load('services.xml');
    }
}
