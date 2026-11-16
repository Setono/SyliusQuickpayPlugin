# Sylius Quickpay Plugin

Quickpay as a Sylius payment method: the shop's payments on one side, Quickpay's on the other, kept in step.

## Language

**Sylius payment**:
The shop's record of a payment for an order, with its own state in Sylius' payment state machine.
_Avoid_: order payment, local payment

**Quickpay payment**:
The payment as Quickpay holds it, identified by its Quickpay payment id and linked to exactly one Sylius payment.
_Avoid_: transaction, remote payment

**Order description**:
What Quickpay is told about an order when its Quickpay payment is created: the invoice and shipping addresses, the basket, shipping and the shop system that created the payment. Its basket and shipping add up to the order total. It never changes the amount, currency or order id, but some payment methods check it: Klarna requires the basket and shipping to equal the payment's amount, and Resurs requires a basket. It is distinct from Payum's one-line payment description.
_Avoid_: payment description, order details, basket (one part of it)

**Callback**:
Quickpay's request telling the shop that a Quickpay payment has changed.
_Avoid_: notify, notification, webhook

**Reconcile**:
Bringing a Sylius payment's state in line with its Quickpay payment's status, applying at most one state transition.
_Avoid_: sync (Payum's Sync only refreshes the stored payment details), poll

**Status check**:
Reading a Quickpay payment's status without changing the Sylius payment in any way.
_Avoid_: peek, sync

**Pending payment**:
A Sylius payment of a Quickpay gateway that is new, processing or authorized and linked to a Quickpay payment, however old it is. A new or processing one is stuck; an authorized one mostly waits for its order to ship.
_Avoid_: non-final payment, open payment

**Gateway configuration**:
The settings a Quickpay gateway is built from (api key, private key, order prefix, ...), stored with a payment method and edited in the admin.
_Avoid_: gateway config, payment method settings, credentials

**Gateway option**:
One named setting of a gateway configuration, such as the order prefix.
_Avoid_: config key, parameter

**Order id**:
Quickpay's `order_id` of a Quickpay payment: the order prefix followed by the Sylius order number. Quickpay requires it to be unique on the account and to be 4–20 letters, digits, spaces, `.`, `_` or `-`. payum-quickpay builds it (`OrderId::of()`) and reads the order number back (`OrderId::numberIn()`). It is not the Sylius order's id or number.
_Avoid_: order number (the Sylius part of it), reference

**Order prefix**:
The gateway option prepended to every order number in the order id, unique per project and environment sharing a Quickpay account. It holds at most 19 of the order id's characters, and with the order number at most 20, which save validation and the doctor check against the latest order's number.
_Avoid_: order id prefix, shop prefix

**Capture mode**:
Whether a payment is authorized at checkout and captured when the Sylius payment completes, or captured by Quickpay at authorization.
_Avoid_: auto capture, use_authorize

**Gateway check**:
A test against Quickpay of what a gateway configuration's credentials allow, such as whether the api key is accepted, the agreement exists or payment links may be created.
_Avoid_: probe, verification, doctor check

**Verdict**:
The outcome of a gateway check: passed, failed, or undetermined when Quickpay's answer proves neither.
_Avoid_: result, verification
