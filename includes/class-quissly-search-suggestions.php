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
 * A multilingual store (WPML, Polylang - Quissly_Languages) has a list per language, in the
 * Magento plugin's keys: `client_specific_queries` is the main language's (named in
 * `client_specific_queries_language`), the others are under `client_specific_queries_by_language`.
 * A shopper sees their language's list; a language without one uses the main language's (the
 * Shopify app's rule).
 *
 * clean(), validate(), from_config(), pick() and pick_generated() are pure (unit-tested).
 */
class Quissly_Search_Suggestions {

	const QUERIES_KEY = 'client_specific_queries';
	const TYPING_KEY  = 'search_typing_enabled';

	/** The main list's language ("ka"), and the other languages' lists {"en": [...]}. */
	const PRIMARY_KEY     = 'client_specific_queries_language';
	const BY_LANGUAGE_KEY = 'client_specific_queries_by_language';

	/** What Quissly's generator last wrote, {main, by_language} (written by Quissly, read only here). */
	const GENERATED_KEY = 'client_specific_queries_generated';

	/** At most this many suggestions, each at most MAX_LENGTH characters (as Shopify). */
	const MAX_COUNT  = 20;
	const MAX_LENGTH = 80;

	/** Suggestion buttons under the overlay's bar, at most (the Shopify app's MAX_CHIPS). */
	const MAX_CHIPS = 10;

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
	 * @return array{enabled:bool,queries:string[],language:string,by_language:array<string,string[]>,generated:string[],generated_by_language:array<string,string[]>}
	 */
	public static function from_config( $config ) {
		$config    = is_array( $config ) ? $config : array();
		$generated = isset( $config[ self::GENERATED_KEY ] ) && is_array( $config[ self::GENERATED_KEY ] ) ? $config[ self::GENERATED_KEY ] : array();
		$primary   = $config[ self::PRIMARY_KEY ] ?? '';

		return array(
			'enabled'               => ! ( array_key_exists( self::TYPING_KEY, $config ) && false === $config[ self::TYPING_KEY ] ),
			'queries'               => self::clean( $config[ self::QUERIES_KEY ] ?? array() ),
			'language'              => is_string( $primary ) ? strtolower( trim( $primary ) ) : '',
			'by_language'           => self::lists_by_language( $config[ self::BY_LANGUAGE_KEY ] ?? array() ),
			// What Quissly's generator last wrote (its merchant-edit baseline): "Reset to generated".
			'generated'             => self::clean( $generated['main'] ?? array() ),
			'generated_by_language' => self::lists_by_language( $generated['by_language'] ?? array() ),
		);
	}

	/**
	 * {language: list} cleaned, keys lowercased, empty lists left out. PURE.
	 *
	 * @param mixed $raw Map.
	 * @return array<string,string[]>
	 */
	private static function lists_by_language( $raw ) {
		$out = array();
		foreach ( (array) $raw as $language => $queries ) {
			$queries = self::clean( $queries );
			if ( is_string( $language ) && '' !== $language && ! empty( $queries ) ) {
				$out[ strtolower( $language ) ] = $queries;
			}
		}

		return $out;
	}

	/**
	 * The typing list for a shopper's language: the main list in the main language (or with
	 * no language given), else that language's list (or its base language's: "pt-br" ->
	 * "pt"), else the main list - the Shopify app's "a language with no list of its own uses
	 * the default one". PURE.
	 *
	 * @param array  $lists    from_config().
	 * @param string $language The shopper's language key.
	 * @param string $main     The main language's key (when the config does not say).
	 * @return string[]
	 */
	public static function pick( array $lists, $language, $main ) {
		$main     = '' !== ( $lists['language'] ?? '' ) ? $lists['language'] : (string) $main;
		$language = strtolower( str_replace( '_', '-', trim( (string) $language ) ) );
		if ( '' === $language || $language === $main ) {
			return (array) ( $lists['queries'] ?? array() );
		}
		$by_language = (array) ( $lists['by_language'] ?? array() );
		$base        = explode( '-', $language )[0];

		return $by_language[ $language ] ?? $by_language[ $base ] ?? (array) ( $lists['queries'] ?? array() );
	}

	/**
	 * The overlay's Automatic suggestion buttons for a language: only what Quissly's generator
	 * wrote, never the typing list - the Shopify app's chipsForLocale(). That language's list
	 * first (then its base language's: the generator's main list stays the old one when the
	 * merchant edited the typing list), else the generated main list; none before anything was
	 * generated. At most MAX_CHIPS. PURE.
	 *
	 * A language without a list of its own gets the main language's (then the generated main
	 * list), as the Shopify app's chipsForLocale().
	 *
	 * @param array  $lists    from_config().
	 * @param string $language Language key ("en", "pt-br").
	 * @param string $main     The main language's key ('' = not known).
	 * @return string[]
	 */
	public static function pick_generated( array $lists, $language, $main = '' ) {
		$by_language = (array) ( $lists['generated_by_language'] ?? array() );
		$language    = strtolower( str_replace( '_', '-', trim( (string) $language ) ) );
		foreach ( array( $language, explode( '-', $language )[0], strtolower( (string) $main ) ) as $key ) {
			if ( '' !== $key && ! empty( $by_language[ $key ] ) ) {
				return array_slice( array_values( $by_language[ $key ] ), 0, self::MAX_CHIPS );
			}
		}

		return array_slice( array_values( (array) ( $lists['generated'] ?? array() ) ), 0, self::MAX_CHIPS );
	}

	/**
	 * What the storefront overlay types: the shopper's language's list (pick()) when typing
	 * is on, else none.
	 *
	 * @return string[]
	 */
	public static function for_storefront() {
		$lists = self::storefront_lists();

		return $lists['enabled'] ? self::pick( $lists, Quissly_Languages::current_key(), Quissly_Languages::main_key() ) : array();
	}

	/**
	 * The overlay's Automatic suggestion buttons, for the shopper's language (pick_generated()).
	 *
	 * @return string[]
	 */
	public static function generated_for_storefront() {
		return self::pick_generated( self::storefront_lists(), Quissly_Languages::current_key(), Quissly_Languages::main_key() );
	}

	/**
	 * Quissly's lists for the storefront, cached (a failed read is cached briefly too, so a
	 * Quissly outage costs one call a minute). The stored service id only: a shopper's page
	 * never waits on a console lookup (Configuration looks it up and stores it).
	 *
	 * @return array from_config().
	 */
	private static function storefront_lists() {
		$cached = get_transient( self::CACHE );
		// A list cached before the generated lists or the per-language ones were is read again.
		if ( is_array( $cached ) && isset( $cached['queries'], $cached['generated_by_language'], $cached['by_language'] ) ) {
			return $cached;
		}
		$read  = self::read( false );
		$lists = null !== $read ? $read : self::from_config( array( self::TYPING_KEY => false ) );
		set_transient( self::CACHE, $lists, null === $read ? MINUTE_IN_SECONDS : self::CACHE_TTL );

		return $lists;
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
	 * our keys, write it back. '' on success, else a merchant-facing reason.
	 *
	 * @param bool                         $enabled     Show typing suggestions.
	 * @param string[]                     $queries     Cleaned, validated main list.
	 * @param array<string,string[]>|null  $by_language A multilingual store's other languages'
	 *                                                  lists (null = leave them as they are).
	 * @return string
	 */
	public static function save( $enabled, array $queries, $by_language = null ) {
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
		if ( is_array( $by_language ) ) {
			// As an object, also when empty: the Shopify app reads a language map.
			$config[ self::BY_LANGUAGE_KEY ] = (object) array_map( 'array_values', array_filter( $by_language ) );
			$config[ self::PRIMARY_KEY ]     = Quissly_Languages::main_key();
		}

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
