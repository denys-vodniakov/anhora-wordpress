=== Anhora ===
Contributors: anhora
Tags: chatbot, woocommerce, ai, customer-support, knowledge
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Embed the Anhora assistant, sync WordPress pages into knowledge, and connect WooCommerce catalog + session context.

== Description ==

Anhora helps visitors on your WordPress site with grounded answers from your pages and (optionally) WooCommerce store data.

* Embed the Anhora chat widget via the stable loader
* Sync selected pages (shipping, payment, FAQ, returns) into Anhora knowledge
* When WooCommerce is active: catalog ingest, shipping/payment rule snapshots, Host Bridge for PDP/cart/user/orders

Anhora does **not** process card payments in chat. Payment and checkout stay in WooCommerce; the assistant only knows method titles and steps.

== Installation ==

1. Upload the `anhora` folder to `/wp-content/plugins/`
2. Activate the plugin through the Plugins menu
3. Go to Settings → Anhora and enter API base, widget id, ingest secret, and deployment key from https://app.anhora.net
4. Select knowledge pages and save
5. Click “Sync knowledge now”

== Frequently Asked Questions ==

= Does this require WooCommerce? =

No. Phase 1 works on any WordPress site. WooCommerce features load automatically when WooCommerce is active.

= Are orders stored in Anhora? =

No. Order history is session-only via the Host Bridge for logged-in customers.

== Changelog ==

= 0.1.0 =
* Initial release: embed, knowledge sync, WooCommerce catalog + bridge + shipping/payment knowledge
