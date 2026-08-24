# Pay with Xara for WooCommerce

WooCommerce payment gateway that creates a Xara invoice at checkout and waits for a signed `invoice.paid` webhook.

## Requirements

- WordPress 6.5+
- WooCommerce 8.3+
- PHP 7.4+
- Store currency: NGN
- Xara business API token

## Install

Copy `pay-with-xara/` into `wp-content/plugins/` and activate it. Then open **WooCommerce → Settings → Payments → Pay with Xara**.

## Merchant setup

1. Generate an API token in the Xara business dashboard under Developers.
2. Create a webhook secret and paste it into the plugin settings.
3. Register this store URL for `invoice.paid` and `invoice.payment_received`:

   `https://your-store.com/wp-json/xara/v1/webhook`

   Saving the plugin settings tries to register that URL automatically.

4. Enable billing phone at checkout.

## Payment flow

1. Customer selects **Pay with Xara** and places the order.
2. The plugin calls `POST /rest/Payment/initiateInvoice`.
3. WooCommerce stores the invoice reference and sets the order to **On hold**.
4. The customer pays from WhatsApp.
5. Xara POSTs a signed webhook. The plugin verifies `X-Xara-Signature` and calls `payment_complete()`.

## Compatibility

- High-Performance Order Storage
- Classic shortcode checkout
- WooCommerce Checkout Blocks
- Uses WooCommerce CRUD (`wc_get_order`, order meta) — no direct `wp_posts` access
