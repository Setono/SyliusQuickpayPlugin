# A "Require 3-D Secure" setting

The gateway configuration has no setting that forces 3-D Secure. A merchant who wants it writes Quickpay's `3d-`
prefix in the *Payment methods* field, e.g. `3d-creditcard`.

## Why this is out of scope

- **3-D Secure is already the norm.** PSD2's strong customer authentication applies it to most card payments in the
  EU.
- **The field already does it.** The `3d-` prefix works in *Payment methods*, the field's help text links Quickpay's
  documentation of the tokens, and the checkout logos understand the prefix.
- **A setting would rewrite another option.** The toggle would prepend `3d-` to the payment methods. What a gateway
  option means belongs to payum-quickpay (ADR 0002), so it would have to start there, for a case the field already
  covers.

## Prior requests

- #137: "Add a force 3-D Secure toggle to the gateway configuration"
