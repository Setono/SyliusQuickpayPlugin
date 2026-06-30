# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A Sylius plugin that adds **QuickPay** (a Danish payment gateway, with Klarna support) as a payment method.
It is a thin Sylius/Payum integration layer on top of the lower-level Payum gateway package
[`setono/payum-quickpay`](https://github.com/Setono/PayumQuickPay) — that package owns the actual QuickPay API
client and models (`QuickPayPayment`, `QuickPayPaymentOperation`, `ConfirmPayment`, etc.); this package wires
it into Sylius's checkout, state machine, and admin.

Targets PHP 8.1+, Symfony ^6.4, Sylius ~1.14. The active development branch is `1.x` (also the default/PR base).

All dev tooling (PHPStan, PHPUnit, Rector, Infection, ECS, composer-dependency-analyser, composer-normalize) is
delegated to the **`setono/sylius-plugin-pack`** meta-package — it is not listed package-by-package in
`require-dev`. The setup mirrors [`Setono/SyliusPluginSkeleton@1.14.x`](https://github.com/Setono/SyliusPluginSkeleton/tree/1.14.x).

## Commands

```bash
composer phpunit          # run the PHPUnit test suite (tests/)
composer analyse          # PHPStan (level max) — boots the test app via tests/PHPStan/*.php loaders
composer check-style      # ECS dry-run (sylius-labs coding standard)
composer fix-style        # ECS auto-fix
vendor/bin/phpunit --filter CountryCurrencyMatcherTest   # run a single test
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

PHPStan runs at `level: max` with a `phpstan-baseline.neon` capturing the pre-existing `src/` issues inherited from
the old Psalm setup — prefer fixing an issue over leaving it baselined, and shrink the baseline over time.

## Test application

`tests/Application/` is a full, bootable Sylius 1.14 app (structure mirrors the skeleton) used as the host for
static analysis, PHPUnit bootstrapping, and integration testing. PHPStan boots it through
`tests/PHPStan/console_application.php` (Symfony) and `tests/PHPStan/object_manager.php` (Doctrine); PHPUnit
bootstraps from `tests/Application/config/bootstrap.php`. The plugin is wired into the app via
`config/packages/setono_sylius_quickpay.yaml` (imports the plugin's `app/config.yaml` + `app/fixtures.yaml`),
`config/routes/setono_sylius_quickpay.yaml`, and `config/validator/Address.xml` (Klarna constraint, see below).

Run any Symfony console command for the plugin from inside that directory, e.g.
`(cd tests/Application && bin/console debug:container setono_sylius_quickpay)`. For a manual run:
`(cd tests/Application && yarn install && yarn build && bin/console doctrine:database:create && bin/console doctrine:schema:create && bin/console sylius:fixtures:load -n)`.

## Architecture

The payment lifecycle is implemented with **Payum's action pattern**. The three actions in `src/Action/` are each
registered in `src/Resources/config/services.xml` with `<tag name="payum.action" factory="quickpay">`, which binds
them to the QuickPay gateway. They use `ApiAwareTrait`/`GatewayAwareTrait` from `setono/payum-quickpay`.

- **`ConvertPaymentAction`** — turns a Sylius payment into the QuickPay request array (amount, basket, addresses,
  callback/continue/cancel URLs). On first run it calls the API to create the QuickPay payment and stashes
  `quickpayPayment` + `quickpayPaymentId` into the Sylius payment's `details`. It also mints a Payum *notify token*
  whose target URL becomes the QuickPay `callback_url`.
- **`StatusAction`** — maps QuickPay payment state + latest operation onto Payum's status markers
  (`markAuthorized`/`markCaptured`/`markRefunded`/`markCanceled`). Default is `markNew` so the payment can be reused
  for further operations.
- **`Action/NotifyAction`** (Payum action) — validates the `quickpay-checksum-sha256` header against the raw body,
  parses the callback JSON into a `QuickPayPayment`, writes it into the payment details, and dispatches `ConfirmPayment`.

### Two NotifyActions — don't confuse them
- `src/Controller/NotifyAction.php` is the **HTTP entry point** (route `setono_sylius_quickpay_notify` →
  `POST /payment/quickpay/notify`, in `src/Resources/config/routing.yaml`). It receives the raw QuickPay server
  callback, resolves the order by `order_id`, finds the matching payment by `quickpayPaymentId`, then dispatches the
  Payum `Notify` request.
- `src/Action/NotifyAction.php` is the **Payum action** that actually handles that `Notify` request (checksum + confirm).

The controller strips `QUICKPAY_ORDER_PREFIX` from the incoming `order_id` to recover the Sylius order number — this
prefix handling is the source of several documented "order_id" troubleshooting cases (see README).

### State machine integration
`src/Resources/config/app/config.yaml` registers a `winzou_state_machine` **before** callback on the
`sylius_payment` machine for the `complete`, `refund`, and `cancel` transitions, invoking
`StateMachine/PaymentProcessor`. That processor translates each transition into the corresponding Payum request
(`Capture`/`Refund`/`Cancel`) against the gateway, but only when the payment actually has a `quickpayPaymentId` and
the operation hasn't already been approved. Each operation can be turned off via the plugin config
`disable_capture` / `disable_refund` / `disable_cancel` (defined in `DependencyInjection/Configuration.php`, passed
to the processor as container parameters). This config file must be imported by the host app (see README install steps).

### Klarna-specific pieces
QuickPay's Klarna requires structured data that plain Sylius addresses don't provide:
- `Action/ConvertPaymentAction::convertAddress()` splits a one-line street into street + house number for `DE`/`NL`
  using `viison/address-splitter`.
- `Validator/Constraints/AddressStreetEligibility` + `AddressStreetEligibilityValidator` + `Checker/StreetEligibilityChecker`
  enforce that an address street can be split — the host app must opt in by registering the constraint on
  `Sylius\Component\Addressing\Model\Address` (see README step 5 and `tests/Application/config/validator/Address.xml`).
- `Klarna/Matcher/CountryCurrencyMatcher` validates country↔currency pairs supported by QuickPay's acquirer.
- `Fixture/KlarnaTestShopUserFixture` seeds a test user/address for manual Klarna testing.

### Gateway config & language
- `Form/Type/QuickPayGatewayConfigurationType` is the admin form for the gateway (tagged
  `sylius.gateway_configuration_type` type `quickpay`).
- `FactoryBuilder/QuickpayGatewayFactoryBuilder` injects a guessed UI `language` into the gateway default config at
  build time; `Guesser/QuickpayLanguageGuesser` derives it from Sylius's locale context (mapping `nb`/`nn` → `no`).

## Conventions specific to this repo

- The plugin reads runtime config from **env vars** (notably `QUICKPAY_ORDER_PREFIX`, plus the API credentials used
  by `setono/payum-quickpay`). The order prefix must be unique per project/environment — re-used prefixes cause the
  QuickPay "order_id already exists" / length errors documented in the README.
- New Payum behavior = a new class in `src/Action/` tagged `payum.action factory="quickpay"` in `services.xml`.
  Services are wired explicitly in `services.xml` (no autowiring/autoconfiguration in this bundle).
- `composer.lock` is gitignored — this is a plugin, so no lockfile is committed.
- The plugin's `src/` still uses the **winzou** state machine (`SM\Event\TransitionEvent` + the
  `winzou_state_machine` callback in `app/config.yaml`). Sylius 1.14 ships `SyliusStateMachineAbstractionBundle` and
  also supports the `symfony_workflow` adapter; under that adapter the winzou callback would not fire. Migrating to
  `Sylius\Abstraction\StateMachine` is a known follow-up.
