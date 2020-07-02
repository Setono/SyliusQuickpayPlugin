<?php

declare(strict_types=1);

namespace Setono\SyliusQuickpayPlugin\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('setono_quickpay_plugin');
        $rootNode = $treeBuilder->getRootNode();

        $rootNode
            ->children()
                ->arrayNode('sylius_refund_plugin')
                    ->{class_exists('Sylius\RefundPlugin\SyliusRefundPlugin') ? 'canBeDisabled' : 'canBeEnabled'}()
                ->end()
            ->end()
        ;

        return $treeBuilder;
    }
}

