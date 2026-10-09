=== Quissly for WooCommerce ===
Contributors: quissly
Tags: woocommerce, search, ai search, autocomplete, product discovery
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.8
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Replace native WooCommerce search with Quissly AI product discovery: semantic search, voice and image search, autocomplete, and the QChat assistant.

== Description ==

Quissly for WooCommerce connects your store to Quissly's AI product search. It keeps your
catalog in sync with Quissly, answers your store's searches with Quissly's results (shown in
your own theme), and can add a Quick results dropdown with voice and image search and the
QChat shopping assistant.

Quissly only decides which products a search shows and in what order. Prices, stock, the
cart and checkout stay WooCommerce's own, and your theme's layout is not changed.

When a shopper opens search, Quissly can also help them start:

* Search Examples - example searches typed letter by letter into the empty search bar.
* Search Suggestions - buttons under the empty search bar; a click searches for it.

Quissly creates both from your catalog after the first sync, and you can edit them in
Quissly > Configuration.

Stores in several languages (WPML or Polylang) are supported: every language's pages show
that language's products, and each language can have its own Search Examples and Search
Suggestions.

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
for the analytics in the Quissly Admin Panel: product ids, quantities, prices and order
totals with the visitor id above - never a name, email address, postal address or IP address. Nothing is recorded for a visitor who has not consented to
statistics cookies (WP Consent API).

A Quissly account (managed at admin.quissly.com) is required. The plugin is free and
GPL-licensed; Quissly the service is the paid product.

== Installation ==

1. Install and activate WooCommerce.
2. Download `quissly-for-woocommerce.zip` from the [latest release](https://github.com/quissly/quissly-for-woocommerce/releases/latest) and install it under Plugins > Add New Plugin > Upload Plugin, then activate it.
3. Open Quissly in the admin menu. Quissly Setup takes three steps: connect (one click, with your email), choose a plan, and go live once your catalog has synced - the sync starts by itself.

== Settings ==

Quissly > Configuration has five sections; click a title to open it.

* Features - switch search, the search overlay, Quick results, voice and image search, QChat and shopping analytics on or off.
* Search Examples - the example searches typed into the empty search bar: switch them on or off and edit the list. "Reset to generated" brings back the list Quissly made from your catalog.
* Search Suggestions - the buttons under the empty search bar: Automatic (Quissly picks them from your catalog) or Manual (you type up to 10).
* Catalog data - which product attributes are sent to Quissly, so a search can match them.
* Advanced - your theme's search box (only if Quissly does not find it by itself), whether your settings are kept when the plugin is deleted, and how often the plugin checks for updates.

On a store with several languages, Search Examples and Search Suggestions have a Language select: each language can have its own list, and a language without one uses your main language's.

Click Save Changes at the bottom of the page to keep your changes.

== Updates ==

The plugin updates itself. Every 30 minutes it checks the [plugin's GitHub releases](https://github.com/quissly/quissly-for-woocommerce/releases) for a newer version, and WordPress installs it in the background with its own updater - the same one that updates plugins from WordPress.org, including putting the previous version back if the new one fails to load. Every release is signed by Quissly, and the plugin installs only a download whose signature checks out - a zip without it is refused, whoever published it. Your settings, connection and catalog sync are kept.

You can change how often it checks under Quissly > Configuration > Advanced > Update check frequency: from every 30 minutes to once a day, or Never - then nothing is installed automatically and a new version waits on the Plugins page for "Update now". WordPress only runs its background tasks when someone visits the site, so a quiet site checks on its next visit.

WordPress installs updates in the background only where it can write the plugin's files itself (no FTP credentials needed) and automatic updates are not switched off for the site (`AUTOMATIC_UPDATER_DISABLED`). A site under version control (git) is not updated automatically - install the new release from its zip there.

== Changelog ==

= 1.0.8 =
* Changed: with Search Suggestions on Automatic, Configuration shows the buttons your shop shows now (per language on a multilingual store).
* Changed: your Search Suggestions choice and Manual buttons are kept in your Quissly account, next to your Search Examples. Buttons you typed in an earlier version keep showing and move to Quissly the next time you save Configuration.
* Fixed: Search Examples and Automatic Search Suggestions could stay hidden on the shop until someone opened Quissly > Configuration once after connecting.

= 1.0.7 =
* Changed: Configuration has a section each for "Search Examples" (the example searches typed into the search bar) and "Search Suggestions" (the buttons under it), named as in Quissly's other apps.
* Changed: Search Examples are listed one per row with a Remove button; manual Search Suggestions get a field each, and the next field appears when you fill the last one.
* Changed: Search Suggestions are chosen with an Automatic / Manual dropdown, and "Show typing suggestions" is a checkbox.
* Changed: Configuration's dropdowns look and open like WooCommerce's own.
* Changed: on a store with several languages, a language without its own Search Examples or Search Suggestions uses your main language's.

= 1.0.6 =
* New: choose how often Quissly checks for a new version, in Configuration > Advanced - every 30 minutes (the default) to once a day, every 5 minutes for testing, or never (then a new version waits on the Plugins page for "Update now").
* Fixed: Catalog data says "on 1 product", not "on 1 products".

= 1.0.5 =
* New: stores in several languages (WPML or Polylang). Searches on every language's pages show that language's products, Quick and the chat's Add to Cart use them too, and your catalog is sent to Quissly once, in your main language.
* New: search bar suggestions and your own search examples have a list per language, chosen with a Language select in Configuration. Shoppers see their language's list, never another language's.
* Changed: page-builder shortcodes are left out of the product descriptions sent to Quissly.
* Changed: updates are checked for every 30 minutes.

= 1.0.4 =
* New: updates arrive within minutes of a release instead of up to a day later.
* Changed: Configuration is laid out in sections - Features, Search bar suggestions, Catalog data and Advanced - each opening and closing on a click, with a description under every setting.
* Changed: search bar suggestions and your own search examples are edited as a list of tags: type one and press Add, click × to remove it, and "Reset to generated" brings back the list Quissly made from your catalog.
* Changed: "Search suggestions" is now called "Search Examples".

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
* First release: catalog sync, AI-ranked search, Quick autocomplete with voice and image
  search, the QChat assistant, and a one-click Quissly Setup with an admin dashboard.
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
* Preview.
