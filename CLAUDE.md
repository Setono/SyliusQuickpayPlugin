# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A Sylius plugin that adds **Quickpay** (a Danish payment gateway) as a payment method.
It is a thin Sylius/Payum integration layer on top of the lower-level Payum gateway package
[`setono/payum-quickpay`](https://github.com/Setono/payum-quickpay) 2.x, which in turn delegates HTTP to
[`setono/quickpay-php-sdk`](https://github.com/Setono/quickpay-php-sdk) (PSR-18/17). The SDK owns the API
client (`Setono\Quickpay\Client\*`), request/response DTOs (`Setono\Quickpay\Request\Payment\*`,
`Setono\Quickpay\Response\Payment\*`) and enums; the gateway package owns the Payum actions plus the
`Setono\Payum\Quickpay\{Api,Operations}` helpers; this package wires it all into Sylius's checkout,
state machine, and admin.

Targets PHP 8.1+, Symfony ^6.4, Sylius ~1.14. The active development branch is `2.x` (also the default/PR
base), tracking payum-quickpay 2.x (pre-release: the plugin requires `^2.0@beta`; the SDK is stable, `^1.0`).
The `1.x` branch carries the payum-quickpay 1.5 line. Payment details are **scalar-only** in 2.x —
`quickpayPaymentId` is the source of truth and the payment is re-fetched from Quickpay when needed.
`UPGRADE-2.0.md` (repo root) is the authority on what changed for stores upgrading from 1.x — keep it
updated when further 2.x breaks land.

All dev tooling (PHPStan, PHPUnit, Rector, Infection, ECS, composer-dependency-analyser, composer-normalize) is
delegated to the **`setono/sylius-plugin-pack`** meta-package — it is not listed package-by-package in
`require-dev`. The setup mirrors [`Setono/SyliusPluginSkeleton@1.14.x`](https://github.com/Setono/SyliusPluginSkeleton/tree/1.14.x).

## Commands

```bash
composer phpunit          # run the PHPUnit test suite (tests/)
composer analyse          # PHPStan (level max) — boots the test app via tests/PHPStan/*.php loaders
composer check-style      # ECS dry-run (sylius-labs coding standard)
composer fix-style        # ECS auto-fix
vendor/bin/phpunit --filter PaymentProcessorTest         # run a single test
vendor/bin/rector process --dry-run                      # Rector (UP_TO_PHP_81), dry-run
vendor/bin/composer-dependency-analyser                  # unused/undeclared dependency check
```

CI (`.github/workflows/build.yaml`) is the bar a change must pass — jobs: coding-standards
(`composer validate --strict`, `composer normalize --dry-run`, `composer check-style`,
`vendor/bin/rector process --dry-run`, `lint:yaml`, `lint:twig`), dependency-analysis (composer-dependency-analyser),
static-code-analysis (`composer analyse`), unit-tests (`composer phpunit`), integration-tests
(`lint:container`, `doctrine:schema:create`, `doctrine:schema:validate -vvv`), mutation-tests (Infection) and
code-coverage (Codecov). Matrix: PHP 8.1–8.3 × Symfony `~6.4.0` × deps lowest/highest. A separate
`backwards-compatibility-check.yaml` runs Roave BC-check on PRs.

PHPStan runs at `level: max` with **no baseline** — the error count is zero and must stay there; fix new
errors rather than introducing a baseline.

## Test application

`tests/Application/` is a full, bootable Sylius 1.14 app (structure mirrors the skeleton) used as the host for
static analysis, PHPUnit bootstrapping, and integration testing. PHPStan boots it through
`tests/PHPStan/console_application.php` (Symfony) and `tests/PHPStan/object_manager.php` (Doctrine); PHPUnit
bootstraps from `tests/Application/config/bootstrap.php`. The plugin is wired into the app via
`config/packages/setono_sylius_quickpay.yaml` (imports the plugin's `app/fixtures.yaml`)
and `config/routes/setono_sylius_quickpay.yaml`.

Run any Symfony console command for the plugin from inside that directory, e.g.
`(cd tests/Application && bin/console debug:container setono_sylius_quickpay)`. For a manual run:
`(cd tests/Application && yarn install && yarn build && bin/console doctrine:database:create && bin/console doctrine:schema:create && bin/console sylius:fixtures:load -n)`.

## Architecture

The payment lifecycle is implemented with **Payum's action pattern**. The plugin overrides exactly one library
action: `src/Action/ConvertPaymentAction.php`, registered in `src/Resources/config/services.xml` with
`<tag name="payum.action" factory="quickpay">` (tagged actions are consulted before the factory defaults, so it
shadows the library's own convert action). Everything else — authorize (payment-link creation + notify-token
minting), capture, refund, cancel, status (balance-aware), notify (HMAC validation) — is handled by the library's
2.x actions.

- **`ConvertPaymentAction`** — turns a Sylius payment into a Quickpay payment. On first run it builds SDK DTOs
  (`Address` incl. company name, `BasketItem` per order item, `Shipping`) and calls
  `$this->api->payments()->create(new CreatePaymentRequest(...))`, then stores the scalar `quickpayPaymentId` +
  `order_id` in the Sylius payment's `details` along with `amount`, `currency` and continue/cancel URLs. The
  Payum `Convert` source is Payum's *synthetic* payment; the real Sylius order is resolved via the token identity
  through `$this->payum->getStorage(...)`. Per-item and shipping VAT rates come from
  `Taxation/VatRateResolver` (reads the tax adjustments, falling back to deriving the rate from the totals).

Services use **FQCN ids** in `services.xml`, with interface → class aliases for every `*Interface` the plugin
defines (`PaymentProcessorInterface`, `PaymentProviderInterface`, `VatRateResolverInterface`,
`LanguageGuesserInterface`) — inject the interface, alias resolution does the rest.

### The notify flow
- `src/Controller/NotifyAction.php` is the **HTTP entry point** (route `setono_sylius_quickpay_notify` →
  `POST /payment/quickpay/notify`, in `src/Resources/config/routes.yaml`). It only handles callbacks whose
  `QuickPay-Resource-Type` header is `Payment`, recovers the Sylius order number by stripping the
  `order_prefix` of **each configured Quickpay gateway** from the incoming `order_id` (read from the stored
  gateway configs — with the raw `order_id` as fallback candidate), finds the matching payment via
  `Provider/PaymentProvider::findByQuickpayPaymentId()`, then dispatches the Payum `Notify` request with the
  Sylius payment on that payment's own gateway.
- Sylius's `ExecuteSameRequestWithPaymentDetailsAction` rewraps that as `Notify(details)`, which the **library's**
  `NotifyAction` handles: it validates the `QuickPay-Checksum-Sha256` HMAC against the gateway `private_key` and
  dispatches `ConfirmPayment`.

The prefix handling is the source of several documented "order_id" troubleshooting cases (see README).

`Controller/Admin/PaymentOperationsAction` (route `setono_sylius_quickpay_admin_payment_operations`,
`GET /admin/quickpay/payments/{id}/operations`) renders the live Quickpay operation history for the
admin order view. The panel itself is a `sylius_ui` block prepended onto
`sylius.admin.order.show.payment_content` (guarded by `hasExtension('sylius_ui')`): a placeholder that
fetches the route after page load, so the order page never blocks on Quickpay; failures render an
inline retry notice (HTTP 502). The controller resolves the api key from the payment's own gateway
config and fetches via `Quickpay/ClientFactory`.

`Command/ReconcilePaymentsCommand` (`setono:sylius-quickpay:reconcile-payments`) is the backstop for
callbacks that never arrive: `Provider/PendingPaymentProvider` queries non-final Quickpay payments with
a `quickpayPaymentId`, the command polls each via `GetHumanStatus` and applies the matching transition
through `Sylius\Abstraction\StateMachine` (adapter-agnostic, so it pairs with either state machine
adapter). Doctrine access in these classes goes through `setono/doctrine-orm-trait`'s `ORMTrait`
(inject `ManagerRegistry`, call `$this->getManager(...)`) rather than injecting an entity manager.

### State machine integration
Both Sylius 1.14 state machine adapters forward the `complete`, `refund`, and `cancel` transitions to
`StateMachine/PaymentProcessor`: `SetonoSyliusQuickpayExtension::prepend()` registers a
`winzou_state_machine` **before** callback (guarded by `hasExtension('winzou_state_machine')`), and
`StateMachine/WorkflowSubscriber` listens on the `workflow.sylius_payment.transition.*` events for the
`symfony_workflow` adapter — the parity point of the winzou `before` callback (both fire while the
transition is applied, so an exception aborts it identically). Only the adapter actually applying a
transition dispatches its events, so the dual registration never double-processes. The processor
implements `PaymentProcessorInterface` and is `LoggerAwareInterface` (wired via a `setLogger()` call with
`on-invalid="ignore"`). It translates each transition into the corresponding Payum request
(`Capture`/`Refund`/`Cancel`) against the gateway, but only when the payment actually has a `quickpayPaymentId`,
and it guards each operation by first executing `GetHumanStatus` (the library's status action re-fetches the
payment from Quickpay), skipping operations that already happened. A failed cancel
(`Payum\Core\Exception\ExceptionInterface` or the SDK's `Setono\Quickpay\Exception\QuickpayException`) is logged
but does not block the transition. Operations execute with the Sylius payment through Sylius' Payum bridge, so
gateway-updated details persist; an unqualified `Refund` refunds Quickpay's remaining `balance` and the balance is
persisted into the details by the library's Status/Confirm/Sync actions. Each operation can be turned off via the plugin config
`operations.capture` / `operations.refund` / `operations.cancel` (defined in `DependencyInjection/Configuration.php`,
passed to the processor as container parameters).

### Payment link (admin)
`PaymentLink/PaymentLinkProvider` returns Sylius' `sylius_shop_order_pay` url (built for the order's channel
hostname, like Sylius' `sylius_channel_url`; `%sylius.unsecured_urls%` picks the scheme) for a Quickpay payment
that is the order's last payment in state `new` on a non-cancelled order — null otherwise, also when the shop
route is absent (headless). It is deliberately *not* a raw Quickpay window url: opening it mints a fresh Payum
token and runs the normal Convert → payment-window flow, so nothing happens at Quickpay until the customer
clicks and a payment without a `quickpayPaymentId` works too. Exposed to Twig as
`setono_sylius_quickpay_payment_link(payment)` (on `Twig/PaymentExtension` + `PaymentRuntime`, the plugin's single
Twig extension/runtime pair); `admin/order/show/payment/_quickpay.html.twig` renders the
copy-able link plus a send link shaped like Sylius' resend-order-confirmation-email button (a GET carrying
`_csrf_token`, token id = payment id) to `Controller/Admin/SendPaymentLinkAction`, which reads the flash bag via
Sylius' `FlashBagProvider`, sends through `Mailer/PaymentLinkEmailManager` (the Sylius
email-manager pattern; code `Mailer\Emails::PAYMENT_LINK`, prepended into `sylius_mailer` with
`email/payment_link.html.twig`) in the order's locale and flashes in the `flashes` translation domain
(`Resources/translations/flashes.*.yaml`). Both Twig functions are covered by the `Twig\Test\IntegrationTestCase`
subclass `tests/Twig/PaymentExtensionTest` with `.test` fixtures in `tests/Twig/Fixtures/` (implement both `getFixturesDir()` for Twig < 3.13 and
`getFixturesDirectory()`).

### Checkout presentation
`Checkout/PaymentMethodLogoProvider` turns a Quickpay payment method's gateway `payment_methods` option into a
list of `PaymentMethodLogo` value objects (token, label, `asset()` path or null) — parsing Quickpay's token
grammar (`!` exclusions, `3d-` prefix, `-dk`/`-debet` variants collapsing onto the brand, `creditcard`
expanding to the configured `checkout.creditcard_brands`) and applying the `checkout.payment_method_logos`
config overrides (path adds/overrides, null hides). Exposed to Twig as
`setono_sylius_quickpay_payment_method_logos(method)` (on `Twig/PaymentExtension` + `PaymentRuntime`, the plugin's
single Twig extension/runtime pair; `phpstan.neon` ignores the one error Twig < 3.9's `callable|null` docblock raises
for the `[Runtime::class, 'method']` form on the lowest-deps job) and
rendered by `shop/checkout/select_payment/_payment_method_logos.html.twig`, a `sylius_ui` block prepended on
`sylius.shop.checkout.select_payment.choice_item_content`. The SVGs in
`Resources/public/images/payment-methods/` are Shopify's MIT-licensed payment_icons (attribution README
in that folder) and need `assets:install` in the host app.

### Gateway config & language
- `Form/Type/GatewayConfigurationType` is the admin form for the gateway (tagged
  `sylius.gateway_configuration_type` type `quickpay`). Every field carries a translated `help` text
  (16 locales in `Resources/translations/`). The **capture mode** field is Sylius core's `use_authorize`
  option rendered as a choice (`true` = authorize at checkout, capture on the payment's `complete`
  transition — the default; `false` = Sylius executes `Capture`, Quickpay captures at authorization);
  the deprecated `auto_capture` gateway option is never written. A `PRE_SET_DATA` listener migrates
  configs stored under the pre-2.0 option names (`apikey`/`privatekey`/`agreement` →
  `api_key`/`private_key`/`agreement_id`, the last normalized to int/null for the integer field) and folds
  a stored `auto_capture` into `use_authorize` (enabled → `false`). The `api_key` carries a `QuickpayCredentials` constraint
  (sylius group) whose validator pings Quickpay via `Quickpay/ClientFactory` (symfony/http-client
  capped at 5s) — an explicit 401/403 raises a violation, anything else fails open.
  Sylius' admin form theme ignores Symfony's `help_html` option, so the
  `payment_methods` docs link renders through the plugin's own form theme
  (`Resources/views/form/theme.html.twig`, scoped to that field's block prefix and registered by
  `SetonoSyliusQuickpayExtension::prepend()` via `twig.form_themes`).
- `FactoryBuilder/QuickpayGatewayFactoryBuilder` injects a guessed UI `language` into the gateway default config at
  build time; `Guesser/LanguageGuesser` derives it from Sylius's locale context (mapping `nb`/`nn` → `no`).

## Conventions specific to this repo

- Runtime config lives in the **gateway configuration stored per payment method** (admin form) — the
  `QUICKPAY_*` env vars only feed the test application's fixtures. The order prefix must be unique per
  project/environment — re-used prefixes cause the Quickpay "order_id already exists" / length errors
  documented in the README.
- New Payum behavior = a new class in `src/Action/` tagged `payum.action factory="quickpay"` in `services.xml`.
  Services are wired explicitly in `services.xml` (no autowiring/autoconfiguration in this bundle).
- `composer.lock` is gitignored — this is a plugin, so no lockfile is committed.
- `PaymentProcessorInterface` is adapter-agnostic (`(PaymentInterface $payment, string $transition)`), fed
  by the winzou callback (which passes `event.getTransition()`) and by `WorkflowSubscriber` under the
  `symfony_workflow` adapter. New transition-driven behavior must be hooked into **both** adapters.
