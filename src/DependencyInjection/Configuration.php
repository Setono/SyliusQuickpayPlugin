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
                            ->info("Forward the payment's complete transition to Quickpay as a capture (only relevant to payment methods in the authorize capture mode; a payment captured at checkout is left alone)")
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
                ->arrayNode('checkout')
                    ->info('Presentation of Quickpay payment methods on the checkout payment step')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('payment_method_logos')
                            ->info('Add or override the logo shown for a Quickpay payment method token (e.g. "mobilepay"): the value is an asset path or URL for the image, or null to hide the token. Bundled logos exist for the common tokens; anything else renders as a text label')
                            ->useAttributeAsKey('token')
                            ->normalizeKeys(false)
                            ->scalarPrototype()->end()
                        ->end()
                        ->arrayNode('creditcard_brands')
                            ->info('Which card brands the "creditcard" token (every card enabled on the Quickpay agreement) shows on the checkout')
                            ->scalarPrototype()->end()
                            ->defaultValue(['visa', 'mastercard'])
                        ->end()
                    ->end()
                ->end()
        ;

        return $treeBuilder;
    }
}
