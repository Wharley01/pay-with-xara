=== Pay with Xara ===
Contributors: XavaTech
Tags: woocommerce, payments, xara, whatsapp, nigeria
Requires at least: 6.5
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept WooCommerce payments through Xara. Customers receive an invoice on WhatsApp and pay with wallet, bank transfer, and more.

== Description ==

Pay with Xara adds a WooCommerce payment method that creates a Xara invoice at checkout and sends it to the customer's WhatsApp number.

Customers can then pay with:

* Xara wallet
* Bank transfer
* Mobile Money
* Other methods enabled on the Xara business

The plugin is built for current WooCommerce standards:

* High-Performance Order Storage (HPOS)
* Classic checkout and Checkout Blocks
* Signed webhook verification
* Server-side API calls only — the API token never reaches the browser

= Requirements =

* WooCommerce 8.3 or later
* Store currency set to NGN
* A Xara business account with an API token
* Customer billing phone numbers (used to deliver the WhatsApp invoice)

== Installation ==

1. Upload the `pay-with-xara` folder to `/wp-content/plugins/`.
2. Activate **Pay with Xara** from the Plugins screen.
3. Go to WooCommerce → Settings → Payments → Pay with Xara.
4. Enter your Xara API token and webhook secret from [business.usexara.ai](https://business.usexara.ai).
5. Copy the shown webhook URL into the Xara Developers dashboard, or save the settings to register it automatically.
6. Enable the gateway.

== Frequently Asked Questions ==

= Does the customer pay on my website? =

No. Checkout creates the invoice and Xara sends it to the customer's WhatsApp. The order stays **On hold** until Xara reports `invoice.paid`.

= Why is a phone number required? =

Xara delivers the invoice over WhatsApp. Enable the billing phone field in WooCommerce checkout.

= Which currencies are supported? =

NGN.

== External services ==

This plugin connects to the Xara Business API to create invoices, look them up, and register a webhook so your store is notified when an invoice is paid.

* Service: Xara Business API (https://graph.usexara.ai)
* Data sent: your API token (for authentication), and for each order the customer name, WhatsApp phone number, line items, amounts, an order note, and the delivery address entered at checkout.
* When: when a customer places an order with Pay with Xara, and when you save the plugin settings (to verify the token and register the webhook).
* Terms: https://usexara.ai/terms
* Privacy: https://usexara.ai/privacy

No data is sent to Xara for customers who choose a different payment method.

== Changelog ==

= 1.0.2 =
* Send the customer's checkout address with the invoice so it appears on the order.
* Record WooCommerce invoices as website sales.
* Use the official Xara logo at checkout and on the settings screen.

= 1.0.0 =
* Initial release with classic checkout, Checkout Blocks, HPOS, and signed webhooks.
