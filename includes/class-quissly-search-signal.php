<?php
/**
 * What happened to this request's product search (port of the Magento plugin's
 * Model/Search/SearchSignal).
 *
 * @package Quissly_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One outcome per request: Quissly answered (hit), Quissly failed and WooCommerce's own
 * search rendered the page (fallback, with a code), or the plugin stepped aside before
 * calling Quissly (skipped, with a reason). A hit or a fallback always wins over a skip.
 * Read by Quissly_Search_Origin for the X-Quissly-Search header and the ?quissly_debug=1
 * badge, so "was this page Quissly or WooCommerce?" is answerable from a browser.
 *
 * Carries no shopper data - an outcome word, two counts, fixed words and field names;
 * never the query text. Static: one request, one search.
 */
class Quissly_Search_Signal {

	const HIT      = 'hit';
	const FALLBACK = 'fallback';
	const SKIPPED  = 'skipped';

	/** @var string|null */
	private static $outcome = null;

	/** @var int */
	private static $ids = 0;

	/** @var int */
	private static $total = 0;

	/** @var string */
	private static $code = '';

	/** @var string */
	private static $reason = '';

	/**
	 * Quissly answered and its results are being rendered.
	 *
	 * @param int $ids   Ids on this page.
	 * @param int $total Total matches.
	 */
	public static function record_hit( $ids, $total ) {
		self::$outcome = self::HIT;
		self::$ids     = (int) $ids;
		self::$total   = (int) $total;
	}

	/**
	 * Quissly did not answer (or answered nothing); WooCommerce's own search rendered this
	 * page (page 2+: a graceful empty page).
	 *
	 * @param string $code Short code (no_results, transport_error, http_401...).
	 */
	public static function record_fallback( $code ) {
		self::$outcome = self::FALLBACK;
		self::$code    = self::token( $code );
	}

	/**
	 * The plugin stepped aside before calling Quissly - not a failure, a guard that did not
	 * pass (search-off, gate-closed, no-term). Never overrides a hit or a fallback.
	 *
	 * @param string $reason Fixed words / field names only, never query text.
	 */
	public static function record_skip( $reason ) {
		if ( null !== self::$outcome ) {
			return;
		}
		self::$outcome = self::SKIPPED;
		self::$reason  = self::token( $reason );
	}

	/**
	 * Why the plugin stepped aside on this request, or null when it did not.
	 *
	 * @return string|null
	 */
	public static function skip_reason() {
		return self::SKIPPED === self::$outcome ? self::$reason : null;
	}

	/**
	 * Whether Quissly's results are the ones on this page.
	 *
	 * @return bool
	 */
	public static function served_by_quissly() {
		return self::HIT === self::$outcome;
	}

	/**
	 * Header value, or null when no product search ran on this request.
	 *
	 * @return string|null
	 */
	public static function header_value() {
		if ( self::HIT === self::$outcome ) {
			return sprintf( 'hit; ids=%d; total=%d', self::$ids, self::$total );
		}
		if ( self::FALLBACK === self::$outcome ) {
			return sprintf( 'fallback; code=%s', self::$code );
		}
		if ( self::SKIPPED === self::$outcome ) {
			return sprintf( 'skipped; reason=%s', self::$reason );
		}

		return null;
	}

	/**
	 * Forget this request's outcome (tests).
	 */
	public static function reset() {
		self::$outcome = null;
		self::$ids     = 0;
		self::$total   = 0;
		self::$code    = '';
		self::$reason  = '';
	}

	/**
	 * One clean header token (a code or reason may come from an HTTP status or a setting).
	 *
	 * @param string $value Raw.
	 * @return string
	 */
	private static function token( $value ) {
		return (string) preg_replace( '/[^A-Za-z0-9_.:-]/', '', (string) $value );
	}
}
