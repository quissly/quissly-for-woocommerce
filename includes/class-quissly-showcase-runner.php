<?php
/**
 * Search Examples (the typing list), asked of Quissly in the background (as the Quissly Shopify
 * app does).
 *
 * @package Quissly_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Asks Quissly for the store's search bar suggestions, in the background, and keeps track of
 * when to ask - the Quissly Shopify app's showcase runner (the Magento and CS-Cart plugins have
 * the same).
 *
 * Quissly makes the suggestions (`POST /v2beta/qsearch/suggestions_enrich`,
 * Quissly_Suggestions_Enrich): it writes them, checks each one finds products, and writes them
 * into the QSearch widget_config itself - keeping any list the merchant edited, so nothing here
 * needs to know about the merchant's own list. What this sends is what only WooCommerce knows:
 * the store's languages and its catalog in each (the 60 most recently changed published
 * products; a multilingual store's - WPML, Polylang - in every language it has, so each gets its
 * own list, the main language's being the main one).
 *
 * When: after the first catalog sync (before it there is nothing to search). Right after the
 * sync the index can still be filling - Quissly answers "insufficient" and this asks again in an
 * hour; after MAX_ATTEMPTS it accepts a shorter list. Done once Quissly answers written or
 * unchanged, until the store's languages change or the generator does (lists from an older one
 * are made again once). Runs from an hourly Action Scheduler action. On by default; the
 * `quissly_showcase_enabled` filter switches it off.
 */
class Quissly_Showcase_Runner {

	const HOOK   = 'quissly_showcase_tick';
	const OPTION = 'quissly_showcase_state';

	const LOCK_SECONDS = 600;

	/** One call can take minutes: a model call and up to 12 searches. */
	const TIMEOUT_SECONDS = 180;

	/**
	 * Register the Action Scheduler handler and make sure the hourly tick is scheduled.
	 */
	public function register() {
		add_action( self::HOOK, array( $this, 'tick' ) );
		add_action( 'init', array( $this, 'ensure_scheduled' ), 20 );
	}

	/**
	 * Schedule the hourly tick while something is outstanding (Action Scheduler is up by init).
	 */
	public function ensure_scheduled() {
		if ( ! self::enabled() || ! function_exists( 'as_has_scheduled_action' ) || ! self::outstanding() ) {
			return;
		}
		if ( ! as_has_scheduled_action( self::HOOK ) ) {
			as_schedule_recurring_action( time() + MINUTE_IN_SECONDS, HOUR_IN_SECONDS, self::HOOK, array(), 'quissly' );
		}
	}

	/**
	 * @return bool
	 */
	public static function enabled() {
		return (bool) apply_filters( 'quissly_showcase_enabled', true );
	}

	/**
	 * The state: generated_at, generator, language, generated (string[]), attempts, next_at,
	 * locked_at, last_error, last_status.
	 *
	 * @return array
	 */
	public static function state() {
		$state = get_option( self::OPTION, array() );

		return is_array( $state ) ? $state : array();
	}

	/**
	 * @param array $changes Keys to set.
	 */
	private static function update( array $changes ) {
		update_option( self::OPTION, array_merge( self::state(), $changes ), false );
	}

	/**
	 * Quissly has made the list.
	 *
	 * @return bool
	 */
	public static function finished() {
		return ! empty( self::state()['generated_at'] );
	}

	/**
	 * Something to ask for: never asked, the store's languages changed (its main one, or one
	 * added), or an older generator.
	 *
	 * @return bool
	 */
	public static function outstanding() {
		$state = self::state();

		return empty( $state['generated_at'] )
			|| Quissly_Suggestions_Enrich::GENERATOR !== ( $state['generator'] ?? '' )
			|| self::language() !== ( $state['language'] ?? '' )
			// A list made before every language was asked for covered the main one alone.
			|| self::languages() !== ( $state['languages'] ?? array( $state['language'] ?? '' ) );
	}

	/**
	 * What Quissly generated last, as it answered here ([] when nothing yet). Configuration
	 * prefers Quissly's own copy (client_specific_queries_generated).
	 *
	 * @return string[]
	 */
	public static function generated() {
		$state = self::state();

		return Quissly_Search_Suggestions::clean( $state['generated'] ?? array() );
	}

	/**
	 * The store's (main) language as a widget_config key ("en", "ka", "pt-br").
	 *
	 * @return string
	 */
	public static function language() {
		return Quissly_Languages::main_key();
	}

	/**
	 * Every language's key, the main one first (one, without a multilingual plugin).
	 *
	 * @return string[]
	 */
	public static function languages() {
		return array_values( array_unique( wp_list_pluck( Quissly_Languages::languages(), 'key' ) ) );
	}

	/**
	 * The hourly tick: ask Quissly when due.
	 *
	 * @param int|null $now Time (tests).
	 * @return string What happened (for the log / tests).
	 */
	public function tick( $now = null ) {
		$now = null === $now ? time() : (int) $now;
		if ( ! self::enabled() || ! self::outstanding() ) {
			if ( function_exists( 'as_unschedule_all_actions' ) && ! self::outstanding() ) {
				as_unschedule_all_actions( self::HOOK );
			}
			return 'finished';
		}
		if ( ! Quissly_Sync_State::is_initial_sync_complete() || '' === Quissly_Env::token() ) {
			return 'not_ready';
		}
		// The storefront reads the lists Quissly writes with the stored service id: have it.
		Quissly_Search_Suggestions::lookup_service_id();
		$state = self::state();
		if ( (int) ( $state['next_at'] ?? 0 ) > $now ) {
			return 'not_due';
		}
		if ( (int) ( $state['locked_at'] ?? 0 ) > $now - self::LOCK_SECONDS ) {
			return 'locked';
		}
		self::update( array( 'locked_at' => $now ) );
		$outcome = $this->ask( $state, $now );
		if ( ! self::outstanding() && function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK ); // done: nothing left to tick for.
		}

		return $outcome;
	}

	/**
	 * One call, end to end. Never throws.
	 *
	 * @param array $state The state before.
	 * @param int   $now   Time.
	 * @return string
	 */
	private function ask( array $state, $now ) {
		try {
			$language   = self::language();
			$languages  = self::languages();
			$catalogs   = array();
			foreach ( Quissly_Languages::languages() as $entry ) {
				if ( ! isset( $catalogs[ $entry['key'] ] ) ) {
					$catalogs[ $entry['key'] ] = self::catalog_facts( $entry['code'] );
				}
			}
			$attempts   = (int) ( $state['attempts'] ?? 0 ) + 1;
			$regenerate = ! empty( $state['generated_at'] ) && Quissly_Suggestions_Enrich::GENERATOR !== ( $state['generator'] ?? '' );
			$body       = Quissly_Suggestions_Enrich::body(
				$language,
				$languages,
				$catalogs,
				array(
					'main'        => self::generated(),
					'by_language' => (array) ( $state['generated_by_language'] ?? array() ),
				),
				$regenerate ? 'regenerate' : 'fill',
				$attempts >= Quissly_Suggestions_Enrich::MAX_ATTEMPTS
			);

			$private = ( new Quissly_Key_Store() )->get_private_key();
			if ( ! $private ) {
				return $this->retry( $now, HOUR_IN_SECONDS, 'Not connected.', 'not_ready' );
			}
			$client   = new Quissly_Http_Client( Quissly_Env::token(), Quissly_Env::environment(), new Quissly_Signer( $private ) );
			$response = $client->post_v2( Quissly_Suggestions_Enrich::PATH, $body, self::TIMEOUT_SECONDS );
			if ( is_wp_error( $response ) || $response['code'] < 200 || $response['code'] >= 300 || ! is_array( $response['body'] ) ) {
				$error = is_wp_error( $response ) ? 'suggestions_enrich transport error' : 'suggestions_enrich http=' . (int) $response['code'];
				return $this->retry( $now, HOUR_IN_SECONDS, $error, 'error' );
			}

			$status = isset( $response['body']['status'] ) ? (string) $response['body']['status'] : '';
			$next   = Quissly_Suggestions_Enrich::schedule( $status );
			if ( ! $next['done'] ) {
				return $this->retry( $now, $next['retry'], $next['reason'], '' !== $status ? $status : 'error' );
			}

			$main = Quissly_Search_Suggestions::clean( $response['body']['main'] ?? array() );
			if ( empty( $main ) ) {
				$main = Quissly_Search_Suggestions::clean( $response['body']['languages'][ $language ] ?? array() );
			}
			// The other languages' lists (Quissly writes them into the widget_config itself;
			// kept here as the next call's baseline).
			$by_language = (array) ( $state['generated_by_language'] ?? array() );
			foreach ( array_slice( $languages, 1 ) as $other ) {
				$list = Quissly_Search_Suggestions::clean( $response['body']['languages'][ $other ] ?? array() );
				if ( ! empty( $list ) ) {
					$by_language[ $other ] = $list;
				}
			}
			self::update(
				array(
					'generated_at'          => (int) ( $state['generated_at'] ?? 0 ) ? (int) $state['generated_at'] : $now,
					'generator'             => Quissly_Suggestions_Enrich::GENERATOR,
					'language'              => $language,
					'languages'             => $languages,
					'generated_by_language' => $by_language,
					'generated'             => ! empty( $main ) ? $main : ( $state['generated'] ?? array() ),
					'attempts'              => 0,
					'next_at'               => 0,
					'locked_at'             => 0,
					'last_error'            => '',
					'last_status'           => $status,
				)
			);
			if ( 'written' === $status ) {
				// The overlay's list is cached for the storefront: show the new one at once.
				delete_transient( Quissly_Search_Suggestions::CACHE );
				Quissly_Sync_Log::log( 'Search bar suggestions written by Quissly.' );
			}

			return $status;
		} catch ( \Throwable $e ) {
			return $this->retry( $now, HOUR_IN_SECONDS, substr( $e->getMessage(), 0, 300 ), 'error' );
		}
	}

	/**
	 * Try again later.
	 *
	 * @param int    $now     Time.
	 * @param int    $in      Seconds until the next attempt.
	 * @param string $error   Why (kept for the admin).
	 * @param string $outcome Outcome word.
	 * @return string
	 */
	private function retry( $now, $in, $error, $outcome ) {
		self::update(
			array(
				'attempts'    => (int) ( self::state()['attempts'] ?? 0 ) + 1,
				'next_at'     => $now + $in,
				'locked_at'   => 0,
				'last_error'  => $error,
				'last_status' => $outcome,
			)
		);

		return $outcome;
	}

	/**
	 * Catalog facts for Quissly's suggestion generator: the 60 most recently changed published
	 * products - title, category (the deepest), brand, attributes with their values, price range
	 * - of one language on a multilingual store (as its shoppers read them).
	 *
	 * @param string $code A language's plugin code ('' = the store's own products, as before).
	 * @return array
	 */
	public static function catalog_facts( $code = '' ) {
		$products = array();
		$args     = array( 'limit' => Quissly_Suggestions_Enrich::MAX_PRODUCTS, 'status' => 'publish', 'orderby' => 'modified', 'order' => 'DESC' );
		if ( '' !== $code ) {
			$args['lang'] = $code; // Polylang's query var; WPML follows the switched language.
		}
		$found = Quissly_Languages::in_language(
			$code,
			static function () use ( $args ) {
				return wc_get_products( $args );
			}
		);
		foreach ( $found as $product ) {
			$options = array();
			foreach ( $product->get_attributes() as $attribute ) {
				if ( ! is_a( $attribute, 'WC_Product_Attribute' ) ) {
					continue;
				}
				$values = $attribute->is_taxonomy() ? wp_list_pluck( (array) $attribute->get_terms(), 'name' ) : $attribute->get_options();
				// A custom attribute often holds several values in one ("Linen, Polyester"):
				// one value each, as a Shopify option has.
				$split = array();
				foreach ( $values as $value ) {
					foreach ( preg_split( '/\s*[,|]\s*/u', (string) $value ) as $part ) {
						if ( '' !== trim( $part ) ) {
							$split[] = trim( $part );
						}
					}
				}
				$options[] = array( 'name' => wc_attribute_label( $attribute->get_name(), $product ), 'values' => $split );
			}
			$price      = $product->is_type( 'variable' ) ? $product->get_variation_price( 'min' ) : $product->get_price();
			$max_price  = $product->is_type( 'variable' ) ? $product->get_variation_price( 'max' ) : $price;
			$products[] = array(
				'title'        => $product->get_name(),
				'product_type' => self::deepest_category( $product->get_id() ),
				'category'     => null,
				'vendor'       => self::brand( $product ),
				'options'      => $options,
				'min_price'    => is_numeric( $price ) ? (float) $price : null,
				'max_price'    => is_numeric( $max_price ) ? (float) $max_price : null,
			);
		}

		return array(
			'shop_name' => get_bloginfo( 'name' ),
			'currency'  => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '',
			'products'  => $products,
		);
	}

	/**
	 * The product's deepest category name ("Jackets", not "Men"), '' when none.
	 *
	 * @param int $product_id Product id.
	 * @return string
	 */
	private static function deepest_category( $product_id ) {
		$terms = get_the_terms( $product_id, 'product_cat' );
		if ( ! is_array( $terms ) ) {
			return '';
		}
		$best = null;
		$max  = -1;
		foreach ( $terms as $term ) {
			if ( 'uncategorized' === $term->slug ) {
				continue;
			}
			$depth = count( get_ancestors( $term->term_id, 'product_cat' ) );
			if ( $depth > $max ) {
				$max  = $depth;
				$best = $term;
			}
		}

		return $best ? html_entity_decode( $best->name, ENT_QUOTES, 'UTF-8' ) : '';
	}

	/**
	 * The product's brand: a brand taxonomy, else a brand attribute, else ''.
	 *
	 * @param WC_Product $product Product.
	 * @return string
	 */
	private static function brand( WC_Product $product ) {
		foreach ( array( 'product_brand', 'pwb-brand', 'product_brands' ) as $taxonomy ) {
			if ( taxonomy_exists( $taxonomy ) ) {
				$terms = get_the_terms( $product->get_id(), $taxonomy );
				if ( is_array( $terms ) && isset( $terms[0] ) ) {
					return (string) $terms[0]->name;
				}
			}
		}
		$attr = $product->get_attribute( 'brand' );

		return '' !== $attr ? $attr : (string) $product->get_attribute( 'pa_brand' );
	}
}
