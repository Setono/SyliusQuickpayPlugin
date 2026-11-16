# Probing Quickpay's payment window from the doctor

The doctor (`setono:sylius-quickpay:doctor`) checks a gateway configuration through Quickpay's API only. It doesn't
open Quickpay's payment window, the page customers pay on, to see whether the configuration works there. That holds
for `--live` too.

## Why this is out of scope

The case that asked for it is a wrong agreement id.

- **Quickpay accepts an unknown agreement id when a payment link is created.** `PUT /payments/{id}/link` with
  `agreement_id: 999999` answers 200. Only the payment window fails, with HTTP 400 "Could not find merchant with id …
  and agreement id 999999".
- **Saving and the doctor look the id up.** Both run the agreement gateway check, `GET /agreements/{id}`, and a wrong
  id fails saving the payment method.
- **The gap:** an api user without the permission to read agreements gets a 401, so the check is undetermined. Saving
  goes through, and the doctor only warns.

So a wrong id reaches customers only with a wrong agreement id and a restricted api user together. The README says to
grant that permission, which closes the gap without any code.

Probing the payment window would close it in code, but:

- **The payment window isn't an API.** It is Quickpay's page for customers, and its status codes are undocumented. A
  doctor that fails on them would start failing stores whenever Quickpay changes that page.
- **It would reach almost no one.** The probe writes to the account (it needs a payment link), so it could only run
  under `--live`, never on save. It would help only merchants who run `doctor --live`, and they already see the
  warning that tells them to grant the permission.

## Prior requests

- #177: "Catch an unknown agreement id in doctor --live when the api user may not read agreements"
