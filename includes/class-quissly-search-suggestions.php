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

	/**
	 * The Search Suggestions buttons, in the Shopify app's keys: which ones ("manual", else the
	 * generated ones) and the merchant's Manual lists per language, the main one included -
	 * {"ka": [...], "en": [...]}. Kept in Quissly, so Quissly sees them too.
	 */
	const CHIPS_MODE_KEY   = 'search_chips_mode';
	const MANUAL_CHIPS_KEY = 'search_chips_manual';

	/** The background lookup of the service id (lookup_service_id()), at most once an hour. */
	const LOOKUP_HOOK = 'quissly_lookup_search_service';
	const LOOKUP_WAIT = 'quissly_search_service_lookup_wait';

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
			// '' = not in Quissly yet (a store whose buttons were kept in the plugin before).
			'chips_mode'            => in_array( $config[ self::CHIPS_MODE_KEY ] ?? '', array( 'automatic', 'manual' ), true ) ? $config[ self::CHIPS_MODE_KEY ] : '',
			'manual_chips'          => self::lists_by_language( $config[ self::MANUAL_CHIPS_KEY ] ?? array() ),
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
	 * The Manual buttons for a shopper's language: that language's list, its base language's,
	 * else the main language's (the Shopify app's manualChipsForLocale()). At most MAX_CHIPS.
	 * PURE.
	 *
	 * @param array  $lists    from_config().
	 * @param string $language The shopper's language key.
	 * @param string $main     The main language's key.
	 * @return string[]
	 */
	public static function pick_manual( array $lists, $language, $main ) {
		$manual   = (array) ( $lists['manual_chips'] ?? array() );
		$language = strtolower( str_replace( '_', '-', trim( (string) $language ) ) );
		foreach ( array( $language, explode( '-', $language )[0], strtolower( (string) $main ) ) as $key ) {
			if ( '' !== $key && ! empty( $manual[ $key ] ) ) {
				return array_slice( array_values( $manual[ $key ] ), 0, self::MAX_CHIPS );
			}
		}

		return array();
	}

	/**
	 * The Search Suggestions buttons for the shopper's language: the Manual list or the
	 * generated one, as Quissly holds the choice. A store whose choice is not in Quissly yet
	 * (kept in the plugin before 1.0.8, until the next save in Configuration) still uses the
	 * plugin's copy, so nothing is lost on the way.
	 *
	 * @return string[]
	 */
	public static function buttons_for_storefront() {
		$lists = self::storefront_lists();
		$main  = Quissly_Languages::main_key();
		if ( '' === $lists['chips_mode'] ) {
			$lists['chips_mode']   = 'manual' === Quissly_Settings::get( 'quissly_overlay_suggestions_mode' ) ? 'manual' : 'automatic';
			$lists['manual_chips'] = self::plugin_manual_lists();
		}
		if ( 'manual' === $lists['chips_mode'] ) {
			return self::pick_manual( $lists, Quissly_Languages::current_key(), $main );
		}

		return self::pick_generated( $lists, Quissly_Languages::current_key(), $main );
	}

	/**
	 * The Manual lists the plugin kept before they moved to Quissly, as {language key: list}.
	 *
	 * @return array<string,string[]>
	 */
	public static function plugin_manual_lists() {
		$lists = array( Quissly_Languages::main_key() => (string) Quissly_Settings::get( 'quissly_overlay_suggestions_manual' ) );
		foreach ( (array) Quissly_Settings::get( 'quissly_overlay_suggestions_manual_by_language' ) as $language => $lines ) {
			$lists[ (string) $language ] = (string) $lines;
		}
		$out = array();
		foreach ( $lists as $language => $lines ) {
			$list = array_slice( self::clean( preg_split( '/\R/u', $lines ) ?: array() ), 0, self::MAX_CHIPS );
			if ( ! empty( $list ) ) {
				$out[ $language ] = $list;
			}
		}

		return $out;
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
		// A list cached before the generated, per-language or button lists were is read again.
		if ( is_array( $cached ) && isset( $cached['queries'], $cached['generated_by_language'], $cached['by_language'], $cached['manual_chips'] ) ) {
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
		return self::write(
			static function ( array $config ) use ( $enabled, $queries, $by_language ) {
				$config[ self::QUERIES_KEY ] = array_values( $queries );
				$config[ self::TYPING_KEY ]  = (bool) $enabled;
				if ( is_array( $by_language ) ) {
					// As an object, also when empty: the Shopify app reads a language map.
					$config[ self::BY_LANGUAGE_KEY ] = (object) array_map( 'array_values', array_filter( $by_language ) );
					$config[ self::PRIMARY_KEY ]     = Quissly_Languages::main_key();
				}
				return $config;
			}
		);
	}

	/**
	 * Write the Search Suggestions buttons to Quissly (the Shopify app's saveSearchChips()): the
	 * choice and every language's Manual list, replaced whole; an empty list is left out, so
	 * that language uses the main one. '' on success, else a merchant-facing reason.
	 *
	 * @param string                 $mode  automatic | manual.
	 * @param array<string,string[]> $lists Cleaned Manual lists by language key, the main one included.
	 * @return string
	 */
	public static function save_buttons( $mode, array $lists ) {
		return self::write(
			static function ( array $config ) use ( $mode, $lists ) {
				$config[ self::CHIPS_MODE_KEY ]   = 'manual' === $mode ? 'manual' : 'automatic';
				$config[ self::MANUAL_CHIPS_KEY ] = (object) array_map( 'array_values', array_filter( $lists ) );
				return $config;
			}
		);
	}

	/**
	 * Sign in as the store, read the whole widget_config, change it, write it back (PUT replaces
	 * the whole blob, so every other key is kept). '' on success, else a merchant-facing reason.
	 *
	 * @param callable $change fn(array $config): array.
	 * @return string
	 */
	private static function write( callable $change ) {
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
		$config = $change( is_array( $config ) ? $config : array() );

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
	 * Hook the background lookup of the service id: the storefront reads Quissly's lists with
	 * the stored id only (a shopper's page never waits on a lookup), so the id is found in the
	 * background as soon as the store is connected - not only when Configuration is opened.
	 */
	public static function register() {
		add_action( self::LOOKUP_HOOK, array( __CLASS__, 'lookup_service_id' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_schedule_lookup' ) );
	}

	/**
	 * `admin_init`: a connected store without a stored service id gets one looked up in the
	 * background (Action Scheduler), at most once an hour.
	 */
	public static function maybe_schedule_lookup() {
		if ( '' !== (string) get_option( self::OPTION_SERVICE_ID, '' ) || '' === Quissly_Env::token() || get_transient( self::LOOKUP_WAIT ) ) {
			return;
		}
		set_transient( self::LOOKUP_WAIT, 1, HOUR_IN_SECONDS );
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::LOOKUP_HOOK, array(), 'quissly' );
		}
	}

	/**
	 * Look the service id up and store it; the storefront's lists are read again with it.
	 *
	 * @return string The id, '' when it could not be found.
	 */
	public static function lookup_service_id() {
		$found = self::service_id();
		if ( '' !== $found ) {
			delete_transient( self::CACHE );
		}

		return $found;
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
