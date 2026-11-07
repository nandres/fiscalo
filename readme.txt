=== Fiscalo – Tax ID Fields for WooCommerce ===
Contributors: unandre
Tags: cpf, cnpj, vat, tax id, checkout fields
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

The right tax ID field for every country (CPF/CNPJ, RUC, CUIT, RUT, RFC, NIF, VAT) with real validation, plus house number and postcode autofill.

== Description ==

Selling to customers who need an invoice? Fiscalo adds **one tax ID field that changes with the billing country** and checks the number before the order is placed, so you stop receiving orders with mistyped documents.

* Brazil asks for **CPF or CNPJ** (including the new alphanumeric CNPJ), Paraguay for **RUC or CI**, Argentina for **CUIT, CUIL or DNI**, Chile for **RUT**, Mexico for **RFC**, Spain for **NIF / NIE / CIF**, Portugal for **NIF**, Italy for **Codice fiscale or Partita IVA**, the rest of Europe for a **VAT number**, and every other country for a generic **Tax ID**.
* **Real check-digit validation** for CPF, CNPJ, RUC (Paraguay, Peru), CUIT/CUIL, RUT (Chile, Uruguay), CI (Uruguay), NIT (Colombia), cédula and RUC (Ecuador), NIF/NIE/CIF, Portuguese NIF, Codice fiscale and Partita IVA. Numbers are saved neatly formatted (529.982.247-25, 20-12345678-6, 12.345.678-5…).
* Works with the **block checkout** and the **classic checkout**, and in My Account.
* **Always ask, or only for invoices**: optionally show an "I need an invoice" checkbox and ask for the tax ID only when it is ticked.
* Choose the countries where the field shows and where it is required.
* **House number and neighborhood** fields for the countries you choose (Brazil by default), shown as "Street, number" in addresses, emails and the order screen.
* **Postcode autofill**: in Brazil the CEP fills street, neighborhood, city and state; in about 60 other countries the postcode fills city and state.
* The tax ID appears in the billing address of the order screen, emails, the thank-you page and My Account.

= Compatible with your current setup =

Orders keep the same data keys used by the Brazilian Market plugin (`_billing_cpf`, `_billing_cnpj`, `_billing_persontype`, `_billing_number`, `_billing_neighborhood`), so payment gateways and invoicing tools that read them keep working. When you switch, your Brazilian Market settings are imported automatically. Spanish and Portuguese numbers are also saved as `_billing_nif`. Every order gets `_billing_tax_id` and `_billing_tax_id_type` (cpf, cnpj, ruc, cuit, rut, rfc, nif, vat…).

= Pro =

Fiscalo Pro adds:

* **Company lookup**: type a CNPJ or an EU VAT number and the company name and address fill in by themselves. Optionally reject EU VAT numbers that VIES reports as not registered.
* **Discount by payment method**: e.g. 10% off with bank transfer, Pix or cash, recalculated live in both checkouts.
* **Instalments and discounted price** under every price: "or 12 × $10.00 interest-free" and "$95.00 with bank transfer".

= Trademarks =

WooCommerce is a trademark of Automattic Inc. Fiscalo is an independent plugin and is not affiliated with, endorsed or sponsored by Automattic or by the authors of Brazilian Market on WooCommerce.

== Installation ==

1. Install and activate the plugin (WooCommerce 9.9 or newer is required).
2. Go to **WooCommerce → Fiscalo**.
3. Choose where to ask for the tax ID, whether it is required, and the countries for house number and neighborhood. That's it.

== Frequently Asked Questions ==

= Does it work with the new block checkout? =

Yes. In the block checkout the field label follows the billing country live. It also works with the classic checkout and the My Account address forms.

= Which countries are validated? =

Check digits are verified for Brazil, Paraguay, Argentina, Chile, Uruguay, Peru, Colombia, Ecuador, Spain, Portugal and Italy. Mexico's RFC, EU VAT numbers and other countries are checked for format. You can switch validation to "format only" or turn it off.

= What if a customer's real number is rejected? =

The rules follow each tax authority's published algorithm and were tested against thousands of numbers. When a rule is known to have exceptions (Mexican RFC, Ecuadorian company RUC), only the format is checked, so real customers are never blocked.

= I use Brazilian Market on WooCommerce. Can I switch? =

Yes. Fiscalo reads and writes the same order data, imports its settings and pre-fills returning customers. Deactivate Brazilian Market afterwards so the fields do not show twice.

= Is personal data sent anywhere? =

Only for postcode autofill: the postcode and country are sent to the services listed below. Tax IDs are not sent anywhere in the free version.

== External services ==

When "Postcode autofill" is on (it can be turned off in the settings), the postcode and country typed at checkout are sent from your site to one of these services to find the address. Answers are cached on your site for 30 days.

* **ViaCEP** (Brazilian postcodes) — [website](https://viacep.com.br/), [terms](https://viacep.com.br/)
* **BrasilAPI** (backup for Brazilian postcodes) — [website](https://brasilapi.com.br/), [terms](https://github.com/BrasilAPI/BrasilAPI/blob/main/LICENSE)
* **Zippopotam.us** (other countries) — [website](https://www.zippopotam.us/), [terms](https://www.zippopotam.us/)

== Screenshots ==

1. Block checkout in Brazil: CPF or CNPJ, house number and neighborhood, filled in from the CEP.
2. The same field in other countries: CUIT in Argentina, VAT number in Germany, RUC in Paraguay.
3. Settings: countries, required, validation, address fields and postcode autofill.
4. The order screen with the tax ID and the house number in the address.

== Changelog ==

= 1.0.0 =
* First release.

== Upgrade Notice ==

= 1.0.0 =
First release.
