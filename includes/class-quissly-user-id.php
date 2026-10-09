<?php
/**
 * Who is searching: the shopper id (and device / OS) sent with Quissly requests.
 *
 * @package Quissly_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves the `user_id` sent with every search/quick/image request, in the Shopify app's
 * scheme, so every platform reports shoppers alike:
 *
 *   customer:<id>  a logged-in WordPress user - stable across devices and sessions;
 *   guest:<uuid>   an anonymous browser - a UUID minted into a first-party cookie for a year.
 *                  One device, not one person.
 *   null           a guest who has not consented to statistics cookies, on a store whose
 *                  consent banner speaks the WP Consent API (Complianz, CookieYes, ...): no
 *                  cookie is read or set - as the Shopify app withholds its visitor id when
 *                  analytics consent is refused. With no such banner nothing changes.
 *
 * The prefix keeps the two apart. There is deliberately no session-length tier: the
 * WooCommerce session id (used here before 2026-09-30) is replaced every ~48 hours, so one
 * returning shopper counted as a new "user" every couple of days - the same reason the
 * Shopify app dropped Shopify's session cookie.
 */
class Quissly_User_Id {

	const COOKIE = 'quissly_uid';

	const CUSTOMER_PREFIX = 'customer:';

	const GUEST_PREFIX = 'guest:';

	/**
	 * The quissly_uid cookie is a statistics cookie: declare it to the WP Consent API, so
	 * consent banners list it and Site Health counts this plugin as consent-aware.
	 */
	public static function register_consent() {
		add_filter( 'wp_consent_api_registered_' . QUISSLY_PLUGIN_BASENAME, '__return_true' );
		add_action(
			'init',
			static function () {
				if ( function_exists( 'wp_add_cookie_info' ) ) {
					wp_add_cookie_info(
						self::COOKIE,
						'Quissly',
						'statistics',
						__( '1 year', 'quissly-for-woocommerce' ),
						__( 'Recognises a returning visitor in search analytics.', 'quissly-for-woocommerce' )
					);
				}
			}
		);
	}

	/**
	 * Resolve the current visitor's id.
	 *
	 * @return string|null Null for a guest without statistics consent.
	 */
	public static function resolve() {
		if ( function_exists( 'is_user_logged_in' ) && is_user_logged_in() ) {
			return self::CUSTOMER_PREFIX . get_current_user_id();
		}
		// WP Consent API (a consent banner plugin): with no banner it answers true.
		if ( function_exists( 'wp_has_consent' ) && ! wp_has_consent( 'statistics' ) ) {
			return null;
		}

		// First-party cookie (server-minted, persistent). Trusted only if it is EXACTLY
		// the shape this server mints below - accepting an arbitrary client-supplied
		// value verbatim would let a visitor pick their own identity (or replay someone
		// else's), which is a spoofing/segmentation risk, not just a formatting detail.
		// A value that doesn't match gets treated the same as no cookie at all: a fresh
		// one is minted and reset.
		if ( ! empty( $_COOKIE[ self::COOKIE ] ) ) {
			$existing = sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) );
			if ( self::is_valid_uuid( $existing ) ) {
				return self::GUEST_PREFIX . strtolower( $existing );
			}
		}

		$uuid = wp_generate_uuid4();
		if ( ! headers_sent() ) {
			// httponly=true: nothing client-side ever needs to read this cookie.
			setcookie( self::COOKIE, $uuid, time() + YEAR_IN_SECONDS, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN, is_ssl(), true );
			$_COOKIE[ self::COOKIE ] = $uuid; // reflect within this request
		}

		return self::GUEST_PREFIX . $uuid;
	}

	/**
	 * The shopper's device and OS for a text search (QueryRequestV2 `device` / `os`), from
	 * their browser's User-Agent - the Shopify app's deriveDeviceAndOs(). `os` is '' when
	 * unknown (then not sent); anything unrecognised counts as a desktop.
	 *
	 * @param string $user_agent The User-Agent header.
	 * @return array{device:string,os:string}
	 */
	public static function device_and_os( $user_agent ) {
		$ua = strtolower( (string) $user_agent );
		if ( false !== strpos( $ua, 'ipad' ) ) {
			return array( 'device' => 'tablet', 'os' => 'iOS' );
		}
		if ( false !== strpos( $ua, 'iphone' ) ) {
			return array( 'device' => 'mobile', 'os' => 'iOS' );
		}
		if ( false !== strpos( $ua, 'android' ) ) {
			return array( 'device' => false !== strpos( $ua, 'mobile' ) ? 'mobile' : 'tablet', 'os' => 'Android' );
		}
		if ( false !== strpos( $ua, 'mac os x' ) ) {
			return array( 'device' => 'desktop', 'os' => 'macOS' );
		}
		if ( false !== strpos( $ua, 'windows nt' ) ) {
			return array( 'device' => 'desktop', 'os' => 'Windows' );
		}
		if ( false !== strpos( $ua, 'linux' ) ) {
			return array( 'device' => 'desktop', 'os' => 'Linux' );
		}
		return array( 'device' => 'desktop', 'os' => '' );
	}

	/**
	 * Whether a value is a well-formed UUID v4 - the exact shape wp_generate_uuid4()
	 * produces (version nibble '4', variant nibble one of 8/9/a/b per RFC 4122).
	 *
	 * @param string $value Candidate cookie value.
	 * @return bool
	 */
	private static function is_valid_uuid( $value ) {
		return 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', (string) $value );
	}
}
