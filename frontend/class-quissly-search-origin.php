<?php
/**
 * Which engine answered this search: the X-Quissly-Search header and the ?quissly_debug=1
 * badge (port of the Magento plugin's SearchDiagnosticHeader + SearchOrigin).
 *
 * @package Quissly_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads Quissly_Search_Signal and reports it, but only when asked:
 *  - the header `X-Quissly-Search: hit; ids=N; total=N` / `fallback; code=X` /
 *    `skipped; reason=X`, on any request carrying ?quissly_debug=1, or on every request
 *    while QUISSLY_DEBUG / WP_DEBUG is on. The codes can be auth/billing ones, which belong
 *    to the merchant, not to a shopper - so it is opt-in per request, nothing changes for
 *    shoppers, and the URL parameter makes the page a separate cache entry;
 *  - with ?quissly_debug=1 only, a small badge: "Results by Quissly" / "Results by
 *    WooCommerce search", plus the skip reason when the plugin stepped aside. A shopper
 *    never sees it.
 *
 * The header goes out at template_redirect, after the main query ran and before any output,
 * so it describes the classic-theme path and a block theme's inherited query (the usual
 * case); a Product Collection block with its OWN query runs while the page renders, after the
 * headers - the badge, drawn in the footer, still reports it.
 */
class Quissly_Search_Origin {

	const DEBUG_PARAM = 'quissly_debug';

	/**
	 * Register hooks.
	 */
	public function register() {
		add_action( 'template_redirect', array( $this, 'send_header' ), 999 );
		add_action( 'wp_footer', array( $this, 'render_badge' ), 999 );
	}

	/**
	 * Whether this request asked for the diagnostics.
	 *
	 * @return bool
	 */
	public static function asked() {
		return isset( $_GET[ self::DEBUG_PARAM ] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only diagnostics switch, no state change.
	}

	/**
	 * Send X-Quissly-Search.
	 */
	public function send_header() {
		$debugging = ( defined( 'QUISSLY_DEBUG' ) && QUISSLY_DEBUG ) || ( defined( 'WP_DEBUG' ) && WP_DEBUG );
		$value     = Quissly_Search_Signal::header_value();
		if ( null === $value || headers_sent() || ( ! self::asked() && ! $debugging ) ) {
			return;
		}
		header( 'X-Quissly-Search: ' . $value );
	}

	/**
	 * The ?quissly_debug=1 badge.
	 */
	public function render_badge() {
		if ( ! self::asked() || null === Quissly_Search_Signal::header_value() ) {
			return;
		}
		echo self::badge_html( Quissly_Search_Signal::served_by_quissly(), Quissly_Search_Signal::skip_reason() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside badge_html().
	}

	/**
	 * The badge markup (colors as the Magento plugin's search-origin.phtml), fixed
	 * bottom-LEFT: the chat launcher owns the bottom-right corner.
	 *
	 * @param bool        $by_quissly  Quissly's results are on the page.
	 * @param string|null $skip_reason Why the plugin stepped aside, or null.
	 * @return string
	 */
	public static function badge_html( $by_quissly, $skip_reason ) {
		$colors = $by_quissly ? 'background:#EEF0FE;border-color:#6366f1;color:#2C2F6B' : 'background:#FDF3E7;border-color:#E8A33D;color:#6B4A16';
		$html   = '<div class="quissly-origin quissly-origin--' . ( $by_quissly ? 'quissly' : 'woocommerce' ) . '" role="status"'
			. ' style="position:fixed;left:16px;bottom:16px;z-index:99999;max-width:360px;padding:8px 14px;border-radius:8px;border-left:4px solid;'
			. 'font:600 13px/1.4 -apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;box-shadow:0 4px 14px rgba(0,0,0,.12);' . $colors . '">'
			. esc_html( $by_quissly ? __( 'Results by Quissly', 'quissly-for-woocommerce' ) : __( 'Results by WooCommerce search', 'quissly-for-woocommerce' ) );
		if ( null !== $skip_reason ) {
			/* translators: %s: the reason, e.g. "search-off" or "gate-closed". */
			$html .= '<span class="quissly-origin__why" style="display:block;margin-top:2px;font-weight:400">' . esc_html( sprintf( __( 'Quissly skipped this search: %s', 'quissly-for-woocommerce' ), $skip_reason ) ) . '</span>';
		}

		return $html . '</div>';
	}
}
