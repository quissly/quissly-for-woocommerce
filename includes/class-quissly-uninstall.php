<?php
/**
 * Uninstall routine (testable).
 *
 * @package Quissly_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Performs the uninstall cleanup. Extracted from uninstall.php so it can be exercised
 * directly in integration tests (with snapshot/restore) instead of by deleting the plugin.
 */
class Quissly_Uninstall {

	/**
	 * Remove the plugin's data.
	 *
	 * Runtime artifacts (dirty-queue table, sync log) are removed unconditionally. Options
	 * (configuration + keys) are removed unless $preserve is true.
	 *
	 * @param bool $preserve Keep configuration + keys (the merchant's "preserve" toggle).
	 */
	public static function run( $preserve = false ) {
		global $wpdb;

		require_once QUISSLY_PLUGIN_DIR . 'sync/class-quissly-dirty-queue.php';
		require_once QUISSLY_PLUGIN_DIR . 'sync/class-quissly-sync-log.php';
		require_once QUISSLY_PLUGIN_DIR . 'includes/class-quissly-event-queue.php';

		Quissly_Dirty_Queue::drop_table();
		Quissly_Event_Queue::drop_table();
		Quissly_Sync_Log::delete_log();

		// Cancel any pending/recurring flush - runtime state, like the queue table above,
		// removed unconditionally even when $preserve keeps configuration. Without this an
		// uninstall left an orphaned Action Scheduler row that fires flush_batch() with
		// nothing left to load it (the plugin's classes are gone), or - on a later
		// reinstall before that row's turn comes up - fired against a queue the fresh
		// install never populated.
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( '', array(), 'quissly' );
		}

		// Per-product ingested markers (Quissly_Sync_Worker::INGESTED_META) are runtime sync
		// state, not configuration — remove unconditionally (like the queue table); a reinstall
		// rebuilds them on the next sync.
		$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_quissly_ingested'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		// The "purchase sent" marker on orders (Quissly_Events::PURCHASE_META) - runtime state
		// too. Orders live in postmeta, or in wc_orders_meta under HPOS.
		$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_quissly_purchase_recorded'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$orders_meta = $wpdb->prefix . 'wc_orders_meta';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $orders_meta ) ) === $orders_meta ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( "DELETE FROM {$orders_meta} WHERE meta_key = '_quissly_purchase_recorded'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		}

		if ( $preserve ) {
			return;
		}

		$like = $wpdb->esc_like( 'quissly_' ) . '%';
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$t1 = $wpdb->esc_like( '_transient_quissly_' ) . '%';
		$t2 = $wpdb->esc_like( '_transient_timeout_quissly_' ) . '%';
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $t1, $t2 ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		delete_site_transient( 'quissly_update_release' ); // the update check's cache (a site transient).

		// Direct DELETEs bypass the options cache; clear it so the wipe is observable.
		wp_cache_flush();
	}
}
