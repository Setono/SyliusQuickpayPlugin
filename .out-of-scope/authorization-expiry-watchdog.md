# Warning about authorizations that may lapse

The plugin doesn't warn about authorized payments whose authorization may lapse before the order ships, and doesn't
capture them early.

## Why this is out of scope

- **Quickpay doesn't say when an authorization lapses.** Its payment carries no expiry. `deadline_at` is the deadline
  for authorizing, which a payment link can set, not for capturing.
- **Any threshold would be a guess.** How long an authorization holds depends on the card scheme, the acquirer and the
  payment method. A report going by the payment's age would warn too early about some payments and too late about
  others.
- **Capturing early is no fix.** Many stores may only capture when the order ships, which is why the default capture
  mode is "on completion".

## Prior requests

- #133: "Authorization-expiry watchdog: warn about (or act on) authorized payments nearing expiry"
