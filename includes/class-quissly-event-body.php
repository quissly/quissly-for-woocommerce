<?php
/**
 * The shopping events sent to Quissly's analytics.
 *
 * @package Quissly_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `POST /v2beta/qsearch/events`: the five events the Quissly Shopify app sends, in its shape
 * (the Magento and CS-Cart plugins send the same) - a product page view, a search, an add to cart, an add to the wishlist (WooCommerce
 * has no wishlist of its own: sent for YITH WooCommerce Wishlist, the most used one) and a paid
 * order. Quissly keeps each one, and the Quissly Admin Panel's e-commerce and conversion tabs
 * count them by type and day. Everything that is not a
 * body field goes in `metadata`, which the engine keeps as it is.
 *
 * Ids are strings (the catalog's own); prices are strings with two decimals, as Shopify's order
 * webhook sends them. Nothing about a shopper but the user_id (customer:/guest:, as a search
 * sends it) - no name, email, address or IP.
 *
 * Pure: builds bodies, nothing else.
 */
final class Quissly_Event_Body {

	const VIEW        = 'view_PDP';
	const SEARCH      = 'search';
	const ADD_TO_CART = 'add_to_cart';
	const WISHLIST    = 'add_to_wishlist';
	const PURCHASE    = 'purchase_successful';

	/** A shopper's own action on the storefront. */
	const SOURCE_STOREFRONT = 'storefront';

	/** A paid order - recorded by the store, not the shopper's browser (Shopify: "webhook"). */
	const SOURCE_ORDER = 'order';

	const MODALITIES = array( 'text', 'voice', 'image' );

	/** The storefront search's own limit. */
	const MAX_QUERY_LENGTH = 150;

	/** Order lines sent at most; a larger order still counts, with its first lines. */
	const MAX_ORDER_LINES = 100;

	const NAME_LENGTH = 255;

	/**
	 * A product page was opened.
	 *
	 * @param int $product_id The page's product.
	 * @return array Metadata.
	 */
	public static function view( $product_id ) {
		return array( 'product_id' => (string) (int) $product_id );
	}

	/**
	 * A search was run (Shopify: {query, modality, source}). A voice or image search carries
	 * no words of its own; a text search needs 1-150 characters.
	 *
	 * @param string $query    Words.
	 * @param string $modality text | voice | image.
	 * @return array|null Metadata, or null when there is nothing to record.
	 */
	public static function search( $query, $modality ) {
		if ( ! in_array( $modality, self::MODALITIES, true ) ) {
			return null;
		}
		$query = trim( (string) preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', (string) $query ) );
		if ( 'text' === $modality && ( '' === $query || mb_strlen( $query ) > self::MAX_QUERY_LENGTH ) ) {
			return null;
		}
		$metadata = array( 'modality' => $modality );
		if ( 'text' === $modality ) {
			$metadata['query'] = $query;
		}

		return $metadata;
	}

	/**
	 * A product went into the cart.
	 *
	 * @param int      $product_id   The product (a variable product's parent).
	 * @param int|null $variation_id The variation chosen.
	 * @param float    $quantity     Quantity.
	 * @return array Metadata.
	 */
	public static function add_to_cart( $product_id, $variation_id, $quantity ) {
		$metadata = array(
			'product_id' => (string) (int) $product_id,
			'quantity'   => max( 1, (int) round( (float) $quantity ) ),
		);
		if ( (int) $variation_id > 0 && (int) $variation_id !== (int) $product_id ) {
			$metadata['variant_id'] = (string) (int) $variation_id;
		}

		return $metadata;
	}

	/**
	 * A product went onto the wishlist.
	 *
	 * @param int $product_id Product.
	 * @return array Metadata.
	 */
	public static function wishlist( $product_id ) {
		return array( 'product_id' => (string) (int) $product_id );
	}

	/**
	 * An order was paid (Shopify's {order_id, total_price, currency, products[]}).
	 *
	 * @param string $order_id The order number the merchant sees.
	 * @param float  $total    Order total.
	 * @param string $currency Currency.
	 * @param array  $lines    Each {product_id, variant_id|null, name, quantity, price}.
	 * @return array Metadata.
	 */
	public static function purchase( $order_id, $total, $currency, array $lines ) {
		$products = array();
		foreach ( array_slice( $lines, 0, self::MAX_ORDER_LINES ) as $line ) {
			$product_id = (int) ( $line['product_id'] ?? 0 );
			if ( $product_id <= 0 ) {
				continue;
			}
			$variant_id = (int) ( $line['variant_id'] ?? 0 );
			$products[] = array(
				'product_id' => (string) $product_id,
				'variant_id' => $variant_id > 0 && $variant_id !== $product_id ? (string) $variant_id : null,
				'name'       => mb_substr( trim( (string) ( $line['name'] ?? '' ) ), 0, self::NAME_LENGTH ),
				'quantity'   => max( 1, (int) round( (float) ( $line['quantity'] ?? 1 ) ) ),
				'price'      => self::money( (float) ( $line['price'] ?? 0 ) ),
			);
		}

		return array(
			'order_id'    => (string) $order_id,
			'total_price' => self::money( (float) $total ),
			'currency'    => strtoupper( trim( (string) $currency ) ),
			'products'    => $products,
		);
	}

	/**
	 * The request body. `event_id` is chosen here, once: a send that times out after Quissly
	 * stored the row is sent again with the same id, which Quissly de-duplicates on.
	 *
	 * @param string      $type     One of the constants.
	 * @param array       $metadata From one of the methods above.
	 * @param string|null $user_id  customer:<id> | guest:<uuid> | null.
	 * @param array       $shopper  {device?, os?} - '' or missing = not sent.
	 * @param string      $shop     The store's host.
	 * @param string      $source   SOURCE_*.
	 * @param string      $event_id A UUID v4.
	 * @param int         $time     When it happened (unix seconds).
	 * @return array
	 */
	public static function envelope( $type, array $metadata, $user_id, array $shopper, $shop, $source, $event_id, $time ) {
		$body = array(
			'event_type' => $type,
			'event_id'   => $event_id,
			'channel'    => 'web',
		);
		if ( null !== $user_id && '' !== $user_id ) {
			$body['user_id'] = $user_id;
		}
		if ( in_array( $shopper['device'] ?? '', array( 'mobile', 'tablet', 'desktop' ), true ) ) {
			$body['device'] = $shopper['device'];
		}
		if ( '' !== (string) ( $shopper['os'] ?? '' ) ) {
			$body['os'] = (string) $shopper['os'];
		}
		// Set last, as the Shopify app does, so an event's own keys can never replace them.
		$body['metadata'] = array_merge(
			$metadata,
			array(
				'shop'       => (string) $shop,
				'source'     => $source,
				'occurredAt' => gmdate( 'Y-m-d\TH:i:s\Z', (int) $time ),
			)
		);

		return $body;
	}

	/**
	 * A price as Shopify's webhook writes one: "19.90".
	 *
	 * @param float $amount Amount.
	 * @return string
	 */
	private static function money( $amount ) {
		return number_format( max( 0.0, (float) $amount ), 2, '.', '' );
	}
}
