<?php
/**
 * Dirty-product queue (custom table).
 *
 * @package Quissly_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * De-duplicated queue of dirty product IDs awaiting catalog sync, backed by a custom
 * table created via dbDelta on activation and dropped on uninstall.
 *
 * DEDUP: the product id is the PRIMARY KEY, so re-enqueuing an id is harmless (a save
 * firing several hooks collapses to one row). The original `created_at` is preserved on
 * re-enqueue so the "oldest pending" flush trigger reflects the true wait. A later
 * `delete` operation overrides a pending `upsert` (the product is gone); an `upsert`
 * never clobbers a pending `delete`.
 *
 * Times are stored and compared in UTC (`gmdate`), independent of the site timezone.
 */
class Quissly_Dirty_Queue {

	const OP_UPSERT = 'upsert';
	const OP_DELETE = 'delete';

	/**
	 * Fully-qualified table name.
	 *
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;

		return $wpdb->prefix . 'quissly_dirty_queue';
	}

	/**
	 * Create the queue table (dbDelta, idempotent). Called on plugin activation.
	 */
	public static function create_table() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table           = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		// dbDelta is whitespace/format sensitive: two spaces after PRIMARY KEY, `KEY`
		// (not INDEX), one column per line.
		$sql = "CREATE TABLE {$table} (
  product_id bigint(20) unsigned NOT NULL,
  operation varchar(20) NOT NULL DEFAULT 'upsert',
  created_at datetime NOT NULL,
  attempts smallint(5) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (product_id),
  KEY created_at (created_at)
) {$charset_collate};";

		dbDelta( $sql );
	}

	/**
	 * Drop the queue table. Called on uninstall.
	 */
	public static function drop_table() {
		global $wpdb;

		$table = self::table_name();
		// Table name is plugin-controlled (not user input); safe to interpolate.
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Enqueue a dirty product id (dedup by id).
	 *
	 * @param int         $product_id WooCommerce product id.
	 * @param string      $operation  self::OP_UPSERT or self::OP_DELETE.
	 * @param string|null $created_at GMT 'Y-m-d H:i:s' override (tests backdate this);
	 *                                defaults to now. Only applied to NEW rows.
	 * @return bool Whether the write succeeded.
	 */
	public function enqueue( $product_id, $operation = self::OP_UPSERT, $created_at = null ) {
		global $wpdb;

		$product_id = (int) $product_id;
		if ( $product_id <= 0 ) {
			return false;
		}
		$operation  = ( self::OP_DELETE === $operation ) ? self::OP_DELETE : self::OP_UPSERT;
		$created_at = $created_at ? $created_at : gmdate( 'Y-m-d H:i:s' );
		$table      = self::table_name();

		// INSERT, or on a duplicate id: let a `delete` win over a pending `upsert`, keep
		// the original created_at (preserve oldest-wait), and reset retries (it changed).
		$sql = $wpdb->prepare(
			"INSERT INTO {$table} (product_id, operation, created_at, attempts)
			 VALUES (%d, %s, %s, 0)
			 ON DUPLICATE KEY UPDATE
			   operation = IF(VALUES(operation) = 'delete', 'delete', operation),
			   attempts = 0", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$product_id,
			$operation,
			$created_at
		);

		return false !== $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Number of pending items.
	 *
	 * @return int
	 */
	public function count_pending() {
		global $wpdb;

		$table = self::table_name();

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Age (in seconds) of the oldest pending item, or null when the queue is empty.
	 *
	 * @return int|null
	 */
	public function oldest_pending_age() {
		global $wpdb;

		$table = self::table_name();
		$min   = $wpdb->get_var( "SELECT MIN(created_at) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( ! $min ) {
			return null;
		}

		return max( 0, time() - (int) strtotime( $min . ' UTC' ) );
	}

	/**
	 * Read (but do not remove) up to $limit oldest items.
	 *
	 * @param int $limit Max rows.
	 * @return array<int,array{product_id:int,operation:string,attempts:int}>
	 */
	public function claim_batch( $limit = 250 ) {
		global $wpdb;

		$table = self::table_name();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT product_id, operation, attempts FROM {$table} ORDER BY created_at ASC, product_id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				(int) $limit
			),
			ARRAY_A
		);

		return array_map(
			static function ( $r ) {
				return array(
					'product_id' => (int) $r['product_id'],
					'operation'  => (string) $r['operation'],
					'attempts'   => (int) $r['attempts'],
				);
			},
			$rows ? $rows : array()
		);
	}

	/**
	 * Remove items by id (on successful sync, or when an item can no longer be mapped).
	 *
	 * @param int[] $product_ids Ids to remove.
	 * @return int Rows removed.
	 */
	public function remove( array $product_ids ) {
		global $wpdb;

		$ids = array_filter( array_map( 'intval', $product_ids ) );
		if ( empty( $ids ) ) {
			return 0;
		}
		$table = self::table_name();
		$in    = implode( ',', $ids ); // ints only — safe.

		return (int) $wpdb->query( "DELETE FROM {$table} WHERE product_id IN ({$in})" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Increment the retry counter for the given ids (after a recoverable failure).
	 *
	 * @param int[] $product_ids Ids to bump.
	 */
	public function bump_attempts( array $product_ids ) {
		global $wpdb;

		$ids = array_filter( array_map( 'intval', $product_ids ) );
		if ( empty( $ids ) ) {
			return;
		}
		$table = self::table_name();
		$in    = implode( ',', $ids );
		$wpdb->query( "UPDATE {$table} SET attempts = attempts + 1 WHERE product_id IN ({$in})" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Of the given ids, those whose attempts have reached or exceeded $max (permanent
	 * failures to drop + log).
	 *
	 * @param int[] $product_ids Candidate ids.
	 * @param int   $max         Max attempts allowed.
	 * @return int[]
	 */
	public function over_max_attempts( array $product_ids, $max ) {
		global $wpdb;

		$ids = array_filter( array_map( 'intval', $product_ids ) );
		if ( empty( $ids ) ) {
			return array();
		}
		$table = self::table_name();
		$in    = implode( ',', $ids );
		$found = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT product_id FROM {$table} WHERE attempts >= %d AND product_id IN ({$in})", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				(int) $max
			)
		);

		return array_map( 'intval', $found ? $found : array() );
	}

	/**
	 * The stored operation for an id (or null if not queued). Test/inspection helper.
	 *
	 * @param int $product_id Product id.
	 * @return string|null
	 */
	public function operation_for( $product_id ) {
		global $wpdb;

		$table = self::table_name();
		$op    = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT operation FROM {$table} WHERE product_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				(int) $product_id
			)
		);

		return null === $op ? null : (string) $op;
	}

	/**
	 * Empty the queue (test/reset helper).
	 */
	public function clear() {
		global $wpdb;

		$table = self::table_name();
		$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}
}
