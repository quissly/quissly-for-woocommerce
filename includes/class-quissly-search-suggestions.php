<?php
/**
 * Search bar suggestions: the queries the search overlay types into its empty bar
 * (port of the Shopify app's "Search bar suggestions").
 *
 * @package Quissly_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The list lives in Quissly, not here - in the store's QSearch service's widget_config,
 * under the same keys the Shopify app uses (`client_specific_queries`, a list of strings,
 * and `search_typing_enabled`), so every platform and Quissly itself read one list.
 *
 *  - read():            the public widget-config read; a service with no config row yet
 *                       (404) has none, typing on.
 *  - for_storefront():  read() cached for five minutes - what the overlay types.
 *  - save():            sign in as the store (Quissly_Panel_Session, the embedded panel's
 *                       sign-in), read, merge OUR two keys, write back (PUT replaces the
 *                       whole blob, so anything else in it is kept).
 *
 * clean(), validate() and from_config() are pure (unit-tested).
 */
class Quissly_Search_Suggestions {

	const QUERIES_KEY = 'client_specific_queries';
	const TYPING_KEY  = 'search_typing_enabled';

	/** At most this many suggestions, each at most MAX_LENGTH characters (as Shopify). */
	const MAX_COUNT  = 20;
	const MAX_LENGTH = 80;

	/** The QSearch service's id (looked up once, then remembered). */
	const OPTION_SERVICE_ID = 'quissly_search_service_id';

	const CACHE     = 'quissly_search_suggestions';
	const CACHE_TTL = 300;
	const TIMEOUT   = 10;

	/**
	 * Trimmed, non-empty, de-duplicated (case-insensitive), in order. PURE.
	 *
	 * @param mixed $raw List (or anything).
	 * @return string[]
	 */
	public static function clean( $raw ) {
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$seen = array();
		$out  = array();
		foreach ( $raw as $item ) {
			if ( ! is_string( $item ) ) {
				continue;
			}
			$query = trim( preg_replace( '/\s+/u', ' ', $item ) );
			$key   = function_exists( 'mb_strtolower' ) ? mb_strtolower( $query ) : strtolower( $query );
			if ( '' === $query || isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$out[]        = $query;
		}

		return $out;
	}

	/**
	 * Why a list cannot be saved, or '' when it can. PURE.
	 *
	 * @param string[] $queries Cleaned list.
	 * @return string
	 */
	public static function validate( array $queries ) {
		if ( count( $queries ) > self::MAX_COUNT ) {
			/* translators: %d: the maximum number of suggestions. */
			return sprintf( __( 'Up to %d search bar suggestions.', 'quissly-for-woocommerce' ), self::MAX_COUNT );
		}
		foreach ( $queries as $query ) {
			if ( ( function_exists( 'mb_strlen' ) ? mb_strlen( $query ) : strlen( $query ) ) > self::MAX_LENGTH ) {
				/* translators: %d: the maximum length. */
				return sprintf( __( 'Keep each search bar suggestion under %d characters.', 'quissly-for-woocommerce' ), self::MAX_LENGTH );
			}
		}

		return '';
	}

	/**
	 * The suggestions a widget_config holds (null = no config row: typing on, none). PURE.
	 *
	 * @param array|null $config Decoded widget_config.
	 * @return array{enabled:bool,queries:string[]}
	 */
	public static function from_config( $config ) {
		$config = is_array( $config ) ? $config : array();

		return array(
			'enabled' => ! ( array_key_exists( self::TYPING_KEY, $config ) && false === $config[ self::TYPING_KEY ] ),
			'queries' => self::clean( $config[ self::QUERIES_KEY ] ?? array() ),
		);
	}

	/**
	 * What the storefront overlay types: the list when typing is on, else none. Cached
	 * (a failed read is cached briefly too, so a Quissly outage costs one call a minute).
	 *
	 * @return string[]
	 */
	public static function for_storefront() {
		$cached = get_transient( self::CACHE );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		// The stored service id only: a shopper's page never waits on a console lookup
		// (Configuration looks it up and stores it).
		$read    = self::read( false );
		$queries = ( null !== $read && $read['enabled'] ) ? $read['queries'] : array();
		set_transient( self::CACHE, $queries, null === $read ? MINUTE_IN_SECONDS : self::CACHE_TTL );

		return $queries;
	}

	/**
	 * The current list from Quissly, or null when it cannot be read (not connected,
	 * no search service, Quissly unreachable).
	 *
	 * @param bool $look_up May look the service id up (admin only - never on a storefront page).
	 * @return array{enabled:bool,queries:string[]}|null
	 */
	public static function read( $look_up = true ) {
		$service_id = $look_up ? self::service_id() : (string) get_option( self::OPTION_SERVICE_ID, '' );
		if ( '' === $service_id ) {
			return null;
		}
		$response = wp_remote_get( self::read_url( $service_id ), array( 'timeout' => self::TIMEOUT ) );
		if ( is_wp_error( $response ) ) {
			return null;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 404 === $code ) {
			return self::from_config( null );
		}
		if ( 200 !== $code ) {
			return null;
		}

		return self::from_config( json_decode( wp_remote_retrieve_body( $response ), true ) );
	}

	/**
	 * Write the list to Quissly: sign in as the store, read the whole widget_config, set
	 * our two keys, write it back. '' on success, else a merchant-facing reason.
	 *
	 * @param bool     $enabled Show typing suggestions.
	 * @param string[] $queries Cleaned, validated list.
	 * @return string
	 */
	public static function save( $enabled, array $queries ) {
		$service_id = self::service_id();
		if ( '' === $service_id ) {
			return __( 'Search isn\'t set up for this store in Quissly yet, so there is nowhere to save search bar suggestions.', 'quissly-for-woocommerce' );
		}
		$session = Quissly_Panel_Session::open_for_store();
		if ( empty( $session['ok'] ) ) {
			return __( 'Couldn\'t sign in to Quissly to save the search bar suggestions. Please try again.', 'quissly-for-woocommerce' );
		}
		$headers = array(
			'Authorization' => 'Bearer ' . $session['access_token'],
			'X-Platform'    => Quissly_Http_Client::X_PLATFORM,
		);

		$current = wp_remote_get( self::read_url( $service_id ), array( 'headers' => $headers, 'timeout' => self::TIMEOUT ) );
		$code    = is_wp_error( $current ) ? 0 : (int) wp_remote_retrieve_response_code( $current );
		if ( 200 !== $code && 404 !== $code ) {
			return __( 'Couldn\'t save the search bar suggestions. Please try again.', 'quissly-for-woocommerce' );
		}
		$config = 200 === $code ? json_decode( wp_remote_retrieve_body( $current ), true ) : array();
		$config = is_array( $config ) ? $config : array();

		$config[ self::QUERIES_KEY ] = array_values( $queries );
		$config[ self::TYPING_KEY ]  = (bool) $enabled;

		$written = wp_remote_request(
			self::write_url( $service_id ),
			array(
				'method'  => 'PUT',
				'headers' => $headers + array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $config ),
				'timeout' => self::TIMEOUT,
			)
		);
		if ( is_wp_error( $written ) || 200 !== (int) wp_remote_retrieve_response_code( $written ) ) {
			return __( 'Couldn\'t save the search bar suggestions. Please try again.', 'quissly-for-woocommerce' );
		}
		delete_transient( self::CACHE );

		return '';
	}

	/**
	 * The store's QSearch service id, '' when unknown. Looked up once through the account's
	 * service list (the same lookup that finds the QChat agent), then remembered.
	 *
	 * @return string
	 */
	public static function service_id() {
		$stored = (string) get_option( self::OPTION_SERVICE_ID, '' );
		if ( '' !== $stored ) {
			return $stored;
		}
		$project = (string) Quissly_Settings::get( 'quissly_project_id' );
		$email   = (string) Quissly_Settings::get( 'quissly_account_email' );
		$token   = Quissly_Env::token();
		if ( '' === $project || '' === $email || '' === $token ) {
			return '';
		}
		$found = ( new Quissly_Live_Service_Directory_Client() )->qsearch_service_id( $project, $email, $token );
		if ( null === $found || '' === $found ) {
			return '';
		}
		update_option( self::OPTION_SERVICE_ID, $found, false );

		return $found;
	}

	/**
	 * @param string $service_id Service id.
	 * @return string
	 */
	private static function read_url( $service_id ) {
		return rtrim( Quissly_Live_Provisioner_Client::console_url(), '/' ) . '/api/v1/services/widget-config/' . rawurlencode( $service_id );
	}

	/**
	 * @param string $service_id Service id.
	 * @return string
	 */
	private static function write_url( $service_id ) {
		return rtrim( Quissly_Live_Provisioner_Client::console_url(), '/' ) . '/api/v1/services/' . rawurlencode( $service_id ) . '/widget-config';
	}
}
