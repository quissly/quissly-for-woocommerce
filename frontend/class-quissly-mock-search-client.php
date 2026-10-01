<?php
/**
 * Mock search client — canned results for offline development.
 *
 * @package Quissly_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * DEV-ONLY stand-in for the live qsearch call. Returns a canned response in the
 * documented shape using REAL sample-store product IDs, so the storefront renders them.
 *
 * Reachable ONLY when the dev flag QUISSLY_USE_MOCK_SEARCH is set (see the orchestrator);
 * never wired in a production path. Simulation hooks (dev-only) read `?quissly_mock=`:
 *  - failall   -> return null on every page (page-1 failure: native fallback)
 *  - failpage2 -> return null on page >= 2 (page-2+ failure: graceful, no native swap)
 *
 * Reports num_total_results far larger than the page so pagination is exercised, and a
 * DIFFERENT ordering for price sort (sort_by 2) to prove the sort wiring. (Documents carry
 * id + score; top_variant_id is OPTIONAL and only returned by the live API — the variant
 * deep-link is verified live, not via a canned mock fixture.)
 */
class Quissly_Mock_Search_Client implements Quissly_Search_Client {

	const TOTAL = 50;

	/**
	 * Canned relevance ordering (real, search-visible sample-product IDs).
	 * (Product 19 is set exclude-from-search in this store, so it is intentionally
	 * omitted — WooCommerce's visibility filter would drop it anyway.)
	 */
	const RELEVANCE_ORDER = array( 18, 17, 16, 15, 14, 13, 12, 11, 34, 33, 32, 31, 24, 23, 22, 21, 20 );

	/** Canned price-ascending ordering — a visibly different first product (11, not 18). */
	const PRICE_ORDER = array( 11, 12, 13, 14, 15, 16, 17, 18, 20, 21, 22, 23, 24, 31, 32, 33, 34 );

	/**
	 * @param array $request Request body.
	 * @return array|null
	 */
	public function search( array $request ) {
		$page = max( 1, (int) ( $request['page_number'] ?? 1 ) );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- dev-only simulation switch, read-only.
		$sim = isset( $_GET['quissly_mock'] ) ? sanitize_key( wp_unslash( $_GET['quissly_mock'] ) ) : '';
		// phpcs:enable
		if ( 'failall' === $sim ) {
			return null;
		}
		if ( 'failpage2' === $sim && $page >= 2 ) {
			return null;
		}

		$page_size = max( 1, (int) ( $request['page_size'] ?? 6 ) );
		$pool      = ( 2 === (int) ( $request['sort_by'] ?? 0 ) ) ? self::PRICE_ORDER : self::RELEVANCE_ORDER;
		$slice     = array_slice( $pool, ( $page - 1 ) * $page_size, $page_size );

		$documents = array();
		foreach ( array_values( $slice ) as $i => $id ) {
			// LIVE-CONFIRMED shape (FINAL): the WooCommerce post id is the top-level id
			// (integer), with include_metadata:false. (The interim UUID/metadata shape was
			// reverted by the backend.)
			$documents[] = array(
				'id'    => (int) $id,
				'score' => round( 1 - ( $i * 0.01 ), 3 ),
			);
		}

		return array(
			'query'             => (string) ( $request['query'] ?? '' ),
			'num_total_results' => self::TOTAL,
			'documents'         => $documents,
			'facets'            => array(),
		);
	}
}
