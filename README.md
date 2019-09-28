[![Latest Version on Packagist][ico-version]][link-packagist]
[![Software License][ico-license]](LICENSE)
[![Build Status][ico-travis]][link-travis]
[![Quality Score][ico-code-quality]][link-code-quality]

# Quickpay Payment plugin for Sylius

This plugin adds Quickpay as a payment option to Sylius.

## Installation

### 1. Install plugin
 
```bash
$ composer require setono/sylius-quickpay-plugin
```

### 2. Make sure the plugin is added to `bundles.php`:

```php
# config/bundles.php
Setono\SyliusQuickpayPlugin\SetonoSyliusQuickpayPlugin::class => ['all' => true],
```

### 3. Import the config file

```yaml
# config/packages/_sylius.yaml
imports:
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

## Troubleshooting

- `Validation error: Transaction in wrong state for this operation` after upgrading to Sylius v1.6

  After this [commit](https://github.com/Sylius/Sylius/commit/6c748c9aec878687c610bd440aac9635143df0c3#diff-063b340e70ed54a7454a9c76bd3ef84eR158),
  `use_authorize` config option should be strictly `boolean` typed. Update your `payment_method` fixtures like done
  at this [commit](https://github.com/Setono/SyliusQuickpayPlugin/commit/a23a9d8552ed4dda528a810ed2c7e062106cf470).
  
  At live app - open each quickpay payment method at admin and click save so hidden `use_authorize` form field
  will be stored in database in new format.

- `Not authorized: Not authorized to PUT /payments/:id/link`
  at `/payment/authorize/...` url:
  
  You should check at `https://manage.quickpay.net/account/{QUICKPAY_MERCHANT_ID}/settings/users`
  that `System users` > `API User` > `User permissions` > `Create or update payment link` have `PUT`
  checkbox checked. Also check `QUICKPAY_API_KEY` and `QUICKPAY_AGREEMENT_ID` is filled with `API User`'s 
  api key and agreement id rather than `Payment Window`'s.

- `Validation error: order_id already exists on another payment`
  
  Make sure you changed your `QUICKPAY_ORDER_PREFIX` at `.env.*` to some unique string
  like `qp_<projectname>_<date>_` (when `date` should be updated to actual 
  every time you recreate dev database) whenever you:
  
  - Recreating your database on dev environment and your order IDs become same as they was before
  - Use `SetonoSyliusQuickpayPlugin` at two different projects but with same QuickPay 
    (developer) account credentials

- `Validation error: order_id must have length between 4 and 20`

  You should cut your `QUICKPAY_ORDER_PREFIX` to 11 chars or less.

[ico-version]: https://img.shields.io/packagist/v/setono/sylius-quickpay-plugin.svg?style=flat-square
[ico-license]: https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square
[ico-travis]: https://travis-ci.com/Setono/SyliusQuickpayPlugin.svg?branch=master
[ico-code-quality]: https://img.shields.io/scrutinizer/g/Setono/SyliusQuickpayPlugin.svg?style=flat-square

[link-packagist]: https://packagist.org/packages/setono/sylius-quickpay-plugin
[link-travis]: https://travis-ci.com/Setono/SyliusQuickpayPlugin
[link-code-quality]: https://scrutinizer-ci.com/g/Setono/SyliusQuickpayPlugin
