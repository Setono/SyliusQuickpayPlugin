<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\XmlFileLoader;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;

final class SetonoSyliusQuickpayExtension extends Extension implements PrependExtensionInterface
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        /** @var array{operations: array{capture: bool, refund: bool, cancel: bool}, checkout: array{payment_method_logos: array<string, string|null>, creditcard_brands: list<string>}} $config */
        $config = $this->processConfiguration($this->getConfiguration([], $container), $configs);
        $loader = new XmlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));

        $container->setParameter('setono_sylius_quickpay.operations.capture', $config['operations']['capture']);
        $container->setParameter('setono_sylius_quickpay.operations.refund', $config['operations']['refund']);
        $container->setParameter('setono_sylius_quickpay.operations.cancel', $config['operations']['cancel']);
        $container->setParameter('setono_sylius_quickpay.checkout.payment_method_logos', $config['checkout']['payment_method_logos']);
        $container->setParameter('setono_sylius_quickpay.checkout.creditcard_brands', $config['checkout']['creditcard_brands']);

        $loader->load('services.xml');
    }

    public function prepend(ContainerBuilder $container): void
    {
        if ($container->hasExtension('twig')) {
            $container->prependExtensionConfig('twig', [
                'form_themes' => ['@SetonoSyliusQuickpayPlugin/form/theme.html.twig'],
            ]);
        }

        if ($container->hasExtension('sylius_ui')) {
            $container->prependExtensionConfig('sylius_ui', [
                'events' => [
                    'sylius.admin.order.show.payment_content' => [
                        'blocks' => [
                            'setono_sylius_quickpay_operations' => [
                                'template' => '@SetonoSyliusQuickpayPlugin/admin/order/show/payment/_quickpay.html.twig',
                                'priority' => -10,
                            ],
                        ],
                    ],
                    'sylius.shop.checkout.select_payment.choice_item_content' => [
                        'blocks' => [
                            'setono_sylius_quickpay_payment_method_logos' => [
                                'template' => '@SetonoSyliusQuickpayPlugin/shop/checkout/select_payment/_payment_method_logos.html.twig',
                                'priority' => -10,
                            ],
                        ],
                    ],
                ],
            ]);
        }

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
                            'do' => ['@Setono\SyliusQuickpayPlugin\StateMachine\PaymentProcessor', '__invoke'],
                            'args' => ['object', 'event.getTransition()'],
                        ],
                    ],
                ],
            ],
        ]);
    }
}
