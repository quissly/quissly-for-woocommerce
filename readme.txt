=== Quissly for WooCommerce ===
Contributors: quissly
Tags: woocommerce, search, ai search, autocomplete, product discovery
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.3
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Replace native WooCommerce search with Quissly AI product discovery: semantic search, voice and image search, autocomplete, and the QChat assistant.

== Description ==

Quissly for WooCommerce connects a WooCommerce store to the Quissly AI product-discovery
API. It keeps the catalog synced to Quissly, replaces native WooCommerce search with
relevance-ranked Quissly results rendered through the active theme, adds a Quick
autocomplete dropdown with voice and image search, and injects the hosted QChat widget.

The plugin is a thin proxy: Quissly returns product IDs and the plugin hydrates prices,
stock, and add-to-cart natively from WooCommerce. It does not touch cart, checkout, or
theme layout beyond the search query.

The search overlay also types example searches into its empty bar ("search bar
suggestions") so shoppers see what they can ask. Five are generated from your catalog
after the first sync; edit them (up to 20) in the plugin's Configuration page.

== Privacy ==

Each search tells Quissly who is searching, so its analytics count a returning visitor
once: `customer:<user id>` for a logged-in user, otherwise `guest:<random id>` from a
first-party cookie (`quissly_uid`, one year, HttpOnly), plus the device type and operating
system read from the browser. No name, email address or IP address is sent.

The plugin supports the WP Consent API: if your cookie banner uses it and a visitor has not
consented to statistics cookies, no cookie is set and the search is sent without a visitor
id. Searching works the same either way. Without such a banner the cookie is set on a
visitor's first search.

Shopping activity for Quissly's analytics (Quissly > Configuration, on by default): product
page views, searches, adds to cart (and to the wishlist with YITH WooCommerce Wishlist) and
paid orders - an order counts once it reaches Processing or Completed - are sent to Quissly
for the analytics in the Quissly Admin Panel, as the Quissly Shopify app does. Product ids,
quantities, prices and order totals with the visitor id above; never a name, email address,
postal address or IP address. Nothing is recorded for a visitor who has not consented to
statistics cookies (WP Consent API).

A Quissly account (managed at admin.quissly.com) is required. The plugin is free and
GPL-licensed; Quissly the service is the paid product.

== Installation ==

1. Install and activate WooCommerce.
2. Download `quissly-for-woocommerce.zip` from the [latest release](https://github.com/quissly/quissly-for-woocommerce/releases/latest) and install it under Plugins > Add New Plugin > Upload Plugin, then activate it.
3. Open Quissly in the admin menu. Quissly Setup takes three steps: connect (one click, with your email), choose a plan, and go live once your catalog has synced - the sync starts by itself.

== Updates ==

The plugin updates itself. Once a day it checks the [plugin's GitHub releases](https://github.com/quissly/quissly-for-woocommerce/releases) for a newer version, and WordPress installs it in the background with its own updater - the same one that updates plugins from WordPress.org, including putting the previous version back if the new one fails to load. Every release is signed by Quissly, and the plugin installs only a download whose signature checks out - a zip without it is refused, whoever published it. Automatic updates are always on for this plugin, and "Update now" on the Plugins page works too. Settings, credentials and sync state are kept.

WordPress installs updates in the background only where it can write the plugin's files itself (no FTP credentials needed) and automatic updates are not switched off for the site (`AUTOMATIC_UPDATER_DISABLED`). A site under version control (git) is not updated automatically - install the new release from its zip there.

== Changelog ==

= 1.0.3 =
* New: suggestions under the search bar. When the search overlay opens, the shopper sees your store's suggestions as buttons; one click searches. Automatic (Quissly picks them from your catalog) or Manual (you type up to 10).
* New: search bar suggestions are made by Quissly from your catalog, each checked to find products.
* New: shopping activity for the analytics in the Quissly Admin Panel - product views, searches, adds to cart and to the wishlist, paid orders. Never a customer's name, email, address or IP; nothing for a visitor without statistics consent. Can be switched off in Configuration.
* New: Billing offers automatic top-up, shows each extra request's price, and lets you request a refund.
* Changed: the Quissly menu shows Quissly's own icon.
* Fixed: a product with very many options or a very long name no longer stops the search bar suggestions from being made.

= 1.0.2 =
* New: the plugin updates itself. Once a day it checks for a new release, signed by Quissly, and WordPress installs it in the background.
* New: Billing in the Quissly menu - your plan, usage and invoices, and every plan change (each previewed before anything is charged).
* Changed: Quissly Setup's last step is Finish Setup, with Save changes beside it to finish setup and switch search on later; plans can be compared feature by feature.
* Changed: catalog changes are counted as delivered as soon as Quissly accepts them, so syncs finish sooner.

= 1.0.1 =
* Fixed: a product moved to the trash and restored before the next sync stayed out of search
  until it was edited again.
* Changed: runs on PHP 7.4 without relying on WordPress's PHP 8 compatibility functions.

= 1.0.0 =
* First real release. Catalog sync, AI-ranked search, Quick autocomplete with voice
  and image search, the QChat assistant, and a one-click Quissly Setup + admin dashboard —
  all built on top of the 0.0.1 scaffold.
* Fixed: a product title could execute a script in the Quick autocomplete dropdown
  (stored XSS).
* Fixed: an unpublished, pending, or private product could still be pushed to and
  found in the Quissly index; going from published back to draft now removes it.
* Fixed: a store reporting itself as a staging environment sent an invalid value and
  every request was rejected.
* Fixed: the Quick, voice, and image endpoints stayed reachable even with their
  feature switched off, with no limit on how often they could be called.
* Fixed: starting a full catalog sync from the dashboard could time out on a large
  catalog; it now hands off to the background queue after a couple of batches.
* Fixed: a corrupted signing key failed silently instead of reporting the problem.
* Fixed: the visitor-identity cookie used for search personalization was trusted
  without checking it was one this site actually issued.
* Hardened: the bearer token is now encrypted at rest and masked in the admin, the
  sync log directory name is no longer guessable, and uninstalling now cleans up a
  pending background sync instead of leaving it scheduled.
* Verified on WordPress 6.7 and 7.1, WooCommerce 9.4 and 11.1.
* Added: search bar suggestions - the overlay types example searches into its empty bar,
  in random order; generated from the catalog after the first sync, editable (up to 20).
* Added: `?quissly_debug=1` on a search URL shows which engine answered and why.
* Changed: searches identify the shopper as `customer:<id>` or `guest:<random id>`, with
  the device and operating system, and respect statistics consent through the WP Consent
  API. The WooCommerce session id is no longer used as an identity.
* Changed: the QChat widget loads its WooCommerce build (`universal_woocommerce.js`).
* Fixed: the WordPress admin bar covered the search overlay for logged-in staff.
* Fixed: a custom attribute named like a product field (e.g. "title") could clash with
  that field in Quissly; such metadata keys are now sent as `attr_<name>`.

= 0.0.1 =
* Initial scaffold.
