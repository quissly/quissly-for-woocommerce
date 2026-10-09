<?php
/**
 * Frontend asset loader.
 *
 * @package Quissly_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enqueues the storefront Quick-widget script + style and passes the runtime config
 * (search-box selectors, layout, labels, REST endpoints, voice/image toggles) to JS.
 *
 * The Quick dropdown, voice, and image controls are built client-side (the dropdown is a
 * body-level floating element so theme overflow/transform can't clip it). This class only
 * provides the assets and config.
 */
class Quissly_Assets {

	/**
	 * Auto-detect priority list for the search input. The widget attaches to ALL matches and
	 * self-heals at runtime.
	 *
	 * @var string[]
	 */
	const SELECTOR_PRIORITY = array(
		'form.woocommerce-product-search input[name="s"]',
		'.wp-block-search__input',
		'input[type="search"]',
		'input[name="s"]',
	);

	/**
	 * Hook asset enqueuing.
	 */
	public function register() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Enqueue the Quick widget assets and localize the config.
	 */
	public function enqueue() {
		if ( is_admin() ) {
			return;
		}

		wp_register_script(
			'quissly-quick',
			QUISSLY_PLUGIN_URL . 'assets/js/quick.js',
			array(),
			QUISSLY_VERSION,
			true
		);
		wp_register_style(
			'quissly-quick',
			QUISSLY_PLUGIN_URL . 'assets/css/quick.css',
			array(),
			QUISSLY_VERSION
		);

		wp_localize_script( 'quissly-quick', 'quisslyQuick', $this->config() );

		wp_enqueue_script( 'quissly-quick' );
		wp_enqueue_style( 'quissly-quick' );
	}

	/**
	 * Build the JS runtime config.
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
			'restUrl'      => esc_url_raw( rest_url( Quissly_Proxy::NAMESPACE . '/' ) ),
			'nonce'        => wp_create_nonce( 'wp_rest' ), // sent but not required (public endpoints).
			// The page's language on a multilingual store ('' otherwise): Quick's rows are its.
			'lang'         => Quissly_Languages::is_multilingual() ? Quissly_Languages::current_code() : '',
			'selectors'    => array_merge( $overrides, self::SELECTOR_PRIORITY ),
			'layout'       => Quissly_Settings::get( 'quissly_quick_layout' ),
			'seeAllLabel'  => (string) Quissly_Settings::get( 'quissly_see_all_label' ),
			'enableVoice'  => (bool) Quissly_Settings::get( 'quissly_enable_voice' ),
			'enableImage'  => (bool) Quissly_Settings::get( 'quissly_enable_image' ),
			'minChars'     => 2,
			'debounceMs'   => 200,
			'maxRows'      => 10,
			'voiceMaxMs'   => 5000,
			'imageMaxEdge' => 1024,
			'imgQueryVar'  => Quissly_Proxy::QUERY_VAR_IMG,
			'voiceQueryVar' => Quissly_Proxy::QUERY_VAR_VOICE,
			'i18n'         => array(
				'placeholderImage' => __( 'Search by image', 'quissly-for-woocommerce' ),
				'placeholderVoice' => __( 'Search by voice', 'quissly-for-woocommerce' ),
				'listening'        => __( 'Listening…', 'quissly-for-woocommerce' ),
				'searching'        => __( 'Searching…', 'quissly-for-woocommerce' ),
				'micDenied'        => __( 'Microphone unavailable.', 'quissly-for-woocommerce' ),
				'micUnsupported'   => __( 'Voice search is not supported in this browser.', 'quissly-for-woocommerce' ),
				'micInsecure'      => __( 'Voice search needs a secure (https) connection.', 'quissly-for-woocommerce' ),
				// Shown inside the theme's own "Search results for: %s" heading — an
				// image search carries no words, and a voice search whose transcription
				// came back empty has none either, so the results page needs SOMETHING
				// to show rather than a blank/space. The wrapping sentence is the
				// theme's, not ours, so this is worded to read naturally inside it
				// ("Search results for: your image search").
				'imageFallbackQuery' => __( 'your image search', 'quissly-for-woocommerce' ),
				'voiceFallbackQuery' => __( 'your voice search', 'quissly-for-woocommerce' ),
			),
		);
	}
}
