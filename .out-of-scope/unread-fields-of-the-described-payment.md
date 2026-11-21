# Dropping the described payment's unread fields

The plugin's convert, `Action\ConvertPaymentAction`, sets the client email, the client id and Payum's description on
the `DescribedPayment` it hands payum-quickpay's convert. The description comes from Sylius' payment description
provider, `sylius.payment_description_provider`, which the action takes as its second constructor argument.
payum-quickpay's convert reads none of the three. It reads only the number, the total amount, the currency, the
details and the order description. The fields stay anyway.

## Why this is out of scope

- **The described payment mirrors Sylius' fallback on purpose.** When no action supports the convert of a Sylius
  payment, Sylius' capture and authorize actions build a synthetic Payum payment: number, total, currency, client
  email and id, description and details. The plugin's convert handles that first attempt instead (ADR 0004) and
  builds its payment the same way. So the gateway's convert gets the same source as without the plugin, with the order
  description added. Whatever reads a Payum payment's standard fields finds them filled, whether a later
  payum-quickpay or another action or extension on the gateway.
- **Their mutants are expected to survive.** Infection's mutants that remove the three setters escape, because
  nothing reads the fields today. They are equivalent mutants, and as `CLAUDE.md` says they are left, not hidden with
  an ignore. An escaped mutant there is no reason to raise this again.

## Prior requests

- #218: "ConvertPaymentAction sets the client email, client id and description, which payum-quickpay's convert never
  reads"
