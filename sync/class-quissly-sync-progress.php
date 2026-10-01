<?php
/**
 * Catalog-sync progress state (for the admin progress UI).
 *
 * @package Quissly_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tracks the progress of a full catalog sync: total products, processed, and the per-outcome
 * counts the LIVE status body reports — ok (genuine success), already (duplicate "already
 * exists" skips — counted as ok, surfaced separately for transparency), and failed (genuine
 * failures). The worker calls record() as each batch settles and complete() when the queue
 * drains; the admin polls get() to drive a progress bar.
 *
 * State lives in one option (`quissly_sync_progress`). The compute helpers (percent,
 * eta_seconds) are PURE and unit-tested; the storage is integration-tested.
 */
class Quissly_Sync_Progress {

	const OPTION = 'quissly_sync_progress';

	/** Basis for a cold-start ETA when no live rate exists yet: ~1 hour per 50k products. */
	const RATE_ITEMS_PER_SEC = 50000 / 3600;

	/**
	 * Default/empty state.
	 *
	 * @return array
	 */
	public static function blank() {
		return array(
			'running'     => false,
			'status'      => 'idle', // idle|running|complete
			'total'       => 0,
			'processed'   => 0,
			'ok'          => 0,
			'already'     => 0,
			'failed'      => 0,
			'batches'     => 0,
			'started_at'  => 0,
			'updated_at'  => 0,
			'finished_at' => 0,
		);
	}

	/**
	 * Begin a full sync of $total products (resets counters).
	 *
	 * @param int $total Products to sync.
	 * @param int $now   Current unix time (testable).
	 */
	public static function start( $total, $now = null ) {
		$now   = null === $now ? time() : (int) $now;
		$state = self::blank();
		$state['running']    = true;
		$state['status']     = 'running';
		$state['total']      = max( 0, (int) $total );
		$state['started_at'] = $now;
		$state['updated_at'] = $now;
		self::save( $state );
	}

	/**
	 * Record one settled batch's outcome (no-op unless a sync is running). `already` is a
	 * subset of "in the index" outcomes and is ALSO counted in `ok` for processed totals.
	 *
	 * @param int $ok_count      Genuine successes (already-merged re-routes included).
	 * @param int $failed_count  Genuine failures.
	 * @param int $already_count Duplicate "already exists" skips (informational).
	 * @param int $now           Current unix time (testable).
	 */
	public static function record( $ok_count, $failed_count, $already_count = 0, $now = null ) {
		$state = self::get_raw();
		if ( empty( $state['running'] ) ) {
			return;
		}
		$now = null === $now ? time() : (int) $now;

		$state['ok']        += max( 0, (int) $ok_count );
		$state['failed']    += max( 0, (int) $failed_count );
		$state['already']   += max( 0, (int) $already_count );
		$state['processed'] += max( 0, (int) $ok_count ) + max( 0, (int) $failed_count );
		++$state['batches'];
		$state['updated_at'] = $now;
		self::save( $state );
	}

	/**
	 * Mark the running sync complete.
	 *
	 * @param int $now Current unix time (testable).
	 */
	public static function complete( $now = null ) {
		$state = self::get_raw();
		if ( empty( $state['running'] ) ) {
			return;
		}
		$now = null === $now ? time() : (int) $now;
		$state['running']     = false;
		$state['status']      = 'complete';
		$state['updated_at']  = $now;
		$state['finished_at'] = $now;
		self::save( $state );
	}

	/**
	 * Whether a full sync is currently running.
	 *
	 * @return bool
	 */
	public static function is_running() {
		$state = self::get_raw();

		return ! empty( $state['running'] );
	}

	/**
	 * The full state plus computed `percent` and `eta_seconds` (for the admin/REST layer).
	 *
	 * @param int $now Current unix time (testable).
	 * @return array
	 */
	public static function get( $now = null ) {
		$now   = null === $now ? time() : (int) $now;
		$state = self::get_raw();
		$state['percent']     = self::percent( $state );
		$state['eta_seconds'] = self::eta_seconds( $state, $now );

		return $state;
	}

	/**
	 * Reset to idle (test/reset helper).
	 */
	public static function reset() {
		delete_option( self::OPTION );
	}

	// --- Pure compute helpers (unit-tested) -------------------------------------------

	/**
	 * Completion percentage (0-100). 100 when complete; capped at 100 if edits arrive
	 * mid-sync and processed exceeds the original total.
	 *
	 * @param array $state State.
	 * @return int
	 */
	public static function percent( array $state ) {
		$total     = isset( $state['total'] ) ? (int) $state['total'] : 0;
		$processed = isset( $state['processed'] ) ? (int) $state['processed'] : 0;
		$running   = ! empty( $state['running'] );

		if ( $total <= 0 ) {
			return $running ? 0 : 100;
		}

		return (int) min( 100, max( 0, (int) floor( $processed * 100 / $total ) ) );
	}

	/**
	 * Estimated seconds remaining, from the observed rate (processed / elapsed); falls back
	 * to the ~1hr/50k basis before any rate exists. 0 when not running or already done.
	 *
	 * @param array $state State.
	 * @param int   $now   Current unix time.
	 * @return int
	 */
	public static function eta_seconds( array $state, $now ) {
		if ( empty( $state['running'] ) ) {
			return 0;
		}
		$total     = (int) ( $state['total'] ?? 0 );
		$processed = (int) ( $state['processed'] ?? 0 );
		$remaining = max( 0, $total - $processed );
		if ( 0 === $remaining ) {
			return 0;
		}

		$elapsed = max( 0, (int) $now - (int) ( $state['started_at'] ?? $now ) );
		$rate    = ( $processed > 0 && $elapsed > 0 ) ? ( $processed / $elapsed ) : self::RATE_ITEMS_PER_SEC;
		if ( $rate <= 0 ) {
			$rate = self::RATE_ITEMS_PER_SEC;
		}

		return (int) ceil( $remaining / $rate );
	}

	// --- Storage ----------------------------------------------------------------------

	/**
	 * Raw stored state merged over the blank defaults.
	 *
	 * @return array
	 */
	private static function get_raw() {
		$stored = get_option( self::OPTION, array() );

		return array_merge( self::blank(), is_array( $stored ) ? $stored : array() );
	}

	/**
	 * Persist state (autoload off — it changes frequently during a sync).
	 *
	 * @param array $state State.
	 */
	private static function save( array $state ) {
		update_option( self::OPTION, $state, false );
	}
}
