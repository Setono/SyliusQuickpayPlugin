# The admin payment link as a QR code

The admin's *Payment link* panel shows the link and a button that emails it to the customer. It doesn't show the link
as a QR code.

## Why this is out of scope

- **A QR code is for a customer standing in front of the merchant**, which a webshop rarely has. A phone or invoice
  order already gets the link by email.
- **It costs a dependency.** Generating the code without external requests needs a QR library in the plugin's
  requirements, plus a toggle and its translations, for that rare case. The link can be pasted into any QR generator.

## Prior requests

- #138: "Render the admin payment link as a QR code"
