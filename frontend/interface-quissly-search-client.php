<?php
/**
 * Search client seam — the swappable boundary between the interceptor and Quissly.
 *
 * @package Quissly_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A source of qsearch results.
 *
 * Implementations: Quissly_Live_Search_Client (real signed endpoint) and
 * Quissly_Mock_Search_Client (canned, dev-only). The interceptor depends only on this
 * interface, so swapping mock -> live is a one-line filter change.
 */
interface Quissly_Search_Client {

	/**
	 * Run a search.
	 *
	 * @param array $request {query, page_number, page_size, sort_by, sort_type, ...}.
	 * @return array|null The documented qsearch response
	 *                    ({query, num_total_results, documents:[{id,score}], facets:[...]}),
	 *                    or null on timeout / error / not-configured (native fallback).
	 */
	public function search( array $request );
}
