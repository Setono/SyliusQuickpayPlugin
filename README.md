# Quickpay Payment Plugin for Sylius

[![Latest Version][ico-version]][link-packagist]
[![Software License][ico-license]](LICENSE)
[![Build Status][ico-github-actions]][link-github-actions]
[![Code Coverage][ico-code-coverage]][link-code-coverage]

Adds [Quickpay](https://quickpay.net) as a payment gateway to your Sylius store. The plugin integrates the
[`setono/payum-quickpay`](https://github.com/Setono/payum-quickpay) Payum gateway into Sylius' checkout,
state machine, and admin.

## Requirements

* PHP 8.1 or higher
* Sylius 1.x on Symfony ^6.4 (tested against Sylius 1.14)
* A PSR-18 HTTP client and PSR-17 factories discoverable by `php-http/discovery`
  (e.g. `symfony/http-client` + `nyholm/psr7`)

## Installation

### 1. Install the plugin

The plugin builds on `setono/payum-quickpay` 2.x, which is still in pre-release, so your project
must allow the pre-release versions explicitly:

```bash
composer require setono/sylius-quickpay-plugin:^2.0@alpha setono/payum-quickpay:^2.0@alpha setono/quickpay-php-sdk:^1.0@beta
```

If your project does not already provide a PSR-18 client and PSR-17 factories:

```bash
composer require symfony/http-client nyholm/psr7
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

### 3. Configure the plugin (optional)

The state machine callback that captures, refunds, and cancels Quickpay payments when the corresponding
Sylius payment transitions are applied (see [How it works](#how-it-works)) is registered automatically.
Each operation can be turned off individually:

```yaml
# config/packages/setono_sylius_quickpay.yaml
setono_sylius_quickpay:
    operations:
        capture: true  # forward the payment's complete transition to Quickpay as a capture
        refund: true   # forward the refund transition to Quickpay
        cancel: true   # forward the cancel transition to Quickpay
```

**Note:** The callback is registered with `winzou_state_machine`, the default state machine adapter in Sylius 1.14.
If your application runs the `sylius_payment` graph on the `symfony_workflow` adapter, the callback will not fire.

### 4. Import the routes

```yaml
# config/routes/setono_sylius_quickpay.yaml
setono_sylius_quickpay:
    resource: "@SetonoSyliusQuickpayPlugin/Resources/config/routes.yaml"
```

This registers the callback endpoint (`POST /payment/quickpay/notify`) that Quickpay's servers use to notify your
store about payment state changes.

### 5. Import fixtures (optional, development only)

```yaml
# config/packages/setono_sylius_quickpay.yaml
imports:
    - { resource: "@SetonoSyliusQuickpayPlugin/Resources/config/app/fixtures.yaml" }
```

The fixtures create a Quickpay credit card payment method and matching channels. They read the gateway
credentials from these environment variables:

```dotenv
QUICKPAY_API_KEY=
QUICKPAY_PRIVATE_KEY=
QUICKPAY_AGREEMENT_ID=
QUICKPAY_ORDER_PREFIX=qp_
```

## Configuration

Create a new payment method of type **Quickpay** in the admin panel (*Configuration* → *Payment methods*) and fill
out the gateway configuration:

| Field | Description |
|---|---|
| Api key | The API key of the **API user** in your Quickpay manager (*Settings* → *Users*) |
| Private key | The private key of your merchant account (*Settings* → *Integration*) |
| Agreement id | *(optional)* The agreement id used for the payment window |
| Order prefix | Prepended to order numbers sent to Quickpay as the `order_id` — must be **unique per project and environment** sharing the same Quickpay account (see [Troubleshooting](#troubleshooting)), and 11 characters or less |
| Payment methods | Which payment methods the Quickpay payment window offers, e.g. `creditcard` or `mobilepay` — see the [Quickpay documentation](https://learn.quickpay.net/tech-talk/appendixes/payment-methods/#payment-methods) |
| Auto capture | Capture the payment automatically right after authorization — useful for digital products |
| Synchronized operations | Run capture, refund and cancel synchronously instead of relying on the Quickpay callback |
| Branding id | *(optional)* The payment window branding to use |

## How it works

* During checkout the customer is redirected to the Quickpay payment window through a payment link. The payment is
  **authorized**, not captured (unless *Auto capture* is enabled).
* Quickpay notifies your store of every payment change on the callback endpoint. The callback's
  `Quickpay-Checksum-SHA256` header is validated against your private key before the payment details are updated.
* When you **complete**, **refund**, or **cancel** a payment in the Sylius admin, the plugin performs the matching
  capture, refund, or cancel operation against Quickpay. A failed cancel at Quickpay (e.g. the customer never
  completed checkout, so there is nothing to cancel) is logged but does not block cancelling the order.
* A refund targets Quickpay's **balance** (what is still captured) rather than the original amount — so a payment
  that was partially refunded directly in the Quickpay manager refunds only the remainder instead of failing. The
  balance is also persisted into the payment details on every status check and callback. An explicit
  `refund_amount` / `capture_amount` in the payment details is passed through to Quickpay untouched for
  programmatic partial operations; note that Sylius' payment state machine still treats the payment as a whole —
  the `refund` transition can only be applied once.

## Testing

```bash
composer phpunit       # unit tests
composer analyse       # static analysis (PHPStan)
composer check-style   # coding standards
```

For manual testing, use the credit card numbers from the
[Quickpay test data](https://learn.quickpay.net/tech-talk/appendixes/test/#test-data).

## Upgrading from 1.x

Version 2.x of this plugin is built on `setono/payum-quickpay` 2.0 — see its
[upgrade guide](https://github.com/Setono/payum-quickpay/blob/2.x/docs/UPGRADE-2.0.md) for the
full background. What it means for a store using this plugin:

- **Payment details are scalar-only now.** Existing payments keep working: `quickpayPaymentId` is
  the source of truth and the payment is re-fetched from Quickpay when needed. The stored
  `quickpayPayment` object in old payment details is simply ignored.
- **The `merchant` gateway option is gone** (Quickpay authenticates with the API key alone) and
  `agreement` is optional. Existing gateway configurations keep working — unknown keys are
  ignored — but you can open each Quickpay payment method in the admin and click save to clean
  them up.
- **A partially refunded payment now stays `captured`** until the full amount is refunded,
  instead of flipping to `refunded` on the first partial refund.
- **Callbacks are HMAC-verified by the gateway library**; unsigned or tampered callbacks are
  rejected with a 400 response.
- **The routes file was renamed** from `Resources/config/routing.yaml` to `Resources/config/routes.yaml` —
  update the import in your `config/routes/setono_sylius_quickpay.yaml`.
- **The state machine callback is now registered automatically** and
  `Resources/config/app/config.yaml` no longer exists — remove its import from your
  `config/packages/setono_sylius_quickpay.yaml` (keeping it would break the container build).
- **The `disable_capture`/`disable_refund`/`disable_cancel` config keys were replaced** by
  positively-named flags nested under `operations` (`operations.capture: true` etc., all
  defaulting to enabled) — update your `setono_sylius_quickpay` configuration if you set them.
- **The credential config keys are now `api_key`/`private_key`** (previously `apikey`/`privatekey`).
  Stored gateway configurations keep working — the old spellings remain deprecated aliases in the
  gateway library, and the admin form migrates them to the new keys the next time you save the
  payment method.
- **The `QUICKPAY_ORDER_PREFIX` environment variable is no longer required.** Callbacks resolve
  the order against the *Order prefix* configured on each Quickpay payment method, so the env var
  only remains relevant if your fixtures or gateway configuration reference it.
- **`Exception\UnsupportedPaymentTransitionException` was removed.** The plugin has not thrown it
  since 2018, so catching it never had any effect.

## Troubleshooting

- `Not authorized: Not authorized to PUT /payments/:id/link`
  at `/payment/authorize/...` url:

  You should check at `https://manage.quickpay.net/account/{your merchant id}/settings/users`
  that `System users` > `API User` > `User permissions` > `Create or update payment link` have `PUT`
  checkbox checked. Also check `QUICKPAY_API_KEY` and `QUICKPAY_AGREEMENT_ID` is filled with `API User`'s
  api key and agreement id rather than `Payment Window`'s.

- `Validation error: order_id already exists on another payment`

  Make sure you changed the *Order prefix* of your Quickpay payment method to some unique string
  like `qp_<projectname>_<date>_` (when `date` should be updated to actual
  every time you recreate dev database) whenever you:

  - Recreating your database on dev environment and your order IDs become same as they was before
  - Use `SetonoSyliusQuickpayPlugin` at two different projects but with same Quickpay
    (developer) account credentials

- `Validation error: order_id must have length between 4 and 20`

  You should cut the *Order prefix* of your Quickpay payment method to 11 chars or less.

[ico-version]: https://poser.pugx.org/setono/sylius-quickpay-plugin/v/stable
[ico-license]: https://poser.pugx.org/setono/sylius-quickpay-plugin/license
[ico-github-actions]: https://github.com/Setono/SyliusQuickpayPlugin/actions/workflows/build.yaml/badge.svg?branch=2.x
[ico-code-coverage]: https://codecov.io/gh/Setono/SyliusQuickpayPlugin/branch/2.x/graph/badge.svg

[link-packagist]: https://packagist.org/packages/setono/sylius-quickpay-plugin
[link-github-actions]: https://github.com/Setono/SyliusQuickpayPlugin/actions
[link-code-coverage]: https://codecov.io/gh/Setono/SyliusQuickpayPlugin
