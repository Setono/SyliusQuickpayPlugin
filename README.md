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

The plugin 2.x line and the `setono/payum-quickpay` gateway it builds on are still in pre-release,
so your project must allow the pre-release versions explicitly:

```bash
composer require setono/sylius-quickpay-plugin:^2.0@alpha setono/payum-quickpay:^2.0@RC setono/quickpay-php-sdk:^1.2
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
    fraud:
        block_capture: false  # skip the automatic capture when Quickpay reports the payment as fraud suspected (see Fraud signals)
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
| Agreement id | *(optional)* Which agreement the payment window runs on. Leave empty to use your account's default Payment Window agreement — set it only if your account has several agreements and the window should use a specific one |
| Order prefix | Prepended to order numbers sent to Quickpay as the `order_id` — must be **unique per project and environment** sharing the same Quickpay account (see [Troubleshooting](#troubleshooting)), and 11 characters or less |
| Payment methods | Which payment methods the Quickpay payment window offers, e.g. `creditcard` or `mobilepay` — see the [Quickpay documentation](https://learn.quickpay.net/tech-talk/appendixes/payment-methods/#payment-methods) |
| Capture mode | When the money is taken. **On completion** (default) only authorizes the payment at checkout and captures it when the payment is completed in Sylius (e.g. when the order is shipped) — what shops that may not capture before dispatch need. **Immediately** lets Quickpay capture the moment the card is authorized — useful for digital products |
| Synchronized operations | Run capture, refund and cancel synchronously instead of relying on the Quickpay callback |
| Branding id | *(optional)* The payment window branding to use |

When you save the payment method, in the admin or through the Admin API, the plugin refuses a gateway configuration
the gateway would reject (a missing api or private key, a branding name where the branding id belongs, an agreement
id of 0, ...), with an error on each field to fix. Once the configuration is valid, it verifies the API key against
Quickpay's API and refuses the key if Quickpay rejects it — a typo'd key is caught immediately instead of by the first
customer whose checkout fails. A valid key whose API user lacks the `/ping` permission is verified through a
`/payments` read instead, so a locked-down API user never blocks saving; neither does an unreachable
Quickpay — the check fails open on anything but an explicit rejection. Fixtures, the console and writes straight to
the database skip this validation.

**Encrypting gateway configurations is not supported.** Don't switch on PayumBundle's encryption of stored gateway
configurations (`payum.dynamic_gateways.encryption.defuse_secret_key`) in a store that uses this plugin. Only Payum's
own storage and Sylius' admin form decrypt a configuration, so the plugin finds none where it reads one and treats
that as an error: the shared callback endpoint, for one, answers every callback with a 500 until encryption is
switched off. Sylius core itself also reads the capture mode (`use_authorize`) before Payum decrypts the
configuration, so its checkout would capture immediately whatever the payment method's capture mode says.

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
  before anything about the payment changes. A genuine callback then **reconciles** the payment: Sylius applies the
  state change its own mapping gives for the Quickpay payment's status (authorized, completed, refunded, ...), at
  most once.
* When you **complete**, **refund**, or **cancel** a payment in the Sylius admin, the plugin performs the matching
  capture, refund, or cancel operation against Quickpay. A failed cancel at Quickpay (e.g. the customer never
  completed checkout, so there is nothing to cancel) is logged but does not block cancelling the order. Completing
  captures only a payment Quickpay holds an authorization for: completing one the customer never paid, or whose
  authorization was declined, fails with an error naming the payment and the state Quickpay's status maps to, and
  nothing is sent to Quickpay. An operation turned off in the [plugin config](#3-configure-the-plugin-optional) leaves
  Quickpay alone: its transition is applied without contacting Quickpay.
* A refund targets Quickpay's **balance** (what is still captured) rather than the original amount — so a payment
  that was partially refunded directly in the Quickpay manager refunds only the remainder instead of failing. The
  balance is also persisted into the payment details whenever a callback or the
  [reconciliation command](#reconciling-missed-callbacks) reconciles the payment, also when its state doesn't
  change. An explicit `refund_amount` / `capture_amount` in the payment details is passed through to Quickpay
  untouched for programmatic partial operations; note that Sylius' payment state machine still treats the payment
  as a whole — the `refund` transition can only be applied once.

## Payment method logos on the checkout

On the checkout payment step, each Quickpay payment method shows the brands its payment window will offer — derived
from the gateway configuration's **Payment methods** field, so nothing is configured twice and no API is called.
`creditcard, mobilepay` renders the Visa and Mastercard marks (what `creditcard` stands for is configurable) and the
MobilePay mark; a token without a bundled logo (e.g. `resurs`) renders as a small text label. Quickpay's token
grammar is understood: exclusions (`!diners`) are skipped, forced 3-D Secure (`3d-creditcard`) is ignored, and
regional/debit variants (`visa-dk`, `mastercard-debet-dk`, `mobilepay-subscriptions`) collapse onto their brand.
The field is read the way the gateway reads it, so `payment_methods` stored as a list (by fixtures or the Admin API)
gets logos too. A payment method whose gateway configuration is unusable shows no logos and a warning is logged; the
checkout keeps working.

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
so the order page is never delayed by a slow gateway; if Quickpay cannot be reached, or the payment method's
gateway configuration is unusable, the panel shows an inline notice with a retry link. Nothing is stored — the panel reflects what Quickpay reports right now.

## Fraud signals

Quickpay flags payments it suspects of fraud, and the plugin surfaces that in three places:

- A **Fraud suspected** badge on the admin operation history panel, next to the test-mode badge.
- An **opt-in capture guard**: with `fraud.block_capture: true` (see [Configure the plugin](#3-configure-the-plugin-optional)),
  the automatic capture on the payment's `complete` transition is skipped when Quickpay reports the payment as
  fraud suspected — the completion itself is not blocked; the payment is logged and left for manual review, so
  you capture or cancel it in the Quickpay manager after looking at it. The check asks Quickpay at capture time
  and fails open: an unreachable Quickpay, or an unusable gateway configuration, never blocks the payment flow. It costs one extra API call per
  automatic capture, which is why it is off by default.
- A **report mode** on the reconciliation command that asks Quickpay for every payment flagged in the period,
  regardless of its local state — report only, nothing is transitioned. A gateway it can't query, because Quickpay
  fails or its gateway configuration is unusable, is reported by name and makes the run exit non-zero:

```bash
bin/console setono:sylius-quickpay:reconcile-payments --fraud-suspected --since="7 days"
```

`--limit` (default 100) caps the payments reported across all gateways. A report that reaches it says so and names
the gateways it didn't query; raise `--limit` to see the rest.

## Callbacks

All callbacks for what your store does arrive on a **per-payment url** the gateway mints and registers itself —
the payment window's outcome on the payment link's callback url, and capture/refund/cancel confirmations via the
`QuickPay-Callback-Url` header the gateway sends on every operation it issues. Payum checks the url's token and
the plugin handles the callback; none of it needs configuration.

The **account-wide** callback url of your Quickpay account (*Settings* → *Integration* → *Callback url*) is only
consulted for operations made *outside* the store — a refund clicked in the Quickpay manager, a capture issued by
another system talking to the API. If you do that and want your store to know, point the account-wide url at the
plugin's endpoint:

```
https://your-shop.example/payment/quickpay/notify
```

That endpoint resolves the payment from the callback body (`order_id` → your order prefix → the Sylius payment)
precisely because one static url cannot carry a Payum token. It strips the order prefix of every Quickpay payment
method, so while one of them has a gateway configuration the gateway can't be built from (written by fixtures, the
Admin API or the database directly), it answers every callback with a 500 and Quickpay retries it once that is fixed.
A Quickpay account has exactly one such url, so when
several environments share an account, only the one it points at receives those callbacks — the others can rely on
the [reconciliation command](#reconciling-missed-callbacks). Quickpay retries undelivered callbacks 24 times with
backoff.

Deliveries can race: Quickpay retries a callback it considers undelivered, and a retry can arrive while the
original is still being handled. Both callback urls reconcile the payment under a per-payment lock, held until the
state change is applied and saved. A callback arriving while the lock is held is answered with a 2xx and runs
nothing, so nothing is applied twice and Quickpay stops retrying; one arriving after the lock was released sees the
saved state and changes nothing. Nothing is lost either: handling a callback re-fetches the payment from Quickpay,
so the callback holding the lock sees the state the duplicate carried. The lock is taken before the callback's
signature is checked, so a forged callback arriving while the payment's lock is held is answered with a 2xx as well;
like any duplicate it runs nothing and changes nothing. A callback whose handling fails (Quickpay unreachable, a
state-machine callback throwing) saves nothing, not even a state change that was halfway applied, and is answered
with an error, so Quickpay's retry finds the payment as it was. The
[reconciliation command](#reconciling-missed-callbacks) takes the same lock, so a callback and the command never
reconcile a payment at the same time. The lock is a regular [Symfony lock](https://symfony.com/doc/current/lock.html):
the plugin registers a named `framework.lock` resource, `setono_sylius_quickpay`, defaulting to the `flock` store,
which locks per server. If your shop runs on several servers, redefine the resource with a shared store, or two
servers can each handle one of a pair of duplicate callbacks:

```yaml
# config/packages/lock.yaml
framework:
    lock:
        setono_sylius_quickpay: '%env(LOCK_DSN)%'   # e.g. LOCK_DSN=redis://localhost
```

## Reconciling missed callbacks

Callbacks are normally how your store learns about a payment state change. If one never arrives — the store was
unreachable until Quickpay gave up retrying, or the change was made outside the store and the account-wide callback
url points elsewhere (see [Callbacks](#callbacks)) — the payment stays stuck in a non-final state and the order
never completes. The plugin ships a reconciliation command that closes this gap by reconciling each stuck payment with
its Quickpay payment:

```bash
bin/console setono:sylius-quickpay:reconcile-payments                             # last 7 days, max 100 payments
bin/console setono:sylius-quickpay:reconcile-payments --since="12 hours" --limit=50
bin/console setono:sylius-quickpay:reconcile-payments --dry-run                   # report only, change nothing
```

Each stuck payment is reconciled: the command fetches its status from Quickpay and Sylius applies the state change
its own mapping gives, exactly the change the callback would have made (a pending Quickpay payment, for example,
moves a new payment to *processing*). Each payment is saved as soon as it is reconciled, and every payment that
changed is listed with its state before and after and the state Sylius maps its Quickpay payment's status to:

```
Payment 42: "new" -> "authorized" (Quickpay → "authorized")
Payment 43: skipped, it is locked by a callback being handled or another run

 [OK] 3 checked, 1 transitioned, 1 unchanged, 1 skipped, 0 errored
```

The command takes the same per-payment lock as the [callbacks](#callbacks): a payment whose callback is being
handled at that moment is skipped and reported as such, and the next run picks it up. A payment counted as
*unchanged* kept its state; the payment details refreshed from Quickpay, such as its balance, are still saved. A
payment that fails (Quickpay unreachable, a state-machine callback throwing, say Sylius failing to sell the order's
inventory) is reported and counted, and nothing of it is saved, not even a state change that was halfway applied, so
the next run retries it; the run goes on with the next payment. When the database fails to save a payment, Doctrine
closes its entity manager and the rest of that run is reported as errored too; nothing of those payments is saved
either, and the next run reconciles them.

`--dry-run` only reads: it reports each payment's state, the transition that would be applied (or `no transition`)
and the state the Quickpay payment's status maps to, and changes nothing — no state, no state-machine callbacks, no
database writes. It is safe against a production shop:

```
Payment 42: "new", would apply "authorize" (Quickpay → "authorized")
```

Scheduling is your application's choice; a cron entry along these lines is plenty:

```cron
*/30 * * * * /usr/bin/php /path/to/shop/bin/console setono:sylius-quickpay:reconcile-payments >> /var/log/quickpay-reconcile.log 2>&1
```

The command exits non-zero when any payment could not be checked, so cron mail or your monitoring
will surface persistent problems.

## HTTP policy

Every request to Quickpay, the gateway's (checkout, capture, refund, cancel, callbacks, reconciling) and the plugin's
own (the fraud check, the operation history, the fraud report, the doctor, verifying the api key on save), goes
through one [scoped HTTP client](https://symfony.com/doc/current/http_client.html#scoping-client) that the plugin adds
to your framework configuration: `setono_sylius_quickpay.http_client`. Its defaults:

| Option | Default | |
|---|---|---|
| `base_uri` | `https://api.quickpay.net` | |
| `timeout` | `10` | seconds a request may wait for Quickpay without receiving anything |
| `max_duration` | `10` | seconds a request may take in total |
| `retry_failed` | off | no request is retried |

So a slow Quickpay can't hang checkout, the admin or a callback for more than 10 seconds per request, and the requests
show up in the Symfony profiler. Override any option under the same name in your own framework configuration; the
others keep the plugin's defaults. A proxy or extra headers go here too:

```yaml
# config/packages/framework.yaml
framework:
    http_client:
        scoped_clients:
            setono_sylius_quickpay.http_client:
                timeout: 5
                max_duration: 15
                proxy: 'http://proxy.example.com:3128'
```

Mind the [reconcile lock](#callbacks) when you raise the timeouts: a callback makes up to four requests in a row while
it holds its payment's lock, which expires after 60 seconds.

**Retries are off on purpose.** Symfony's `retry_failed` also retries `POST` requests on some statuses (423, 425,
429, 502 and 503), and a retried capture or refund can take or return the money twice. If you enable retries, limit
them to reads:

```yaml
framework:
    http_client:
        scoped_clients:
            setono_sylius_quickpay.http_client:
                retry_failed:
                    max_retries: 1
                    http_codes:
                        0: [GET]     # no response at all, a timeout included
                        429: [GET]
                        500: [GET]
                        502: [GET]
                        503: [GET]
                        504: [GET]
```

Each retry waits up to the full timeouts again, so keep `max_retries` low with the lock in mind.

## Testing

```bash
composer phpunit       # unit and functional tests (the functional suite needs the test application's database)
composer analyse       # static analysis (PHPStan)
composer check-style   # coding standards
```

For manual testing, use the credit card numbers from the
[Quickpay test data](https://learn.quickpay.net/tech-talk/appendixes/test/#test-data).

## Checking your configuration

The plugin ships a doctor that runs the checks otherwise surfacing as support cases — try it first
when something misbehaves, and after every configuration change:

```bash
bin/console setono:sylius-quickpay:doctor          # read-only
bin/console setono:sylius-quickpay:doctor --live   # also probes the payment link permission
```

For every configured Quickpay gateway it verifies the api key against Quickpay, self-tests the private
key's checksum computation, checks that a configured agreement id exists on the account, and validates
the order prefix length — plus, across gateways, that no two share a prefix, and that the notify route
is registered at all. With `--live` it also creates a money-less test payment and attempts the payment
link `PUT` the checkout depends on, catching a missing *Create or update payment link* permission before
a customer does (the test payment remains visible on the account; it never carries money). The command
exits non-zero when any check fails, so it can run in CI or cron.

First, it reads each gateway configuration by the same rules the gateway is built from, so "All checks passed"
means the gateway can be built:

- every gateway option the gateway would reject is a failed check of its own, e.g. a missing api key or private key,
  an agreement or branding id that isn't a positive whole number, or a `synchronized` that isn't a yes/no value. Only
  the checks against Quickpay are then skipped for that gateway, since their errors would only follow from these: its
  order prefix is still checked for length and compared with the other gateways' prefixes;
- an encrypted gateway configuration is a failed check saying encryption isn't supported (see
  [Configuration](#configuration));
- the agreement it looks up is the one the gateway sends: an empty agreement id means the account's default
  agreement, and an agreement id stored under its 1.x name `agreement` is used like the gateway uses it.

It also warns, without failing the run, about two things that work but surprise people:

- a gateway option still stored under its 1.x name (`apikey`, `privatekey`, `agreement`), or the deprecated
  `auto_capture` switched on: re-save the payment method in the admin to replace them;
- no capture mode stored (`use_authorize`), as fixtures and the Admin API may leave it: checkout then captures
  immediately. Choose the capture mode on the payment method in the admin and save it.

## Upgrading from 1.x

See [UPGRADE-2.0.md](UPGRADE-2.0.md) for the full list of changes an upgrading store has to make —
gateway configuration keys, removed imports, renamed routes and classes, the Klarna removal, and the
behavioral changes around refunds and callbacks. Coming from an earlier 2.0 alpha, read its
*Upgrading from an earlier 2.0 alpha* section.

## Troubleshooting

Run [the doctor](#checking-your-configuration) first — it detects every case below.

- `Not authorized: Not authorized to PUT /payments/:id/link`
  at a `/payment/authorize/...` url:

  In the Quickpay manager, check under *Settings* → *Users* that the API user's *User permissions* have the
  `PUT` checkbox checked for *Create or update payment link*. Also make sure the **Api key** field of your
  payment method holds the **API user's** key (*Settings* → *Users*) — not the api key of a Payment Window
  agreement (*Settings* → *Integration*).

- `Validation error: order_id already exists on another payment`

  Quickpay requires the `order_id` to be unique across everything sharing the account, and the `order_id` is
  your order number with the payment method's *Order prefix* prepended. Change the prefix to a string unique
  per project **and** environment, e.g. `qp_<project>_<date>_`, whenever:

  - you recreate a development database, so order numbers start over and collide with the ones already sent
    to Quickpay — bump the `<date>` part;
  - several projects or environments share the same Quickpay (developer) account credentials.

- `Validation error: order_id must have length between 4 and 20`

  Shorten the *Order prefix* of your Quickpay payment method to 11 characters or less.

- `The "branding_id" option must be a positive integer (or an integer string), got 'Our brand'` from the doctor, or
  any other `The "<option>" option ...` failure

  The gateway is built from that gateway configuration on every checkout, callback, capture, refund and reconcile,
  and it rejects the option, so each of them fails for that payment method. Open the payment method in the admin and
  correct the field named in the message: an agreement or branding id is the number from the Quickpay manager, not
  its name.

- `The gateway configuration of the Quickpay gateway "<gateway>" reads as empty, as an encrypted one does` from the
  doctor

  PayumBundle's encryption of gateway configurations is switched on, which this plugin doesn't support. Switch it off
  (see [Configuration](#configuration)).

- `The gateway configuration uses a deprecated gateway option` (a doctor warning)

  The payment method's gateway configuration still has a 1.x option name (`apikey`, `privatekey`, `agreement`) or
  `auto_capture` switched on. It works, but 3.0 stops reading them. Open the payment method in the admin and save it:
  that stores the current names, with `auto_capture` folded into the capture mode.

- `The capture mode is not set, so checkout captures immediately` (a doctor warning)

  The gateway configuration has no `use_authorize`, as fixtures and the Admin API can leave it, and Sylius' checkout
  then lets Quickpay capture the money at authorization. The admin form shows it as "Immediately". Open the payment
  method in the admin, choose the capture mode you want and save it: the mode is then stored, and the warning goes.

- `Payment 42: skipped, it is locked by a callback being handled or another run`

  The reconciliation command found the payment's lock held: a callback for it was being handled at that moment,
  or another run of the command was reconciling it. Nothing was changed, and the next run picks the payment up.
  A lock is never left behind for long: a `flock` lock is released with the process holding it, and a lock in a
  shared store expires after 60 seconds.

[ico-version]: https://poser.pugx.org/setono/sylius-quickpay-plugin/v/stable
[ico-license]: https://poser.pugx.org/setono/sylius-quickpay-plugin/license
[ico-github-actions]: https://github.com/Setono/SyliusQuickpayPlugin/actions/workflows/build.yaml/badge.svg?branch=2.x
[ico-code-coverage]: https://codecov.io/gh/Setono/SyliusQuickpayPlugin/branch/2.x/graph/badge.svg

[link-packagist]: https://packagist.org/packages/setono/sylius-quickpay-plugin
[link-github-actions]: https://github.com/Setono/SyliusQuickpayPlugin/actions
[link-code-coverage]: https://codecov.io/gh/Setono/SyliusQuickpayPlugin
