<?php
/**
 * The request to, and the answer from, Quissly's suggestion generator.
 *
 * @package Quissly_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `POST /v2beta/qsearch/suggestions_enrich` (v2-signed, like a search) writes a store's search
 * bar suggestions itself: Quissly generates them in each language, checks each one finds
 * products, and writes them into the QSearch service's widget_config, keeping any list the
 * merchant edited. Every Quissly connector calls it (the Shopify app; the
 * Magento and CS-Cart plugins have the same); no plugin holds a model key. What the plugin sends
 * is what only the store knows: its language and its catalog as shoppers read it.
 *
 * Pure: builds the body and reads the status, nothing else.
 */
final class Quissly_Suggestions_Enrich {

	/** The signed path. */
	const PATH = '/v2beta/qsearch/suggestions_enrich';

	/** The generator these lists come from (a list from any other is made again once). */
	const GENERATOR = 'suggestions-enrich-v1';

	/** Products sent per language; Quissly shows its model at most 40. */
	const MAX_PRODUCTS = 60;

	/** Values sent per product option. */
	const MAX_OPTION_VALUES = 20;

	/**
	 * Options sent per product, and a title's length: the backend's own limits (ProductFactIn) -
	 * one product over either fails the whole request (422). Shopify never reaches them (3
	 * options, 255-character titles); WooCommerce attributes and titles can.
	 */
	const MAX_OPTIONS      = 10;
	const MAX_TITLE_LENGTH = 300;

	/** Attempts before Quissly may write a shorter list than it would like (accept_partial). */
	const MAX_ATTEMPTS = 6;

	/**
	 * The request body. A product without a title is left out, and a language without
	 * products is too (Quissly then uses its own catalog).
	 *
	 * @param string     $primary        The main list's language key ("en").
	 * @param string[]   $languages      Every language asked for, the primary one first.
	 * @param array      $catalogs       Per language: {shop_name, currency, products[]}.
	 * @param array|null $previous       What was generated before: {main, by_language}.
	 * @param string     $mode           fill | regenerate (lists from an older generator).
	 * @param bool       $accept_partial The last attempt: a shorter list is better than none.
	 * @return array
	 */
	public static function body( $primary, array $languages, array $catalogs, $previous, $mode, $accept_partial ) {
		$catalog   = array();
		$shop_name = null;
		$currency  = null;
		foreach ( $languages as $language ) {
			if ( ! isset( $catalogs[ $language ] ) || ! is_array( $catalogs[ $language ] ) ) {
				continue;
			}
			$facts = $catalogs[ $language ];
			if ( null === $shop_name && '' !== self::text( $facts['shop_name'] ?? null ) ) {
				$shop_name = self::text( $facts['shop_name'] );
			}
			if ( null === $currency && '' !== self::text( $facts['currency'] ?? null ) ) {
				$currency = self::text( $facts['currency'] );
			}
			$products = array();
			foreach ( (array) ( $facts['products'] ?? array() ) as $product ) {
				$title = self::text( $product['title'] ?? null );
				if ( '' === $title ) {
					continue;
				}
				$products[] = array(
					'title'        => mb_substr( $title, 0, self::MAX_TITLE_LENGTH ),
					'product_type' => '' !== self::text( $product['product_type'] ?? null ) ? self::text( $product['product_type'] ) : null,
					'vendor'       => '' !== self::text( $product['vendor'] ?? null ) ? self::text( $product['vendor'] ) : null,
					'options'      => self::options( (array) ( $product['options'] ?? array() ) ),
					'min_price'    => self::price( $product['min_price'] ?? null ),
					'max_price'    => self::price( $product['max_price'] ?? ( $product['min_price'] ?? null ) ),
				);
				if ( count( $products ) >= self::MAX_PRODUCTS ) {
					break;
				}
			}
			if ( ! empty( $products ) ) {
				$catalog[ $language ] = $products;
			}
		}

		$body        = array(
			'primary_language' => (string) $primary,
			'languages'        => array_values( $languages ),
			'shop_name'        => $shop_name,
			'currency'         => $currency,
			'catalog'          => (object) $catalog,
		);
		$main        = is_array( $previous ) ? array_values( (array) ( $previous['main'] ?? array() ) ) : array();
		$by_language = is_array( $previous ) ? array_filter( (array) ( $previous['by_language'] ?? array() ) ) : array();
		if ( ! empty( $main ) || ! empty( $by_language ) ) {
			$body['previous_generated'] = array(
				'main'        => $main,
				'by_language' => (object) $by_language,
			);
		}
		$body['mode']           = 'regenerate' === $mode ? 'regenerate' : 'fill';
		$body['accept_partial'] = (bool) $accept_partial;

		return $body;
	}

	/**
	 * What an answer means for the schedule: done (stop asking until something changes), or
	 * ask again after `retry` seconds.
	 *
	 * @param string $status written|unchanged|insufficient|no_products|no_widget_service, or error.
	 * @return array{done:bool,retry:int,reason:string}
	 */
	public static function schedule( $status ) {
		switch ( $status ) {
			case 'written':
			case 'unchanged':
				return array( 'done' => true, 'retry' => 0, 'reason' => '' );
			case 'insufficient':
				// Most likely the search index is still filling after the first sync.
				return array( 'done' => false, 'retry' => HOUR_IN_SECONDS, 'reason' => 'Too few suggestions returned results yet.' );
			case 'no_products':
				return array( 'done' => false, 'retry' => DAY_IN_SECONDS, 'reason' => 'No published products to build suggestions from.' );
			case 'no_widget_service':
				return array( 'done' => false, 'retry' => DAY_IN_SECONDS, 'reason' => 'The search service is not set up in Quissly yet.' );
		}

		return array( 'done' => false, 'retry' => HOUR_IN_SECONDS, 'reason' => 'Quissly could not generate suggestions.' );
	}

	/**
	 * A WordPress locale as the widget_config's language key: "ka_GE" -> "ka", "pt_BR" -> "pt-br"
	 * (the Shopify app's keys; Portuguese and Chinese keep their region).
	 *
	 * @param string $locale Locale.
	 * @return string
	 */
	public static function language_key( $locale ) {
		$parts = explode( '_', strtolower( str_replace( '-', '_', trim( (string) $locale ) ) ) );
		if ( '' === $parts[0] ) {
			return 'en';
		}
		if ( isset( $parts[1] ) && in_array( $parts[0], array( 'pt', 'zh' ), true ) ) {
			return $parts[0] . '-' . $parts[1];
		}

		return $parts[0];
	}

	/**
	 * Options with a name and values: at most MAX_OPTIONS, MAX_OPTION_VALUES values each.
	 *
	 * @param array $options Options.
	 * @return array
	 */
	private static function options( array $options ) {
		$out = array();
		foreach ( $options as $option ) {
			$name   = self::text( $option['name'] ?? null );
			$values = array();
			foreach ( (array) ( $option['values'] ?? array() ) as $value ) {
				if ( '' !== self::text( $value ) ) {
					$values[] = self::text( $value );
				}
			}
			if ( '' !== $name && ! empty( $values ) ) {
				$out[] = array(
					'name'   => $name,
					'values' => array_slice( $values, 0, self::MAX_OPTION_VALUES ),
				);
			}
			if ( count( $out ) >= self::MAX_OPTIONS ) {
				break;
			}
		}

		return $out;
	}

	/**
	 * A trimmed string ('' for anything else).
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function text( $value ) {
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	/**
	 * A price, or null when there is none.
	 *
	 * @param mixed $value Value.
	 * @return float|null
	 */
	private static function price( $value ) {
		return is_numeric( $value ) && (float) $value > 0 ? round( (float) $value, 2 ) : null;
	}
}
