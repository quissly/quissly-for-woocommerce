<?php
/**
 * Catalog sender seam — the swappable boundary between the sync worker and Quissly.
 *
 * @package Quissly_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A destination for catalog batches.
 *
 * Mirrors the search-client seam (Quissly_Search_Client): the worker depends only on
 * this interface, so the live v1-signed sender and the dev/offline mock are
 * interchangeable via the `quissly_catalog_client` filter. NO live call is ever made by
 * the worker directly.
 *
 * Result shape (per call):
 *   array{
 *     ok:                 int[]   product ids accepted,
 *     failed:             int[]   product ids that failed but may be retried,
 *     already_exists:     int[]   (add only) ids rejected as duplicates -> re-route to PUT,
 *     operation_id:       ?string the v1 operation id (status polling), if any,
 *     concurrency_limited: bool   true ONLY on a concurrency-limit rejection (worker
 *                                 backs off 5 min; distinct from a generic failure),
 *     account_refused:    bool    (optional) Quissly refused the ACCOUNT, not the payload
 *                                 (401/402, or 403 with a JSON detail - no active plan or
 *                                 trial, credentials no longer accepted). A retry cannot
 *                                 clear it, so the worker leaves the batch queued with no
 *                                 attempt burned instead of dropping it by attrition,
 *     refused_code:       int     (optional) the HTTP status behind account_refused,
 *   }
 *   or null when the sender is not configured / a transport error occurred — the worker
 *   then leaves the batch queued for a later attempt (no retry consumed).
 */
interface Quissly_Catalog_Client {

	/**
	 * Send a batch of NEW products (POST /add). /add is NOT idempotent — an already-ingested
	 * id is rejected ("already exists"); such ids come back in the result's `already_exists`
	 * so the worker can re-route them to send_updates().
	 *
	 * @param array<int,array> $records Mapped Quissly records, keyed by product id.
	 * @return array|null
	 */
	public function send_upserts( array $records );

	/**
	 * Send a batch of updates to ALREADY-INGESTED products (PUT). The worker routes here when
	 * a product has been ingested before (an update to an existing item must NOT use /add).
	 *
	 * @param array<int,array> $records Mapped Quissly records, keyed by product id.
	 * @return array|null Same result shape as send_upserts().
	 */
	public function send_updates( array $records );

	/**
	 * Send a batch of product deletions.
	 *
	 * @param int[] $ids Product ids to delete.
	 * @return array|null
	 */
	public function send_deletes( array $ids );

	/**
	 * Whether credentials are present so a real send could succeed. The worker uses this
	 * to decide whether rescheduling is worthwhile (an unconfigured store just queues).
	 *
	 * @return bool
	 */
	public function is_configured();
}
