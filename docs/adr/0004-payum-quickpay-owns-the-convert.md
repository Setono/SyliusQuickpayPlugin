# payum-quickpay owns the convert; the plugin only describes the order

The plugin used to shadow payum-quickpay's convert action with a copy of its own, only to add the order description: the invoice and shipping addresses, the basket, shipping and the shopsystem. Most of the copy repeated the library's convert line for line (the order id, picking up a never-paid Quickpay payment, the currency check, the return urls), so every library release had to be mirrored into it by hand. Its own part had never run under a test. And it found the Sylius order through the Payum token and Payum's storage, so a convert without a token failed. Now payum-quickpay's convert is the only one, and it sends an order description when its source describes one (the library's `DescribedPaymentInterface`). Sylius' capture and authorize actions first convert the Sylius payment itself, and fall back to a synthetic Payum payment only when no action supports that. The plugin's convert takes that first attempt: it asks the order describer for the order description of the payment's order and hands the library's convert a `DescribedPayment` carrying it.

## Considered Options

- **A sub-request**: before creating, the library's convert executes a request of its own asking for the order description, and a plugin action answers it. Rejected: the convert's source is still Sylius' synthetic Payum payment, so the plugin would still find the order through the token and Payum's storage.
- **A subclass hook**: the library's convert gets a protected method the plugin's subclass overrides. Rejected: still bound to the token for the same reason, and every protected method becomes library API a release must keep compatible.
- **A gateway option callable**, set by the plugin's gateway factory builder as it sets the Quickpay client (ADR 0003). Rejected: the callable gets the synthetic payment too, so it is still bound to the token.

The described source won because it reads the order straight from the Sylius payment, with no token or storage lookup; it uses Sylius' own hook, the convert Sylius tries first (its own PayPal Express convert took it the same way); and the carrier is an interface in the library, which any payum-quickpay integration can implement on its payment model to describe its order without a convert action of its own.

## Consequences

- **No mirroring per library release.** The order id, finding or creating the Quickpay payment, the currency check and the return urls exist only in the library, so a fix there reaches the plugin with the update. What the plugin still mirrors is Sylius' fallback: it builds the described payment the way Sylius builds its synthetic one (number, total, currency, client, Payum's description, details).
- **A new part of the order description starts in the library**, in its `OrderDescription`, and the plugin's order describer then fills it.
- **The plugin doesn't shadow a library action to add data it can describe.** Its convert supports only a Sylius payment as the source, which the library's convert never supports, so the two never compete and the order of Payum's actions doesn't matter.
