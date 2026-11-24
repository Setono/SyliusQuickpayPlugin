# Partial captures from Sylius

The plugin captures a Quickpay payment as a whole, on the Sylius payment's `complete` transition. The admin offers no
way to capture less than the authorized amount.

## Why this is out of scope

- **The Quickpay manager already does partial captures.** A merchant who captures part of a payment there gets the
  Sylius payment completed by the capture's callback or the next reconcile run. payum-quickpay reads a payment whose
  latest approved operation is a capture as captured, whatever the amount, and Sylius maps that to *completed*.
- **Sylius treats a payment as a whole.** Its `complete` transition applies once. A partial capture from Sylius would
  either complete the payment and let the rest of the authorization lapse, which the manager already gives, or leave
  the payment *authorized* so the admin can capture more later. The second needs a status that tells a partly
  captured payment from a fully captured one. Sylius has no such payment state, and payum-quickpay's status would have
  to change too, or every callback and reconcile run would complete the payment anyway.
- **The usual reason for it doesn't come up.** A merchant captures less when shipping part of an order. Sylius'
  checkout ships an order as one shipment.
- **Code can still capture an amount.** An explicit `capture_amount` in the payment details is passed through to
  Quickpay untouched, as the README says.

## Prior requests

- #27: "Partial refund (and capture)", its capture half
- #130: "Partial capture from the admin order view"
