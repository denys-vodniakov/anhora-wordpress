=== Anhora ===
Contributors: denysvodniakov
Tags: chatbot, woocommerce, ai, customer-support, knowledge
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.4.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Embed the Anhora assistant, sync WordPress pages into knowledge, and connect WooCommerce catalog and session context.

== Description ==

Anhora is a Software-as-a-Service assistant. This plugin connects your WordPress site to your Anhora account so visitors can get grounded answers from selected pages and, optionally, WooCommerce store data.

An Anhora account is required. Create a project and copy credentials from https://app.anhora.net before the plugin can sync content or show the chat widget.

Features:

* Embed the Anhora chat widget after you save a deployment key
* Open Commerce chat from any theme button or the [anhora_chat_button] shortcode
* Sync selected pages (shipping, payment, FAQ, returns) into Anhora knowledge
* When WooCommerce is active: catalog ingest, shipping/payment rule snapshots, and Host Bridge context for the current page, cart, signed-in customer, and recent orders

Anhora does not process card payments in chat. Payment and checkout stay in WooCommerce. The assistant only receives method titles and public checkout steps.

== Installation ==

1. Upload the `anhora` folder to `/wp-content/plugins/`
2. Activate the plugin through the Plugins menu
3. Create an Anhora account at https://app.anhora.net and open a project
4. Go to Settings → Anhora and enter API base, integration ID, widget ID, ingest secret, and deployment key
5. Select knowledge pages and save
6. Click "Sync knowledge now"

== Custom chat buttons ==

Commerce plans can hide the floating launcher in Anhora Dashboard and open chat from an existing WordPress button. Add the CSS class `anhora-chat-open`, the data attribute `data-anhora-chat-open`, or use `#anhora-chat` as the button link. The shortcode `[anhora_chat_button]` renders a ready-to-style button with automatic English, Russian, Ukrainian, Hebrew, German, French, Spanish, Italian, Polish, Dutch, or Portuguese text based on the WordPress locale. Override it with `text="Ask us"` or locale-specific attributes such as `text_en`, `text_de`, and `text_he`. Use `aria_label` for an icon-only visual treatment. It remains hidden until an entitled widget runtime is ready, so visitors never see a button that cannot open the chat.

== Frequently Asked Questions ==

= Does this require WooCommerce? =

No. Knowledge sync and the chat widget work on any WordPress site. WooCommerce features load automatically when WooCommerce is active.

= Do I need a paid Anhora account? =

You need an Anhora account and project credentials. Paid Anhora plans, if any, are billed by the Anhora service, not by this plugin. All plugin code on WordPress.org is available without a license lock.

= Are orders stored in Anhora? =

Order history is not written into Anhora search storage. For a signed-in customer, recent orders can be passed to the chat widget for the current browser session only.

= What data leaves my WordPress site? =

See the External services section below. Nothing is sent until an administrator saves Anhora credentials.

== External services ==

This plugin relies on the Anhora service to provide the assistant, knowledge ingest, and (when enabled) the storefront chat widget. By installing the plugin, creating an Anhora account, and saving credentials under Settings → Anhora, you authorize those connections.

Service: Anhora
Website: https://anhora.net
Dashboard: https://app.anhora.net
API: https://api.anhora.net
Terms of Use: https://anhora.net/terms
Privacy Policy: https://anhora.net/privacy

What is sent, and when:

* **Knowledge sync** (when you save selected pages, click "Sync knowledge now", or the daily cron runs): page title, URL, and plain-text content of the selected published pages, plus an optional geo tag you configure.
* **WooCommerce catalog sync** (when WooCommerce is active and sync-on-save or a full catalog sync runs): product names, descriptions, categories, tags, product types, public attributes, featured/sales/rating metadata, SKUs, prices, stock flags, image URLs, and shipping/payment method titles. Card numbers and payment credentials are never sent.
* **Chat widget** (only if Embed widget is enabled and a deployment key is saved): the browser loads the Anhora widget script from anhora.net. The local Host Bridge may pass the current page URL/title, visible products, and, for a signed-in customer, name, email, country, and recent order summaries to the widget for that session.

The ingest secret stays on your server and is sent only as an HTTP header to api.anhora.net. It is never printed in the storefront.

== Changelog ==

= 0.4.2 =
* Decode WooCommerce categories, tags, and attributes before catalog ingest and keep every published variation.
* Queue one resumable full catalog snapshot after the normalized contract upgrade, without exposing an inbound sync endpoint.

= 0.4.1 =
* Sync both short and full WooCommerce product descriptions so multilingual characteristics remain searchable.
* Preserve readable paragraph, list, and table boundaries in catalog text and include the canonical product URL.

= 0.4.0 =
* Sync normalized WooCommerce categories, tags, product types, public attributes, and merchandising signals for more precise catalog search and palettes.
* Preserve the existing product-card contract while adding optional discovery metadata.

= 0.3.1 =
* Clear the previous chat identity and transcript when a WooCommerce customer logs out or switches accounts.
* Advertise Host Bridge logout cleanup support to the widget runtime.

= 0.3.0 =
* Add the `[anhora_chat_button]` shortcode and external trigger selectors for Commerce launcher customization.
* Keep shortcode buttons hidden until the entitled chat runtime is ready.
* Localize default button text and accessible labels from the page locale.

= 0.2.6 =
* Persist WooCommerce catalog snapshot progress so interrupted background jobs can resume from the last uploaded page.
* Add bounded retries, a watchdog, and a worker lock for reliable catalog reconciliation.
* Show catalog progress in Settings and provide separate resume and restart-from-beginning actions.

= 0.2.5 =
* Advertise adapter version, host protocol, and supported capabilities to the remote widget runtime.
* Advertise current-session WooCommerce order context without declaring it as an authenticated status action.
* Report searchable WordPress content and WooCommerce catalog capabilities to Anhora after setup and before manual sync.

= 0.2.3 =
* Host Bridge add-to-cart now replies with anhora:addToCartResult and no longer redirects on failure.

= 0.2.2 =
* Plugin Check: escape catalog sync errors, drop manual textdomain loading, prefix uninstall locals.

= 0.2.1 =
* WordPress.org review readiness: document Anhora as an external service, load the widget via a local script, and keep admin notices off the query string.

= 0.2.0 =
* Universal V2 integration events with idempotent upserts and deletes
* Atomic paged snapshots for WordPress knowledge and WooCommerce catalog
* Background keyset catalog reconciliation via Action Scheduler or WP Cron
* Integration-scoped ownership and bounded connector requests

= 0.1.1 =
* Batch WooCommerce catalog ingest (avoids HTTP 413 on large catalogs); retry/split on 413

= 0.1.0 =
* Initial release: embed, knowledge sync, WooCommerce catalog + bridge + shipping/payment knowledge
