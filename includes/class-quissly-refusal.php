<?php
/**
 * Quissly refusing the store's searches (HTTP 401 / 402 / 403).
 *
 * @package Quissly_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The last refusal - status, Quissly's own reason, when - kept so "Search status" says Inactive and
 * why while Quissly refuses (it said Active: storefront searches fell back to WooCommerce's quietly),
 * and the merchant's text for it: by status, with Quissly's words. Test connection used to say
 * "check the token, the registered public key, and the environment" for every failure, also for a
 * store Setup connected whose plan Quissly did not have (403 "No active quota grant for this
 * service", a plan chosen for manual invoice). As the CS-Cart add-on's Refusal (1.0.19).
 *
 * Recorded by the storefront search and Test connection, cleared by their next 200. Finish Setup
 * runs Test connection's probe, so a refused store is not shown Active until a shopper's search fails.
 */
class Quissly_Refusal {

	/** Small and read on every status render: autoloaded, written only when it changes. */
	const OPTION = 'quissly_search_refusal';

	const STATUSES = array( 401, 402, 403 );

	/**
	 * Whether an HTTP status is Quissly refusing the store (not a failure to answer).
	 *
	 * @param int $code HTTP status.
	 * @return bool
	 */
	public static function is_refusal( $code ) {
		return in_array( (int) $code, self::STATUSES, true );
	}

	/**
	 * Quissly's reason for refusing (the `detail` of its error answer) as plain text, or ''. PURE.
	 *
	 * @param mixed $body The decoded answer.
	 * @return string
	 */
	public static function detail( $body ) {
		$detail = is_array( $body ) && isset( $body['detail'] ) && is_string( $body['detail'] ) ? $body['detail'] : '';
		$detail = trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( $detail ) ) );

		return function_exists( 'mb_substr' ) ? mb_substr( $detail, 0, 200 ) : substr( $detail, 0, 200 );
	}

	/**
	 * Remember a refusal.
	 *
	 * @param int    $code   401 / 402 / 403.
	 * @param string $detail Quissly's reason.
	 */
	public static function record( $code, $detail ) {
		$now  = array( 'status' => (int) $code, 'detail' => (string) $detail );
		$kept = self::get();
		if ( null !== $kept && $kept['status'] === $now['status'] && $kept['detail'] === $now['detail'] ) {
			return; // Same refusal: no write per shopper search.
		}
		update_option( self::OPTION, $now + array( 'at' => time() ), true );
	}

	/** Quissly answered: a refusal kept from before is over. */
	public static function clear() {
		if ( null !== self::get() ) {
			delete_option( self::OPTION );
		}
	}

	/**
	 * The refusal kept, or null.
	 *
	 * @return array{status:int,detail:string,at:int}|null
	 */
	public static function get() {
		$kept = get_option( self::OPTION, null );
		if ( ! is_array( $kept ) || ! self::is_refusal( $kept['status'] ?? 0 ) ) {
			return null;
		}

		return array(
			'status' => (int) $kept['status'],
			'detail' => (string) ( $kept['detail'] ?? '' ),
			'at'     => (int) ( $kept['at'] ?? 0 ),
		);
	}

	/**
	 * The merchant's text for a refusal: why, by status, and Quissly's own words. PURE.
	 *
	 * @param int    $code   HTTP status.
	 * @param string $detail Quissly's reason ('' when it gave none).
	 * @return string
	 */
	public static function text( $code, $detail ) {
		switch ( (int) $code ) {
			case 401:
				$text = __( 'Quissly did not accept this store\'s key (HTTP 401). Contact Quissly support to reconnect the store.', 'quissly-for-woocommerce' );
				break;
			case 402:
				$text = __( 'Quissly is waiting for a payment for this store\'s plan (HTTP 402) - see Billing.', 'quissly-for-woocommerce' );
				break;
			case 403:
				$text = __( 'Quissly refuses this store\'s searches (HTTP 403). Usually the store has no active plan with search yet - see Billing; a plan chosen for manual invoice works once Quissly activates it.', 'quissly-for-woocommerce' );
				break;
			default:
				/* translators: %d: HTTP status code. */
				$text = sprintf( __( 'Quissly refused the request (HTTP %d).', 'quissly-for-woocommerce' ), (int) $code );
		}
		if ( '' !== (string) $detail ) {
			/* translators: %s: Quissly's own reason, as it gave it. */
			$text .= ' ' . sprintf( __( 'Quissly said: "%s".', 'quissly-for-woocommerce' ), (string) $detail );
		}

		return $text;
	}
}
