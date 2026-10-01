<?php
/**
 * Variant deep-link: rewrite a product permalink to pre-select the matched variation.
 *
 * @package Quissly_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Translates a Quissly top_variant_id into the WooCommerce variation-pre-select query params
 * and appends them to the product's permalink, so a click-through lands on the PDP with that
 * variation already selected. This touches ONLY the URL (data), never card rendering.
 *
 * WooCommerce pre-selects a variation from URL params named `attribute_<attribute key>` whose
 * values are the variation's stored attribute values (taxonomy attrs keep the `pa_` prefix and
 * use the term slug; custom attrs use the bare name and the option value) — LIVE-CONFIRMED:
 * variation 35 (parent 12) -> get_attributes() == { pa_color: blue, logo: Yes } ->
 * ?attribute_pa_color=blue&attribute_logo=Yes.
 */
class Quissly_Variant_Deeplink {

	/**
	 * Append the variation pre-select params to a permalink, or return it UNCHANGED when the
	 * variant can't be applied (missing/invalid id, not a variation, parent mismatch, or no
	 * pinned attributes) — graceful degradation to the plain product link.
	 *
	 * @param string $permalink         The product permalink.
	 * @param int    $variant_id        Candidate WC variation id (top_variant_id).
	 * @param int    $expected_parent_id The result product id the variation MUST belong to.
	 * @return string
	 */
	public static function apply( $permalink, $variant_id, $expected_parent_id ) {
		$args = self::for_variation( $variant_id, $expected_parent_id );
		if ( empty( $args ) ) {
			return $permalink; // graceful: plain product link.
		}

		return add_query_arg( $args, $permalink );
	}

	/**
	 * Resolve a variation id (validated to belong to $expected_parent_id) into its
	 * attribute_* query args, or an empty array if it can't be applied (logged). Touches WC.
	 *
	 * @param int $variant_id         Candidate variation id.
	 * @param int $expected_parent_id The product the variation must be a child of.
	 * @return array<string,string>
	 */
	public static function for_variation( $variant_id, $expected_parent_id ) {
		$variant_id = (int) $variant_id;
		if ( $variant_id <= 0 || ! function_exists( 'wc_get_product' ) ) {
			return array();
		}

		$variation = wc_get_product( $variant_id );
		if ( ! $variation instanceof WC_Product || 'variation' !== $variation->get_type() ) {
			self::log_skip( $variant_id, $expected_parent_id, 'not a variation' );
			return array();
		}
		if ( (int) $variation->get_parent_id() !== (int) $expected_parent_id ) {
			self::log_skip( $variant_id, $expected_parent_id, 'parent mismatch' );
			return array();
		}

		return self::attribute_query_args( $variation->get_attributes() );
	}

	/**
	 * Build the `attribute_<key> => value` query args from a variation's get_attributes() map.
	 * PURE + unit-testable. Skips empty values ("any" attributes — not pinned). The key is
	 * prefixed with `attribute_` unless it already carries that prefix (defensive).
	 *
	 * @param array<string,mixed> $attributes WC_Product_Variation::get_attributes() output,
	 *                                         e.g. { pa_color: 'blue', logo: 'Yes' }.
	 * @return array<string,string>
	 */
	public static function attribute_query_args( array $attributes ) {
		$args = array();
		foreach ( $attributes as $key => $value ) {
			$value = (string) $value;
			if ( '' === $value ) {
				continue; // "any value" attribute -> do not pin it.
			}
			$key   = (string) $key;
			$param = ( 0 === strpos( $key, 'attribute_' ) ) ? $key : 'attribute_' . $key;

			$args[ $param ] = $value;
		}

		return $args;
	}

	/**
	 * Log a skipped (invalid/mismatched) top_variant_id when the WP logger is present.
	 *
	 * @param int    $variant_id Candidate variation id.
	 * @param int    $parent_id  Expected parent product id.
	 * @param string $reason     Short reason.
	 * @return void
	 */
	private static function log_skip( $variant_id, $parent_id, $reason ) {
		if ( class_exists( 'Quissly_Sync_Log' ) ) {
			Quissly_Sync_Log::log( 'variant deep-link skipped (' . $reason . '): top_variant_id ' . $variant_id . ' for product ' . $parent_id . ' -> plain link.' );
		}
	}
}
