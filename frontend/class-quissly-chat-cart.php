<?php
/**
 * QChat "Add to Cart" -> the WooCommerce cart.
 *
 * @package Quissly_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Makes the chat widget's "Add to Cart" reach the store's real cart (port of
 * the CS-Cart plugin's bridge).
 *
 * Quissly's chat widget has no WooCommerce cart of its own: for stores without a
 * dedicated widget build it keeps a generic cart that only counts quantities inside the
 * widget and announces each change as a `quissly:generic-cart-sync` window event,
 * detail = { productId: quantity }. The product ids there are QUISSLY'S OWN ids -
 * uuid5( qsearch service's quissly_service_link, "<product id>" ) - not WooCommerce ids
 * (LIVE-CONFIRMED 2026-09-24).
 *
 * assets/js/chat-cart.js listens for that event and:
 *  - translates the ids through `?wc-ajax=quissly_cart_resolve` (this class: uuid5 over
 *    every product and variation, cached);
 *  - adds with WooCommerce's own `?wc-ajax=add_to_cart` (the theme's add-to-cart request;
 *    a variation id adds that variation, a variable parent answers with its product URL
 *    so the shopper can pick options there);
 *  - lowers or removes with `?wc-ajax=quissly_cart_adjust` (this class; WooCommerce has
 *    no "change quantity by product id" request);
 *  - then refreshes the theme's mini-cart (classic fragments and block mini-cart).
 *
 * Loaded exactly where the chat widget is (Quissly_QChat::should_inject()).
 */
class Quissly_Chat_Cart {

	const RESOLVE_ACTION = 'quissly_cart_resolve';
	const ADJUST_ACTION  = 'quissly_cart_adjust';
	const MAP_TRANSIENT  = 'quissly_chat_id_map';
	const MAP_TTL        = 3600;
	const MAX_IDS        = 50;
	const UUID_PATTERN   = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

	/**
	 * Hook the script and the two wc-ajax actions (WooCommerce loads the cart for those).
	 */
	public function register() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'wc_ajax_' . self::RESOLVE_ACTION, array( $this, 'handle_resolve' ) );
		add_action( 'wc_ajax_' . self::ADJUST_ACTION, array( $this, 'handle_adjust' ) );
	}

	/**
	 * Enqueue assets/js/chat-cart.js where the chat widget renders.
	 */
	public function enqueue() {
		if ( is_admin() || ! Quissly_QChat::should_inject( Quissly_Settings::get( 'quissly_enable_qchat' ), Quissly_Env::qchat_service_id() ) ) {
			return;
		}
		wp_register_script( 'quissly-chat-cart', QUISSLY_PLUGIN_URL . 'assets/js/chat-cart.js', array(), QUISSLY_VERSION, true );
		wp_localize_script(
			'quissly-chat-cart',
			'quisslyChatCart',
			array(
				'ajaxUrl'    => WC_AJAX::get_endpoint( '%%endpoint%%' ),
				'resolve'    => self::RESOLVE_ACTION,
				'adjust'     => self::ADJUST_ACTION,
				'addToCart'  => 'add_to_cart',
			)
		);
		wp_enqueue_script( 'quissly-chat-cart' );
	}

	/**
	 * `?wc-ajax=quissly_cart_resolve&ids=uuid,uuid` -> { uuid: product_id } for known ids.
	 */
	public function handle_resolve() {
		$raw = isset( $_GET['ids'] ) ? sanitize_text_field( wp_unslash( $_GET['ids'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public, read-only, like search.
		$ids      = self::valid_ids( explode( ',', $raw ) );
		$resolved = self::resolve( $ids, self::id_map() );
		// Quissly's ids are the main language's products: on a multilingual store the
		// shopper's language's copy goes in the cart.
		foreach ( $resolved as $uuid => $product_id ) {
			$resolved[ $uuid ] = Quissly_Languages::translate( $product_id, '', 'product_variation' === get_post_type( $product_id ) ? 'product_variation' : 'product' );
		}
		wp_send_json( (object) $resolved );
	}

	/**
	 * `?wc-ajax=quissly_cart_adjust` (POST product_id, delta < 0): lower a product's quantity
	 * in the cart - most recently added line first, 0 removes the line.
	 */
	public function handle_adjust() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- same trust level as WooCommerce's own wc-ajax add_to_cart, which takes no nonce.
		$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		$reduce_by  = isset( $_POST['delta'] ) ? -1 * (int) $_POST['delta'] : 0;
		// phpcs:enable
		$changed = 0;
		if ( function_exists( 'WC' ) && WC()->cart ) {
			$lines = array();
			foreach ( WC()->cart->get_cart() as $key => $item ) {
				$lines[ $key ] = array(
					'product_id' => (int) ( ! empty( $item['variation_id'] ) ? $item['variation_id'] : $item['product_id'] ),
					'parent_id'  => (int) $item['product_id'],
					'amount'     => (int) $item['quantity'],
				);
			}
			foreach ( self::plan_reduce( $lines, $product_id, $reduce_by ) as $change ) {
				if ( 0 === $change['amount'] ) {
					WC()->cart->remove_cart_item( $change['key'] );
				} else {
					WC()->cart->set_quantity( $change['key'], $change['amount'] );
				}
				$changed++;
			}
		}
		wp_send_json( array( 'changed' => $changed ) );
	}

	/**
	 * uuid5 (RFC 4122, SHA-1) - how Quissly derives its product ids. PURE.
	 *
	 * @param string $namespace A UUID.
	 * @param string $name      The product id, as a string.
	 * @return string
	 */
	public static function uuid5( $namespace, $name ) {
		$bytes = hex2bin( str_replace( '-', '', strtolower( (string) $namespace ) ) );
		$hash  = sha1( $bytes . (string) $name );

		return sprintf(
			'%s-%s-%04x-%04x-%s',
			substr( $hash, 0, 8 ),
			substr( $hash, 8, 4 ),
			( hexdec( substr( $hash, 12, 4 ) ) & 0x0fff ) | 0x5000,
			( hexdec( substr( $hash, 16, 4 ) ) & 0x3fff ) | 0x8000,
			substr( $hash, 20, 12 )
		);
	}

	/**
	 * Quissly id => product id for every given product id. PURE.
	 *
	 * @param string $namespace  The qsearch quissly_service_link.
	 * @param int[]  $product_ids Product and variation ids.
	 * @return array<string,int>
	 */
	public static function build_map( $namespace, array $product_ids ) {
		$map = array();
		foreach ( $product_ids as $id ) {
			$map[ self::uuid5( $namespace, (string) (int) $id ) ] = (int) $id;
		}

		return $map;
	}

	/**
	 * The known ids of $ids, translated. PURE.
	 *
	 * @param string[]          $ids Quissly ids (lower-case UUIDs).
	 * @param array<string,int> $map Quissly id => product id.
	 * @return array<string,int>
	 */
	public static function resolve( array $ids, array $map ) {
		$out = array();
		foreach ( $ids as $id ) {
			if ( isset( $map[ $id ] ) ) {
				$out[ $id ] = $map[ $id ];
			}
		}

		return $out;
	}

	/**
	 * Well-formed Quissly ids, lower-cased, at most MAX_IDS. PURE.
	 *
	 * @param string[] $raw Candidate ids.
	 * @return string[]
	 */
	public static function valid_ids( array $raw ) {
		$ids = array();
		foreach ( $raw as $id ) {
			$id = strtolower( trim( (string) $id ) );
			if ( preg_match( self::UUID_PATTERN, $id ) && ! in_array( $id, $ids, true ) ) {
				$ids[] = $id;
			}
		}

		return array_slice( $ids, 0, self::MAX_IDS );
	}

	/**
	 * Which cart lines to lower, and to what, to take $reduce_by off a product. PURE.
	 * Matches the line's own product (a variation id matches its variation line; a parent
	 * id matches a simple product's line). Most recently added line first.
	 *
	 * @param array<string,array{product_id:int,parent_id:int,amount:int}> $lines     Cart lines by key.
	 * @param int                                                          $product_id Product or variation id.
	 * @param int                                                          $reduce_by  How many to take off.
	 * @return array<int,array{key:string,amount:int}> amount 0 = remove the line
	 */
	public static function plan_reduce( array $lines, $product_id, $reduce_by ) {
		$product_id = (int) $product_id;
		$reduce_by  = (int) $reduce_by;
		if ( $product_id <= 0 || $reduce_by <= 0 ) {
			return array();
		}
		$changes = array();
		foreach ( array_reverse( $lines, true ) as $key => $line ) {
			if ( $reduce_by <= 0 ) {
				break;
			}
			if ( (int) $line['product_id'] !== $product_id ) {
				continue;
			}
			$take      = min( (int) $line['amount'], $reduce_by );
			$changes[] = array( 'key' => (string) $key, 'amount' => (int) $line['amount'] - $take );
			$reduce_by -= $take;
		}

		return $changes;
	}

	/**
	 * Quissly id => product id for the whole catalog (products and variations), cached.
	 * Empty when the namespace is unknown and cannot be looked up (e.g. a store connected
	 * by pasting a token has no project id / email to open a panel session with).
	 *
	 * @return array<string,int>
	 */
	private static function id_map() {
		$namespace = self::namespace_uuid();
		if ( '' === $namespace ) {
			return array();
		}
		$cached = get_transient( self::MAP_TRANSIENT );
		if ( is_array( $cached ) && isset( $cached['ns'], $cached['map'] ) && $cached['ns'] === $namespace ) {
			return $cached['map'];
		}
		global $wpdb;
		$ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ('product','product_variation') AND post_status = 'publish'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the result is cached right below.
		$map = self::build_map( $namespace, array_map( 'intval', (array) $ids ) );
		set_transient( self::MAP_TRANSIENT, array( 'ns' => $namespace, 'map' => $map ), self::MAP_TTL );

		return $map;
	}

	/**
	 * The stored namespace, looked up (once per hour at most) when missing.
	 *
	 * @return string
	 */
	private static function namespace_uuid() {
		$namespace = (string) Quissly_Settings::get( 'quissly_search_namespace' );
		if ( preg_match( self::UUID_PATTERN, $namespace ) ) {
			return strtolower( $namespace );
		}
		if ( get_transient( 'quissly_ns_lookup_failed' ) ) {
			return '';
		}
		$project_id = (string) Quissly_Settings::get( 'quissly_project_id' );
		$email      = (string) Quissly_Settings::get( 'quissly_account_email' );
		$token      = Quissly_Env::token();
		$directory  = apply_filters( 'quissly_service_directory_client', null );
		$directory  = ( $directory instanceof Quissly_Service_Directory_Client ) ? $directory : new Quissly_Live_Service_Directory_Client();
		$found      = ( '' !== $project_id && '' !== $email && '' !== $token ) ? $directory->qsearch_namespace( $project_id, $email, $token ) : null;
		if ( null === $found || ! preg_match( self::UUID_PATTERN, (string) $found ) ) {
			set_transient( 'quissly_ns_lookup_failed', 1, HOUR_IN_SECONDS );
			return '';
		}
		Quissly_Settings::update( 'quissly_search_namespace', $found );

		return strtolower( (string) $found );
	}
}
