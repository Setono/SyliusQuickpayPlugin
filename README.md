# Quickpay Payment plugin for Sylius

This plugin adds Quickpay as a payment option to Sylius.

## Installation

``composer require setono/sylius-quickpay-plugin``

Make sure the plugin is added to `bundles.php`

``Setono\SyliusQuickpayPlugin\SetonoSyliusQuickpayPlugin::class => ['all' => true],``

Import the config file

``- { resource: "@SetonoSyliusQuickpayPlugin/Resources/config/config.yaml" }``

## Configuration

Create a new Payment method of the type *Quickpay* and fill out the required form fields.
