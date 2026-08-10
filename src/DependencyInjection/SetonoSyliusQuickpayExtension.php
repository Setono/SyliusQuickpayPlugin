<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\XmlFileLoader;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;

class SetonoSyliusQuickpayExtension extends Extension implements PrependExtensionInterface
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        /** @var array{operations: array{capture: bool, refund: bool, cancel: bool}} $config */
        $config = $this->processConfiguration($this->getConfiguration([], $container), $configs);
        $loader = new XmlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));

        $container->setParameter('setono_sylius_quickpay.operations.capture', $config['operations']['capture']);
        $container->setParameter('setono_sylius_quickpay.operations.refund', $config['operations']['refund']);
        $container->setParameter('setono_sylius_quickpay.operations.cancel', $config['operations']['cancel']);

        $loader->load('services.xml');
    }

    public function prepend(ContainerBuilder $container): void
    {
        // The guard keeps the plugin bootable in applications running the sylius_payment graph on
        // the symfony_workflow adapter, where the winzou bundle is not necessarily registered
        if (!$container->hasExtension('winzou_state_machine')) {
            return;
        }

        $container->prependExtensionConfig('winzou_state_machine', [
            'sylius_payment' => [
                'callbacks' => [
                    'before' => [
                        'setono_quickpay_resolve_state' => [
                            'on' => ['complete', 'refund', 'cancel'],
                            'do' => ['@setono_sylius_quickpay.state_machine.payment_processor', '__invoke'],
                            'args' => ['object', 'event.getTransition()'],
                        ],
                    ],
                ],
            ],
        ]);
    }
}
