<?php
/**
 * WP-CLI control plane (dev/test conveniences, alongside the admin UI).
 *
 * @package Quissly_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `wp quissly <command>` — drive keygen, status, and connection testing from the shell.
 *
 * These coexist with the admin UI; they do not replace it. Secrets (private key, token)
 * are NEVER printed.
 */
class Quissly_CLI {

	/**
	 * Generate an RSA-2048 keypair and store the private key encrypted (Mechanism 2).
	 *
	 * ## OPTIONS
	 *
	 * [--force]
	 * : Regenerate even if a key already exists (invalidates the current registration).
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Associative args.
	 */
	public function generate_keys( $args, $assoc_args ) {
		$store = new Quissly_Key_Store();

		if ( $store->is_dev_override_active() ) {
			WP_CLI::warning( 'A dev .env private-key override is active; the generated key will not be used for signing while it remains set.' );
		}

		if ( $store->has_stored_key() && empty( $assoc_args['force'] ) ) {
			WP_CLI::error( 'A key already exists. Re-run with --force to regenerate (this invalidates the key currently registered with Quissly).' );
		}

		$public = $store->generate_and_store();

		WP_CLI::success( 'Generated an RSA-2048 keypair; private key stored AES-256-GCM encrypted in the options table.' );
		WP_CLI::line( '' );
		WP_CLI::line( 'Public key — upload at admin.quissly.com to mint a bearer token:' );
		WP_CLI::line( $public );
	}

	/**
	 * Show connection and configuration status (no secrets).
	 */
	public function status() {
		$store = new Quissly_Key_Store();

		if ( $store->is_dev_override_active() ) {
			$key_source = 'dev .env override (Mechanism 1)';
		} elseif ( $store->has_stored_key() ) {
			$key_source = 'encrypted DB (Mechanism 2)';
		} else {
			$key_source = 'none — run `wp quissly generate-keys`';
		}

		$queue   = new Quissly_Dirty_Queue();
		$pending = $queue->count_pending();
		$age     = $queue->oldest_pending_age();
		$queue_line = $pending . ' pending' . ( null !== $age ? ' (oldest ' . $age . 's)' : '' );

		WP_CLI::line( 'Environment (X-Environment): ' . Quissly_Env::environment() );
		WP_CLI::line( 'Private key source:          ' . $key_source );
		WP_CLI::line( 'Bearer token:                ' . ( '' !== Quissly_Env::token() ? 'configured' : 'missing' ) );
		WP_CLI::line( 'Sync queue:                  ' . $queue_line );
		WP_CLI::line( 'Initial sync complete:       ' . ( Quissly_Sync_State::is_initial_sync_complete() ? 'yes (search interception gate OPEN)' : 'no (interception gated OFF)' ) );
	}

	/**
	 * Mark the initial sync complete (opens the search-interception gate), or reset it.
	 *
	 * The flag is normally set by the live sync / setup wizard when the first full ingest
	 * finishes; this command drives it without the UI for dev/testing.
	 *
	 * ## OPTIONS
	 *
	 * [--reset]
	 * : Clear the flag instead of setting it (re-gates interception OFF).
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Associative args.
	 */
	public function mark_synced( $args, $assoc_args ) {
		if ( ! empty( $assoc_args['reset'] ) ) {
			Quissly_Sync_State::reset_initial_sync();
			WP_CLI::success( 'Initial-sync flag cleared; search interception is gated OFF (native search).' );

			return;
		}

		Quissly_Sync_State::mark_initial_sync_complete();
		WP_CLI::success( 'Initial-sync flag set; the search-interception gate is now OPEN.' );
	}

	/**
	 * Enqueue every published product for catalog sync (the initial-sync trigger).
	 *
	 * Drives the dirty queue without the admin UI. The Action Scheduler worker flushes
	 * batches (<=250) once a catalog sender is configured; NO live call is made here.
	 */
	public function sync_all() {
		if ( ! function_exists( 'wc_get_products' ) ) {
			WP_CLI::error( 'WooCommerce is not available.' );
		}

		$ids   = ( new Quissly_Sync_Worker() )->catalog_ids(); // a multilingual store's main language only
		$queue = new Quissly_Dirty_Queue();
		foreach ( $ids as $id ) {
			$queue->enqueue( (int) $id, Quissly_Dirty_Queue::OP_UPSERT );
		}

		WP_CLI::success( sprintf( 'Enqueued %d products. Pending in queue: %d.', count( $ids ), $queue->count_pending() ) );
		WP_CLI::line( 'The worker flushes batches (<=250) on Action Scheduler once a catalog sender is configured.' );
		WP_CLI::line( 'No live Quissly call is made until credentials exist.' );
	}

	/**
	 * Test the connection with a minimal signed qsearch (200, even empty, = success).
	 *
	 * Live verification requires credentials. Without them, reports what is
	 * missing rather than attempting a call that cannot succeed.
	 */
	public function test_connection() {
		$result = Quissly_Connection::probe();

		if ( ! $result['configured'] ) {
			WP_CLI::warning( $result['message'] );
			WP_CLI::line( 'Dev: set QUISSLY_DEV_PRIVATE_PEM + QUISSLY_TOKEN + QUISSLY_ENV in .env (key pre-registered at admin.quissly.com).' );
			WP_CLI::line( 'Prod: run `wp quissly generate-keys`, upload the public key, then paste the minted token.' );

			return;
		}

		if ( $result['ok'] ) {
			WP_CLI::success( $result['message'] );
		} else {
			WP_CLI::error( $result['message'] );
		}
	}
}
