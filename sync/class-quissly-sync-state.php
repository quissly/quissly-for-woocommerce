<?php
/**
 * Sync state — the "initial sync completed" production gate.
 *
 * @package Quissly_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tracks whether the catalog has been fully synced to Quissly at least once.
 *
 * PRODUCTION GATE: search interception must stay OFF until this is true, so a fresh
 * install whose catalog has not yet been indexed never serves shoppers an empty Quissly
 * result set (it falls back to native WooCommerce search instead). The orchestrator checks
 * this before registering the interceptor — independently of the dev mock-search flag,
 * which only selects WHICH client is used, not WHETHER interception runs.
 *
 * Set-points:
 *  - the live sync marks it complete when the first full ingest finishes (wired later),
 *  - the wizard's "run initial sync" step marks it complete,
 *  - `wp quissly mark-synced` (dev/test) sets/resets it,
 *  - the optional QUISSLY_FORCE_SYNC_COMPLETE constant forces it open for ephemeral dev
 *    environments without touching the DB (NOT set in committed config).
 */
class Quissly_Sync_State {

	const OPT_INITIAL_SYNC = 'quissly_initial_sync_complete';

	/**
	 * Whether the initial full sync has completed at least once.
	 *
	 * @return bool
	 */
	public static function is_initial_sync_complete() {
		if ( defined( 'QUISSLY_FORCE_SYNC_COMPLETE' ) && QUISSLY_FORCE_SYNC_COMPLETE ) {
			return true;
		}

		return (bool) get_option( self::OPT_INITIAL_SYNC, false );
	}

	/**
	 * Mark the initial sync complete (called by the live sync / wizard / CLI).
	 */
	public static function mark_initial_sync_complete() {
		update_option( self::OPT_INITIAL_SYNC, true, false );
	}

	/**
	 * Clear the flag (re-gates interception; dev/test + "reset connection" use).
	 */
	public static function reset_initial_sync() {
		delete_option( self::OPT_INITIAL_SYNC );
	}

	/** Option: {code, at} of the latest account refusal; absent when Quissly is accepting. */
	const OPT_REFUSED = 'quissly_sync_refused';

	/** Option: unix time a full sync drained without delivering anything. */
	const OPT_GATE_BLOCKED = 'quissly_sync_gate_blocked';

	/**
	 * Record that Quissly refused catalog updates for the account.
	 *
	 * @param int $code HTTP status.
	 */
	public static function record_refusal( $code ) {
		update_option( self::OPT_REFUSED, array( 'code' => (int) $code, 'at' => time() ), false );
	}

	/**
	 * Clear the refusal (Quissly accepted a batch again).
	 */
	public static function clear_refusal() {
		delete_option( self::OPT_REFUSED );
	}

	/**
	 * The latest refusal, or null when none is outstanding.
	 *
	 * @return array{code:int,at:int}|null
	 */
	public static function refusal() {
		$refusal = get_option( self::OPT_REFUSED );

		return is_array( $refusal ) && ! empty( $refusal['code'] ) ? $refusal : null;
	}

	/**
	 * A full sync finished but nothing reached Quissly, so the gate was kept shut.
	 */
	public static function mark_gate_blocked() {
		update_option( self::OPT_GATE_BLOCKED, time(), false );
	}

	/**
	 * Clear the blocked marker (a new sync started, or one delivered).
	 */
	public static function clear_gate_blocked() {
		delete_option( self::OPT_GATE_BLOCKED );
	}

	/**
	 * Whether the last full sync drained without delivering anything.
	 *
	 * @return bool
	 */
	public static function is_gate_blocked() {
		return (bool) get_option( self::OPT_GATE_BLOCKED, false );
	}
}
