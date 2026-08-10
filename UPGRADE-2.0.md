# Upgrading from 1.x to 2.0

Version 2.x of this plugin is built on [`setono/payum-quickpay`](https://github.com/Setono/payum-quickpay) 2.x,
which replaces its hand-rolled API client with [`setono/quickpay-php-sdk`](https://github.com/Setono/quickpay-php-sdk)
(PSR-18/PSR-17). Read the gateway library's own
[`docs/UPGRADE-2.0.md`](https://github.com/Setono/payum-quickpay/blob/2.x/docs/UPGRADE-2.0.md) for the
gateway-level background; this document covers what a **Sylius shop upgrading this plugin** has to do.

## Requirements

- Your project needs a PSR-18 HTTP client and PSR-17 factories discoverable by `php-http/discovery`
  (e.g. `composer require symfony/http-client nyholm/psr7`).
- Until the gateway library and SDK have stable releases, allow the pre-release versions in your
  **root** `composer.json`:

  ```bash
  composer require setono/sylius-quickpay-plugin:^2.0@alpha setono/payum-quickpay:^2.0@alpha setono/quickpay-php-sdk:^1.0@beta
  ```

## Gateway configuration stored in the database

Your `GatewayConfig` rows carry values written by the 1.x admin form. After upgrading:

- **`merchant` is gone — and was never used.** Quickpay API v10 authenticates with the API key alone,
  so the value was inert even on 1.x (where the form required it). Stale `merchant` keys in existing
  configs are harmless and are dropped the next time you save the payment method.
- **`apikey`/`privatekey` are now `api_key`/`private_key`.** The old spellings keep working as
  deprecated aliases in the gateway library, so stored configs survive the upgrade untouched — and the
  admin form migrates them: editing an existing Quickpay payment method shows the stored credentials,
  and saving writes the new keys. Re-save each Quickpay payment method once to migrate.
- **`agreement` is now `agreement_id` — and optional.** It maps to the payment window's agreement id.
  The old name keeps working as a deprecated alias in the gateway library, and the admin form migrates
  it on re-save like the credentials. Update it before 3.0: unlike the credentials it is optional, so
  after the alias removal a stale name fails *silently* — the payment link is created without an
  agreement id and Quickpay falls back to the account default.
- **New optional options**: `synchronized` (run capture/refund/cancel synchronously instead of relying
  on the Quickpay callback) and `branding_id` (payment window branding).
- `use_authorize` is unchanged and still required to be `true` — Sylius core reads it to select the
  authorize checkout flow; the form keeps writing it automatically.

## Plugin configuration

- **Remove the `app/config.yaml` import.** The state machine callback is now registered automatically
  by the bundle extension; the file no longer exists, so a kept import breaks the container build:

  ```diff
  # config/packages/setono_sylius_quickpay.yaml
  -imports:
  -    - { resource: "@SetonoSyliusQuickpayPlugin/Resources/config/app/config.yaml" }
  ```

- **The `disable_*` flags became positively-named flags nested under `operations`:**

  ```diff
  setono_sylius_quickpay:
  -    disable_capture: true
  +    operations:
  +        capture: false
  ```

  All three (`capture`, `refund`, `cancel`) default to enabled; `config:dump-reference` documents them.

## Routing

The routes file was renamed — update your import:

```diff
# config/routes/setono_sylius_quickpay.yaml
setono_sylius_quickpay:
-    resource: "@SetonoSyliusQuickpayPlugin/Resources/config/routing.yaml"
+    resource: "@SetonoSyliusQuickpayPlugin/Resources/config/routes.yaml"
```

## Environment variables

- **`QUICKPAY_ORDER_PREFIX` is no longer required** for the container to compile. Notify callbacks
  resolve the order against the *Order prefix* configured on each Quickpay payment method, so the env
  var only remains relevant if your fixtures or gateway configuration reference it.
- **`QUICKPAY_MERCHANT_ID` is obsolete** (see the `merchant` removal above) and can be deleted.

## Klarna support was removed

Everything Klarna-specific is gone: the street eligibility checker and validator constraint, the DE/NL
street splitting, the country/currency matcher, and the Klarna fixtures.

- **Remove the validator mapping** your app registered for it (typically `config/validator/Address.xml`
  referencing `Setono\SyliusQuickpayPlugin\Validator\Constraints\AddressStreetEligibility`) — the class
  no longer exists and the container will fail to build with the mapping in place.
- Remove any `quickpay_klarna`-style payment methods or fixtures referencing `payment_methods: klarna`.

## Services

- **Service ids are now FQCNs** (e.g. `Setono\SyliusQuickpayPlugin\StateMachine\PaymentProcessor`
  instead of `setono_sylius_quickpay.state_machine.payment_processor`), with interface → class aliases
  for every interfaced service. Update any service references or decorations.
- **All classes are `final`.** Extension happens through the supported seams: decorate or replace the
  interfaced services (`PaymentProcessorInterface`, `PaymentProviderInterface`,
  `VatRateResolverInterface`, `LanguageGuesserInterface`), or extend the gateway configuration form
  with a regular `AbstractTypeExtension`.

## Removed and renamed classes

| 1.x | 2.x |
|---|---|
| `Action\StatusAction` | removed — the gateway library's balance-aware status action is used |
| `Action\NotifyAction` | removed — the gateway library validates the callback HMAC itself |
| `Exception\UnsupportedPaymentTransitionException` | removed — has not been thrown since 2018 |
| `Checker\*`, `Validator\*`, `Klarna\*`, `Fixture\KlarnaTestShopUserFixture` | removed with Klarna support |
| `Form\Type\QuickPayGatewayConfigurationType` | `Form\Type\GatewayConfigurationType` |
| `Guesser\QuickpayLanguageGuesser(Interface)` | `Guesser\LanguageGuesser(Interface)` |

## Behavioral changes

- **Payment details are scalar-only.** `quickpayPaymentId` is the source of truth and the payment is
  re-fetched from Quickpay when needed. The `quickpayPayment` object stored in old payment details is
  simply ignored — existing payments keep working.
- **A refund targets Quickpay's `balance`** (what is still captured) rather than the original amount,
  so a payment partially refunded directly in the Quickpay manager refunds only the remainder instead
  of failing. The balance is persisted into the payment details on every status check and callback. An
  explicit `refund_amount`/`capture_amount` in the details is passed through untouched.
- **A partially refunded payment stays `completed`** until the full amount is refunded, instead of
  flipping to `refunded` on the first partial refund.
- **Callbacks are HMAC-verified** (`QuickPay-Checksum-Sha256`) by the gateway library; unsigned or
  tampered callbacks are rejected with a 400 response.
- **A failed cancel no longer blocks cancelling the order** — e.g. when the customer never completed
  checkout, so there is nothing to cancel at Quickpay; the failure is logged instead.
- **Both state machine adapters are supported.** The winzou before-callback (the Sylius 1.14 default
  adapter) is complemented by a Symfony workflow event subscriber, so capture/refund/cancel reach
  Quickpay also when the `sylius_payment` graph runs on the `symfony_workflow` adapter — where 1.x
  was silently inert.
