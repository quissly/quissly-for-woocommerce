<?php
/**
 * Mock catalog sender — records batches for OFFLINE sync development/testing.
 *
 * @package Quissly_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * DEV/TEST stand-in for the live v1 catalog sender. Makes NO network call: it records
 * every batch it is handed (so tests can assert batch size, the mapped payload, and the
 * `in_stock:false` out-of-stock path) and returns a structured result.
 *
 * Failure simulation (constructor args) exercises the worker's 207 partial-failure and
 * concurrency-backoff paths without a live API:
 *  - $fail_ids:            those ids come back in `failed` (retryable).
 *  - $concurrency_limited: the call reports a concurrency-limit rejection.
 *  - $refused_code:        non-zero -> the call reports an account refusal with that HTTP
 *                          status (no plan/trial, credentials not accepted).
 */
class Quissly_Mock_Catalog_Client implements Quissly_Catalog_Client {

	/**
	 * Ids the mock should report as failed.
	 *
	 * @var int[]
	 */
	private $fail_ids;

	/**
	 * Whether to report a concurrency-limit rejection.
	 *
	 * @var bool
	 */
	private $concurrency_limited;

	/**
	 * HTTP status of a simulated account refusal (0 = none). Public so a test can lift the
	 * refusal mid-run, the way a merchant fixing their account would.
	 *
	 * @var int
	 */
	public $refused_code = 0;

	/**
	 * Recorded upsert batches (each is the records array passed to send_upserts).
	 *
	 * @var array<int,array>
	 */
	public $sent_upserts = array();

	/**
	 * Recorded UPDATE (PUT) batches (each is the records array passed to send_updates).
	 *
	 * @var array<int,array>
	 */
	public $sent_updates = array();

	/**
	 * Recorded delete batches (each is the id array passed to send_deletes).
	 *
	 * @var array<int,int[]>
	 */
	public $sent_deletes = array();

	/**
	 * @param int[] $fail_ids            Ids to report as failed.
	 * @param bool  $concurrency_limited Report a concurrency-limit rejection.
	 * @param int   $refused_code        Non-zero: report an account refusal with this status.
	 */
	public function __construct( array $fail_ids = array(), $concurrency_limited = false, $refused_code = 0 ) {
		$this->fail_ids            = array_map( 'intval', $fail_ids );
		$this->concurrency_limited = (bool) $concurrency_limited;
		$this->refused_code        = (int) $refused_code;
	}

	/**
	 * @param array<int,array> $records Mapped records keyed by id.
	 * @return array
	 */
	public function send_upserts( array $records ) {
		$this->sent_upserts[] = $records;

		return $this->result( array_map( 'intval', array_keys( $records ) ) );
	}

	/**
	 * @param array<int,array> $records Mapped records keyed by id.
	 * @return array
	 */
	public function send_updates( array $records ) {
		$this->sent_updates[] = $records;

		return $this->result( array_map( 'intval', array_keys( $records ) ) );
	}

	/**
	 * @param int[] $ids Product ids.
	 * @return array
	 */
	public function send_deletes( array $ids ) {
		$ids                  = array_values( array_map( 'intval', $ids ) );
		$this->sent_deletes[] = $ids;

		return $this->result( $ids );
	}

	/**
	 * The mock is always "configured" so the worker exercises its full path offline.
	 *
	 * @return bool
	 */
	public function is_configured() {
		return true;
	}

	/**
	 * Build the result for a set of attempted ids, applying the simulation knobs.
	 *
	 * @param int[] $attempted_ids Ids in this batch.
	 * @return array
	 */
	private function result( array $attempted_ids ) {
		if ( $this->refused_code ) {
			return array(
				'ok'                  => array(),
				'failed'              => array(),
				'already_exists'      => array(),
				'operation_id'        => null,
				'concurrency_limited' => false,
				'account_refused'     => true,
				'refused_code'        => $this->refused_code,
			);
		}
		if ( $this->concurrency_limited ) {
			return array(
				'ok'                  => array(),
				'failed'              => array(),
				'operation_id'        => null,
				'concurrency_limited' => true,
			);
		}

		$failed = array_values( array_intersect( $attempted_ids, $this->fail_ids ) );
		$ok     = array_values( array_diff( $attempted_ids, $failed ) );

		return array(
			'ok'                  => $ok,
			'failed'              => $failed,
			'already_exists'      => array(), // the mock never simulates duplicate rejection.
			'operation_id'        => 'mock-op-' . count( $this->sent_upserts ) . count( $this->sent_deletes ),
			'concurrency_limited' => false,
		);
	}
}
