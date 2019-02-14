[![Latest Version on Packagist][ico-version]][link-packagist]
[![Software License][ico-license]](LICENSE)
[![Build Status][ico-travis]][link-travis]
[![Quality Score][ico-code-quality]][link-code-quality]

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

[ico-version]: https://img.shields.io/packagist/v/setono/sylius-quickpay-plugin.svg?style=flat-square
[ico-license]: https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square
[ico-travis]: https://travis-ci.com/Setono/SyliusQuickpayPlugin.svg?branch=master
[ico-code-quality]: https://img.shields.io/scrutinizer/g/Setono/SyliusQuickpayPlugin.svg?style=flat-square

[link-packagist]: https://packagist.org/packages/setono/sylius-quickpay-plugin
[link-travis]: https://travis-ci.com/Setono/SyliusQuickpayPlugin
[link-code-quality]: https://scrutinizer-ci.com/g/Setono/SyliusQuickpayPlugin
