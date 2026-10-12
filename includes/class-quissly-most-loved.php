<?php
/**
 * Most loved products: the merchant's picks, shown in the search overlay when it opens (the
 * Quissly Shopify app's "Most loved products").
 *
 * @package Quissly_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The merchant picks 8 products to show and 4 backups in Configuration. The overlay shows 8,
 * in order: the shown ones that can be bought, each one that cannot replaced by the first
 * available backup. With fewer than 8 between them, fewer than 8 + 4 picked, or a product in
 * both lists, nothing is shown, as in the Shopify app. Configuration says what
 * needs fixing (status()).
 *
 * pick() and problems() are pure (unit-tested).
 */
final class Quissly_Most_Loved {

	/** Products shown, and backups for the ones that cannot be bought. */
	const SHOWN   = 8;
	const BACKUPS = 4;

	/**
	 * The 8 to show, in order, or none. PURE.
	 *
	 * @param int[]    $shown     The picked products to show.
	 * @param int[]    $backups   The picked backups.
	 * @param callable $available fn(int $id): bool - can be bought now.
	 * @return int[]
	 */
	public static function pick( array $shown, array $backups, callable $available ) {
		if ( count( $shown ) !== self::SHOWN || count( $backups ) !== self::BACKUPS || self::duplicates( $shown, $backups ) ) {
			return array();
		}
		$out = array();
		foreach ( array_merge( $shown, $backups ) as $id ) {
			$id = (int) $id;
			if ( count( $out ) < self::SHOWN && ! in_array( $id, $out, true ) && $available( $id ) ) {
				$out[] = $id;
			}
		}

		return self::SHOWN === count( $out ) ? $out : array();
	}

	/**
	 * What the merchant has to fix: picks still to make, products picked in both lists, and
	 * picks that cannot be shown, with why. Null when nothing. PURE.
	 *
	 * @param int[]    $shown   The picked products to show.
	 * @param int[]    $backups The picked backups.
	 * @param callable $state   fn(int $id): array{title:string, reason:string} - reason '' when
	 *                          the product can be shown, else deleted | not_published | out_of_stock.
	 * @return array{missing_shown:int, missing_backups:int, duplicates:string[], problems:array, hidden:bool}|null
	 */
	public static function problems( array $shown, array $backups, callable $state ) {
		$problems = array();
		$ok       = array();
		foreach ( array( false => $shown, true => $backups ) as $backup => $ids ) {
			foreach ( $ids as $id ) {
				$product = $state( (int) $id );
				if ( '' === $product['reason'] ) {
					$ok[ (int) $id ] = true;
				} else {
					$problems[] = array(
						'title'  => $product['title'],
						'backup' => (bool) $backup,
						'reason' => $product['reason'],
					);
				}
			}
		}
		$duplicates = array();
		foreach ( self::duplicates( $shown, $backups ) as $id ) {
			$duplicates[] = $state( (int) $id )['title'];
		}
		$missing_shown   = max( 0, self::SHOWN - count( $shown ) );
		$missing_backups = max( 0, self::BACKUPS - count( $backups ) );
		if ( 0 === $missing_shown && 0 === $missing_backups && empty( $duplicates ) && empty( $problems ) ) {
			return null;
		}

		return array(
			'missing_shown'   => $missing_shown,
			'missing_backups' => $missing_backups,
			'duplicates'      => $duplicates,
			'problems'        => $problems,
			'hidden'          => $missing_shown > 0 || $missing_backups > 0 || ! empty( $duplicates ) || count( $ok ) < self::SHOWN,
		);
	}

	/**
	 * The backups that are also picked to show: each product goes in one list only (the Shopify
	 * app's rule - nothing is shown until it is fixed). PURE.
	 *
	 * @param int[] $shown   The picked products to show.
	 * @param int[] $backups The picked backups.
	 * @return int[]
	 */
	public static function duplicates( array $shown, array $backups ) {
		$shown = array_map( 'intval', $shown );

		return array_values(
			array_filter(
				array_map( 'intval', $backups ),
				static function ( $id ) use ( $shown ) {
					return in_array( $id, $shown, true );
				}
			)
		);
	}

	/**
	 * Whether the merchant switched it on.
	 *
	 * @return bool
	 */
	public static function enabled() {
		return (bool) Quissly_Settings::get( 'quissly_most_loved' );
	}

	/**
	 * The merchant's picks.
	 *
	 * @return array{shown:int[], backups:int[]}
	 */
	public static function picks() {
		return array(
			'shown'   => array_map( 'intval', (array) Quissly_Settings::get( 'quissly_most_loved_shown' ) ),
			'backups' => array_map( 'intval', (array) Quissly_Settings::get( 'quissly_most_loved_backups' ) ),
		);
	}

	/**
	 * The cards the overlay shows (none while it is off or the picks are not enough), in the
	 * shopper's language on a multilingual store.
	 *
	 * @return array<int,array{title:string, url:string, image:string, price:string, rating:float, ratingCount:int, variants:string}>
	 */
	public static function for_storefront() {
		if ( ! self::enabled() || ! function_exists( 'wc_get_product' ) ) {
			return array();
		}
		$picks = self::picks();
		$ids   = self::pick(
			$picks['shown'],
			$picks['backups'],
			static function ( $id ) {
				return '' === self::state( $id )['reason'];
			}
		);
		$cards = array();
		foreach ( $ids as $id ) {
			$product = wc_get_product( Quissly_Languages::translate( $id ) );
			if ( $product ) {
				$cards[] = self::card( $product );
			}
		}

		return $cards;
	}

	/**
	 * What Configuration warns about (problems()), or null when nothing - and nothing while
	 * Most loved is off.
	 *
	 * @return array|null
	 */
	public static function status() {
		if ( ! self::enabled() ) {
			return null;
		}
		$picks = self::picks();

		return self::problems( $picks['shown'], $picks['backups'], array( __CLASS__, 'state' ) );
	}

	/**
	 * A picked product's title, and why it cannot be shown ('' when it can): deleted, not
	 * published (or hidden from the shop), or out of stock.
	 *
	 * @param int $id Product id.
	 * @return array{title:string, reason:string}
	 */
	public static function state( $id ) {
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( (int) $id ) : null;
		if ( ! $product || 'trash' === $product->get_status() ) {
			/* translators: %d: a product id. */
			return array( 'title' => sprintf( __( 'Product #%d', 'quissly-for-woocommerce' ), (int) $id ), 'reason' => 'deleted' );
		}
		$title = $product->get_name();
		if ( 'publish' !== $product->get_status() || 'hidden' === $product->get_catalog_visibility() ) {
			return array( 'title' => $title, 'reason' => 'not_published' );
		}
		if ( ! $product->is_in_stock() || ! $product->is_purchasable() ) {
			return array( 'title' => $title, 'reason' => 'out_of_stock' );
		}

		return array( 'title' => $title, 'reason' => '' );
	}

	/**
	 * A card: title, link, image, price (a variable product's lowest), its reviews, and how many
	 * of its first option there are ("3 colors available").
	 *
	 * @param WC_Product $product Product.
	 * @return array{title:string, url:string, image:string, price:string, rating:float, ratingCount:int, variants:string}
	 */
	private static function card( WC_Product $product ) {
		$price    = $product->is_type( 'variable' ) ? $product->get_variation_price( 'min' ) : $product->get_price();
		$image    = $product->get_image_id() ? wp_get_attachment_image_url( $product->get_image_id(), 'woocommerce_thumbnail' ) : '';
		$variants = '';
		if ( $product->is_type( 'variable' ) ) {
			$count      = count( $product->get_visible_children() );
			$attributes = array_keys( (array) $product->get_variation_attributes() );
			if ( $count > 1 ) {
				$variants = isset( $attributes[0] )
					/* translators: 1: how many, 2: the option's name in the plural, e.g. "3 colors available". */
					? sprintf( __( '%1$d %2$s available', 'quissly-for-woocommerce' ), $count, strtolower( wc_attribute_label( $attributes[0], $product ) ) . 's' )
					/* translators: %d: how many options. */
					: sprintf( _n( '%d option available', '%d options available', $count, 'quissly-for-woocommerce' ), $count );
			}
		}

		return array(
			'title'       => wp_specialchars_decode( $product->get_name(), ENT_QUOTES ),
			'url'         => (string) get_permalink( $product->get_id() ),
			'image'       => $image ? (string) $image : '',
			'price'       => '' !== (string) $price ? html_entity_decode( wp_strip_all_tags( wc_price( (float) $price ) ), ENT_QUOTES, 'UTF-8' ) : '',
			'rating'      => (float) $product->get_average_rating(),
			'ratingCount' => (int) $product->get_rating_count(),
			'variants'    => $variants,
		);
	}
}
