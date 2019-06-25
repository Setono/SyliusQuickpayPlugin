[![Latest Version on Packagist][ico-version]][link-packagist]
[![Software License][ico-license]](LICENSE)
[![Build Status][ico-travis]][link-travis]
[![Quality Score][ico-code-quality]][link-code-quality]

# Quickpay Payment plugin for Sylius

This plugin adds Quickpay as a payment option to Sylius.

## Installation

### 1. Install plugin
 
    ```bash
    composer require setono/sylius-quickpay-plugin
    ```

### 2. Make sure the plugin is added to `bundles.php`:

    ```php
    # config/bundles.php
    Setono\SyliusQuickpayPlugin\SetonoSyliusQuickpayPlugin::class => ['all' => true],
    ```

### 3. Import the config file

    ```yaml
    # config/packages/_sylius.yaml
    - { resource: "@SetonoSyliusQuickpayPlugin/Resources/config/app/config.yaml" }
    ```

### 4. (Optional) Import fixtures to play in your app

````yaml
# config/packages/_sylius.yaml
imports:
    - { resource: "@SetonoSyliusQuickpayPlugin/Resources/config/app/fixtures.yaml" }    
````

## Configuration

Create a new Payment method of the type *Quickpay* and fill out the required form fields.

## Testing

### Automated tests

Run `composer tests`

### Manual testing

- Use credit card numbers from https://learn.quickpay.net/tech-talk/appendixes/test/#test-data

[ico-version]: https://img.shields.io/packagist/v/setono/sylius-quickpay-plugin.svg?style=flat-square
[ico-license]: https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square
[ico-travis]: https://travis-ci.com/Setono/SyliusQuickpayPlugin.svg?branch=master
[ico-code-quality]: https://img.shields.io/scrutinizer/g/Setono/SyliusQuickpayPlugin.svg?style=flat-square

[link-packagist]: https://packagist.org/packages/setono/sylius-quickpay-plugin
[link-travis]: https://travis-ci.com/Setono/SyliusQuickpayPlugin
[link-code-quality]: https://scrutinizer-ci.com/g/Setono/SyliusQuickpayPlugin
