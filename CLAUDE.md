# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A Sylius plugin that adds **Quickpay** (a Danish payment gateway) as a payment method.
It is a thin Sylius/Payum integration layer on top of the lower-level Payum gateway package
[`setono/payum-quickpay`](https://github.com/Setono/payum-quickpay) 2.x, which in turn delegates HTTP to
[`setono/quickpay-php-sdk`](https://github.com/Setono/quickpay-php-sdk) (PSR-18/17). The SDK owns the API
client (`Setono\Quickpay\Client\*`), request/response DTOs (`Setono\Quickpay\Request\Payment\*`,
`Setono\Quickpay\Response\Payment\*`) and enums; the gateway package owns the Payum actions plus the
`Setono\Payum\Quickpay\Api` helper; this package wires it all into Sylius's checkout,
state machine, and admin.

Targets PHP 8.1+, Symfony ^6.4, Sylius ~1.14. The active development branch is `2.x` (also the default/PR
base), tracking payum-quickpay 2.x (pre-release: the plugin requires `^2.0.0-rc.2`, the first release with the
public `GatewayOptions`; the SDK is stable, `^1.2`).
The 2.x line itself is published as `v2.0.0-alpha.x` pre-release tags — the latest of these is the Roave
BC-check baseline. The `1.x` branch carries the payum-quickpay 1.5 line. Payment details are **scalar-only** in 2.x —
`quickpayPaymentId` is the source of truth and the payment is re-fetched from Quickpay when needed.
`UPGRADE-2.0.md` (repo root) is the authority on what changed for stores upgrading from 1.x, and (its last
section) from an earlier 2.0 alpha: removed/changed classes, services, constructor signatures and behaviour —
keep it updated when further 2.x breaks land. Domain terms are defined in `GLOSSARY.md`, settled decisions in
`docs/adr/`.

All dev tooling (PHPStan, PHPUnit, Rector, Infection, ECS, composer-dependency-analyser, composer-normalize) is
delegated to the **`setono/sylius-plugin-pack`** meta-package — it is not listed package-by-package in
`require-dev`. The setup mirrors [`Setono/SyliusPluginSkeleton@1.14.x`](https://github.com/Setono/SyliusPluginSkeleton/tree/1.14.x).

## Commands

```bash
composer phpunit          # run both PHPUnit suites (the functional one needs the test database)
vendor/bin/phpunit --testsuite unit                      # unit suite: tests/Unit, no database
vendor/bin/phpunit --testsuite functional                # functional suite: kernel tests against the test database
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
static-code-analysis (`composer analyse`), unit-tests (the unit suite), integration-tests
(`lint:container`, `doctrine:schema:create`, `doctrine:schema:validate -vvv`, the functional suite), mutation-tests
(Infection, unit suite only via `testFrameworkOptions` in `infection.json.dist`) and code-coverage (MySQL, both
suites, Codecov). Matrix: PHP 8.1–8.3 × Symfony `~6.4.0` × deps lowest/highest. A separate
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

### Test layout
Each PHPUnit suite is one directory: `tests/Unit/` (no kernel, no database; paths mirror `src/`, namespace
`Setono\SyliusQuickpayPlugin\Tests\Unit\…`) and `tests/Functional/` (below). Test support stays outside both, where
either suite and the test application can reach it: the Quickpay HTTP doubles in `tests/Quickpay/` (`ScriptedHttpClient`
for the functional suite, the PSR-18 `FixedResponseHttpClient` and `QueuedResponsesHttpClient` for unit tests running a
real SDK client), `tests/GatewayConfiguration/GatewayConfigurations`,
`tests/Payum/OfflineNotifyAction`, `tests/Application/` and `tests/PHPStan/`. `GatewayConfigurations` is the one shared
helper for real gateway config objects: `of($config, $gatewayName)` and `encrypted()` give the module's
`GatewayConfiguration`, `entity(...)` and `encryptedEntity()` Sylius' `GatewayConfig` entity (encrypted = the encrypted
flag with nothing decrypted, as Doctrine loads it), `USABLE` the two required gateway options. Never double
`getConfig()` arrays; a reader's unit test doubles `GatewayConfigurationProviderInterface` and returns values from this
helper. A caller reading a Quickpay payment (the fraud check, the operations panel) doubles
`QuickpayClientProviderInterface` and returns the SDK's `Payment` objects or throws, never an SDK client or a client
factory.

### Functional suite
`tests/Functional/` holds kernel tests against the test application's database (create it once with
`(cd tests/Application && bin/console doctrine:database:create --env=test && bin/console doctrine:schema:create --env=test)`).
Extend `tests/Functional/FunctionalTestCase`. dama/doctrine-test-bundle (registered in `phpunit.xml.dist`) rolls
each test back, so a test builds its own entities from Sylius' factories (`createSyliusPayment()`); no fixtures.
Only Quickpay's HTTP is substituted, below the plugin's scoped HTTP client: in the `test` env,
`tests/Quickpay/ScriptedHttpClient` is the framework's `http_client.mock_response_factory`
(`config/packages/test/setono_sylius_quickpay.yaml`), so every request to Quickpay, the gateways' and the plugin's own,
runs through the real client provider, SDK clients and HTTP policy and arrives there with the options the scoped
client gave it. Script every Quickpay call with `$this->quickpay()->respond('GET', '/payments/1', ...)` (a PSR-7
response) and assert on `$this->quickpay()->sentRequests()` (`"METHOD /path"` each); `sentRequestOptions()` gives each
request's options in the same order, as Symfony's HTTP client resolved them (`timeout` and `max_duration` as floats,
`normalized_headers` keyed by lowercase name, e.g. `['authorization']`). An unscripted request throws a
`LogicException`, whichever path sent it. The gateway,
Sylius' payment-state extension, the state machine, the
lock and the database are real. `$this->holdLockOf($quickpayPaymentId)` holds a payment's lock (as a callback
being handled would) until you release it. To count how often a payment transition (and so its callbacks) ran, under either
adapter, take `$transitions = $this->recordPaymentTransitions()` before acting and assert on
`$transitions->getArrayCopy()`. For inventory evidence, order tracked variants:
`$variant = $this->createTrackedVariant($code, onHand: 5, onHold: 1)` and
`createSyliusPayment(..., orderedVariants: [$variant])` (the order then totals the payment's amount and paying it sells
one unit of each); read the committed counts with `$this->inventoryOf($variant)`. To store another payment method with
its gateway configuration as given (an unusable one, say, or another factory's), use
`$this->createPaymentMethod($code, $config, factoryName: 'offline')`; its gateway is named after its code. A variant with fewer units on hold
than ordered makes Sylius' sale throw mid-transition, a real failing state-machine callback. `$this->reconciler()` is
the module as wired; `transitioningQuickpayStatuses` is the shared Quickpay status → transition data provider.
`self::quickpayCallback($quickpayPaymentId)` builds Quickpay's signed callback for
the shared endpoint (pass `privateKey:` to forge one): `self::$kernel->handle()` it to POST it, or
`$this->receiveCallback()` it to make it the request being handled when calling the module directly (Payum reads
the callback from the request stack). For a gateway other than Quickpay, the `test` env gives Payum's offline
gateway a notify action that marks the payment captured (`tests/Payum/OfflineNotifyAction`, registered in
`config/packages/test/payum.yaml`). To run a test under both state machine adapters, use
`@dataProvider stateMachineAdapters` and `self::bootKernelWithStateMachineAdapter($adapter)`: `symfony_workflow`
runs in the `test_workflow` env (`config/packages/test_workflow/`, which imports the `test` config), sharing the
test database through `.env.test`'s fixed `DATABASE_URL`. In both test envs the `setono_sylius_quickpay` lock
resource is an in-memory store, shared within the test process only.

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

Services use **FQCN ids** in `services.xml`, with an interface → class alias for every `*Interface` the plugin
defines (`PaymentReconcilerInterface`, `GatewayConfigurationProviderInterface`, `PaymentProcessorInterface`,
`PaymentProviderInterface`, `ClientFactoryInterface`, ...) — inject the interface, alias resolution does the rest.

### Gateway configuration (`src/GatewayConfiguration/`)
Read `GLOSSARY.md` (Gateway configuration, Gateway option, Capture mode) and
`docs/adr/0002-payum-quickpay-owns-gateway-option-rules.md` first. The rule from the ADR: **payum-quickpay owns what a
gateway option means** (1.x names, defaults, normalization, validation: `Setono\Payum\Quickpay\GatewayOptions`, the
code the gateway itself is built from), and the plugin reads a stored gateway configuration only through this module.
Don't add a reader of `getConfig()` keys or a filter on the factory name anywhere else; a new gateway option starts in
the library and only gets a form field and its translated save message here (ADR 0002). The one factory-name filter
left outside the module is
`Provider/PendingPaymentProvider`'s DQL join (`gatewayConfig.factoryName = quickpay`), a deliberate exception: it
selects the Quickpay payments inside the database query, which the module's lookups can't do.
- **`GatewayConfigurationProviderInterface`** (service `GatewayConfigurationProvider`, arg
  `sylius.repository.gateway_config`) finds the stored Quickpay gateway configurations (factory name `quickpay`):
  `all()`, `forGatewayName()` (looked up by name among the stored configs, as Payum's dynamic registry does),
  `forPaymentMethod()` and `forPayment()` (through its method). A lookup answers null for a missing or non-Quickpay
  configuration. Listing and lookup never throw for an unusable configuration.
- **`GatewayConfiguration`** (value; `fromGatewayConfig()` for the entity, `fromConfig($config)` for a bare stored
  array such as the admin form's `config` field, which has no gateway name): `gatewayName`; `options(): GatewayOptions`
  (the library's typed, normalized options); `captureMode(): CaptureMode` (`OnCompletion`/`Immediately`, the
  *effective* mode: Sylius' `use_authorize` as `CaptureMode::fromUseAuthorize()` reads it, immediately when it isn't
  set or is falsy under PHP's bool cast, which is Sylius' checkout rule; then immediately when the library reads a
  stored `auto_capture` as true); `isCaptureModeSet()` (`use_authorize` stored and not null);
  `usesDeprecatedOptions()` (the library's check). `CaptureMode` owns the mapping to and from `use_authorize`
  (`fromUseAuthorize()`, `useAuthorize()`); don't write a `use_authorize` ternary elsewhere.
- **Reading a configuration the library rejects**, for whoever shows or checks one that may still need fixing (the
  admin form, the doctor's order prefix checks): `optionsWithRejectedAsStored(): GatewayOptionValues` never throws for
  a readable configuration (an unreadable one throws as `options()` does). `values` holds every option the library
  reads (the keys of `GatewayOptions::DEFAULTS`, each read from the `GatewayOptions` property named after it in
  camelCase), by current name: the library's normalized value where it accepts the option, the stored value (null when
  not stored) where it rejects it; `rejected`/`isRejected()` say which are as stored; `captureMode` is the effective
  mode, with `auto_capture` folded in unless it is rejected. The plugin holds no list of the library's required options:
  the accepted options are re-read without the rejected ones, and only when that re-read still reports options (only
  required ones can be, now missing) does a placeholder stand in for exactly those; should the library still reject
  it, every option is as stored. `withoutDeprecatedOptions()` is the stored configuration as re-saving the payment
  method stores it before any change: current names, `auto_capture` dropped and the capture mode written as
  `use_authorize`, everything else as stored.
- **Unusable** = invalid by the library's rules, or read as no configuration at all (an encrypted configuration
  nothing decrypted: Payum's model then returns null). Reading one, `options()` (and `captureMode()` only when it needs
  a stored `auto_capture`) throws `UnusableGatewayConfiguration`: `gatewayName`, `isUnreadable()` (no configuration
  at all; its message is the one place saying encrypting gateway configurations is not supported), `getViolations()`
  (the library's message per option, keyed by its current name; empty for an unreadable one). Each reader applies its
  own error policy to it; ask `isUnreadable()` rather than checking for empty violations.
- Readers, each keeping its own error policy for an unusable configuration: the shared callback endpoint (order
  prefixes from `all()`, an unusable configuration propagates → 500), `PayumNotifyController` (`forGatewayName()`: is
  the token's gateway a Quickpay one), `PaymentLinkProvider` (`forPayment()`: is the payment a Quickpay one),
  `PaymentMethodLogoProvider` (`forPaymentMethod()`; unusable → no logos and a warning log), the client provider's
  `quickpayPaymentOf()` (`forPayment()`; none → null, unusable → throws) and through it `FraudChecker` (null → no
  fraud, unusable → fails open with a warning log) and `PaymentOperationsAction` (null → 404, unusable → the inline
  error, 502, and a warning log), and the reconcile command's fraud report (`all()`;
  unusable → a warning naming the gateway and a non-zero exit, the other gateways are still queried). The gateway
  factory builder's `quickpay.client` closure reads each gateway's own config through `fromConfig()` and the client
  provider (*Quickpay client and HTTP policy* below), so building the gateway of an unusable configuration throws
  `UnusableGatewayConfiguration` (not payum-quickpay's `InvalidGatewayOptions`, a Payum `ExceptionInterface`; the
  message names "a Quickpay gateway", since Payum passes no gateway name with the config).
- The doctor (`all()`): an unusable configuration is one failed check per violation (an unreadable one is a single
  failed check with the exception's message) and its checks against Quickpay are skipped, while a readable one's
  order prefix (from `optionsWithRejectedAsStored()`) is still checked; a usable one's checks run on the typed
  `GatewayOptions` (see *Command/DoctorCommand* below).

### Quickpay client and HTTP policy (`src/Quickpay/`)
Read `docs/adr/0003-the-plugin-owns-the-quickpay-http-client.md` first. The rule from the ADR: **every request to
Quickpay goes through one scoped HTTP client with one policy**, the gateway's and the plugin's own alike.
- `SetonoSyliusQuickpayExtension::prepend()` prepends the framework scoped client `setono_sylius_quickpay.http_client`:
  base URI `https://api.quickpay.net`, `timeout` 10, `max_duration` 10, no `retry_failed` (a retried capture or refund
  can move money twice). Apps override single options under the same name in their framework config (deep-merged; the
  README shows a GET-only `retry_failed`). The 10 s ceiling is set against the reconcile lock's 60 s TTL (a callback
  makes up to four sequential reads). No per-method or per-caller policies.
- **`QuickpayClientProviderInterface`** (service `QuickpayClientProvider`, args `setono_sylius_quickpay.http_client`,
  `%kernel.cache_dir%/setono_sylius_quickpay/quickpay_sdk` and `GatewayConfigurationProviderInterface`):
  `forGatewayConfiguration(GatewayConfiguration)` builds
  a new SDK client on every call (no caching: a changed api key needs no invalidation) from `options()` (api key,
  `synchronized`), on a `Psr18Client` over the scoped client, with the SDK's Valinor mapper cache (`FileSystemCache`)
  in that directory. It throws `UnusableGatewayConfiguration` for an unusable configuration.
  `quickpayPaymentOf(PaymentInterface): ?Payment` is the payment-scoped read: the Quickpay payment (the SDK's
  `Payment`) of a Sylius payment, `GET /payments/{id}` with a client for its gateway configuration. Null when the
  module's `forPayment()` finds no Quickpay gateway configuration, or payum-quickpay's `Details::hasPaymentId()` finds no
  Quickpay payment id (checked before the configuration is read, so a payment without one never throws). It throws
  `UnusableGatewayConfiguration`, payum-quickpay's `LogicException` (`Details::paymentId()`, an id it can't read) and the
  SDK's `QuickpayException`s; the fraud check and the operations panel each apply their own error policy. How a
  payment's Quickpay id is read stays payum-quickpay's `Details` (architecture candidate 07).
- The gateway gets its client from the provider: `QuickpayGatewayFactoryBuilder` sets payum-quickpay's `quickpay.client`
  gateway option to a closure Payum resolves with the gateway's own config (builder defaults come before
  `payum.api`, and Payum resolves closures in order). payum-quickpay's check that the client agrees with
  `synchronized` still runs on that client (`QuickpayGatewayFactoryBuilderTest`).
- `Quickpay/ClientFactory` (the plugin's own calls, until they move onto the provider) builds its clients on the
  same scoped client, so the whole plugin follows the one policy. `tests/Functional/QuickpayHttpPolicyTest` asserts
  `timeout` and `max_duration` on a gateway operation's request and on a client factory request.

### Reconciling payments (`src/Reconciliation/`)
Read `GLOSSARY.md` (Sylius payment, Quickpay payment, Callback, Reconcile, Status check; use these terms in code,
docs and output, not "sync" or "notify") and `docs/adr/0001-sylius-applies-payment-transitions.md` first. The rule
from the ADR: **Sylius' `UpdatePaymentStateExtension` is the only thing that applies a payment transition.** It is
registered on every Payum gateway and, after a top-level `GetStatusInterface` or `Notify` request whose first model
is a Sylius payment, runs Sylius' `GetStatus` and applies the transition to the state Sylius maps the status to;
Payum's `StorageExtension` then flushes the entity manager. The plugin never calls `apply` on the payment graph: it
triggers the extension and reports what changed. So never run a status request or `Notify` on a Sylius payment
outside this module (a guard doing so inside a transition applied the transition again from within itself).

`PaymentReconcilerInterface` (service `PaymentReconciler`, args `payum`, `sylius_abstraction.state_machine`,
`lock.setono_sylius_quickpay.factory`, `doctrine`; `LoggerAwareInterface` via `setLogger()`) has three operations:
- **`checkStatus()`, the status check** → `StatusCheck` (`targetState`: the Sylius payment state Sylius' `GetStatus`
  maps the Quickpay payment's status to, e.g. `completed`, `processing`, `unknown` — a Sylius state, never call it
  a Quickpay status; `transition`: what the extension would apply,
  via the state machine abstraction's `getTransitionToState()`, null when the state matches or the status is
  unknown). It runs `GetStatus` on a **copy of the payment's details** (`new ArrayObject($payment->getDetails())`),
  so the extension never fires and nothing is persisted, not even refreshed details. One `GET /payments/{id}`.
- **`reconcile()`, reconcile on demand** and **`reconcileFromCallback()`, reconcile from a callback** →
  `ReconcileResult` (`outcome`: `ReconcileOutcome` `Transitioned`/`Unchanged`/`Skipped`, `stateBefore`,
  `stateAfter`, `targetState`, null only when skipped). `Unchanged` means no state change, not nothing written: the
  details the status read refreshed (e.g. Quickpay's `balance`) are still persisted. Both run the same private
  sequence, `reconcileWith()`: take the payment's lock (key `setono_sylius_quickpay_payment_<quickpayPaymentId>`, TTL
  60s, not auto-released, non-blocking: held → `Skipped`, nothing read or written; a lock store error is logged and the
  payment is reconciled unguarded) → reload the payment and its order (`refresh()`; a payment the entity manager no
  longer manages is loaded afresh with `find()`), so state committed before the lock was taken is seen → begin a
  transaction on the entity manager's connection → run one top-level request on the Sylius payment (the extension
  applies at most one transition, Payum's storage flushes inside the transaction) → commit → release the lock in a
  `finally` (release errors are logged, never thrown) → compare the states. Errors propagate to the caller, and **a
  failed reconcile saves nothing**: Payum's `StorageExtension` flushes after a failed request too (e.g. a
  state-machine callback that threw halfway through the transition, the payment and its order already moved on), so
  on any error the module rolls the transaction back, clears the entity manager (dropping the half-applied changes it
  holds in memory) and rethrows the request's own error. That is all the recovery there is, on purpose: a failed
  flush (a database error) closes the entity manager, and the module doesn't reset it, so every later reconcile in
  that process errors too. That only matters to the reconcile command, which then reports the rest of its run as
  errored; nothing half-applied is saved and the next run reconciles them. A callback handles one payment per
  request.
  - On demand, the request is Sylius' `GetStatus` on the Sylius payment.
  - From a callback, it is Payum's `Notify` on a **copy of the details** first: the **library's** `NotifyAction`
    validates the `QuickPay-Checksum-Sha256` HMAC of the request being handled (the request stack's main request,
    read through Payum's `GetHttpRequest`, so call it while handling the callback) against the gateway
    `private_key`, then `ConfirmPayment` refreshes the details from Quickpay. The confirmed details are set on the
    Sylius payment and `GetStatus` runs on it as the top-level request. Never run `Notify` on the Sylius payment
    itself: the library rejects a forged callback with an `HttpResponse` reply, and Payum still runs the extension's
    post-execute for a reply (only an exception stops it), so a forged callback would reconcile the payment and
    persist it before its 400 (recorded in ADR 0001's consequences).
- The lock comes from the named `framework.lock` resource `setono_sylius_quickpay` that
  `SetonoSyliusQuickpayExtension::prepend()` registers (default `flock`; service
  `lock.setono_sylius_quickpay.factory`; the README shows the multi-server override — apps redefine the resource,
  since framework.lock resources replace rather than merge). Callbacks and the reconcile command take the same
  per-payment lock, so they never reconcile a payment at the same time.

### Callbacks
Callbacks arrive on two endpoints, which behave identically for the same callback
(`tests/Functional/Controller/CallbackEndpointsTest` runs its callback scenarios against both). Both call
`reconcileFromCallback()` and answer 204 whether the payment was reconciled or skipped because its lock is held (a
duplicate of a callback being handled: the lock's holder reconciles it, and Quickpay stops retrying); errors
propagate to an error response (a forged callback → Payum's reply listener turns the `HttpResponse` into a 400,
anything else a 500), so Quickpay retries, and since a failed reconcile saves nothing, the retry finds the payment as
it was. The lock is taken before the HMAC is checked (the check is the `Notify` inside the sequence), so a forged
callback arriving while the payment's lock is held is answered 204 too and runs nothing: nothing is read or changed,
but a 2xx is no proof a callback was genuine.
- `src/Controller/PayumNotifyController.php` **decorates Payum's notify controller** (service
  `Payum\Bundle\PayumBundle\Controller\NotifyController`, route `payum_notify_do` → `/payment/notify/{payum_token}`;
  args `.inner`, `payum`, `PaymentReconcilerInterface`, `GatewayConfigurationProviderInterface`): the per-payment
  `callback_url` the library mints on payment link creation, which Quickpay normally calls. It verifies the token
  as Payum does (Payum's `HttpRequestVerifier`), and when the token's gateway is a Quickpay gateway (the module's
  `forGatewayName()` finds its gateway configuration) it resolves the Sylius payment from the token's details through Payum's storage (404 if gone) and
  reconciles it from the callback. A Quickpay token must **never** fall through to Payum's controller: that runs
  `Notify` on the Sylius payment without the lock, and a forged callback would be applied and persisted before its
  400. Every other token, and Payum's token-less `doUnsafeAction`, is delegated to Payum's controller untouched.
- `src/Controller/NotifyAction.php` is the plugin's **shared endpoint** (route `setono_sylius_quickpay_notify` →
  `POST /payment/quickpay/notify`, in `src/Resources/config/routes/global.yaml`), the account-wide callback url for
  operations made outside the store. It only handles callbacks whose `QuickPay-Resource-Type` header is `Payment`,
  recovers the Sylius order number by stripping the order prefix of **each Quickpay gateway configuration** from the
  incoming `order_id` (the module's `all()`, each read through `options()`, so a prefix stored as a number counts too —
  with the raw `order_id` as the last candidate), finds the matching payment via
  `Provider/PaymentProvider::findByQuickpayPaymentId()`, then reconciles it from the callback. While any stored Quickpay
  gateway configuration is unusable, `UnusableGatewayConfiguration` propagates and every callback on it is answered
  500 (the order might be that gateway's), so Quickpay retries; the reconcile command is the backstop.

The prefix handling is the source of several documented "order_id" troubleshooting cases (see README).

`Controller/Admin/PaymentOperationsAction` (route `setono_sylius_quickpay_admin_payment_operations`,
`GET /admin/quickpay/payments/{id}/operations`) renders the live Quickpay operation history for the
admin order view. The panel itself is a `sylius_ui` block prepended onto
`sylius.admin.order.show.payment_content` (guarded by `hasExtension('sylius_ui')`): a placeholder that
fetches the route after page load, so the order page never blocks on Quickpay; failures render an
inline retry notice (HTTP 502). The controller (args `sylius.repository.payment`, `QuickpayClientProviderInterface`,
`twig`; logger-aware via `setLogger()`) reads the payment through the client provider's `quickpayPaymentOf()`: null
(not a Quickpay payment, or no Quickpay payment id) → 404; any error (Quickpay, an unusable gateway configuration, an
id payum-quickpay can't read) → the inline notice, 502, and a warning log with `paymentId`, `gatewayName` (the
payment's method's gateway) and the error under `exception`. `tests/Functional/Controller/Admin/PaymentOperationsPanelTest`
requests the route as a logged-in administrator (a `TestBrowserToken` stored in a session, as Symfony's test browser
does; browser-kit isn't installed).

`Command/DoctorCommand` (`setono:sylius-quickpay:doctor`; args `ClientFactoryInterface`, `ApiKeyVerifierInterface`,
`GatewayConfigurationProviderInterface`, `router`) machine-checks the README's Troubleshooting
section per Quickpay gateway configuration from the module's `all()`. It checks what the gateway checks: each
violation payum-quickpay finds is a failed check, an unreadable (encrypted) configuration is one failed check
printing `UnusableGatewayConfiguration`'s message, and an unusable configuration's checks against Quickpay are skipped.
The order prefix checks need no gateway, so they run on every readable configuration, on the order prefix
`optionsWithRejectedAsStored()` gives (none when the library rejects the order prefix itself): length per gateway, and
cross-gateway uniqueness among every readable configuration, usable or not. On a usable one it also runs, on the typed options:
api key (via the shared `Quickpay/ApiKeyVerifier` ping-then-payments
probe), private key HMAC self-test (`CallbackValidator`), agreement
existence (`GET agreements/{id}` for the agreement id the gateway sends, none = the default agreement; failing open on
permission errors), and notify-route registration; `--live` creates a money-less test payment and attempts the
link PUT to surface the missing-permission 403. It warns, without failing, about a stored deprecated gateway option
(`usesDeprecatedOptions()`: re-save the payment method) and an unset capture mode (`isCaptureModeSet()`: checkout
captures immediately), for a readable configuration even when it is invalid. Non-zero exit on any failed check;
warnings don't fail. Its output is English console text, like the other commands', so it has no translations.

`Command/ReconcilePaymentsCommand` (`setono:sylius-quickpay:reconcile-payments`; args
`PendingPaymentProviderInterface`, `PaymentReconcilerInterface`, `QuickpayClientProviderInterface`,
`GatewayConfigurationProviderInterface`) is the backstop for callbacks that never arrive:
`Provider/PendingPaymentProvider` queries Quickpay payments in state new/processing/authorized with a
`quickpayPaymentId`, and the command reconciles each through `PaymentReconcilerInterface::reconcile()` (see
*Reconciling payments* above). Each payment is persisted by its own reconcile (no end-of-loop flush); a changed one
prints `Payment N: "<before>" -> "<after>" (Quickpay → "<target state>")`, a skipped one is reported, and the summary is
`N checked, N transitioned, N unchanged, N skipped, N errored` (unchanged = no state change; its refreshed details
are still saved). It catches any `\Exception` per payment (Quickpay, Payum, a failing state-machine callback, a failed
flush), counts it as errored and goes on with the next payment: the module saved nothing of the errored one, and the
next run retries it; any error → non-zero exit. After a failed flush the entity manager stays closed, so the rest of
that run errors as well (accepted: nothing is saved half-applied, and the next run, a new process, reconciles them).
`--dry-run` uses the status check (`checkStatus()`) instead and writes nothing, printing the transition that would
be applied. The command has no status → transition mapping of its own. Doctrine access in `PaymentReconciler` and
`PendingPaymentProvider` goes through `setono/doctrine-orm-trait`'s `ORMTrait` (inject `ManagerRegistry`, call
`$this->getManager(...)`) rather than injecting an entity manager.

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
(`Capture`/`Refund`/`Cancel`) against the gateway, but only when the operation is switched on and the payment actually
has a `quickpayPaymentId`. A switched-off operation returns first: no gateway is built and no status checked, so not
even an unusable gateway configuration stops its transition. Each operation is guarded with the status check
(`PaymentReconcilerInterface::checkStatus()`, the processor's 2nd constructor argument; never a status request on the
Sylius payment, which would make Sylius' extension apply the transition again from inside the one being applied),
skipping operations that already happened at Quickpay, and builds the gateway only after it. `complete` runs, in
order: the status check (target state `completed` → skip); any other target state but `authorized` → throw
`StateMachine\NoAuthorizationToCapture` (carries the Sylius payment, its Quickpay payment id and the target state),
aborting the transition with nothing else sent to Quickpay (a `Capture` without an authorization makes Sylius'
capture action create a payment link: a token-less Convert for a never-paid payment, a replaced link for a declined
one); the opt-in fraud guard (below); `Capture`. An unusable gateway configuration is the error of the operation that
needs it, whichever exception it surfaces as (payum-quickpay's `InvalidGatewayOptions`, a Payum exception, or the
plugin's `UnusableGatewayConfiguration`): capture and refund propagate it and abort the transition. A failed cancel
(`Payum\Core\Exception\ExceptionInterface`, the SDK's `Setono\Quickpay\Exception\QuickpayException` or
`UnusableGatewayConfiguration`, from the status check, building the gateway or the `Cancel`) is logged
but does not block the transition. Operations execute with the Sylius payment through Sylius' Payum bridge, so
gateway-updated details persist (`Capture`/`Refund`/`Cancel` aren't status requests, so the extension leaves them
alone); an unqualified `Refund` refunds the remaining `balance` the library's `RefundAction` fetches from Quickpay
itself (every library action that fetches the payment also writes `balance` into the details). Each operation can be turned off via the plugin config
`operations.capture` / `operations.refund` / `operations.cancel` (defined in `DependencyInjection/Configuration.php`,
passed to the processor as container parameters). An opt-in `fraud.block_capture` flag makes the processor consult
`Fraud/FraudChecker` (reads the payment through the client provider's `quickpayPaymentOf()` and its
`metadata.fraud_suspected`; null → no fraud, and it fails open, with a warning log, on any error, an unusable gateway
configuration included) before the automatic capture and skip it with a warning when fraud is suspected — the
transition itself proceeds. The operation-history admin panel shows a fraud badge from the same metadata, and the
reconcile command has a `--fraud-suspected` report mode that queries each configured gateway's account directly
(`PaymentsQuery`, one per gateway configuration from the module's `all()`, on the client provider's
`forGatewayConfiguration()`), transitioning nothing; a gateway that can't be queried, an unusable gateway
configuration included, prints a warning naming it and fails the run. `--limit` caps the payments reported across
gateways: once reached, the gateways after it aren't queried, and the report says it stopped and names them (or
says it may be incomplete); reaching the limit doesn't fail the run.

### Payment link (admin)
`PaymentLink/PaymentLinkProvider` returns Sylius' `sylius_shop_order_pay` url (built for the order's channel
hostname, like Sylius' `sylius_channel_url`; `%sylius.unsecured_urls%` picks the scheme) for a Quickpay payment
(its method has a Quickpay gateway configuration: the module's `forPayment()`) that is the order's last payment in state `new` on a non-cancelled order — null otherwise, also when the shop
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
subclass `tests/Unit/Twig/PaymentExtensionTest` with `.test` fixtures in `tests/Unit/Twig/Fixtures/` (implement both `getFixturesDir()` for Twig < 3.13 and
`getFixturesDirectory()`).

### Checkout presentation
`Checkout/PaymentMethodLogoProvider` turns a Quickpay payment method's gateway `payment_methods` option, normalized by
the gateway configuration module (`forPaymentMethod()`, so one stored as a list gets logos too), into a
list of `PaymentMethodLogo` value objects (token, label, `asset()` path or null) — parsing Quickpay's token
grammar (`!` exclusions, `3d-` prefix, `-dk`/`-debet` variants collapsing onto the brand, `creditcard`
expanding to the configured `checkout.creditcard_brands`) and applying the `checkout.payment_method_logos`
config overrides (path adds/overrides, null hides). It is logger-aware (`setLogger()` call, like the other
services): an unusable gateway configuration shows no logos and logs a warning, so the checkout page keeps working.
Exposed to Twig as `setono_sylius_quickpay_payment_method_logos(method)` (on `Twig/PaymentExtension` + `PaymentRuntime`, the plugin's
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
  the deprecated `auto_capture` gateway option is never written. Its `PRE_SET_DATA` listener (`formDataOf()`) shows
  what checkout uses and keeps no value rules or raw config keys of its own (ADR 0002): a new payment method (an empty
  config) shows the defaults with capture mode on completion; otherwise it reads the config through the module
  (`GatewayConfiguration::fromConfig()`): `withoutDeprecatedOptions()` is the form data (current names, `auto_capture`
  folded into `use_authorize`, options without a field such as `language` kept as stored), and each field named after
  a gateway option gets that option's value from `optionsWithRejectedAsStored()`. So a configuration payum-quickpay
  rejects still opens, and fixing one option keeps the others: each accepted option is shown as the library reads it,
  each rejected one as stored where its field holds it unchanged, empty otherwise (`holds()`, decided by the field's
  own type: a string in a text field, an int in an integer field, a bool in a checkbox). A new gateway option starts in
  payum-quickpay, and the plugin only adds its field and its translated save message (below).
  Sylius' admin form theme ignores Symfony's `help_html` option, so the
  `payment_methods` docs link renders through the plugin's own form theme
  (`Resources/views/form/theme.html.twig`, scoped to that field's block prefix and registered by
  `SetonoSyliusQuickpayExtension::prepend()` via `twig.form_themes`). The fields carry no constraints of the
  gateway's rules, which save validation (below) checks; only the order prefix keeps its `Length`.
- **Save validation**: `SetonoSyliusQuickpayExtension::prepend()` prepends
  `sylius_payum.gateway_config.validation_groups.quickpay: [sylius, sylius_quickpay]`, so Sylius'
  `GatewayConfigGroupsGenerator` validates a Quickpay payment method in `sylius_quickpay` too, in the admin form and the
  Admin API alike (the payment method's `Valid` cascade reaches the gateway config; the pattern Sylius' Stripe and
  PayPal gateways use). The plugin's validation mapping (`Resources/config/validation/GatewayConfig.xml`) declares
  `Validator/Constraints/UsableGatewayConfiguration` (class constraint on Sylius' `GatewayConfig`, group
  `sylius_quickpay`): its validator reads the config through the gateway configuration module and adds a violation per
  option payum-quickpay rejects, at `config[<option>]` (lands on the form field; an option without a field, like
  `language`, on the config form). The admin never sees the library's English: each violation is the plugin's own
  message saying what the option accepts (the constraint's `optionMessages`, keys
  `setono_sylius_quickpay.gateway_configuration.gateway_option.<option>`), or, for an option without one (a later
  payum-quickpay's), the generic `invalid_gateway_option` naming it; the library's reason is logged at info level with
  the option name (`setLogger`). When payum-quickpay adds an option, add its message. An unreadable/encrypted config
  (`isUnreadable()`) gets the plugin's `unreadable` message on `config`. Every message is a `validators` key shipped in
  all 16 locales (the functional test checks each locale). The doctor still prints the library's text: console output is
  English. Only on a usable configuration
  does it then verify the api key through `Quickpay/ApiKeyVerifier` (shared with the doctor command; backed by
  `Quickpay/ClientFactory`, on the scoped HTTP client): ping first, falling back to a one-item `/payments` read
  because Quickpay answers 401 on `/ping` both for an invalid key and for a valid key whose api user lacks the `/ping`
  permission (verified live) — an explicit 401/403 is a violation at `config[api_key]` (translated
  `validators` message), anything else fails open. Tested functionally in `tests/Functional/Validator/` (the
  container's validator with the generator's groups) and `tests/Functional/Form/PaymentMethodFormTest` (the real
  payment method form type).
- `FactoryBuilder/QuickpayGatewayFactoryBuilder` injects a guessed UI `language` into the gateway default config at
  build time; `Guesser/LanguageGuesser` derives it from Sylius's locale context (mapping `nb`/`nn` → `no`). It also
  sets the `quickpay.client` gateway option (*Quickpay client and HTTP policy*).

## Conventions specific to this repo

- Runtime config lives in the **gateway configuration stored per payment method** (admin form) — the
  `QUICKPAY_*` env vars only feed the test application's fixtures. The order prefix must be unique per
  project/environment — re-used prefixes cause the Quickpay "order_id already exists" / length errors
  documented in the README.
- The plugin never applies a payment transition (ADR 0001). To bring a Sylius payment in line with Quickpay,
  reconcile it (`PaymentReconcilerInterface`); to read Quickpay's status without side effects, use the status check.
  Don't execute a status request or `Notify` on a Sylius payment anywhere else.
- New Payum behavior = a new class in `src/Action/` tagged `payum.action factory="quickpay"` in `services.xml`.
  Services are wired explicitly in `services.xml` (no autowiring/autoconfiguration in this bundle).
- `composer.lock` is gitignored — this is a plugin, so no lockfile is committed.
- `PaymentProcessorInterface` is adapter-agnostic (`(PaymentInterface $payment, string $transition)`), fed
  by the winzou callback (which passes `event.getTransition()`) and by `WorkflowSubscriber` under the
  `symfony_workflow` adapter. New transition-driven behavior must be hooked into **both** adapters.

## Agent skills

- When grilling (`/mattpocock-skills:grilling`, `/mattpocock-skills:grill-me`, `/mattpocock-skills:grill-with-docs`, and grilling inside other skills), ask via the `AskUserQuestion` tool instead of as plain chat text. The skills ask in chat on purpose and leave this to the project's CLAUDE.md ([mattpocock/skills: native question tool](https://github.com/mattpocock/skills/blob/main/.out-of-scope/native-question-tool.md)).

### Issue tracker

Issues and specs live in GitHub Issues on `Setono/SyliusQuickpayPlugin`, managed with the `gh` CLI. See `docs/agents/issue-tracker.md`.

### Triage labels

The five default triage labels are used as-is (`needs-triage`, `needs-info`, `ready-for-agent`, `ready-for-human`, `wontfix`). See `docs/agents/triage-labels.md`.

### Domain docs

Single-context: one `GLOSSARY.md` and `docs/adr/` at the repo root, created only when a term or decision gets settled. See `docs/agents/domain.md`.
