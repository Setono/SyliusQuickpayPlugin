<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('setono_sylius_quickpay');

        $rootNode = $treeBuilder->getRootNode();

        $rootNode
            ->addDefaultsIfNotSet()
            ->children()
                ->arrayNode('operations')
                    ->info('Which payment operations the state machine forwards to Quickpay when the corresponding Sylius payment transition is applied')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('capture')
                            ->info("Forward the payment's complete transition to Quickpay as a capture")
                            ->defaultTrue()
                        ->end()
                        ->booleanNode('refund')
                            ->info('Forward the refund transition to Quickpay')
                            ->defaultTrue()
                        ->end()
                        ->booleanNode('cancel')
                            ->info('Forward the cancel transition to Quickpay')
                            ->defaultTrue()
                        ->end()
                    ->end()
                ->end()
        ;

        return $treeBuilder;
    }
}
