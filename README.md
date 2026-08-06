# QuickPay Payment Plugin for Sylius

[![Latest Version][ico-version]][link-packagist]
[![Software License][ico-license]](LICENSE)
[![Build Status][ico-github-actions]][link-github-actions]
[![Code Coverage][ico-code-coverage]][link-code-coverage]

Adds [QuickPay](https://quickpay.net) as a payment gateway to your Sylius store, including credit card and Klarna
payments. The plugin integrates the [`setono/payum-quickpay`](https://github.com/Setono/payum-quickpay) Payum gateway
into Sylius' checkout, state machine, and admin.

## Requirements

* PHP 8.1 or higher
* Sylius 1.14 on Symfony 6.4

## Installation

### 1. Install the plugin

```bash
composer require setono/sylius-quickpay-plugin
```

### 2. Register the bundle

```php
<?php
# config/bundles.php

return [
    // ...
    Setono\SyliusQuickpayPlugin\SetonoSyliusQuickpayPlugin::class => ['all' => true],
];
```

### 3. Import the plugin configuration

```yaml
# config/packages/setono_sylius_quickpay.yaml
imports:
    - { resource: "@SetonoSyliusQuickpayPlugin/Resources/config/app/config.yaml" }
```

This registers the state machine callback that captures, refunds, and cancels QuickPay payments when the
corresponding Sylius payment transitions are applied (see [How it works](#how-it-works)). Each operation can be
turned off individually:

```yaml
# config/packages/setono_sylius_quickpay.yaml
setono_sylius_quickpay:
    disable_capture: false
    disable_refund: false
    disable_cancel: false
```

**Note:** The callback is registered with `winzou_state_machine`, the default state machine adapter in Sylius 1.14.
If your application runs the `sylius_payment` graph on the `symfony_workflow` adapter, the callback will not fire.

### 4. Import the routes

```yaml
# config/routes/setono_sylius_quickpay.yaml
setono_sylius_quickpay:
    resource: "@SetonoSyliusQuickpayPlugin/Resources/config/routing.yaml"
```

This registers the callback endpoint (`POST /payment/quickpay/notify`) that QuickPay's servers use to notify your
store about payment state changes.

### 5. Set the order prefix environment variable

```dotenv
# .env
QUICKPAY_ORDER_PREFIX=qp_
```

The prefix is prepended to your order numbers before they are sent to QuickPay as the `order_id`. It **must** be
defined for the container to compile, and it must be **unique per project and environment** sharing the same
QuickPay account — see [Troubleshooting](#troubleshooting). Keep it at 11 characters or less.

### 6. Add the validator constraint (optional, Klarna only)

Klarna requires structured street addresses. In Germany and the Netherlands the plugin splits a one-line street
into street and house number, and this constraint prevents customers from entering an address that cannot be split.

```xml
<?xml version="1.0" encoding="UTF-8"?>
<!-- config/validator/Address.xml -->
<constraint-mapping xmlns="http://symfony.com/schema/dic/constraint-mapping"
                    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
                    xsi:schemaLocation="http://symfony.com/schema/dic/constraint-mapping http://symfony.com/schema/dic/services/constraint-mapping-1.0.xsd">
    <class name="Sylius\Component\Addressing\Model\Address">
        <constraint name="Setono\SyliusQuickpayPlugin\Validator\Constraints\AddressStreetEligibility">
            <option name="message">setono_sylius_quickpay.address.street_eligibility</option>
            <option name="groups">
                <value>sylius_shipping_address_update</value>
                <value>sylius_checkout_complete</value>
                <value>sylius</value>
            </option>
        </constraint>
    </class>
</constraint-mapping>
```

See the [test application](tests/Application/config/validator/Address.xml) for a working example.

### 7. Import fixtures (optional, development only)

```yaml
# config/packages/setono_sylius_quickpay.yaml
imports:
    - { resource: "@SetonoSyliusQuickpayPlugin/Resources/config/app/fixtures.yaml" }
```

The fixtures create QuickPay credit card and Klarna payment methods, matching channels, and a test customer. They
read the gateway credentials from these environment variables:

```dotenv
QUICKPAY_API_KEY=
QUICKPAY_PRIVATE_KEY=
QUICKPAY_MERCHANT_ID=
QUICKPAY_AGREEMENT_ID=
```

## Configuration

Create a new payment method of type **QuickPay** in the admin panel (*Configuration* → *Payment methods*) and fill
out the gateway configuration:

| Field | Description |
|---|---|
| Api key | The API key of the **API user** in your QuickPay manager (*Settings* → *Users*) |
| Private key | The private key of your merchant account (*Settings* → *Integration*) |
| Merchant id | Your QuickPay merchant id |
| Agreement id | The agreement id of the **API user** |
| Order prefix | Prepended to order numbers sent to QuickPay — keep in sync with `QUICKPAY_ORDER_PREFIX` |
| Payment methods | Which payment methods the QuickPay payment window offers, e.g. `creditcard` or `klarna-payments` — see the [QuickPay documentation](https://learn.quickpay.net/tech-talk/appendixes/payment-methods/#payment-methods) |
| Auto capture | Capture the payment automatically right after authorization — useful for digital products |

## How it works

* During checkout the customer is redirected to the QuickPay payment window through a payment link. The payment is
  **authorized**, not captured (unless *Auto capture* is enabled).
* QuickPay notifies your store of every payment change on the callback endpoint. The callback's
  `QuickPay-Checksum-SHA256` header is validated against your private key before the payment details are updated.
* When you **complete**, **refund**, or **cancel** a payment in the Sylius admin, the plugin performs the matching
  capture, refund, or cancel operation against QuickPay. A failed cancel at QuickPay (e.g. the customer never
  completed checkout, so there is nothing to cancel) is logged but does not block cancelling the order.

## Testing

```bash
composer phpunit       # unit tests
composer analyse       # static analysis (PHPStan)
composer check-style   # coding standards
```

For manual testing, use the credit card numbers from the
[QuickPay test data](https://learn.quickpay.net/tech-talk/appendixes/test/#test-data).

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

[ico-version]: https://poser.pugx.org/setono/sylius-quickpay-plugin/v/stable
[ico-license]: https://poser.pugx.org/setono/sylius-quickpay-plugin/license
[ico-github-actions]: https://github.com/Setono/SyliusQuickpayPlugin/actions/workflows/build.yaml/badge.svg?branch=1.x
[ico-code-coverage]: https://codecov.io/gh/Setono/SyliusQuickpayPlugin/branch/1.x/graph/badge.svg

[link-packagist]: https://packagist.org/packages/setono/sylius-quickpay-plugin
[link-github-actions]: https://github.com/Setono/SyliusQuickpayPlugin/actions
[link-code-coverage]: https://codecov.io/gh/Setono/SyliusQuickpayPlugin
