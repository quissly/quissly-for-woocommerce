<?php
/**
 * Request counters for the public storefront endpoints (custom table).
 *
 * @package Quissly_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `{prefix}quissly_rate`: one row per counter (a visitor on an endpoint, or the whole store on an
 * endpoint), a fixed window each. It replaces the per-visitor transients the endpoints used: a bot
 * rotating addresses wrote two `wp_options` rows per address. The table never holds more than
 * MAX_ROWS (older windows go first; a dropped visitor simply starts counting again).
 *
 * STORE_LIMITS is the store-wide limit per endpoint, on top of each endpoint's per-visitor limit: many
 * addresses together still cannot write or spend more than this (the CS-Cart add-on's numbers, after a
 * client's review, 2026-11-03). A missing table (an update before quissly_after_update() created it)
 * never refuses a shopper.
 */
final class Quissly_Rate_Limiter {

	const MAX_ROWS = 20000;

	/** Per endpoint: array( requests, window seconds ), for the whole store. */
	const STORE_LIMITS = array(
		'event'       => array( 1200, 60 ),
		'quick'       => array( 1200, 60 ),
		'cart_adjust' => array( 600, 60 ),
		'voice'       => array( 60, 600 ),
		'image'       => array( 60, 600 ),
	);

	/**
	 * Fully-qualified table name.
	 *
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'quissly_rate';
	}

	/**
	 * Create the table (dbDelta, idempotent).
	 */
	public static function create_table() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table           = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();
		$sql             = "CREATE TABLE {$table} (
  bucket char(40) NOT NULL,
  window_start int(10) unsigned NOT NULL DEFAULT 0,
  hits int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (bucket),
  KEY window_start (window_start)
) {$charset_collate};";
		dbDelta( $sql );
	}

	/**
	 * Drop the table. Called on uninstall.
	 */
	public static function drop_table() {
		global $wpdb;
		$table = self::table_name();
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Count one request in $bucket; false when the window already holds $max.
	 *
	 * @param string $bucket Counter name (hashed before it is stored).
	 * @param int    $max    Requests per window.
	 * @param int    $window Window, seconds.
	 * @param int    $now    Time.
	 * @return bool
	 */
	public static function allow( $bucket, $max, $window, $now ) {
		global $wpdb;
		$table = self::table_name();
		$key   = substr( hash( 'sha256', (string) $bucket ), 0, 40 );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$suppress = $wpdb->suppress_errors( true );
		$row      = $wpdb->get_row( $wpdb->prepare( "SELECT window_start, hits FROM {$table} WHERE bucket = %s", $key ), ARRAY_A );
		if ( ! empty( $wpdb->last_error ) ) {
			$wpdb->suppress_errors( $suppress );
			return true;
		}
		if ( empty( $row ) || (int) $row['window_start'] <= $now - $window ) {
			$wpdb->query( $wpdb->prepare( "REPLACE INTO {$table} (bucket, window_start, hits) VALUES (%s, %d, 1)", $key, $now ) );
			if ( 1 === wp_rand( 1, 50 ) ) {
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE window_start < %d", $now - 3600 ) );
				$over = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ) - self::MAX_ROWS;
				if ( $over > 0 ) {
					$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} ORDER BY window_start ASC LIMIT %d", $over ) );
				}
			}
			$wpdb->suppress_errors( $suppress );
			return true;
		}
		if ( (int) $row['hits'] >= $max ) {
			$wpdb->suppress_errors( $suppress );
			return false;
		}
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET hits = hits + 1 WHERE bucket = %s", $key ) );
		$wpdb->suppress_errors( $suppress );
		// phpcs:enable

		return true;
	}

	/**
	 * Whether the store-wide limit lets one more request to $endpoint through. PURE given $allow.
	 *
	 * @param string        $endpoint A STORE_LIMITS key.
	 * @param callable|null $allow    ( string $bucket, int $max, int $window ): bool; null = this table, now.
	 * @return bool
	 */
	public static function store_allows( $endpoint, $allow = null ) {
		if ( ! isset( self::STORE_LIMITS[ $endpoint ] ) ) {
			return true;
		}
		list( $max, $window ) = self::STORE_LIMITS[ $endpoint ];
		if ( null === $allow ) {
			$allow = static function ( $bucket, $max, $window ) {
				return self::allow( $bucket, $max, $window, time() );
			};
		}

		return (bool) $allow( 'store|' . $endpoint, $max, $window );
	}
}
