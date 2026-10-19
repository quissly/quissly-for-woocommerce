=== Quissly for WooCommerce ===
Contributors: quissly
Tags: woocommerce, search, ai search, autocomplete, product discovery
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
WC requires at least: 8.0
WC tested up to: 11.2
Stable tag: 1.0.21
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
* Your recent searches - the shopper's own last 5 searches beside them, kept only in their browser.

Quissly creates both from your catalog after the first sync, and you can edit them in
Quissly > Configuration. You can also pick your Most loved products, shown under them as product
cards.

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

Quissly Setup creates your Quissly account for you - nothing to copy or paste. The plugin is free
and GPL-licensed; Quissly the service is the paid product (the free search plan starts at once).

== Installation ==

1. Install and activate WooCommerce.
2. Download `quissly-for-woocommerce.zip` from the [latest release](https://github.com/quissly/quissly-for-woocommerce/releases/latest) and install it under Plugins > Add New Plugin > Upload Plugin, then activate it.
3. Open Quissly in the admin menu. Quissly Setup takes three steps: tell us about your store (your email, store name and what you sell - drafted from your products), choose a plan and how to pay for it (manual invoice or credit card), and go live once your catalog has synced - the sync starts by itself.

== Settings ==

Quissly > Configuration has six sections; click a title to open it.

* Features - switch search, the search overlay, Quick results, voice and image search, QChat and shopping analytics on or off.
* Search Examples - the example searches typed into the empty search bar: switch them on or off and edit the list. "Reset to generated" brings back the list Quissly made from your catalog.
* Search Suggestions - the buttons under the empty search bar: Automatic (Quissly picks them from your catalog) or Manual (you type up to 10), and "Show recent searches" (on by default).
* Most Loved Products - pick 8 products to show when search opens, and 4 backups: when a shown product is out of stock, the first available backup takes its place. Shown only once 12 different products are picked and 8 of them are in stock.
* Catalog data - which product attributes are sent to Quissly, so a search can match them.
* Advanced - your theme's search box (only if Quissly does not find it by itself), whether your settings are kept when the plugin is deleted, and how often the plugin checks for updates.

A feature your plan does not include - the chat on a Search plan, or search on a Chat plan - is shown off and greyed out with a link to Billing, and stays off on your shop until you add it. Your setting is kept for then.

On a store with several languages, Search Examples and Search Suggestions have a Language select: each language can have its own list, and a language without one uses your main language's.

Click Save Changes at the bottom of the page to keep your changes.

The Quissly menu also has:

* Dashboard - whether Quissly answers your store's search, the catalog sync, which features are on, and the sync log (which also says when the plugin updated, or why an update could not arrive).
* Quissly Admin Panel - Quissly's own console, signed in for you.
* Billing - your plans and this month's usage, invoices, plan changes, extra requests, automatic top-up, cancelling and refunds. Every charge is shown before you confirm it; payments go through Paddle, never through WooCommerce.

== Updates ==

The plugin updates itself. Every 30 minutes it checks the [plugin's GitHub releases](https://github.com/quissly/quissly-for-woocommerce/releases) for a newer version, and WordPress installs it in the background with its own updater - the same one that updates plugins from WordPress.org, including putting the previous version back if the new one fails to load. Every release is signed by Quissly, and the plugin installs only a download whose signature checks out - a zip without it is refused, whoever published it. Your settings, connection and catalog sync are kept.

You can change how often it checks under Quissly > Configuration > Advanced > Update check frequency: from every 30 minutes to once a day, or Never - then nothing is installed automatically and a new version waits on the Plugins page for "Update now". WordPress only runs its background tasks when someone visits the site, so a quiet site checks on its next visit.

WordPress installs updates in the background only where it can write the plugin's files itself (no FTP credentials needed) and automatic updates are not switched off for the site (`AUTOMATIC_UPDATER_DISABLED`). A site under version control (git) is not updated automatically - install the new release from its zip there.

== Frequently Asked Questions ==

= Does it need a server cron job? =

No. It uses WordPress's own scheduler, which runs when someone visits the site. A site with no visitors (for example a test site on a laptop) sends product changes and checks for updates on its next visit.

= How do I see whether Quissly answered a search? =

Add `?quissly_debug=1` to a search page's address. A small badge, shown only with that parameter, says whether Quissly or WooCommerce answered and why Quissly stepped aside.

= Does a filtered search use Quissly? =

When a shopper uses your theme's filters (category, price, attributes), WooCommerce's own search answers, so the filter is always respected. Without a filter, Quissly answers and its order is kept.

= How do I switch it off? =

Switch Search off in Quissly > Configuration > Features: WooCommerce's own search answers at once and nothing is lost. Deactivating the plugin does the same.

== Changelog ==

= 1.0.21 =
* New: Quissly Setup confirms the account's email with a 6-digit code Quissly sends to it, which Quissly now asks for before it creates an account.

= 1.0.20 =
* Fixed: when Quick Recommendations find nothing, no empty card is shown under the search bar.
* Fixed: every variation is sent with its own stock, so a product sold out in every variation is no longer shown as available, and with its own sale price whenever it is on sale.

= 1.0.19 =
* Fixed: Billing's confirmation dialog title and the automatic top-up checkbox keep their own look, whatever the admin theme styles headings and labels with.

= 1.0.18 =
* Fixed: Quick Recommendations show each product's image, name and price (in your shop's currency) again, read from your own catalog. A hidden or deleted product is left out.

= 1.0.17 =
* New: "Search overlay layout" under Features - Wide (the default) spans the screen and shows Most loved in four columns; Center is the compact panel in the middle. Phones look the same either way.
* Fixed: in the centred panel, Most loved shows six cards of the same size in three columns instead of cutting one off.

= 1.0.16 =
* Fixed: the example searches Quissly made from your catalog are typed into the search bar from the start, even if the list was saved empty before they were made - no need to press "Reset to generated" first. Configuration shows the same list.

= 1.0.15 =
* Fixed: on a theme with more than one search box (for example one in the header on computers and another in the mobile menu), the Quissly search window now opens from every one of them, not only the first.

= 1.0.14 =
* Added: "Your recent searches" - when search opens, the shopper's last 5 searches show beside the suggestions, each with a bin to remove it. Kept only in the shopper's own browser; switch it off in Configuration > Search Suggestions.
* Changed: the search bar looks like Quissly's other apps - one white panel near the top of the page, a small x that clears the text, and Escape or a click outside to close it.
* Changed: a feature your plan does not include is shown greyed out with a link to Billing, and stays off on your shop until you add it.
* Fixed: on a store with WPML, some products were sent to Quissly with the address and categories of another language, so the chat could link to the wrong language's page. Products are now always sent in their own language.

= 1.0.13 =
* Changed: no change in how the plugin works - a release that checks automatic updates.

= 1.0.12 =
* Added: the sync log (Quissly > Dashboard) says when the plugin was updated, and why an update could not be installed - for example GitHub not answering your server, or automatic updates switched off for the site.
* Fixed: a server that GitHub limits (common on shared hosting) finds new versions anyway.
* Changed: the Plugins screen says "Automatic updates on" for Quissly, with a link to where you change it, instead of WordPress's "Enable auto-updates".

= 1.0.11 =
* Added: the plugin tells Quissly which version it runs, the version before it and when it was updated - when the store is connected, after each update and once a day. It sends nothing about shoppers.

= 1.0.10 =
* Added: Most loved products - pick 8 products and 4 backups in Configuration, shown as cards under the search suggestions when search opens. A product picked for both lists, too few picks, or fewer than 8 in stock hides the block, and Configuration says what to fix.
* Changed: Quissly Setup follows Quissly's other apps: "Tell us about your store" asks for your store name and what you sell (both required, drafted from your store) and says why Save is not available yet; a "What happens next" list shows the three steps.
* Added: on "Choose a plan", pick how a paid plan is paid - Manual invoice (the default, no payment page) or Credit card (the secure checkout, as before).
* Changed: "Choose a plan" describes each option ("A smarter search bar", "An AI shopping assistant"); once a plan is chosen, it is changed in Billing after setup.
* Changed: "Go live" says in one sentence what is left, lists Quissly Account, Smart AI Product Search, AI Shopping Assistant and Your Catalog, and offers "Do this later".
* Fixed: Finish Setup waits until your account is fully set up, and on a chat-only plan it switches the AI shopping assistant on instead of search.

= 1.0.9 =
* Changed: a code comment tidied up. No change in how the plugin works.

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
