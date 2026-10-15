<?php
/**
 * Immersive search overlay — the fallback for a theme with no search box of its own.
 *
 * @package Quissly_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Mirrors the Magento plugin's search overlay — the one place this plugin adds its own UI
 * to the storefront.
 *
 * Presentation only: it changes how the search box behaves on click, never which
 * products come back or how the results page renders.
 *
 * Renders ONLY where Quissly is actually answering searches (search enabled AND the
 * first-sync gate is open) — drawing a Quissly search surface over a storefront Quissly
 * is not running would promise something the page does not deliver.
 *
 * The merchant's toggle (quissly_enable_overlay) governs exactly ONE thing: whether an
 * EXISTING theme search box gets taken over. Whether a theme HAS a search box at all is
 * a browser-side fact the server cannot know, so the markup is always emitted wherever
 * Quissly is live, and the JS decides at runtime:
 *
 *   theme HAS a search box  -> the overlay takes it over only if the merchant asked
 *                              (quissly_enable_overlay), else it does nothing
 *   theme has NO search box -> the overlay becomes the search, toggle or not, because
 *                              the alternative is a shop that cannot be searched at all
 *
 * A merchant switching the toggle off is declining a presentation change, not asking
 * for their only search entry point to disappear.
 */
class Quissly_Overlay {

	/**
	 * Hook rendering + asset enqueuing.
	 */
	public function register() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'wp_footer', array( $this, 'render' ) );
	}

	/**
	 * Whether the overlay should render at all, for the current request.
	 *
	 * @return bool
	 */
	public function should_render() {
		if ( is_admin() ) {
			return false;
		}

		return (bool) Quissly_Settings::get( 'quissly_enable_search' ) && Quissly_Sync_State::is_initial_sync_complete();
	}

	/**
	 * Enqueue the overlay script (config passed via wp_localize_script; CSP-safe, no
	 * inline JS). No-ops when should_render() is false, same gate the markup itself uses.
	 */
	public function enqueue() {
		if ( ! $this->should_render() ) {
			return;
		}

		wp_register_script(
			'quissly-overlay',
			QUISSLY_PLUGIN_URL . 'assets/js/overlay.js',
			array(),
			QUISSLY_VERSION,
			true
		);
		wp_localize_script( 'quissly-overlay', 'quisslyOverlay', $this->config() );
		wp_enqueue_script( 'quissly-overlay' );
	}

	/**
	 * Runtime config passed to the JS.
	 *
	 * @return array
	 */
	private function config() {
		$overrides = array_values(
			array_filter(
				array(
					(string) Quissly_Settings::get( 'quissly_search_selector_desktop' ),
					(string) Quissly_Settings::get( 'quissly_search_selector_mobile' ),
				)
			)
		);

		return array(
			'immersive'      => (bool) Quissly_Settings::get( 'quissly_enable_overlay' ),
			'mountSelector'  => (string) Quissly_Settings::get( 'quissly_overlay_mount_selector' ),
			'resultsUrl'     => esc_url_raw( add_query_arg( 'post_type', 'product', home_url( '/' ) ) ),
			'searchSelectors' => array_merge( $overrides, Quissly_Assets::SELECTOR_PRIORITY ),
			'labelTitle'      => __( 'Search', 'quissly-for-woocommerce' ),
			'labelPlaceholder' => __( 'Search products…', 'quissly-for-woocommerce' ),
			'labelClear'       => __( 'Clear search', 'quissly-for-woocommerce' ),
			// Search bar suggestions, typed into the empty bar (Quissly_Search_Suggestions).
			'suggestions'      => Quissly_Search_Suggestions::for_storefront(),
			// ...and as buttons under the bar while it is empty (the merchant's switch, on by default).
			'suggestionsPanel' => (bool) Quissly_Settings::get( 'quissly_overlay_suggestions' ),
			// The buttons' own list: Quissly's generated one (automatic, the default) or the
			// merchant's (manual) - never the typing list (the Shopify app's "Search suggestions").
			'chips'            => self::chips(),
			/* translators: %s: the store's name. */
			'labelSuggestions' => '' !== trim( (string) get_bloginfo( 'name' ) ) ? sprintf( __( '%s suggestions', 'quissly-for-woocommerce' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ) : __( 'Suggestions', 'quissly-for-woocommerce' ),
			// The merchant's Most loved products, under the buttons (Quissly_Most_Loved; none while off).
			'mostLoved'        => Quissly_Most_Loved::for_storefront(),
			/* translators: %s: the store's name. */
			'labelMostLoved'   => '' !== trim( (string) get_bloginfo( 'name' ) ) ? sprintf( __( '%s Most loved', 'quissly-for-woocommerce' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ) : __( 'Most loved', 'quissly-for-woocommerce' ),
			/* translators: %s: a rating such as 4.5. */
			'labelRating'      => __( '%s out of 5', 'quissly-for-woocommerce' ),
			// Recent searches: this shopper's own, kept in their browser (overlay.js).
			'recentSearches'   => (bool) Quissly_Settings::get( 'quissly_overlay_recent' ),
			'labelRecent'      => __( 'Your recent searches', 'quissly-for-woocommerce' ),
			/* translators: %s: a search the shopper made earlier. */
			'labelRemoveRecent' => __( 'Remove %s from recent searches', 'quissly-for-woocommerce' ),
		);
	}

	/**
	 * The suggestion buttons: the merchant's own list (manual), or what Quissly generated for
	 * the site's language (automatic, the default - none until Quissly has generated some).
	 * Nothing while the section is switched off.
	 *
	 * @return string[]
	 */
	private static function chips() {
		if ( ! Quissly_Settings::get( 'quissly_overlay_suggestions' ) ) {
			return array();
		}
		// Manual or generated, as Quissly holds the choice (the Shopify app's keys).
		return Quissly_Search_Suggestions::buttons_for_storefront();
	}

	/**
	 * Render the hidden config host element + styles. The JS (enqueued separately) reads
	 * its config from wp_localize_script's global, not from data attributes — the host
	 * element here only needs to exist so the JS has something to feature-detect against
	 * (mirrors Magento's `[data-quissly-overlay]` presence check).
	 */
	public function render() {
		if ( ! $this->should_render() ) {
			return;
		}
		?>
		<div data-quissly-overlay style="display:none"></div>
		<style>
			/* The bar sits at the top and spans the page; Quick's suggestions fill the
			   space below it (.quissly-overlay__results). */
			/* 100000: above WordPress's admin bar (99999), which logged-in staff see. */
			.quissly-overlay {
				position: fixed; inset: 0; z-index: 100000;
				display: none; align-items: flex-start; justify-content: center;
				padding: 24px clamp(16px, 3vw, 48px);
				overflow-y: auto;
				background: rgba(20, 20, 25, 0.45);
				-webkit-backdrop-filter: blur(6px); backdrop-filter: blur(6px);
				opacity: 0; transition: opacity .16s ease;
			}
			.quissly-overlay.is-open { display: flex; opacity: 1; }
			html.quissly-overlay-open { overflow: hidden; }
			.quissly-overlay__panel {
				width: 100%;
				display: flex; flex-direction: column; gap: 18px;
				transform: translateY(-8px) scale(.985);
				transition: transform .18s cubic-bezier(.2, .8, .3, 1);
			}
			.quissly-overlay.is-open .quissly-overlay__panel { transform: none; }
			.quissly-overlay__bar { display: flex; align-items: center; gap: 14px; }
			/* Above the bar's drop shadow, which would otherwise grey the card's top. */
			.quissly-overlay__results { position: relative; z-index: 1; }
			.quissly-overlay__results:empty { display: none; }
			/* Search bar suggestions as buttons under the bar, while it is empty. */
			.quissly-overlay__suggest {
			    position: relative; z-index: 1;
			    box-sizing: border-box;
			    padding: 20px 26px 22px;
			    background: #fff;
			    border-radius: 22px;
			    box-shadow: 0 20px 55px rgba(0, 0, 0, .22), 0 2px 8px rgba(0, 0, 0, .1);
			}
			.quissly-overlay__suggest[hidden] { display: none; }
			.quissly-overlay .quissly-overlay__suggest-title {
			    display: flex; align-items: center; gap: 10px;
			    margin: 0 0 14px; padding: 0 0 12px;
			    border-bottom: 1px solid #ececf1;
			    font-family: inherit; font-size: 13px; font-weight: 700;
			    letter-spacing: .14em; text-transform: uppercase;
			    color: #1d1d22;
			}
			.quissly-overlay .quissly-overlay__suggest-title svg {
			    flex: 0 0 auto; width: 18px; height: 18px;
			    fill: none; stroke: currentColor; stroke-width: 1.8;
			    stroke-linecap: round; stroke-linejoin: round;
			}
			.quissly-overlay__chips { display: flex; flex-wrap: wrap; gap: 10px; }
			.quissly-overlay .quissly-overlay__chip {
			    display: inline-flex; align-items: center; gap: 10px;
			    margin: 0; padding: 9px 14px 9px 16px;
			    background: #fff; color: #1d1d22;
			    border: 1px solid #ececf1; border-radius: 999px;
			    box-shadow: 0 2px 6px rgba(0, 0, 0, .06);
			    font-family: inherit; font-size: 15px; font-weight: 400; line-height: 1.2;
			    text-transform: none; letter-spacing: normal;
			    cursor: pointer;
			    transition: border-color .15s ease, box-shadow .15s ease, transform .15s ease;
			}
			.quissly-overlay .quissly-overlay__chip svg {
			    flex: 0 0 auto; width: 15px; height: 15px;
			    fill: none; stroke: currentColor; stroke-width: 2.2;
			    stroke-linecap: round; stroke-linejoin: round;
			}
			.quissly-overlay .quissly-overlay__chip:hover {
			    background: #fff; color: #1d1d22;
			    border-color: #d6d6de; transform: translateY(-1px);
			    box-shadow: 0 6px 14px rgba(0, 0, 0, .1);
			}
			.quissly-overlay .quissly-overlay__chip:focus-visible {
			    outline: none; box-shadow: 0 0 0 3px rgba(99, 102, 241, .35);
			}
			/* Most loved: the merchant's picks under the buttons (the Shopify app's cards), in 1, 2 or
			   4 columns - never 3, which leaves 8 cards as 3 + 3 + 2. */
			.quissly-overlay__loved { container-type: inline-size; }
			/* "Your recent searches" beside "<Store> suggestions" (overlay.js renderRecent): two columns
			when both show, one under the other on a phone; a recent search is a chip with its own bin. */
			.quissly-overlay__lists {
			    display: grid; grid-template-columns: minmax(0, 1fr); gap: 22px;
			}
			.quissly-overlay__lists[hidden], .quissly-overlay__group[hidden] { display: none; }
			@media (min-width: 700px) {
			    .quissly-overlay__lists.is-split { grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 40px; }
			}
			.quissly-overlay__lists:not([hidden]) + .quissly-overlay__loved { margin-top: 22px; }
			.quissly-overlay .quissly-overlay__chip--recent { gap: 0; padding: 0; max-width: 100%; }
			.quissly-overlay .quissly-overlay__recent-use,
			.quissly-overlay .quissly-overlay__recent-use:hover,
			.quissly-overlay .quissly-overlay__recent-remove,
			.quissly-overlay .quissly-overlay__recent-remove:hover {
			    display: inline-flex !important; align-items: center; gap: 10px;
			    width: auto; min-width: 0; height: auto; min-height: 0; margin: 0;
			    background: transparent !important; border: 0 !important; border-radius: 999px;
			    box-shadow: none !important; color: inherit !important; cursor: pointer;
			    font: inherit; line-height: 1.2; text-transform: none; letter-spacing: normal;
			}
			.quissly-overlay .quissly-overlay__recent-use { padding: 9px 6px 9px 16px; }
			.quissly-overlay .quissly-overlay__recent-use span {
			    overflow: hidden; max-width: 220px; white-space: nowrap; text-overflow: ellipsis;
			}
			.quissly-overlay .quissly-overlay__recent-remove,
			.quissly-overlay .quissly-overlay__recent-remove:hover { padding: 9px 14px 9px 6px; color: #6b6b70 !important; }
			.quissly-overlay .quissly-overlay__recent-remove:hover { color: #d92d20 !important; }
			.quissly-overlay .quissly-overlay__recent-use:focus-visible,
			.quissly-overlay .quissly-overlay__recent-remove:focus-visible { outline: 2px solid #111; outline-offset: -2px; }
			@media (max-width: 600px) {
			    .quissly-overlay .quissly-overlay__recent-use { padding: 8px 4px 8px 14px; }
			    .quissly-overlay .quissly-overlay__recent-remove,
			    .quissly-overlay .quissly-overlay__recent-remove:hover { padding: 8px 12px 8px 4px; }
			}
			.quissly-overlay .quissly-overlay__loved-title {
			    margin: 0 0 18px; padding: 0 0 14px;
			    border-bottom: 1px solid #ececf1; text-align: center;
			    font-family: inherit; font-size: 13px; font-weight: 700; line-height: 1.3;
			    letter-spacing: .14em; text-transform: uppercase; color: #1d1d22;
			}
			.quissly-overlay__loved-grid { display: grid; gap: 16px; grid-template-columns: 1fr; }
			@container (min-width: 536px) { .quissly-overlay__loved-grid { grid-template-columns: repeat(2, 1fr); } }
			@container (min-width: 1088px) { .quissly-overlay__loved-grid { grid-template-columns: repeat(4, 1fr); } }
			.quissly-overlay a.quissly-overlay__loved-card {
			    display: flex; align-items: center; gap: 16px; padding: 12px;
			    border-radius: 12px; background: #fff; box-shadow: 0 1px 6px rgba(0, 0, 0, .08);
			    text-decoration: none !important; color: #1d1d22 !important;
			    transition: box-shadow .15s;
			}
			.quissly-overlay a.quissly-overlay__loved-card:hover,
			.quissly-overlay a.quissly-overlay__loved-card:focus-visible { box-shadow: 0 2px 12px rgba(0, 0, 0, .16); outline: none; }
			.quissly-overlay__loved-media { flex: 0 0 80px; width: 80px; height: 80px; }
			.quissly-overlay__loved-media img { display: block; width: 100%; height: 100%; object-fit: contain; }
			.quissly-overlay__loved-info { display: flex; flex-direction: column; gap: 6px; flex: 1; min-width: 0; }
			.quissly-overlay__loved-name {
			    font-size: 15px; line-height: 1.35;
			    display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;
			}
			.quissly-overlay__loved-meta { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
			.quissly-overlay__loved-price { font-size: 15px; font-weight: 700; line-height: 1.3; }
			.quissly-overlay__loved-rating { display: flex; align-items: center; gap: 4px; font-size: 12px; color: #444; white-space: nowrap; }
			/* Five grey stars with the filled share of five dark ones laid over them. */
			.quissly-overlay__loved-stars { position: relative; display: inline-block; letter-spacing: 1px; color: #d4d4d8; }
			.quissly-overlay__loved-stars span { position: absolute; top: 0; left: 0; bottom: 0; overflow: hidden; white-space: nowrap; color: #1d1d22; }
			.quissly-overlay__loved-variants {
			    align-self: flex-start; padding: 3px 10px;
			    border: 1px solid #d4d4da; border-radius: 999px;
			    font-size: 13px; line-height: 1.3;
			}
			@media (max-width: 600px) {
			    .quissly-overlay__suggest { padding: 16px 16px 18px; border-radius: 18px; }
			    .quissly-overlay .quissly-overlay__chip { font-size: 14px; padding: 8px 12px 8px 14px; }
			}
			/* !important, same reasoning as .quissly-overlay__input: themes style bare
			   buttons heavily (block themes give every button the accent colour). */
			.quissly-overlay .quissly-overlay__field {
				flex: 1 1 auto; min-width: 0;
				position: relative;
				box-sizing: border-box;
				display: flex; align-items: center; gap: 14px;
				padding: 0 14px 0 26px;
				background: #fff;
				border: 0;
				border-radius: 999px;
				box-shadow: 0 20px 55px rgba(0, 0, 0, .3), 0 2px 8px rgba(0, 0, 0, .14);
				transition: box-shadow .18s ease, transform .18s ease;
			}
			.quissly-overlay .quissly-overlay__field:focus-within {
				transform: translateY(-1px);
				box-shadow: 0 26px 65px rgba(0, 0, 0, .36), 0 0 0 5px rgba(248, 246, 243, .6);
			}
			.quissly-overlay .quissly-overlay__icon {
				flex: 0 0 auto;
				width: 21px; height: 21px;
				fill: none; stroke: #9a9aa4; stroke-width: 2;
				stroke-linecap: round; stroke-linejoin: round;
				transition: stroke .18s ease;
			}
			.quissly-overlay .quissly-overlay__field:focus-within .quissly-overlay__icon { stroke: #4b4b57; }
			.quissly-overlay .quissly-overlay__input,
			.quissly-overlay .quissly-overlay__input:focus,
			.quissly-overlay .quissly-overlay__input:hover {
				-webkit-appearance: none !important;
				appearance: none !important;
				box-sizing: border-box !important;
				flex: 1 1 auto !important;
				width: 100% !important; min-width: 0 !important;
				height: auto !important; min-height: 0 !important;
				margin: 0 !important;
				padding: 21px 0 !important;
				background: transparent !important;
				border: 0 !important;
				border-radius: 0 !important;
				box-shadow: none !important;
				outline: none !important;
				font-family: inherit !important;
				font-size: 20px !important;
				line-height: 1.3 !important;
				font-weight: 400 !important;
				color: #1d1d22 !important;
				letter-spacing: normal !important;
			}
			.quissly-overlay .quissly-overlay__input::placeholder { color: #a3a3ad !important; opacity: 1 !important; }
			.quissly-overlay-trigger {
				display: inline-flex; align-items: center; justify-content: center;
				width: 44px; height: 44px; padding: 0;
				background: transparent; color: inherit;
				border: 0; border-radius: 50%;
				cursor: pointer; vertical-align: middle;
				transition: background .16s ease, transform .16s ease;
			}
			.quissly-overlay-trigger svg {
				width: 22px; height: 22px; display: block;
				fill: none; stroke: currentColor; stroke-width: 2;
				stroke-linecap: round; stroke-linejoin: round;
			}
			.quissly-overlay-trigger[hidden] { display: none; }
			.quissly-overlay-trigger:hover { background: rgba(0, 0, 0, .06); }
			.quissly-overlay-trigger:focus-visible { outline: none; box-shadow: 0 0 0 3px rgba(99, 102, 241, .35); }
			.quissly-overlay-trigger--inline { margin: 0 6px; vertical-align: middle; }
			.quissly-overlay-trigger--floating {
				position: fixed; bottom: 18px; left: 18px; z-index: 9998;
				width: 46px; height: 46px;
				background: #fff; color: #4b4b57;
				box-shadow: 0 4px 16px rgba(0, 0, 0, .18), 0 1px 3px rgba(0, 0, 0, .1);
			}
			.quissly-overlay-trigger--floating:hover {
				background: #fff; transform: translateY(-1px);
				box-shadow: 0 8px 22px rgba(0, 0, 0, .24), 0 1px 3px rgba(0, 0, 0, .1);
			}
			@media (max-width: 480px) {
				.quissly-overlay-trigger { width: 40px; height: 40px; }
				.quissly-overlay-trigger--inline { margin: 0 2px; }
				.quissly-overlay-trigger--floating { bottom: 12px; left: 12px; }
			}
			.quissly-overlay__actions { display: flex; align-items: center; gap: 4px; flex: 0 0 auto; }
			.quissly-overlay__actions:not(:empty) { margin-left: 4px; padding-left: 12px; border-left: 1px solid #e8e8ee; }
			/* The voice/image widgets mount their own buttons here; restyle them to belong
			   to the bar instead of the header they were designed for. Hard-reset with
			   !important, same reasoning as .quissly-overlay__input: themes style bare
			   <button> elements heavily (border, background, padding, box-shadow), and
			   this is server-rendered CSS, not a stylesheet a theme can simply lose a
			   specificity fight against. */
			.quissly-overlay__actions .quissly-voice-button,
			.quissly-overlay__actions .quissly-image-button {
				-webkit-appearance: none !important;
				appearance: none !important;
				display: flex !important; align-items: center !important; justify-content: center !important;
				box-sizing: border-box !important;
				width: 40px !important; height: 40px !important; min-width: 0 !important; min-height: 0 !important;
				padding: 0 !important; margin: 0 !important;
				background: transparent !important; background-image: none !important;
				border: 0 !important; border-radius: 50% !important; box-shadow: none !important; outline: none !important;
				color: #7a7a86 !important; line-height: 1 !important; cursor: pointer !important;
				font-size: 0 !important; text-transform: none !important; letter-spacing: normal !important;
				transition: background .15s ease, color .15s ease;
			}
			.quissly-overlay__actions .quissly-voice-button svg,
			.quissly-overlay__actions .quissly-image-button svg {
				width: 20px !important; height: 20px !important; display: block !important;
			}
			.quissly-overlay__actions .quissly-voice-button:hover,
			.quissly-overlay__actions .quissly-image-button:hover {
				background: #f1f1f6 !important; color: #4b4b57 !important;
			}
			.quissly-overlay__actions .quissly-voice-button.recording {
				background: #fdecec !important; color: #d64545 !important;
				animation: quissly-overlay-pulse 1.2s ease-in-out infinite;
			}
			@keyframes quissly-overlay-pulse {
				50% { background: #f9d5d5; }
			}
			/* Status text sits under the bar so it never resizes the capsule. */
			.quissly-overlay__actions .quissly-voice-notice,
			.quissly-overlay__actions .quissly-image-notice {
				position: absolute; left: 0; top: 100%;
				margin-top: 12px; width: 100%;
				text-align: center; font-size: 13px;
				color: rgba(255, 255, 255, .92);
				text-shadow: 0 1px 3px rgba(0, 0, 0, .55);
			}
			/* Phones: a slimmer bar, the same height as the close button beside it
			   (after the input reset above, which it has to override). */
			@media (max-width: 600px) {
				.quissly-overlay { padding: 12px; }
				.quissly-overlay__bar { gap: 8px; }
				.quissly-overlay .quissly-overlay__field { padding: 0 8px 0 18px; gap: 10px; }
				.quissly-overlay .quissly-overlay__input,
				.quissly-overlay .quissly-overlay__input:focus,
				.quissly-overlay .quissly-overlay__input:hover {
					padding: 15px 0 !important; font-size: 17px !important;
				}
			}
			.quissly-overlay .quissly-overlay__input::-webkit-search-cancel-button,
			.quissly-overlay .quissly-overlay__input::-webkit-search-decoration {
				-webkit-appearance: none; display: none;
			}
			/* The Quissly Shopify app's search overlay look (2026-10-13), shared by every plugin: one
			   white panel near the top - the bar flush at its top, the suggestions under a hairline -
			   over a dimmed, slightly blurred page; no close button (Escape or the backdrop closes), a
			   round x that only clears the text, and quiet line icons for voice and image behind a
			   hairline. Comes last so it wins over the earlier rules. */
			.quissly-overlay {
			    padding: 8vh 16px 16px;
			    overflow: hidden;
			    background: rgba(17, 17, 20, .5);
			    -webkit-backdrop-filter: blur(3px); backdrop-filter: blur(3px);
			    color: #111;
			    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
			}
			.quissly-overlay *, .quissly-overlay *::before, .quissly-overlay *::after {
			    box-sizing: border-box;
			}
			.quissly-overlay__panel {
			    width: 100%; max-width: 760px; max-height: 100%;
			    display: flex; flex-direction: column; gap: 0;
			    overflow: hidden;
			    background: #fff;
			    border-radius: 18px;
			    box-shadow: 0 24px 80px rgba(0, 0, 0, .28);
			    color: #111;
			    transform: translateY(12px); opacity: 0;
			    transition: transform .15s ease, opacity .15s ease;
			}
			.quissly-overlay.is-open .quissly-overlay__panel { transform: none; opacity: 1; }
			.quissly-overlay__bar {
			    display: flex; align-items: center; flex-shrink: 0; gap: 0;
			    min-height: 60px; padding: 0 10px 0 0;
			    background: #fff;
			}
			.quissly-overlay .quissly-overlay__field,
			.quissly-overlay .quissly-overlay__field:focus-within {
			    display: flex; align-items: center; flex: 1 1 auto; gap: 0; min-width: 0;
			    padding: 0 6px 0 20px;
			    background: transparent; border: 0; border-radius: 0; box-shadow: none; transform: none;
			}
			.quissly-overlay .quissly-overlay__icon,
			.quissly-overlay .quissly-overlay__field:focus-within .quissly-overlay__icon {
			    flex: 0 0 20px; width: 20px; height: 20px;
			    fill: none; stroke: #6b6b6b; stroke-width: 1.5; stroke-linecap: round; stroke-linejoin: round;
			}
			.quissly-overlay .quissly-overlay__input,
			.quissly-overlay .quissly-overlay__input:focus,
			.quissly-overlay .quissly-overlay__input:hover {
			    padding: 18px 12px !important;
			    font-family: inherit !important; font-size: 17px !important; line-height: 1.35 !important;
			    color: #111 !important;
			}
			.quissly-overlay .quissly-overlay__input::placeholder {
			    color: #8a8a8a !important;
			    opacity: 1 !important;
			}
			/* The x: clears the text, never closes the overlay; hidden while the bar is empty. */
			.quissly-overlay .quissly-overlay__clear {
			    display: flex; align-items: center; justify-content: center; flex-shrink: 0;
			    width: 40px; min-width: 40px; height: 44px; min-height: 0; padding: 0; margin: 0;
			    border: 0 !important; border-radius: 50%; background: transparent !important;
			    box-shadow: none !important; color: #6b6b70 !important; cursor: pointer;
			}
			.quissly-overlay .quissly-overlay__clear[hidden] { display: none !important; }
			.quissly-overlay .quissly-overlay__clear:focus-visible { outline: none; }
			.quissly-overlay .quissly-overlay__clear:focus-visible svg { box-shadow: 0 0 0 2px #111; }
			.quissly-overlay .quissly-overlay__clear svg {
			    display: block; width: 36px; height: 36px; padding: 9px; border-radius: 50%;
			    background: transparent;
			    fill: none;
			    stroke: currentColor;
			    stroke-width: 1.5;
			    stroke-linecap: round;
			    transition: background .15s;
			}
			.quissly-overlay .quissly-overlay__clear:hover { color: #111 !important; }
			@media (hover: hover) {
			    .quissly-overlay .quissly-overlay__clear:hover svg { background: #f0f0f2; }
			}
			/* Voice and image: line icons behind a hairline, a red disc while recording. */
			.quissly-overlay .quissly-overlay__actions,
			.quissly-overlay .quissly-overlay__actions:not(:empty) {
			    display: flex !important; align-items: center !important; flex-shrink: 0 !important;
			    gap: 0 !important; margin: 0 !important; padding: 0 !important; border: 0 !important;
			}
			.quissly-overlay .quissly-overlay__actions:not(:empty)::before {
			    content: ""; display: block; width: 1px; height: 24px; margin: 0 6px 0 2px; background: #e3e3e6;
			}
			.quissly-overlay .quissly-overlay__actions .quissly-voice-button,
			.quissly-overlay .quissly-overlay__actions .quissly-image-button,
			.quissly-overlay .quissly-overlay__actions .quissly-voice-button:hover,
			.quissly-overlay .quissly-overlay__actions .quissly-image-button:hover {
			    display: flex !important;
			    align-items: center !important;
			    justify-content: center !important;
			    flex-shrink: 0 !important;
			    width: 44px !important;
			    min-width: 44px !important;
			    height: 44px !important;
			    min-height: 0 !important;
			    padding: 0 !important; margin: 0 !important;
			    border: 0 !important;
			    border-radius: 50% !important;
			    background: transparent !important;
			    background-image: none !important;
			    box-shadow: none !important; color: #2a2a2d !important; cursor: pointer;
			}
			.quissly-overlay .quissly-overlay__actions .quissly-voice-button.recording {
			    background: transparent !important; color: #2a2a2d !important; box-shadow: none !important;
			}
			.quissly-overlay .quissly-overlay__actions .quissly-voice-button svg,
			.quissly-overlay .quissly-overlay__actions .quissly-image-button svg {
			    display: block !important; width: 40px !important; height: 40px !important;
			    padding: 8px !important; border-radius: 50% !important;
			    background: transparent !important; color: #2a2a2d !important;
			    fill: none !important; stroke: currentColor !important; stroke-width: 1.7 !important;
			    transition: background .15s, color .15s;
			}
			@media (hover: hover) {
			    .quissly-overlay .quissly-overlay__actions .quissly-voice-button:not(:disabled):hover svg,
			    .quissly-overlay .quissly-overlay__actions .quissly-image-button:not(:disabled):hover svg {
			        background: #f0f0f2 !important;
			    }
			}
			.quissly-overlay .quissly-overlay__actions .quissly-voice-button:disabled,
			.quissly-overlay .quissly-overlay__actions .quissly-image-button:disabled {
			    opacity: .4;
			    cursor: wait;
			}
			.quissly-overlay .quissly-overlay__actions .quissly-voice-button.recording svg {
			    background: #d92d20 !important;
			    color: #fff !important;
			}
			@media (prefers-reduced-motion: no-preference) {
			    .quissly-overlay .quissly-overlay__actions .quissly-voice-button.recording svg {
			        animation: quissly-rec-halo 1.4s ease-out infinite;
			    }
			}
			@keyframes quissly-rec-halo {
			    from { box-shadow: 0 0 0 0 rgba(217, 45, 32, .45);
			    } to { box-shadow: 0 0 0 8px rgba(217, 45, 32, 0);
			    };
			}
			/* Under the bar, inside the panel: the suggestions and the results scroll, the bar stays. */
			.quissly-overlay .quissly-overlay__suggest {
			    flex: 1 1 auto; min-height: 0; overflow-y: auto;
			    padding: 12px 20px 16px;
			    background: transparent; border-radius: 0; box-shadow: none;
			    border-top: 1px solid #ececef;
			    scrollbar-width: thin; scrollbar-color: #d0d0d5 transparent;
			}
			.quissly-overlay .quissly-overlay__results {
			    flex: 1 1 auto; min-height: 0; overflow-y: auto;
			    border-top: 1px solid #ececef;
			}
			.quissly-overlay .quissly-overlay__results:empty { display: none; }
			@media (max-width: 480px) {
			    .quissly-overlay { padding: 12px; }
			    .quissly-overlay__bar { padding-right: 4px; }
			    .quissly-overlay .quissly-overlay__input,
			    .quissly-overlay .quissly-overlay__input:focus,
			    .quissly-overlay .quissly-overlay__input:hover { padding: 16px 8px !important; }
			    .quissly-overlay .quissly-overlay__field:has(.quissly-overlay__actions:not(:empty)) {
			        padding: 0 2px 0 10px;
			    }
			    .quissly-overlay .quissly-overlay__field:has(.quissly-overlay__actions:not(:empty)) .quissly-overlay__icon {
			        display: none;
			    }
			    .quissly-overlay .quissly-overlay__suggest { padding: 12px 12px 14px; }
			}
		</style>
		<?php
	}
}
