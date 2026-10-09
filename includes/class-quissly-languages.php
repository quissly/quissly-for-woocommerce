<?php
/**
 * The store's languages, when a multilingual plugin gives it more than one: WPML (with
 * WooCommerce Multilingual) or Polylang.
 *
 * @package Quissly_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Both plugins keep every translation of a product as its own post, with its own id. Quissly
 * holds the main language's products only (the sync sends those, once - keep_for_sync()), and
 * understands a search in any language, so what it answers on an English page are the
 * Georgian products' ids: they are turned into the shopper's language here (to_current())
 * before WordPress loads them - the multilingual plugin hides a product of another language,
 * which made every search on a second-language page come back empty.
 *
 * Languages are named by the widget_config key Quissly uses ("en", "ka", "pt-br" -
 * Quissly_Suggestions_Enrich::language_key()) and by the plugin's own code, which is what its
 * functions take. Without a multilingual plugin, or with one language, everything here is a
 * no-op: one language, ids unchanged.
 *
 * map_ids() is pure (unit-tested).
 */
final class Quissly_Languages {

	/**
	 * Which multilingual plugin runs: 'wpml', 'polylang' or ''.
	 *
	 * @return string
	 */
	public static function provider() {
		if ( defined( 'ICL_SITEPRESS_VERSION' ) && has_filter( 'wpml_object_id' ) ) {
			return 'wpml';
		}
		if ( function_exists( 'pll_languages_list' ) && function_exists( 'pll_get_post' ) ) {
			return 'polylang';
		}

		return '';
	}

	/**
	 * Whether the store has more than one language.
	 *
	 * @return bool
	 */
	public static function is_multilingual() {
		return count( self::languages() ) > 1;
	}

	/**
	 * The store's languages, the main one first: each {code, key, locale, name, main}.
	 * Without a multilingual plugin, the site's language alone.
	 *
	 * @return array<int,array{code:string,key:string,locale:string,name:string,main:bool}>
	 */
	public static function languages() {
		$main = self::main_code();
		$out  = array();
		if ( 'wpml' === self::provider() ) {
			foreach ( (array) apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) ) as $code => $language ) {
				$locale = (string) ( $language['default_locale'] ?? $code );
				$out[]  = self::language( (string) $code, $locale, (string) ( $language['native_name'] ?? $code ), $main );
			}
		} elseif ( 'polylang' === self::provider() ) {
			foreach ( (array) pll_languages_list( array( 'fields' => '' ) ) as $language ) {
				if ( is_object( $language ) && isset( $language->slug ) ) {
					$out[] = self::language( (string) $language->slug, (string) $language->locale, (string) $language->name, $main );
				}
			}
		}
		if ( empty( $out ) ) {
			return array( self::language( '', get_locale(), '', '' ) );
		}
		usort(
			$out,
			static function ( $a, $b ) {
				return (int) $b['main'] - (int) $a['main'];
			}
		);

		return $out;
	}

	/**
	 * One language's entry.
	 *
	 * @param string $code   The plugin's code ('' without one).
	 * @param string $locale Its locale.
	 * @param string $name   Its name.
	 * @param string $main   The main language's code.
	 * @return array{code:string,key:string,locale:string,name:string,main:bool}
	 */
	private static function language( $code, $locale, $name, $main ) {
		return array(
			'code'   => $code,
			'key'    => Quissly_Suggestions_Enrich::language_key( $locale ),
			'locale' => $locale,
			'name'   => $name,
			'main'   => $code === $main,
		);
	}

	/**
	 * The main (default) language's plugin code, '' without a multilingual plugin.
	 *
	 * @return string
	 */
	public static function main_code() {
		switch ( self::provider() ) {
			case 'wpml':
				return (string) apply_filters( 'wpml_default_language', null );
			case 'polylang':
				return (string) pll_default_language();
		}

		return '';
	}

	/**
	 * The main language's key ("ka"): the language Quissly's catalog and main lists are in.
	 *
	 * @return string
	 */
	public static function main_key() {
		return self::languages()[0]['key'];
	}

	/**
	 * The shopper's language's plugin code ('' when the plugin cannot tell, or without one).
	 *
	 * @return string
	 */
	public static function current_code() {
		switch ( self::provider() ) {
			case 'wpml':
				$code = (string) apply_filters( 'wpml_current_language', null );
				return 'all' === $code ? '' : $code;
			case 'polylang':
				return (string) pll_current_language();
		}

		return '';
	}

	/**
	 * The shopper's language's key ("en"); the site language's without a multilingual plugin.
	 *
	 * @return string
	 */
	public static function current_key() {
		$code = self::current_code();
		foreach ( self::languages() as $language ) {
			if ( '' !== $code && $language['code'] === $code ) {
				return $language['key'];
			}
		}

		return Quissly_Suggestions_Enrich::language_key( get_locale() );
	}

	/**
	 * A language's plugin code from its key or code, '' when the store has no such language.
	 *
	 * @param string $key_or_code "en", "ka", "pt-br"...
	 * @return string
	 */
	public static function code_for( $key_or_code ) {
		$key_or_code = strtolower( trim( (string) $key_or_code ) );
		foreach ( self::languages() as $language ) {
			if ( '' !== $key_or_code && ( $language['code'] === $key_or_code || $language['key'] === $key_or_code ) ) {
				return $language['code'];
			}
		}

		return '';
	}

	/**
	 * Run $fn with WPML switched to a language (WPML narrows queries to the current one), then
	 * switch back. Polylang takes the language as a query var instead; without either, or
	 * with no language given, $fn just runs.
	 *
	 * @param string   $code A language's plugin code ('' = as it is).
	 * @param callable $fn   What to run.
	 * @return mixed What $fn returns.
	 */
	public static function in_language( $code, callable $fn ) {
		if ( '' === $code || 'wpml' !== self::provider() ) {
			return $fn();
		}
		$previous = self::current_code();
		do_action( 'wpml_switch_language', $code );
		try {
			return $fn();
		} finally {
			do_action( 'wpml_switch_language', '' !== $previous ? $previous : null );
		}
	}

	/**
	 * A post's id in another language: its translation, or the id itself when it has none
	 * (or without a multilingual plugin).
	 *
	 * @param int    $post_id Post id.
	 * @param string $code    Target language's plugin code ('' = the shopper's).
	 * @param string $type    Post type ('product', 'product_variation').
	 * @return int
	 */
	public static function translate( $post_id, $code = '', $type = 'product' ) {
		$post_id = (int) $post_id;
		$code    = '' !== $code ? $code : self::current_code();
		if ( $post_id <= 0 || '' === $code ) {
			return $post_id;
		}
		switch ( self::provider() ) {
			case 'wpml':
				return (int) apply_filters( 'wpml_object_id', $post_id, $type, true, $code );
			case 'polylang':
				$translated = (int) pll_get_post( $post_id, $code );
				return $translated > 0 ? $translated : $post_id;
		}

		return $post_id;
	}

	/**
	 * A product (or variation) as Quissly knows it: its main-language version's id - what the
	 * catalog sync sent - for the shopping events. Unchanged without a multilingual plugin.
	 *
	 * @param int $post_id Product or variation id.
	 * @return int
	 */
	public static function to_main( $post_id ) {
		$main = self::main_code();
		if ( '' === $main || (int) $post_id <= 0 ) {
			return (int) $post_id;
		}

		return self::translate( $post_id, $main, 'product_variation' === get_post_type( (int) $post_id ) ? 'product_variation' : 'product' );
	}

	/**
	 * A post's language's plugin code, '' when it has none (or without a multilingual plugin).
	 *
	 * @param int    $post_id Post id.
	 * @param string $type    Post type.
	 * @return string
	 */
	public static function language_of( $post_id, $type = 'product' ) {
		switch ( self::provider() ) {
			case 'wpml':
				return (string) apply_filters( 'wpml_element_language_code', null, array( 'element_id' => (int) $post_id, 'element_type' => $type ) );
			case 'polylang':
				return (string) pll_get_post_language( (int) $post_id );
		}

		return '';
	}

	/**
	 * Whether a product goes to Quissly: one in the main language, or one that has no
	 * main-language version (else the main language's copy stands for it - every
	 * translation of a product is the same product to Quissly).
	 *
	 * @param int $product_id Product id.
	 * @return bool
	 */
	public static function keep_for_sync( $product_id ) {
		$main = self::main_code();
		if ( '' === $main ) {
			return true;
		}
		$language = self::language_of( $product_id );
		if ( '' === $language || $language === $main ) {
			return true;
		}

		return self::translate( $product_id, $main ) === (int) $product_id;
	}

	/**
	 * Quissly's ids in the shopper's language, in order (map_ids()).
	 *
	 * @param int[]  $ids  Ids from Quissly.
	 * @param string $code Target language's plugin code ('' = the shopper's).
	 * @return int[]
	 */
	public static function to_current( array $ids, $code = '' ) {
		$code = '' !== $code ? $code : self::current_code();
		if ( '' === $code || $code === self::main_code() ) {
			return array_values( array_map( 'intval', $ids ) );
		}

		return self::map_ids(
			$ids,
			static function ( $id ) use ( $code ) {
				return self::translate( $id, $code );
			}
		);
	}

	/**
	 * A matched-variation map (product id => variation id) in the shopper's language: the
	 * product's translation, and the variation's - dropped when the variation has none
	 * (the link then opens the product without one chosen).
	 *
	 * @param array<int,int> $variants Product id => variation id.
	 * @param string         $code     Target language's plugin code ('' = the shopper's).
	 * @return array<int,int>
	 */
	public static function variants_to_current( array $variants, $code = '' ) {
		$code = '' !== $code ? $code : self::current_code();
		if ( '' === $code || $code === self::main_code() ) {
			return $variants;
		}
		$out = array();
		foreach ( $variants as $product_id => $variation_id ) {
			$variation = self::translate( $variation_id, $code, 'product_variation' );
			$product   = self::translate( $product_id, $code );
			if ( $variation !== (int) $variation_id || $product === (int) $product_id ) {
				$out[ $product ] = $variation;
			}
		}

		return $out;
	}

	/**
	 * Ids through a translation, in order, each once (two ids that translate to the same
	 * product keep the first place). PURE.
	 *
	 * @param int[]    $ids       Ids.
	 * @param callable $translate fn(int): int.
	 * @return int[]
	 */
	public static function map_ids( array $ids, callable $translate ) {
		$out = array();
		foreach ( $ids as $id ) {
			$mapped = (int) $translate( (int) $id );
			if ( $mapped > 0 && ! in_array( $mapped, $out, true ) ) {
				$out[] = $mapped;
			}
		}

		return $out;
	}
}
