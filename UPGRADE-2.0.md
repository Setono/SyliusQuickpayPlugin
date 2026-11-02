# Upgrading from 1.x to 2.0

Version 2.x of this plugin is built on [`setono/payum-quickpay`](https://github.com/Setono/payum-quickpay) 2.x,
which replaces its hand-rolled API client with [`setono/quickpay-php-sdk`](https://github.com/Setono/quickpay-php-sdk)
(PSR-18/PSR-17). Read the gateway library's own
[`docs/UPGRADE-2.0.md`](https://github.com/Setono/payum-quickpay/blob/2.x/docs/UPGRADE-2.0.md) for the
gateway-level background; this document covers what a **Sylius shop upgrading this plugin** has to do.

Already on a 2.0 alpha? [Upgrading from an earlier 2.0 alpha](#upgrading-from-an-earlier-20-alpha) lists what
changed since `v2.0.0-alpha.2`.

## Requirements

- Your project needs a PSR-18 HTTP client and PSR-17 factories discoverable by `php-http/discovery`
  (e.g. `composer require symfony/http-client nyholm/psr7`).
- Until the plugin's 2.x line and the gateway library have stable releases, allow the pre-release
  versions in your **root** `composer.json`:

  ```bash
  composer require setono/sylius-quickpay-plugin:^2.0@alpha setono/payum-quickpay:^2.0@RC setono/quickpay-php-sdk:^1.2
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
- **`auto_capture` is gone from the form and is no longer written; the capture mode is now a visible
  choice backed by `use_authorize`.** payum-quickpay 2.0 follows Payum's convention: executing `Capture`
  at checkout opens the payment window with auto capture on the link, executing `Authorize` opens an
  auth-only window that is settled later — so "capture immediately" is expressed by the flow Sylius
  runs, not by the (now deprecated) `auto_capture` gateway option. Sylius core picks the flow from
  `use_authorize`, which the form therefore exposes as **Capture mode**:

  | Capture mode | Stored | Checkout | Money |
  |---|---|---|---|
  | On completion (default) | `use_authorize: true` | `Authorize` | held; captured on the payment's `complete` transition |
  | Immediately | `use_authorize: false` | `Capture` | captured by Quickpay at authorization |

  Stored configurations migrate on the first re-save: `auto_capture: 1` becomes `use_authorize: false`,
  `auto_capture: 0` (the 1.x default) becomes `use_authorize: true`, and the `auto_capture` key is
  dropped. **Behaviour does not change** for an unsaved configuration either — the gateway library still
  honours a stored `auto_capture` on the authorize flow until 3.0 — so re-save at your convenience.

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

## The account-wide callback url is only needed for outside operations

Callbacks for everything the store itself does — the payment window's outcome and the capture/refund/cancel
operations the plugin issues — arrive on a per-payment url the gateway registers automatically. The account-wide
callback url in the Quickpay manager (*Settings* → *Integration*) only matters for operations made *outside* the
store (the Quickpay manager, other API clients); point it at `https://your-shop.example/payment/quickpay/notify`
if you make those and want the store to notice. The README's *Callbacks* section has the details.

## Behavioral changes

- **Payment details are scalar-only.** `quickpayPaymentId` is the source of truth and the payment is
  re-fetched from Quickpay when needed. The `quickpayPayment` object stored in old payment details is
  simply ignored — existing payments keep working.
- **A refund targets Quickpay's `balance`** (what is still captured) rather than the original amount,
  so a payment partially refunded directly in the Quickpay manager refunds only the remainder instead
  of failing. The balance is persisted into the payment details whenever a callback or the reconciliation
  command reconciles the payment, also when its state doesn't change. An explicit
  `refund_amount`/`capture_amount` in the details is passed through untouched.
- **A partially refunded payment stays `completed`** until the full amount is refunded, instead of
  flipping to `refunded` on the first partial refund.
- **Callbacks are HMAC-verified** (`QuickPay-Checksum-Sha256`) by the gateway library; unsigned or
  tampered callbacks are rejected with a 400 response before anything about the payment changes.
- **A payment is reconciled under a per-payment lock.** Callbacks, on either callback url, and the
  reconciliation command take the payment's lock and hold it until the state change is applied and
  saved. A callback arriving while it is held, such as a Quickpay retry racing the original delivery,
  is answered with a 2xx and runs nothing, and the reconciliation command skips the payment and reports
  it, so a state change is never applied twice. Locking uses the Symfony Lock component (a new
  dependency, installed by composer) through a named `framework.lock` resource, `setono_sylius_quickpay`,
  defaulting to the per-server `flock` store — the README's *Callbacks* section shows how a multi-server
  shop redefines the resource with a shared store.
- **A failed cancel no longer blocks cancelling the order** — e.g. when the customer never completed
  checkout, so there is nothing to cancel at Quickpay; the failure is logged instead.
- **Both state machine adapters are supported.** The winzou before-callback (the Sylius 1.14 default
  adapter) is complemented by a Symfony workflow event subscriber, so capture/refund/cancel reach
  Quickpay also when the `sylius_payment` graph runs on the `symfony_workflow` adapter — where 1.x
  was silently inert.
- **New: a reconciliation command.** `setono:sylius-quickpay:reconcile-payments` reconciles payments
  stuck in a non-final state — e.g. because a callback never arrived — with their Quickpay payments,
  making the state change the callback would have made; `--dry-run` reports what would change and
  changes nothing. See the README for options and a suggested cron cadence.
- **New: a doctor command.** `setono:sylius-quickpay:doctor` verifies every configured gateway —
  api key, private key self-test, agreement existence, order prefix length and uniqueness, and the
  notify route — and with `--live` probes the payment link permission. See the README's *Checking
  your configuration* section.
- **New: Quickpay's fraud signals are surfaced.** The admin operation history shows a *Fraud suspected*
  badge, the reconciliation command gains a `--fraud-suspected` report mode, and an opt-in
  `fraud.block_capture` config flag skips the automatic capture on completion for fraud suspected
  payments, leaving them for manual review.
- **New: the API key is verified at form-save time.** Saving a Quickpay payment method verifies the
  submitted key against the Quickpay API and rejects the form when Quickpay rejects it. A valid key
  whose API user lacks the `/ping` permission is verified through a `/payments` read instead, and an
  unreachable Quickpay skips the check — neither a locked-down API user nor an outage blocks saving.
- **New: live operation history on the admin order view.** Each Quickpay payment shows its operations
  (type, amount, status, timestamp), captured balance and test-mode flag, fetched from Quickpay after
  the page has rendered — no storage, no migrations.

## Upgrading from an earlier 2.0 alpha

Since `v2.0.0-alpha.2`, a Sylius payment is brought in line with its Quickpay payment in one place, the
`Reconciliation` module (terms in [GLOSSARY.md](GLOSSARY.md), the decision in
[ADR 0001](docs/adr/0001-sylius-applies-payment-transitions.md)). Sylius' payment-state extension is the only
thing that applies a payment transition; the plugin triggers it under the payment's lock and reports what
changed. Every callback and the reconciliation command go through the module.

### Removed

| Class | Service | Replaced by |
|---|---|---|
| `Payum\Extension\NotifyIdempotencyExtension` | `Setono\SyliusQuickpayPlugin\Payum\Extension\NotifyIdempotencyExtension`, tagged `payum.extension` (alias `setono_sylius_quickpay.notify_idempotency`) | the payment's lock, taken by `PaymentReconcilerInterface::reconcileFromCallback()` |
| `Payum\Extension\AcknowledgeNotifyAction` | none | the callback endpoints answer a duplicate callback themselves |

The reconciliation command's private Quickpay status → transition mapping is gone as well: Sylius' own
mapping applies.

### Added

- **`Reconciliation\PaymentReconcilerInterface`**, service `Setono\SyliusQuickpayPlugin\Reconciliation\PaymentReconciler`
  (constructor `(Payum $payum, StateMachineInterface $stateMachine, LockFactory $lockFactory, ManagerRegistry $managerRegistry)`,
  logger-aware), aliased by the interface:
  - `checkStatus(PaymentInterface): StatusCheck`, the status check: the target state (the Sylius payment state
    Sylius maps the Quickpay payment's status to) and the transition Sylius would apply, changing nothing;
  - `reconcile(PaymentInterface): ReconcileResult`, reconcile on demand;
  - `reconcileFromCallback(PaymentInterface): ReconcileResult`, reconcile from the callback being handled.

  `ReconcileResult` carries the state before and after, the target state and a `ReconcileOutcome`
  (`Transitioned`, `Unchanged` or `Skipped`, the last when the payment's lock was held).
- **`Controller\PayumNotifyController`** decorates Payum's `Payum\Bundle\PayumBundle\Controller\NotifyController`
  (route `payum_notify_do`, the per-payment callback url Quickpay normally calls). A callback on a Quickpay
  gateway's token is handled by the plugin and never reaches Payum's controller; every other token, and Payum's
  token-less notify url, is passed on untouched. If your application decorates or replaces Payum's notify
  controller, Quickpay callbacks no longer reach it (nor a decorator of yours stacked inside the plugin's), and a
  replacement must still extend `NotifyController`, which the plugin's decorator receives as its inner service.
- **`payum/payum-bundle` (`^2.5`), `doctrine/orm` (`^2.13`) and `doctrine/dbal` (`^3.0`) are direct
  dependencies.** Sylius already requires them, so nothing new is installed.

### Changed constructors

| Class | `v2.0.0-alpha.2` | Now |
|---|---|---|
| `Controller\NotifyAction` | `(Payum $payum, OrderRepositoryInterface $orderRepository, PaymentProviderInterface $paymentProvider, RepositoryInterface $gatewayConfigRepository)` | `(PaymentReconcilerInterface $reconciler, OrderRepositoryInterface $orderRepository, PaymentProviderInterface $paymentProvider, RepositoryInterface $gatewayConfigRepository)` |
| `Command\ReconcilePaymentsCommand` | `(PendingPaymentProviderInterface $pendingPaymentProvider, Payum $payum, StateMachineInterface $stateMachine, ClientFactoryInterface $clientFactory, RepositoryInterface $gatewayConfigRepository, ManagerRegistry $managerRegistry)` | `(PendingPaymentProviderInterface $pendingPaymentProvider, PaymentReconcilerInterface $reconciler, ClientFactoryInterface $clientFactory, RepositoryInterface $gatewayConfigRepository)` |
| `StateMachine\PaymentProcessor` | `(Payum $payum, FraudCheckerInterface $fraudChecker, bool $captureEnabled, bool $refundEnabled, bool $cancelEnabled, bool $blockCaptureOnSuspectedFraud)` | `(Payum $payum, PaymentReconcilerInterface $reconciler, FraudCheckerInterface $fraudChecker, bool $captureEnabled, bool $refundEnabled, bool $cancelEnabled, bool $blockCaptureOnSuspectedFraud)` |

The service definitions changed accordingly; only a redefinition or decoration of these services needs updating.

### Behavioural changes

- **`reconcile-payments --dry-run` writes nothing.** It runs a status check per payment: no state change, no
  state-machine callbacks (the order isn't marked paid, inventory isn't sold) and nothing persisted, not even
  refreshed payment details. Before, Sylius applied the transitions during the dry run and they were saved. Each
  payment prints its state, the transition that would be applied (or `no transition`) and the state its Quickpay
  payment's status maps to: `Payment N: "<state>", would apply "<transition>" (Quickpay → "<target state>")`.
- **A real reconcile run follows Sylius' mapping and reports truthfully.** Sylius' payment-state extension applies
  the transition, so reconciling makes exactly the change a callback would have made: a pending Quickpay payment
  now moves a `new` payment to `processing`. Each payment is saved as soon as it is reconciled. A changed payment
  prints `Payment N: "<before>" -> "<after>" (Quickpay → "<target state>")`, a payment whose lock is held is skipped
  and reported, and the summary reads `N checked, N transitioned, N unchanged, N skipped, N errored`, where
  unchanged means no state change (the payment details refreshed from Quickpay, such as the balance, are still
  saved). Before, a payment Sylius had just moved was counted as unchanged. The run continues after any error,
  including a failing state-machine callback, and exits non-zero when a payment errored.
- **A reconcile that fails saves nothing.** A callback or the reconciliation command reconciles a payment in a
  database transaction that is rolled back on any error. Before, a state-machine callback throwing halfway through
  the transition (Sylius failing to sell the order's inventory, say) left the payment and its order transitioned in
  the database while the callback was answered with an error or the command reported the payment as errored, and
  every retry then saw the payment as reconciled. The reconciliation command goes on with its next payment after
  such an error, with Doctrine's entity manager cleared (or reset, when a failed flush closed it).
- **Duplicate callbacks are answered 2xx without processing, on both callback urls.** Both urls reconcile the
  payment under its lock, held until the transition is applied and saved. A callback arriving while the lock is
  held is answered 204 and runs nothing; one arriving after it was released sees the saved state and changes
  nothing. Before, the lock was released before the transition was applied and saved, and a locked-out duplicate
  still had its transition applied, so a duplicate could complete a payment, pay its order and sell its inventory
  twice. The lock key changed from `setono_sylius_quickpay_notify_<id>` to `setono_sylius_quickpay_payment_<id>`
  and callbacks and the reconciliation command now take the same one, so they never reconcile a payment at the
  same time. The `setono_sylius_quickpay` lock resource and its multi-server override are unchanged, and a broken
  lock store still fails open.
- **A forged callback changes nothing.** A callback whose HMAC doesn't match is answered 400 before anything about
  the payment changes, on both callback urls. Before, Sylius still reconciled and saved the payment (against
  Quickpay's real status) before the 400 was sent. The payment's lock is taken before the HMAC is checked, so a
  forged callback arriving while the lock is held is answered 204 like any duplicate, and runs nothing.
- **Admin operations no longer apply a transition inside themselves.** The guards before capture, refund and
  cancel (skip the operation when Quickpay already did it) use the status check. Before, completing a payment
  Quickpay had already captured ran `complete` and its callbacks a second time from inside the first, and the
  guard's status read saved refreshed details; now it saves nothing.
