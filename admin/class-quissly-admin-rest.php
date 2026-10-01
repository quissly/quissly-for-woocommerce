<?php
/**
 * Admin-only REST endpoints (sync progress polling, start/re-sync, connection test).
 *
 * @package Quissly_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The admin progress/control surface the dashboard JS talks to. All routes are
 * capability-gated (`manage_woocommerce`); the cookie + X-WP-Nonce that the dashboard sends
 * authenticates the state-changing POSTs. These are admin endpoints (not the public
 * storefront proxy) — no cache-awareness needed.
 */
class Quissly_Admin_Rest {

	const NAMESPACE = 'quissly/v1';

	/**
	 * Wire the rest_api_init hook.
	 */
	public function register() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the admin routes.
	 */
	public function register_routes() {
		$cap = static function () {
			return current_user_can( Quissly_Admin::CAP );
		};

		register_rest_route(
			self::NAMESPACE,
			'/sync/progress',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_progress' ),
				'permission_callback' => $cap,
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/sync/start',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'start_sync' ),
				'permission_callback' => $cap,
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/connection/test',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'test_connection' ),
				'permission_callback' => $cap,
			)
		);
	}

	/**
	 * Current sync progress + queue depth (read-only poll).
	 *
	 * @return WP_REST_Response
	 */
	public function get_progress() {
		return new WP_REST_Response( $this->snapshot(), 200 );
	}

	/**
	 * Start (or re-run) a full catalog sync: enqueue all products, begin progress, and drain
	 * a couple of batches inline so the UI shows immediate movement; the remainder hands off
	 * to Action Scheduler.
	 *
	 * Inline count is deliberately small (2, not "all"). One flush_batch() sends up to
	 * BATCH_SIZE products, then - on the live client - polls status up to MAX_STATUS_POLLS
	 * times, STATUS_POLL_DELAY seconds apart (worst case ~tens of seconds per batch once the
	 * request round-trips are counted). Draining every batch of a large catalog inline, in
	 * one REST request, risked hitting max_execution_time; nothing wedges if it does - queue
	 * rows aren't claim-marked, so a large catalog still finishes, just via Action Scheduler
	 * once schedule_now() below hands off the remainder.
	 *
	 * @return WP_REST_Response
	 */
	public function start_sync() {
		$worker = new Quissly_Sync_Worker();
		$total  = $worker->start_full_sync( false );
		$worker->drain( 2 );

		$snapshot          = $this->snapshot();
		$snapshot['started'] = true;
		$snapshot['total']   = $total;

		return new WP_REST_Response( $snapshot, 200 );
	}

	/**
	 * Run the live connection probe on demand.
	 *
	 * @return WP_REST_Response
	 */
	public function test_connection() {
		return new WP_REST_Response( Quissly_Connection::probe(), 200 );
	}

	/**
	 * Build the progress snapshot returned by the poll + start endpoints.
	 *
	 * @return array
	 */
	private function snapshot() {
		$progress           = Quissly_Sync_Progress::get();
		$progress['pending'] = ( new Quissly_Dirty_Queue() )->count_pending();
		$progress['gate_open'] = Quissly_Sync_State::is_initial_sync_complete();
		$refusal                  = Quissly_Sync_State::refusal();
		$progress['refused_code'] = $refusal ? (int) $refusal['code'] : 0;
		$progress['gate_blocked'] = Quissly_Sync_State::is_gate_blocked();

		return $progress;
	}
}
