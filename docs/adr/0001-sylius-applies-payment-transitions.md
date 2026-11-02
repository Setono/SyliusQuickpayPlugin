# Sylius applies Quickpay payment transitions; the plugin never does

Sylius' `UpdatePaymentStateExtension`, registered on every Payum gateway, applies the payment state transition after any top-level status read or notify on a Sylius payment, and Payum's per-payment notify endpoint always passes through it. The plugin used to apply transitions as well (the reconcile command mapped Quickpay statuses itself), so a payment could be transitioned twice, reports counted wrong, and a dry run wrote to the database. We keep the extension as the only thing that applies transitions: the plugin reconciles a payment by triggering it, under a per-payment lock spanning the transition and the flush, and reports what changed; anything that must not change a payment's state uses a status check, which never reaches the extension.

## Considered Options

- **The plugin applies transitions with its own status mapping**, bypassing the extension. Rejected: Payum's notify endpoint goes through the extension regardless, so this adds a second applier with a second mapping instead of removing one.
