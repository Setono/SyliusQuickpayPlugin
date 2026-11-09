# Sylius Quickpay Plugin

Quickpay as a Sylius payment method: the shop's payments on one side, Quickpay's on the other, kept in step.

## Language

**Sylius payment**:
The shop's record of a payment for an order, with its own state in Sylius' payment state machine.
_Avoid_: order payment, local payment

**Quickpay payment**:
The payment as Quickpay holds it, identified by its Quickpay payment id and linked to exactly one Sylius payment.
_Avoid_: transaction, remote payment

**Callback**:
Quickpay's request telling the shop that a Quickpay payment has changed.
_Avoid_: notify, notification, webhook

**Reconcile**:
Bringing a Sylius payment's state in line with its Quickpay payment's status, applying at most one state transition.
_Avoid_: sync (Payum's Sync only refreshes the stored payment details), poll

**Status check**:
Reading a Quickpay payment's status without changing the Sylius payment in any way.
_Avoid_: peek, sync

**Gateway configuration**:
The settings a Quickpay gateway is built from (api key, private key, order prefix, ...), stored with a payment method and edited in the admin.
_Avoid_: gateway config, payment method settings, credentials

**Gateway option**:
One named setting of a gateway configuration, such as the order prefix.
_Avoid_: config key, parameter

**Capture mode**:
Whether a payment is authorized at checkout and captured when the Sylius payment completes, or captured by Quickpay at authorization.
_Avoid_: auto capture, use_authorize

**Gateway check**:
A test against Quickpay of what a gateway configuration's credentials allow, such as whether the api key is accepted, the agreement exists or payment links may be created.
_Avoid_: probe, verification, doctor check

**Verdict**:
The outcome of a gateway check: passed, failed, or undetermined when Quickpay's answer proves neither.
_Avoid_: result, verification
