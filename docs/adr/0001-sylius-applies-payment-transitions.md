# Sylius applies Quickpay payment transitions; the plugin never does

Sylius' `UpdatePaymentStateExtension`, registered on every Payum gateway, applies the payment state transition after any top-level status read or notify on a Sylius payment, and Payum's per-payment notify endpoint always passes through it. The plugin used to apply transitions as well (the reconcile command mapped Quickpay statuses itself), so a payment could be transitioned twice, reports counted wrong, and a dry run wrote to the database. We keep the extension as the only thing that applies transitions: the plugin reconciles a payment by triggering it, under a per-payment lock spanning the transition and the flush, and reports what changed; anything that must not change a payment's state uses a status check, which never reaches the extension.

## Considered Options

- **The plugin applies transitions with its own status mapping**, bypassing the extension. Rejected: Payum's notify endpoint goes through the extension regardless, so this adds a second applier with a second mapping instead of removing one.

## Consequences

- **A callback is verified on a copy of the payment's details before the extension is triggered.** Payum runs extensions' post-execute even when a request ends in a reply (only an exception counts as a failure), so `Notify` on the Sylius payment would let the extension apply and Payum's storage persist the transition of a forged callback whose HMAC the library rejects with a 400 reply. `Notify` therefore runs on a copy of the details (HMAC check and confirmation, nothing persisted), and only a genuine callback goes on to the status read on the Sylius payment that triggers the extension.
- **The module, not Payum, decides whether the reconcile is saved.** Payum's storage flushes after a failed request too, so a state-machine callback throwing halfway through the transition would leave it half-applied and saved. The request runs in a database transaction the module rolls back on any error.
