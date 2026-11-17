# Partial refunds from Sylius

The plugin refunds a Quickpay payment as a whole, on the Sylius payment's `refund` transition. It doesn't integrate
Sylius/RefundPlugin or offer any other way to refund part of a payment from Sylius.

## Why this is out of scope

- **The Quickpay manager already does partial refunds**, and they are rare. A merchant refunds part of a payment
  there.
- **The Sylius payment stays right.** A partial refund leaves the Quickpay payment captured with a smaller balance, so
  its Sylius payment stays *completed*. Sylius has no partially refunded payment state, so that is the closest state
  it has.
- **A later full refund in Sylius still works.** The plugin's refund targets Quickpay's balance, what is still
  captured, so refunding the payment in Sylius after a partial refund in the manager refunds the rest instead of
  failing.

## Prior requests

- #27: "Partial refund (and capture)", its refund half
- #104: "Integrate with Sylius/RefundPlugin for partial refunds"
