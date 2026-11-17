# Quickpay's auto fee

The plugin doesn't turn on Quickpay's auto fee, which adds the card fee to what the customer pays in the payment
window, and doesn't put such a fee on the Sylius order.

## Why this is out of scope

- **The EU bans most of it.** Since 2018 a merchant in the EU may not surcharge consumer Visa and Mastercard cards
  issued in the EU (PSD2 together with the Interchange Fee Regulation), and some countries ban more. A fee is left
  mostly for business cards and cards from outside the EU.
- **It changes an order after it was paid.** With the fee, Quickpay captures more than the order total. Keeping the
  books straight means adding the fee to a Sylius order that was already placed and paid. Sylius' totals, its
  invoices and the order description sent to Quickpay (whose basket must add up to the payment's amount for Klarna)
  don't expect that.

## Prior requests

- #134: "Support Quickpay's auto fee (surcharging) with the fee reflected on the Sylius order"
- Setono/payum-quickpay#86: "Pass an auto_fee gateway option through to the payment link", the library half, closed
  with it
