<?php
/**
 * Catalog data: which product attributes go to Quissly (the Magento plugin's "Product
 * attributes sent to Quissly").
 *
 * @package Quissly_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The merchant's attribute checklist, stored as DEVIATIONS from the default: an attribute
 * is sent when the product page shows it ("Visible on the product page", decided per
 * product), unless the merchant ticked a hidden one on (true) or unticked a shown one off
 * (false). Storing only the deviations means an attribute created later just follows its
 * visibility, and saving the list unchanged changes nothing.
 *
 * Keyed by the attribute's label, the name its metadata key comes from. A variable
 * product's variation attributes are not governed by this list: they tell its variants
 * apart and always go.
 */
class Quissly_Catalog_Attributes {

	const OPTION = 'quissly_catalog_attribute_choices';

	/**
	 * The stored choices: label => bool.
	 *
	 * @return array<string,bool>
	 */
	public static function choices() {
		$stored = get_option( self::OPTION, array() );

		return is_array( $stored ) ? array_map( 'boolval', $stored ) : array();
	}

	/**
	 * Whether an attribute goes to Quissly. PURE.
	 *
	 * @param string              $label   Attribute label.
	 * @param bool                $visible Shown on this product's page.
	 * @param array<string,bool>  $choices Stored choices.
	 * @return bool
	 */
	public static function included( $label, $visible, array $choices ) {
		return array_key_exists( (string) $label, $choices ) ? (bool) $choices[ (string) $label ] : (bool) $visible;
	}

	/**
	 * The choices a submitted checklist amounts to: only the attributes whose tick differs
	 * from what the list showed pre-ticked. PURE.
	 *
	 * @param array<string,bool> $defaults Offered label => pre-ticked (shown on some product page).
	 * @param string[]           $ticked   Labels ticked on submit.
	 * @return array<string,bool>
	 */
	public static function choices_from( array $defaults, array $ticked ) {
		$ticked  = array_flip( array_map( 'strval', $ticked ) );
		$choices = array();
		foreach ( $defaults as $label => $default ) {
			$on = isset( $ticked[ (string) $label ] );
			if ( $on !== (bool) $default ) {
				$choices[ (string) $label ] = $on;
			}
		}
		ksort( $choices );

		return $choices;
	}

	/**
	 * Every attribute on a published product, label => counts: on how many product pages it
	 * is shown / hidden, and on how many it defines variations. Read from the stored
	 * attribute lists directly (one query), not by loading every product.
	 *
	 * @return array<string,array{shown:int,hidden:int,variation:int}>
	 */
	public static function catalog() {
		global $wpdb;
		$rows = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			"SELECT pm.meta_value FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			WHERE pm.meta_key = '_product_attributes' AND p.post_type = 'product' AND p.post_status = 'publish'"
		);

		$out = array();
		foreach ( $rows as $row ) {
			$attributes = maybe_unserialize( $row );
			if ( ! is_array( $attributes ) ) {
				continue;
			}
			foreach ( $attributes as $attribute ) {
				if ( ! is_array( $attribute ) || empty( $attribute['name'] ) ) {
					continue;
				}
				$name  = (string) $attribute['name'];
				$label = ! empty( $attribute['is_taxonomy'] ) ? wc_attribute_label( $name ) : $name;
				$out[ $label ] = $out[ $label ] ?? array( 'shown' => 0, 'hidden' => 0, 'variation' => 0 );
				$out[ $label ][ empty( $attribute['is_visible'] ) ? 'hidden' : 'shown' ]++;
				if ( ! empty( $attribute['is_variation'] ) ) {
					$out[ $label ]['variation']++;
				}
			}
		}
		uksort( $out, 'strcasecmp' );

		return $out;
	}

	/**
	 * Save a submitted checklist; true when the choices changed (so the catalog must be
	 * re-sent).
	 *
	 * @param string[] $offered Labels the form listed.
	 * @param string[] $ticked  Labels ticked.
	 * @return bool
	 */
	public static function save( array $offered, array $ticked ) {
		$catalog  = self::catalog();
		$defaults = array();
		foreach ( array_map( 'strval', $offered ) as $label ) {
			if ( isset( $catalog[ $label ] ) ) {
				$defaults[ $label ] = $catalog[ $label ]['shown'] > 0;
			}
		}
		$choices = self::choices_from( $defaults, $ticked );
		if ( $choices === self::choices() ) {
			return false;
		}
		update_option( self::OPTION, $choices, false );

		return true;
	}
}
