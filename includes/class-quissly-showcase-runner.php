<?php
/**
 * Showcase queries, generated in the background (port of the Shopify app's
 * showcase-queries.server.ts).
 *
 * @package Quissly_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generates a store's search bar suggestions from its own catalog - once, in the
 * background, after the first catalog sync:
 *
 *  1. read catalog facts (the 100 most recently changed published products);
 *  2. build candidates (Quissly_Showcase_Queries) and RUN each through search - kept only
 *     with at least 2 results (up to 12 searches);
 *  3. fewer than 3 kept = the search index may still be filling: retry in an hour (at most
 *     6 attempts; the last one keeps whatever it found);
 *  4. write them as the list (Quissly_Search_Suggestions) - but ONLY while Quissly holds no
 *     list: a merchant's own list is never overwritten, even one written elsewhere.
 *
 * A merchant who saves their own list in Configuration (an actual change) ends it for
 * good; saving the page unchanged does not (the Shopify app stops on any save, which could
 * leave a store without suggestions forever). The generated list is kept either way, for
 * Configuration's "use the generated suggestions".
 *
 * After MAX_ATTEMPTS with nothing found it stops (the Shopify app's runner retries hourly
 * for ever). Runs from an hourly Action Scheduler action. On by default; the
 * `quissly_showcase_enabled` filter switches it off. Costs up to 12 searches per attempt,
 * which show as ordinary (unattributed) searches.
 */
class Quissly_Showcase_Runner {

	const HOOK   = 'quissly_showcase_tick';
	const OPTION = 'quissly_showcase_state';

	const MIN_ACCEPTABLE = 3;
	const MAX_ATTEMPTS   = 6;
	const LOCK_SECONDS   = 600;

	/**
	 * Register the Action Scheduler handler and make sure the hourly tick is scheduled.
	 */
	public function register() {
		add_action( self::HOOK, array( $this, 'tick' ) );
		add_action( 'init', array( $this, 'ensure_scheduled' ), 20 );
	}

	/**
	 * Schedule the hourly tick once (Action Scheduler is up by init).
	 */
	public function ensure_scheduled() {
		if ( ! self::enabled() || ! function_exists( 'as_has_scheduled_action' ) || self::finished() ) {
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
	 * The state: generated (string[]), generated_at, attempts, next_at, locked_at,
	 * last_error, merchant_saved.
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
	 * Nothing left to do: generated, or the merchant wrote their own list.
	 *
	 * @return bool
	 */
	public static function finished() {
		$state = self::state();

		return ! empty( $state['generated_at'] ) || ! empty( $state['merchant_saved'] );
	}

	/**
	 * The generated list (for Configuration), [] when none.
	 *
	 * @return string[]
	 */
	public static function generated() {
		$state = self::state();

		return Quissly_Search_Suggestions::clean( $state['generated'] ?? array() );
	}

	/**
	 * A merchant wrote their own list: generation never writes after this.
	 */
	public static function merchant_saved() {
		self::update( array( 'merchant_saved' => true ) );
	}

	/**
	 * The hourly tick: generate when due.
	 *
	 * @param int|null $now Time (tests).
	 * @return string What happened (for the log / tests).
	 */
	public function tick( $now = null ) {
		$now = null === $now ? time() : (int) $now;
		if ( ! self::enabled() || self::finished() ) {
			if ( function_exists( 'as_unschedule_all_actions' ) && self::finished() ) {
				as_unschedule_all_actions( self::HOOK );
			}
			return 'finished';
		}
		if ( ! Quissly_Sync_State::is_initial_sync_complete() || '' === Quissly_Env::token() ) {
			return 'not_ready';
		}
		$state = self::state();
		if ( (int) ( $state['next_at'] ?? 0 ) > $now ) {
			return 'not_due';
		}
		if ( (int) ( $state['locked_at'] ?? 0 ) > $now - self::LOCK_SECONDS ) {
			return 'locked';
		}
		self::update( array( 'locked_at' => $now ) );
		$outcome = $this->generate( $now );
		if ( self::finished() && function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK ); // done: nothing left to tick for.
		}

		return $outcome;
	}

	/**
	 * One attempt, end to end. Never throws.
	 *
	 * @param int $now Time.
	 * @return string
	 */
	private function generate( $now ) {
		try {
			$candidates = Quissly_Showcase_Queries::build_candidates( self::catalog_facts() );
			if ( empty( $candidates ) ) {
				return $this->retry( $now, DAY_IN_SECONDS, 'No published products to build suggestions from.', 'no_products' );
			}

			$client   = apply_filters( 'quissly_search_client', null );
			$client   = $client instanceof Quissly_Search_Client ? $client : new Quissly_Live_Search_Client();
			$accepted = Quissly_Showcase_Queries::validate(
				$candidates,
				static function ( $query ) use ( $client ) {
					$response = $client->search(
						array(
							'query'            => $query,
							'user_id'          => null, // unattributed, like the Shopify app's.
							'page_number'      => 1,
							'page_size'        => 5,
							'sort_by'          => 0,
							'include_metadata' => false,
							'channel'          => 'web',
						)
					);
					if ( null === $response ) {
						throw new RuntimeException( 'search failed' );
					}
					$parsed = Quissly_Response_Parser::parse_search( $response );

					return max( (int) $parsed['num_total_results'], count( $parsed['ids'] ) );
				}
			);

			$attempts     = (int) ( self::state()['attempts'] ?? 0 ) + 1;
			$last_attempt = $attempts >= self::MAX_ATTEMPTS;
			if ( $last_attempt && empty( $accepted ) ) {
				// Give up rather than spend searches every hour for ever (the Shopify app's
				// runner keeps retrying); the merchant can still write a list.
				self::update( array( 'generated' => array(), 'generated_at' => $now, 'attempts' => $attempts, 'locked_at' => 0, 'last_error' => 'No candidate search returned results after ' . $attempts . ' attempts.' ) );
				return 'gave_up';
			}
			if ( count( $accepted ) < self::MIN_ACCEPTABLE && ! ( $last_attempt && count( $accepted ) > 0 ) ) {
				// Most likely the search index is still filling after the first sync.
				/* translators: 1: kept, 2: tried. */
				return $this->retry( $now, HOUR_IN_SECONDS, sprintf( 'Only %1$d of %2$d candidate searches returned results.', count( $accepted ), count( $candidates ) ), 'only_' . count( $accepted ) . '_validated' );
			}

			$queries = Quissly_Showcase_Queries::pick( $accepted );
			$current = Quissly_Search_Suggestions::read();
			if ( null === $current ) {
				return $this->retry( $now, HOUR_IN_SECONDS, 'Quissly could not be read.', 'unreadable' );
			}
			if ( ! empty( $current['queries'] ) || ! empty( self::state()['merchant_saved'] ) ) {
				// Someone already wrote a list: keep theirs, remember ours for "use generated".
				self::update( array( 'generated' => $queries, 'generated_at' => $now, 'attempts' => $attempts, 'locked_at' => 0, 'last_error' => '' ) );
				return 'kept_existing';
			}
			$error = Quissly_Search_Suggestions::save( $current['enabled'], $queries );
			if ( '' !== $error ) {
				return $this->retry( $now, HOUR_IN_SECONDS, $error, 'write_failed' );
			}
			self::update( array( 'generated' => $queries, 'generated_at' => $now, 'attempts' => $attempts, 'locked_at' => 0, 'last_error' => '' ) );
			Quissly_Sync_Log::log( 'Search bar suggestions generated from the catalog: ' . count( $queries ) . '.' );

			return 'written';
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
				'attempts'   => (int) ( self::state()['attempts'] ?? 0 ) + 1,
				'next_at'    => $now + $in,
				'locked_at'  => 0,
				'last_error' => $error,
			)
		);

		return $outcome;
	}

	/**
	 * Catalog facts for Quissly_Showcase_Queries: the 100 most recently changed published
	 * products - title, category (the deepest), brand, attributes with their values, price.
	 *
	 * @return array
	 */
	public static function catalog_facts() {
		$products = array();
		foreach ( wc_get_products( array( 'limit' => 100, 'status' => 'publish', 'orderby' => 'modified', 'order' => 'DESC' ) ) as $product ) {
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
			$products[] = array(
				'title'        => $product->get_name(),
				'product_type' => self::deepest_category( $product->get_id() ),
				'category'     => null,
				'vendor'       => self::brand( $product ),
				'options'      => $options,
				'min_price'    => is_numeric( $price ) ? (float) $price : null,
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
