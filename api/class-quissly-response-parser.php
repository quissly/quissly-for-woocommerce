<?php
/**
 * Quissly API response parser.
 *
 * @package Quissly_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Extracts the load-bearing fields from Quissly responses.
 *
 * Pure: operates on already-decoded arrays. Facets are passed through faithfully —
 * the facet<->filter field-key mapping is verified live later, so the parser
 * must not invent semantics for it here.
 */
class Quissly_Response_Parser {

	/**
	 * Parse a qsearch / qimage response.
	 *
	 * The id source (verified against the live API): qsearch returns the WooCommerce post id at top-level
	 * documents[].id (integer), with include_metadata:false. Read documents[].id for post__in.
	 * (History: an interim backend version returned a UUIDv5 and required reading
	 * metadata.q_external_id with include_metadata:true; the backend now returns the post id
	 * directly, so that workaround is reverted.) The inbound boundary rule is KEPT regardless:
	 * accept only a well-formed positive integer, cast to int, drop + log anything else.
	 *
	 * Also extracts documents[].top_variant_id — the variation the match favors (a WC variation
	 * id, string on the wire) — into a product_id => variant_id (int) map for the result-link
	 * variant pre-selection. GRACEFUL: a missing/empty/non-numeric top_variant_id just yields no
	 * map entry for that product (it links normally); the field is OPTIONAL and never affects ids.
	 *
	 * @param array $response Decoded response body.
	 * @return array{ids:int[],num_total_results:int,facets:array,variants:array<int,int>}
	 */
	public static function parse_search( $response ) {
		$ids      = array();
		$variants = array();
		if ( ! empty( $response['documents'] ) && is_array( $response['documents'] ) ) {
			foreach ( $response['documents'] as $doc ) {
				$raw     = isset( $doc['id'] ) ? $doc['id'] : null;
				$post_id = self::to_post_id( $raw );
				if ( null === $post_id ) {
					continue;
				}
				$ids[] = $post_id;

				if ( isset( $doc['top_variant_id'] ) ) {
					$variant_id = self::to_variant_id( $doc['top_variant_id'] );
					if ( null !== $variant_id ) {
						$variants[ $post_id ] = $variant_id;
					}
				}
			}
		}

		return array(
			'ids'               => $ids,
			'num_total_results' => isset( $response['num_total_results'] ) ? (int) $response['num_total_results'] : 0,
			'facets'            => isset( $response['facets'] ) && is_array( $response['facets'] ) ? $response['facets'] : array(),
			'variants'          => $variants,
		);
	}

	/**
	 * Inbound boundary for documents[].top_variant_id: accept ONLY a well-formed positive
	 * integer (int or numeric string) variation id, cast to int; return null for anything else
	 * (absent/empty/non-numeric) so the product simply links normally. Whether the id resolves
	 * to a real variation of THIS product is validated later, against WooCommerce, at link time.
	 *
	 * @param mixed $raw Raw top_variant_id value.
	 * @return int|null
	 */
	private static function to_variant_id( $raw ) {
		if ( is_int( $raw ) ) {
			return $raw > 0 ? $raw : null;
		}
		if ( is_string( $raw ) && ctype_digit( $raw ) && (int) $raw > 0 ) {
			return (int) $raw;
		}

		return null;
	}

	/**
	 * Inbound boundary for the WC post id at documents[].id: accept ONLY a well-formed
	 * positive integer (int, or a purely-numeric string), cast to int; drop and log anything
	 * else. The id now arrives as an integer, but the validate-and-cast is KEPT defensively —
	 * it is harmless and protects against the wire form changing again. (Logging is a no-op
	 * outside WordPress so the parser stays unit-testable without a bootstrap.)
	 *
	 * @param mixed $raw Raw documents[].id value (or null).
	 * @return int|null Positive int post id, or null to drop.
	 */
	private static function to_post_id( $raw ) {
		if ( is_int( $raw ) ) {
			return $raw > 0 ? $raw : self::drop( $raw );
		}
		if ( is_string( $raw ) && ctype_digit( $raw ) && (int) $raw > 0 ) {
			return (int) $raw;
		}

		return self::drop( $raw );
	}

	/**
	 * Log a dropped malformed document id (only when the WP logger is present) and return null.
	 *
	 * @param mixed $raw The offending value.
	 * @return null
	 */
	private static function drop( $raw ) {
		if ( class_exists( 'Quissly_Sync_Log' ) ) {
			Quissly_Sync_Log::log( 'qsearch: dropped document with malformed/missing id: ' . wp_json_encode( $raw ) );
		}

		return null;
	}

	/**
	 * Parse a quick response (a list of {id, score, metadata}).
	 *
	 * Degrades gracefully: any absent metadata field becomes null rather than failing.
	 *
	 * @param array $response Decoded response body (a list).
	 * @return array<int,array{id:int,title:?string,url:?string,price:?float,original_price:?float,image_url:?string}>
	 */
	public static function parse_quick( $response ) {
		$rows = array();
		if ( ! is_array( $response ) ) {
			return $rows;
		}

		foreach ( $response as $item ) {
			if ( ! isset( $item['id'] ) ) {
				continue;
			}
			$meta   = isset( $item['metadata'] ) && is_array( $item['metadata'] ) ? $item['metadata'] : array();
			$rows[] = array(
				'id'             => (int) $item['id'],
				'title'          => isset( $meta['title'] ) ? (string) $meta['title'] : null,
				'url'            => isset( $meta['url'] ) ? (string) $meta['url'] : null,
				'price'          => isset( $meta['price'] ) ? (float) $meta['price'] : null,
				'original_price' => isset( $meta['original_price'] ) ? (float) $meta['original_price'] : null,
				'image_url'      => isset( $meta['image_url'] ) ? (string) $meta['image_url'] : null,
			);
		}

		return $rows;
	}
}
