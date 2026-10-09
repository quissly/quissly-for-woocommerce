<?php
/**
 * Shopping events waiting to be sent (custom table).
 *
 * @package Quissly_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `{prefix}quissly_event_queue`: a shopper's request only writes a row; Quissly_Events::send()
 * (an Action Scheduler action) sends them, so nobody waits for Quissly. Created with the
 * dirty-product queue on activation (and after an update), dropped on uninstall. Times are unix
 * seconds.
 */
final class Quissly_Event_Queue {

	/**
	 * Fully-qualified table name.
	 *
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'quissly_event_queue';
	}

	/**
	 * Create the table (dbDelta, idempotent).
	 */
	public static function create_table() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table           = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();
		// dbDelta is whitespace/format sensitive: two spaces after PRIMARY KEY, `KEY`, one
		// column per line.
		$sql = "CREATE TABLE {$table} (
  queue_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  body longtext NOT NULL,
  attempts smallint(5) unsigned NOT NULL DEFAULT 0,
  created_at int(10) unsigned NOT NULL DEFAULT 0,
  next_at int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (queue_id),
  KEY next_at (next_at)
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
	 * Queue one event body.
	 *
	 * @param array $body Quissly_Event_Body::envelope().
	 * @param int   $now  Time.
	 * @return bool
	 */
	public static function add( array $body, $now ) {
		global $wpdb;
		return false !== $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			self::table_name(),
			array(
				'body'       => wp_json_encode( $body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
				'attempts'   => 0,
				'created_at' => (int) $now,
				'next_at'    => 0,
			),
			array( '%s', '%d', '%d', '%d' )
		);
	}

	/**
	 * The oldest events due now.
	 *
	 * @param int $now   Time.
	 * @param int $limit Rows.
	 * @return array<int, array{queue_id:int,body:array,attempts:int,created_at:int}>
	 */
	public static function due( $now, $limit ) {
		global $wpdb;
		$table = self::table_name();
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT queue_id, body, attempts, created_at FROM {$table} WHERE next_at <= %d ORDER BY queue_id ASC LIMIT %d", (int) $now, (int) $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$out   = array();
		foreach ( (array) $rows as $row ) {
			$body  = json_decode( (string) $row['body'], true );
			$out[] = array(
				'queue_id'   => (int) $row['queue_id'],
				'body'       => is_array( $body ) ? $body : array(),
				'attempts'   => (int) $row['attempts'],
				'created_at' => (int) $row['created_at'],
			);
		}

		return $out;
	}

	/**
	 * When the next event is due, or null when the queue is empty.
	 *
	 * @return int|null
	 */
	public static function next_due() {
		global $wpdb;
		$table = self::table_name();
		$next  = $wpdb->get_var( "SELECT MIN(next_at) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return null === $next ? null : (int) $next;
	}

	/**
	 * Remove sent (or dropped) events.
	 *
	 * @param int[] $ids Queue ids.
	 */
	public static function remove( array $ids ) {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( empty( $ids ) ) {
			return;
		}
		$table = self::table_name();
		$in    = implode( ',', $ids ); // integers only.
		$wpdb->query( "DELETE FROM {$table} WHERE queue_id IN ({$in})" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Try an event again later.
	 *
	 * @param int $id       Queue id.
	 * @param int $attempts Tries made so far.
	 * @param int $next_at  Not before.
	 */
	public static function retry( $id, $attempts, $next_at ) {
		global $wpdb;
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			self::table_name(),
			array(
				'attempts' => (int) $attempts,
				'next_at'  => (int) $next_at,
			),
			array( 'queue_id' => (int) $id ),
			array( '%d', '%d' ),
			array( '%d' )
		);
	}

	/**
	 * Drop the events created before a time, or all of them.
	 *
	 * @param int|null $before Null = every event.
	 * @return int Rows removed.
	 */
	public static function clear( $before = null ) {
		global $wpdb;
		$table = self::table_name();
		if ( null === $before ) {
			return (int) $wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		}

		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %d", (int) $before ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Keep at most $keep events, dropping the oldest (bounds the table while Quissly cannot be
	 * reached on a busy store).
	 *
	 * @param int $keep Rows to keep.
	 * @return int Rows removed.
	 */
	public static function trim( $keep ) {
		global $wpdb;
		$table  = self::table_name();
		$cutoff = $wpdb->get_var( $wpdb->prepare( "SELECT queue_id FROM {$table} ORDER BY queue_id DESC LIMIT 1 OFFSET %d", (int) $keep ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( null === $cutoff ) {
			return 0;
		}

		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE queue_id <= %d", (int) $cutoff ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}
}
