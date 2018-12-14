# Quickpay Payment plugin for Sylius

This plugin adds Quickpay as a payment option to Sylius.

## Installation

``composer require setono/sylius-quickpay-plugin``

Make sure the plugin is added to `bundles.php`
``Setono\SyliusQuickpayPlugin\SetonoSyliusQuickpayPlugin::class => ['all' => true],``

## Configuration

Create a new Payment method
