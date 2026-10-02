=== Quissly for WooCommerce ===
Contributors: quissly
Tags: woocommerce, search, ai search, autocomplete, product discovery
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.1
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

A Quissly account (managed at admin.quissly.com) is required. The plugin is free and
GPL-licensed; Quissly the service is the paid product.

== Installation ==

1. Install and activate WooCommerce.
2. Install and activate Quissly for WooCommerce.
3. Follow the setup wizard to connect your Quissly account and run the initial sync.

== Changelog ==

= 1.0.1 =
* Fixed: a product moved to the trash and restored before the next sync stayed out of search
  until it was edited again.
* Changed: runs on PHP 7.4 without relying on WordPress's PHP 8 compatibility functions.

= 1.0.0 =
* First real release. Catalog sync, AI-ranked search, Quick autocomplete with voice
  and image search, the QChat assistant, and a guided setup wizard + admin dashboard —
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
