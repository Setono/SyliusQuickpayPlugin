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
* PSR-17 factories discoverable by `php-http/discovery` (e.g. `nyholm/psr7`) — the plugin itself
  brings `symfony/http-client` as its PSR-18 client

## Installation

### 1. Install the plugin

The plugin builds on `setono/payum-quickpay` 2.x, which is still in pre-release, so your project
must allow the pre-release versions explicitly:

```bash
composer require setono/sylius-quickpay-plugin:^2.0@RC setono/payum-quickpay:^2.0@RC setono/quickpay-php-sdk:^1.2
```

If your project does not already provide PSR-17 factories:

```bash
composer require nyholm/psr7
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

The plugin hooks into **both state machine adapters** supported by Sylius 1.14 — a `winzou_state_machine`
callback (the default adapter) and a Symfony workflow event subscriber — so the operations are forwarded to
Quickpay no matter which adapter your application runs the `sylius_payment` graph on.

### 4. Import the routes

```yaml
# config/routes/setono_sylius_quickpay.yaml
setono_sylius_quickpay:
    resource: "@SetonoSyliusQuickpayPlugin/Resources/config/routes.yaml"
```

This registers the callback endpoint (`POST /payment/quickpay/notify`) that receives the callbacks of operations
made *outside* your store — in the Quickpay manager, or by anything else talking to the API. Everything the store
itself does is confirmed without configuration; see [Callbacks](#callbacks).

### 5. Install the assets

```bash
bin/console assets:install
```

The plugin ships the payment method logos shown on the checkout (see
[Payment method logos on the checkout](#payment-method-logos-on-the-checkout)); like every bundle asset they are
published to `public/bundles/setonosyliusquickpayplugin/` by `assets:install`.

### 6. Import fixtures (optional, development only)

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
| Capture mode | When the money is taken. **On completion** (default) only authorizes the payment at checkout and captures it when the payment is completed in Sylius (e.g. when the order is shipped) — what shops that may not capture before dispatch need. **Immediately** lets Quickpay capture the moment the card is authorized — useful for digital products |
| Synchronized operations | Run capture, refund and cancel synchronously instead of relying on the Quickpay callback |
| Branding id | *(optional)* The payment window branding to use |

When you save the payment method, the plugin verifies the API key against Quickpay's API (a lightweight
ping) and rejects the form if Quickpay rejects the key — a typo'd key is caught immediately instead of by
the first customer whose checkout fails. If Quickpay cannot be reached, the check is skipped so an outage
never blocks saving.

## How it works

* During checkout the customer is redirected to the Quickpay payment window through a payment link. What happens to
  the money is the payment method's **capture mode**: *on completion* (the default) only **authorizes** the payment
  and the plugin captures it when the payment is completed in Sylius; *immediately* has Quickpay **capture** the
  moment the card is authorized. Under the hood the mode is Sylius core's `use_authorize` gateway option — the
  plugin runs Payum's `Authorize` or `Capture` accordingly, which is how the gateway library expresses the two
  flows since 2.0.
* Quickpay notifies your store of payment changes through callbacks on a **per-payment url** the gateway mints — for
  the payment window's outcome *and* for every capture, refund and cancel the store issues (the gateway names that
  url on each operation). Only operations made outside the store need the account-wide callback url (see
  [Callbacks](#callbacks)). Every callback's `Quickpay-Checksum-SHA256` header is validated against your private key
  before the payment details are updated.
* When you **complete**, **refund**, or **cancel** a payment in the Sylius admin, the plugin performs the matching
  capture, refund, or cancel operation against Quickpay. A failed cancel at Quickpay (e.g. the customer never
  completed checkout, so there is nothing to cancel) is logged but does not block cancelling the order.
* A refund targets Quickpay's **balance** (what is still captured) rather than the original amount — so a payment
  that was partially refunded directly in the Quickpay manager refunds only the remainder instead of failing. The
  balance is also persisted into the payment details on every status check and callback. An explicit
  `refund_amount` / `capture_amount` in the payment details is passed through to Quickpay untouched for
  programmatic partial operations; note that Sylius' payment state machine still treats the payment as a whole —
  the `refund` transition can only be applied once.

## Payment method logos on the checkout

On the checkout payment step, each Quickpay payment method shows the brands its payment window will offer — derived
from the gateway configuration's **Payment methods** field, so nothing is configured twice and no API is called.
`creditcard, mobilepay` renders the Visa and Mastercard marks (what `creditcard` stands for is configurable) and the
MobilePay mark; a token without a bundled logo (e.g. `resurs`) renders as a small text label. Quickpay's token
grammar is understood: exclusions (`!diners`) are skipped, forced 3-D Secure (`3d-creditcard`) is ignored, and
regional/debit variants (`visa-dk`, `mastercard-debet-dk`, `mobilepay-subscriptions`) collapse onto their brand.

Bundled logos cover cards (Visa, Visa Electron, Mastercard, Maestro, American Express, Diners Club, Discover, JCB,
UnionPay, Dankort, Forbrugsforeningen) and MobilePay, Apple Pay, Google Pay, Klarna, Anyday, Vipps, Swish, PayPal,
ViaBill, Trustly, iDEAL, Sofort and paysafecard. They come from Shopify's MIT-licensed
[payment_icons](https://github.com/activemerchant/payment_icons); the marks remain their owners' trademarks and are
shown only to indicate acceptance.

To use your own images, add or override a token, or hide one, configure the plugin — the value is an asset path (or
URL) as `asset()` understands it, or `null` to hide the token:

```yaml
setono_sylius_quickpay:
    checkout:
        payment_method_logos:
            mobilepay: build/images/mobilepay.svg
            resurs: https://cdn.example.com/resurs.png
            apple-pay: ~
        creditcard_brands: [dankort, visa, mastercard]   # what the `creditcard` token shows; default [visa, mastercard]
```

The markup lives in `@SetonoSyliusQuickpayPlugin/shop/checkout/select_payment/_payment_method_logos.html.twig` (a
`sylius_ui` block on `sylius.shop.checkout.select_payment.choice_item_content`) and can be overridden like any bundle
template.

## Payment link in the admin

Every Quickpay payment that is still **awaiting payment** shows a **Payment link** panel on the admin order view: a
copy-able link, and a button that emails it to the customer. Use it to collect payment for a phone or invoice order,
or after a customer abandoned the checkout — anyone opening the link is taken through the normal flow into the
Quickpay payment window, and the payment resolves exactly as after checkout.

The link is Sylius' own "pay for this order" url (`sylius_shop_order_pay`), built for the order's channel hostname:
nothing happens at Quickpay until the customer clicks, so it can be shown, copied and sent as often as needed, and it
also works for a payment whose Quickpay payment was never created (the flow creates it on first use). It is offered for
the order's last payment in state `new` on a Quickpay method, as long as the order is not cancelled — the same payment
Sylius' own "Pay" button in the customer account pays.

The email (`@SetonoSyliusQuickpayPlugin/email/payment_link.html.twig`, code `setono_sylius_quickpay_payment_link`)
is sent in the order's locale through Sylius' mailer and can be overridden like any Sylius email template.

## Operation history in the admin

Each Quickpay payment on the admin order view shows its **live operation history** — every
authorize/capture/refund/cancel with amount, Quickpay status code and message, and timestamp, plus the
captured balance and a test-mode badge. The data is fetched from Quickpay *after* the page has rendered,
so the order page is never delayed by a slow gateway; if Quickpay cannot be reached, the panel shows an
inline notice with a retry link. Nothing is stored — the panel reflects what Quickpay reports right now.

## Callbacks

All callbacks for what your store does arrive on a **per-payment url** the gateway mints and registers itself —
the payment window's outcome on the payment link's callback url, and capture/refund/cancel confirmations via the
`QuickPay-Callback-Url` header the gateway sends on every operation it issues. They are verified and routed by
Payum, and none of it needs configuration.

The **account-wide** callback url of your Quickpay account (*Settings* → *Integration* → *Callback url*) is only
consulted for operations made *outside* the store — a refund clicked in the Quickpay manager, a capture issued by
another system talking to the API. If you do that and want your store to know, point the account-wide url at the
plugin's endpoint:

```
https://your-shop.example/payment/quickpay/notify
```

That endpoint resolves the payment from the callback body (`order_id` → your order prefix → the Sylius payment)
precisely because one static url cannot carry a Payum token. A Quickpay account has exactly one such url, so when
several environments share an account, only the one it points at receives those callbacks — the others can rely on
the [reconciliation command](#reconciling-missed-callbacks). Quickpay retries undelivered callbacks 24 times with
backoff.

Deliveries can race: Quickpay retries a callback it considers undelivered, and a retry can arrive while the
original is still being handled. The plugin serializes callback handling per payment with a
[Symfony lock](https://symfony.com/doc/current/components/lock.html) — a callback arriving while another one for
the same payment is being processed is acknowledged with a 2xx and not processed, so nothing is applied twice and
Quickpay stops retrying. Nothing is lost either: handling a callback re-fetches the payment from Quickpay, so the
processing that holds the lock sees the state the duplicate carried. This guards both callback urls. The default
lock store is the filesystem, which serializes per server; if your shop runs on several servers, alias the
plugin's lock factory to your framework's, backed by a shared store such as Redis or your database
([`framework.lock`](https://symfony.com/doc/current/lock.html)):

```yaml
# config/services.yaml
services:
    setono_sylius_quickpay.lock.factory: '@lock.factory'
```

## Reconciling missed callbacks

Callbacks are normally how your store learns about a payment state change. If one never arrives — the store was
unreachable until Quickpay gave up retrying, or the change was made outside the store and the account-wide callback
url points elsewhere (see [Callbacks](#callbacks)) — the payment stays stuck in a non-final state and the order
never completes. The plugin ships a reconciliation command that closes this gap by polling Quickpay directly:

```bash
bin/console setono:sylius-quickpay:reconcile-payments                             # last 7 days, max 100 payments
bin/console setono:sylius-quickpay:reconcile-payments --since="12 hours" --limit=50
bin/console setono:sylius-quickpay:reconcile-payments --dry-run                   # report only, change nothing
```

For each stuck payment it fetches the current status from Quickpay and applies the matching payment
transition — the same one the callback would have triggered. A callback racing the command is harmless:
transitions are guarded and the resulting Quickpay operations re-check the remote status first.

Scheduling is your application's choice; a cron entry along these lines is plenty:

```cron
*/30 * * * * /usr/bin/php /path/to/shop/bin/console setono:sylius-quickpay:reconcile-payments >> /var/log/quickpay-reconcile.log 2>&1
```

The command exits non-zero when any payment could not be checked, so cron mail or your monitoring
will surface persistent problems.

## Testing

```bash
composer phpunit       # unit tests
composer analyse       # static analysis (PHPStan)
composer check-style   # coding standards
```

For manual testing, use the credit card numbers from the
[Quickpay test data](https://learn.quickpay.net/tech-talk/appendixes/test/#test-data).

## Upgrading from 1.x

See [UPGRADE-2.0.md](UPGRADE-2.0.md) for the full list of changes an upgrading store has to make —
gateway configuration keys, removed imports, renamed routes and classes, the Klarna removal, and the
behavioral changes around refunds and callbacks.

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
