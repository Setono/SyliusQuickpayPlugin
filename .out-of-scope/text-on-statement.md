# A text on statement per payment method

The gateway configuration has no setting for the text on the customer's bank statement. Every payment gets the
acquirer's standard text, set once per acquirer in Quickpay's manager (for Clearhaus, *Settings* → *Acquirers* →
*Clearhaus*, "Standard text-on-statement").

## Why this is out of scope

- **The standard text already does the job.** The reason to set a statement text is a name the customer recognizes,
  which prevents "unknown charge" disputes. The account's standard text gives every payment that name. A text per
  payment method only matters for a store selling several brands through one Quickpay account.
- **A wrong value can break checkout, and the plugin can't catch it.** Quickpay's helpdesk lists "Text on statement
  does not include merchant descriptor" as a Clearhaus error. It means the text the shop system sends leaves out the
  merchant descriptor registered with Clearhaus. A field holding such a text could make every Clearhaus card payment
  fail. The plugin doesn't know the registered descriptor, so it can't validate the field. A test-card check likely
  wouldn't reach the real acquirer either.

## Prior requests

- #136: "Expose text_on_statement in the gateway configuration"
- Setono/payum-quickpay#98: "Add a text_on_statement gateway option, sent when the convert creates the Quickpay
  payment", the library half, closed with it
