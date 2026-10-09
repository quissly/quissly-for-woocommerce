<?php
/**
 * Live catalog sender (v1-signed) — POST add / PUT update / DELETE.
 *
 * @package Quissly_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The real v1-signed catalog sender.
 *
 * VERIFIED LIVE:
 *  - v1 signing authenticates; POST /v1beta/catalog (add) returns HTTP 200 + operation_id
 *    once records match the ProductItem schema (original_price numeric; ids STRINGS — FACT 1).
 *  - Ingest->search round-trip WORKS once ids are strings: qsearch for an ingested title
 *    returns results with the synced categories as `category` term-facet values and an
 *    `original_price` range facet (the synced field name is the returned facet name).
 *
 * SEARCH-RESULT ID: qsearch returns the WooCommerce post id at documents[].id, read by the
 * response parser - no mapping layer. (An interim backend returned a UUIDv5 with the post id in
 * metadata.q_external_id; that was reverted.)
 *
 * SETTLED ON THE SEND'S ANSWER (2026-10-07, the Quissly Shopify app's way): a
 * 2xx to the POST/PUT/DELETE is the batch delivered; the operation's status is not polled.
 * No answer leaves the batch queued (no attempt spent), 429 backs off, an account refusal
 * pauses, any other status is a failure the worker retries then drops.
 *
 * STATUS (for diagnostics only - tests/live/*): GET /v1beta/catalog with QUERY PARAMS
 * (operation_id, timestamp, service=search), a FRESH ISO 8601 timestamp (T separator) that is
 * also the signed `{op}.{ts}` payload, returns HTTP 200 with the real outcome in the BODY:
 * `status` + a per-item `data` map keyed by uuid carrying `q_external_id`, per-item `status`
 * and `reason`. interpret_status() classifies such a body; the sync never reads it.
 */
class Quissly_Live_Catalog_Client implements Quissly_Catalog_Client {

	const PATH = '/v1beta/catalog';

	/**
	 * Send NEW products via POST /add. /add is NOT idempotent — duplicates come back in
	 * `already_exists` for the worker to re-route to send_updates().
	 *
	 * @param array<int,array> $records Mapped records keyed by id.
	 * @return array|null
	 */
	public function send_upserts( array $records ) {
		return $this->send_mutation( 'POST', $records, 'add' );
	}

	/**
	 * Send updates to ALREADY-INGESTED products via PUT (verified against the live API). Used when a product
	 * has been ingested before — /add would reject it as a duplicate.
	 *
	 * @param array<int,array> $records Mapped records keyed by id.
	 * @return array|null
	 */
	public function send_updates( array $records ) {
		return $this->send_mutation( 'PUT', $records, 'update' );
	}

	/**
	 * Shared add/update path: build the v1-signed body, send, settle on the response.
	 *
	 * @param string           $method  'POST' (add) or 'PUT' (update).
	 * @param array<int,array> $records Records keyed by id.
	 * @param string           $ctx     'add'|'update' (logging).
	 * @return array|null
	 */
	private function send_mutation( $method, array $records, $ctx ) {
		if ( ! $this->is_configured() || empty( $records ) ) {
			return null;
		}

		$ts   = Quissly_Signer::format_timestamp_iso( microtime( true ) );
		$data = array();
		foreach ( $records as $id => $rec ) {
			$data[ (string) $id ] = $rec;
		}
		$first   = self::upsert_first_id( $data );
		$all_ids = array_map( 'intval', array_keys( $data ) );
		$body    = array( 'data' => $data, 'service' => 'search', 'timestamp' => $ts );

		$res = $this->http()->request_v1( $method, self::PATH, $body, $first, $ts, 60 );

		return $this->settle( $res, $all_ids, $ctx );
	}

	/**
	 * Interpret a status-poll result into a terminal decision. PURE + unit-testable.
	 *
	 * RETAINED FOR RESTORATION (currently UNUSED in production — see CHANGE 2). This is the
	 * per-item classifier (by q_external_id), for diagnostics (tests/live/*): the sync settles
	 * on the send's answer and never reads the status.
	 *
	 * The body shape, verified against the live API (the corrected query-param status form returns HTTP 200 with
	 * the real outcome in the BODY, not the HTTP code):
	 *   { "type":"add", "status":"completed"|"partially completed"|...,
	 *     "summary":{n_requested,n_successful,n_failed},
	 *     "data": { "<uuid>": { "q_external_id":"<wc id>", "status":"failed"|..., "reason":"..." }, ... },
	 *     "finished_at": ... }
	 * So we classify PER ITEM by `q_external_id` (the WC id the worker's queue is keyed by):
	 *  - a successful item -> ok.
	 *  - a duplicate skip whose reason is "already exists" -> already_exists. The item IS in
	 *    the index, but on the ADD path it means we mis-routed an existing product, so the
	 *    worker re-routes it to PUT (so an update actually applies). On the PUT path the worker
	 *    just treats it as ok.
	 *  - a genuine failure -> failed (the worker retries-then-logs).
	 * HTTP/processing handling: 5xx -> failed; 404 (unexpected with the corrected form) and a
	 * "pending/processing" body -> pending (keep polling).
	 *
	 * @param int   $code    HTTP status code.
	 * @param mixed $body    Decoded response body.
	 * @param int[] $all_ids Ids in the batch.
	 * @return array{state:string,ok:int[],failed:int[],already_exists:int[]}
	 */
	public static function interpret_status( $code, $body, array $all_ids ) {
		$code    = (int) $code;
		$all_ids = array_values( array_map( 'intval', $all_ids ) );

		if ( $code >= 500 ) {
			return array( 'state' => 'failed', 'ok' => array(), 'failed' => $all_ids, 'already_exists' => array() );
		}
		// 404 is unexpected with the corrected query-param form — treat as "not ready yet".
		if ( 404 === $code ) {
			return array( 'state' => 'pending', 'ok' => array(), 'failed' => array(), 'already_exists' => array() );
		}

		$top = ( is_array( $body ) && isset( $body['status'] ) ) ? strtolower( (string) $body['status'] ) : '';

		// Still processing -> keep polling.
		if ( 202 === $code || in_array( $top, array( 'pending', 'processing', 'in progress', 'in_progress', 'queued', 'running', 'started' ), true ) ) {
			return array( 'state' => 'pending', 'ok' => array(), 'failed' => array(), 'already_exists' => array() );
		}

		// Terminal with itemized per-product results (the observed shape): classify each.
		if ( is_array( $body ) && ! empty( $body['data'] ) && is_array( $body['data'] ) ) {
			$ok       = array();
			$failed   = array();
			$already  = array();
			foreach ( $body['data'] as $item ) {
				$wc = self::item_wc_id( $item );
				if ( null === $wc ) {
					continue;
				}
				if ( self::item_is_already_exists( $item ) ) {
					$already[] = $wc;
				} elseif ( self::item_is_success( $item ) ) {
					$ok[] = $wc;
				} else {
					$failed[] = $wc;
				}
			}
			$ok      = array_values( array_unique( $ok ) );
			$failed  = array_values( array_unique( $failed ) );
			$already = array_values( array_unique( $already ) );
			$state   = empty( $failed ) ? 'completed' : ( ( empty( $ok ) && empty( $already ) ) ? 'failed' : 'partial' );

			return array( 'state' => $state, 'ok' => $ok, 'failed' => $failed, 'already_exists' => $already );
		}

		// Terminal, no itemized data: trust the top-level status.
		if ( in_array( $top, array( 'failed', 'error' ), true ) ) {
			return array( 'state' => 'failed', 'ok' => array(), 'failed' => $all_ids, 'already_exists' => array() );
		}

		// 'completed' / 'partially completed' (with no data) / unknown -> accept the batch
		// (avoids a retry loop; the items were received).
		return array( 'state' => 'completed', 'ok' => $all_ids, 'failed' => array(), 'already_exists' => array() );
	}

	/**
	 * The WC id of a status-body item, from its `q_external_id` (well-formed positive int).
	 *
	 * @param mixed $item One entry of body.data.
	 * @return int|null
	 */
	private static function item_wc_id( $item ) {
		if ( is_array( $item ) && isset( $item['q_external_id'] ) ) {
			$ext = (string) $item['q_external_id'];
			if ( ctype_digit( $ext ) && (int) $ext > 0 ) {
				return (int) $ext;
			}
		}

		return null;
	}

	/**
	 * Whether a status-body item is a genuine SUCCESS. The live API's per-item success string
	 * is "successful" (top-level "completed"); the rest are defensive synonyms.
	 *
	 * @param mixed $item One entry of body.data.
	 * @return bool
	 */
	private static function item_is_success( $item ) {
		$status = ( is_array( $item ) && isset( $item['status'] ) ) ? strtolower( (string) $item['status'] ) : '';

		return in_array( $status, array( 'successful', 'success', 'succeeded', 'completed', 'ok', 'created', 'updated', 'added' ), true );
	}

	/**
	 * Whether a status-body item is a benign "already exists" duplicate skip (observed live:
	 * status "failed", reason "Skipping: Item already exists"). The item IS in the index.
	 *
	 * @param mixed $item One entry of body.data.
	 * @return bool
	 */
	private static function item_is_already_exists( $item ) {
		$reason = ( is_array( $item ) && isset( $item['reason'] ) ) ? strtolower( (string) $item['reason'] ) : '';

		return false !== strpos( $reason, 'already exists' ) || false !== strpos( $reason, 'skipping' );
	}

	/**
	 * @param int[] $ids Product ids.
	 * @return array|null
	 */
	public function send_deletes( array $ids ) {
		$ids = array_values( $ids );
		if ( ! $this->is_configured() || empty( $ids ) ) {
			return null;
		}

		$ts    = Quissly_Signer::format_timestamp_iso( microtime( true ) );
		$first = (string) $ids[0];
		// verified against the live API: delete carries `data` as an array of OBJECTS { "id": "<string id>" }
		// and the timestamp under `timestamp` (same key as add). A flat string array was
		// REJECTED (422: "Input should be a valid dictionary"); an earlier `ts` key was also
		// REJECTED (422). The signed ts matches the body's timestamp.
		$body = array( 'data' => self::delete_data( $ids ), 'service' => 'search', 'timestamp' => $ts );

		$res = $this->http()->request_v1( 'DELETE', self::PATH, $body, $first, $ts, 60 );

		return $this->settle( $res, $ids, 'delete' );
	}

	/**
	 * The `data` array for a DELETE: an array of OBJECTS { "id": "<string id>" } (verified against the live API
	 * shape). Ids are cast to STRING (FACT 1) but NOT intval'd — a non-numeric/UUID id must
	 * survive intact. Pure + unit-testable.
	 *
	 * @param array<int|string> $ids Product ids (WooCommerce-side ints, or external string ids).
	 * @return array<int,array{id:string}>
	 */
	public static function delete_data( array $ids ) {
		return array_values(
			array_map(
				static function ( $id ) {
					return array( 'id' => (string) $id );
				},
				$ids
			)
		);
	}

	/**
	 * The v1 signed payload parameter for an upsert batch: the FIRST product id in STRING
	 * form, so the signed `{first_id}.{ts}` matches the string id type sent on the wire
	 * (FACT 1). Pure + unit-testable.
	 *
	 * @param array<int|string,mixed> $data Records keyed by product id.
	 * @return string
	 */
	public static function upsert_first_id( array $data ) {
		return (string) array_key_first( $data );
	}

	/**
	 * Whether credentials are present (private key + token). Even with the status endpoint
	 * unverified, the verified Add path makes sending worthwhile.
	 *
	 * @return bool
	 */
	public function is_configured() {
		$store = new Quissly_Key_Store();

		return (bool) $store->get_private_key() && '' !== Quissly_Env::token();
	}

	/**
	 * Map an HTTP result to the catalog-client result shape.
	 *
	 * @param array|WP_Error $res Result from request_v1.
	 * @param int[]          $ids Attempted ids.
	 * @param string         $ctx 'upsert'|'delete'.
	 * @return array|null
	 */
	private function settle( $res, array $ids, $ctx ) {
		if ( is_wp_error( $res ) ) {
			return null; // transport error -> leave queued, no retry consumed.
		}

		$code = (int) $res['code'];

		if ( in_array( $code, array( 200, 201, 202 ), true ) ) {
			// Accepted: the batch is delivered (its operation's status is not read).
			return array(
				'ok'                  => $ids,
				'failed'              => array(),
				'already_exists'      => array(),
				'operation_id'        => isset( $res['body']['operation_id'] ) ? $res['body']['operation_id'] : null,
				'concurrency_limited' => false,
			);
		}

		if ( 429 === $code ) {
			return array( 'ok' => array(), 'failed' => array(), 'already_exists' => array(), 'operation_id' => null, 'concurrency_limited' => true );
		}

		// A refusal of the ACCOUNT, not the payload: no active plan/trial (402, or 403 with
		// a JSON detail such as "No active quota grant for this service"), or credentials
		// Quissly no longer accepts (401). Retrying cannot clear these, and marking the ids
		// failed would drop the whole catalog after MAX_ATTEMPTS - the queue would drain by
		// attrition and look exactly like a finished sync. A 403 WITHOUT a JSON body is an
		// edge/infrastructure block: transient, so leave queued like a transport error.
		// (Same classification as the Magento plugin's sync.)
		if ( 401 === $code || 402 === $code || ( 403 === $code && is_array( $res['body'] ) ) ) {
			Quissly_Sync_Log::log( 'catalog ' . $ctx . ' REFUSED by Quissly (HTTP ' . $code . '): account not accepting catalog updates; ' . count( $ids ) . ' ids left queued.' );

			return array( 'ok' => array(), 'failed' => array(), 'already_exists' => array(), 'operation_id' => null, 'concurrency_limited' => false, 'account_refused' => true, 'refused_code' => $code );
		}
		if ( 403 === $code ) {
			Quissly_Sync_Log::log( 'catalog ' . $ctx . ' blocked HTTP 403 with no JSON body (edge/infrastructure); left queued.' );

			return null;
		}

		// 4xx/5xx: log (ids/code only) and mark failed so the worker retries then drops —
		// a malformed batch must not loop forever.
		Quissly_Sync_Log::log( 'catalog ' . $ctx . ' rejected HTTP ' . $code . ' (ids: ' . implode( ',', $ids ) . ')' );

		return array( 'ok' => array(), 'failed' => $ids, 'already_exists' => array(), 'operation_id' => null, 'concurrency_limited' => false );
	}

	/**
	 * Build a signed HTTP client.
	 *
	 * @return Quissly_Http_Client
	 */
	private function http() {
		$store = new Quissly_Key_Store();

		return new Quissly_Http_Client(
			Quissly_Env::token(),
			Quissly_Env::environment(),
			new Quissly_Signer( $store->get_private_key() )
		);
	}
}
