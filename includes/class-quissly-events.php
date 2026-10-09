<?php
/**
 * Shopping activity for Quissly's analytics: recording and sending.
 *
 * @package Quissly_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Records the five shopping events (Quissly_Event_Body) and sends them to
 * `POST /v2beta/qsearch/events`, as the Quissly Shopify app does (the Magento and CS-Cart
 * plugins send the same).
 *
 * Where each comes from:
 *  - add to cart: `woocommerce_add_to_cart` (the classic and block carts, the chat's Add to Cart);
 *  - add to wishlist: YITH WooCommerce Wishlist's `yith_wcwl_added_to_wishlist` (WooCommerce has
 *    no wishlist of its own);
 *  - purchase: when the order reaches one of WooCommerce's paid statuses (wc_get_is_paid_statuses:
 *    processing, completed) - cash on delivery at checkout, a bank transfer when the merchant marks
 *    it paid; once per order (order meta PURCHASE_META);
 *  - product page view and search: the browser (assets/js/events.js -> wc-ajax=quissly_event),
 *    since a page cache serves those pages without running PHP.
 *
 * Recorded only while "Shopping activity" is on (default on) and the store is connected. A
 * shopper's own event carries the same user_id a search does (Quissly_User_Id) and is NOT
 * recorded for a guest without statistics consent (WP Consent API) - the Shopify app drops its
 * tracker's events without analytics consent too. A paid order is the store's own record and is
 * always sent, as customer:<id> or with no user_id.
 *
 * A request only writes a queue row (Quissly_Event_Queue); an Action Scheduler action sends them
 * a minute later, one signed call each, settled by Quissly_Event_Outcome. Nothing here throws into
 * a shopper's request.
 */
final class Quissly_Events {

	const PATH           = '/v2beta/qsearch/events';
	const SEND_HOOK      = 'quissly_send_events';
	const AJAX_ACTION    = 'quissly_event';
	const PURCHASE_META  = '_quissly_purchase_recorded';
	const BATCH          = 200;
	const KEEP           = 50000;
	const TIME_BUDGET    = 40;
	const TIMEOUT        = 5;
	const FAILURES       = 5;
	const RATE_MAX       = 60;
	const RATE_WINDOW    = 60;
	const MAX_BODY_BYTES = 1024;

	/**
	 * Hook everything up.
	 */
	public function register() {
		add_action( self::SEND_HOOK, array( $this, 'send' ) );
		add_action( 'wc_ajax_' . self::AJAX_ACTION, array( $this, 'handle_collect' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'woocommerce_add_to_cart', array( $this, 'on_add_to_cart' ), 10, 4 );
		add_action( 'yith_wcwl_added_to_wishlist', array( $this, 'on_wishlist' ), 10, 1 );
		add_action( 'woocommerce_order_status_changed', array( $this, 'on_order_status' ), 10, 4 );
	}

	/**
	 * Whether events are recorded and sent.
	 *
	 * @return bool
	 */
	public static function enabled() {
		if ( ! Quissly_Settings::get( 'quissly_enable_events' ) || '' === (string) Quissly_Env::token() ) {
			return false;
		}
		$keys = new Quissly_Key_Store();

		return $keys->has_stored_key() || $keys->is_dev_override_active();
	}

	/**
	 * Record the current shopper's event.
	 *
	 * @param string $type     Quissly_Event_Body::VIEW | SEARCH | ADD_TO_CART | WISHLIST.
	 * @param array  $metadata From Quissly_Event_Body.
	 * @return bool Recorded.
	 */
	public static function record_shopper( $type, array $metadata ) {
		try {
			if ( ! self::enabled() ) {
				return false;
			}
			$user_id = Quissly_User_Id::resolve();
			if ( null === $user_id ) {
				return false;
			}
			$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';

			return self::queue( Quissly_Event_Body::envelope( $type, $metadata, $user_id, Quissly_User_Id::device_and_os( $ua ), self::shop(), Quissly_Event_Body::SOURCE_STOREFRONT, wp_generate_uuid4(), time() ) );
		} catch ( Throwable $e ) {
			return false;
		}
	}

	/**
	 * Record a paid order (once).
	 *
	 * @param WC_Order $order Order.
	 * @return bool Recorded.
	 */
	public static function record_order( $order ) {
		try {
			if ( ! self::enabled() || ! is_object( $order ) || $order->get_meta( self::PURCHASE_META ) ) {
				return false;
			}
			$lines = array();
			foreach ( $order->get_items() as $item ) {
				if ( ! is_callable( array( $item, 'get_product_id' ) ) ) {
					continue;
				}
				// Products as Quissly knows them (a multilingual store's main-language copies).
				$lines[] = array(
					'product_id' => Quissly_Languages::to_main( (int) $item->get_product_id() ),
					'variant_id' => Quissly_Languages::to_main( (int) $item->get_variation_id() ),
					'name'       => (string) $item->get_name(),
					'quantity'   => (float) $item->get_quantity(),
					'price'      => (float) $order->get_item_subtotal( $item, false, false ),
				);
			}
			$customer = (int) $order->get_customer_id();
			$metadata = Quissly_Event_Body::purchase( (string) $order->get_order_number(), (float) $order->get_total(), (string) $order->get_currency(), $lines );
			$recorded = self::queue( Quissly_Event_Body::envelope( Quissly_Event_Body::PURCHASE, $metadata, $customer > 0 ? Quissly_User_Id::CUSTOMER_PREFIX . $customer : null, array(), self::shop(), Quissly_Event_Body::SOURCE_ORDER, wp_generate_uuid4(), time() ) );
			if ( $recorded ) {
				$order->update_meta_data( self::PURCHASE_META, time() );
				$order->save_meta_data();
			}

			return $recorded;
		} catch ( Throwable $e ) {
			return false;
		}
	}

	/**
	 * `woocommerce_add_to_cart`.
	 *
	 * @param string $cart_item_key Cart item key.
	 * @param int    $product_id    Product (a variable product's parent).
	 * @param int    $quantity      Quantity.
	 * @param int    $variation_id  Variation chosen.
	 */
	public function on_add_to_cart( $cart_item_key, $product_id, $quantity, $variation_id = 0 ) {
		if ( (int) $product_id > 0 ) {
			self::record_shopper( Quissly_Event_Body::ADD_TO_CART, Quissly_Event_Body::add_to_cart( Quissly_Languages::to_main( (int) $product_id ), Quissly_Languages::to_main( (int) $variation_id ), (float) $quantity ) );
		}
	}

	/**
	 * YITH WooCommerce Wishlist's `yith_wcwl_added_to_wishlist`.
	 *
	 * @param int $product_id Product.
	 */
	public function on_wishlist( $product_id ) {
		if ( (int) $product_id > 0 ) {
			self::record_shopper( Quissly_Event_Body::WISHLIST, Quissly_Event_Body::wishlist( Quissly_Languages::to_main( (int) $product_id ) ) );
		}
	}

	/**
	 * `woocommerce_order_status_changed`: an order reaching a paid status is a purchase.
	 *
	 * @param int      $order_id Order id.
	 * @param string   $from     Old status.
	 * @param string   $to       New status.
	 * @param WC_Order $order    Order.
	 */
	public function on_order_status( $order_id, $from, $to, $order = null ) {
		$paid = function_exists( 'wc_get_is_paid_statuses' ) ? wc_get_is_paid_statuses() : array( 'processing', 'completed' );
		if ( ! in_array( (string) $to, $paid, true ) ) {
			return;
		}
		if ( ! is_object( $order ) && function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( $order_id );
		}
		self::record_order( $order );
	}

	/**
	 * The script for a product page or a search results page (page 1).
	 */
	public function enqueue() {
		$event = self::page_event();
		if ( null === $event || ! self::enabled() ) {
			return;
		}
		wp_register_script( 'quissly-events', QUISSLY_PLUGIN_URL . 'assets/js/events.js', array(), QUISSLY_VERSION, true );
		wp_localize_script(
			'quissly-events',
			'quisslyEvent',
			array(
				'endpoint' => class_exists( 'WC_AJAX' ) ? WC_AJAX::get_endpoint( self::AJAX_ACTION ) : home_url( '/?wc-ajax=' . self::AJAX_ACTION ),
				'event'    => $event,
			)
		);
		wp_enqueue_script( 'quissly-events' );
	}

	/**
	 * What this page reports. Page-constant (the page's product, the query in its URL), so a
	 * page cache can keep serving it; who the shopper is is decided when the event arrives.
	 *
	 * @return array|null
	 */
	public static function page_event() {
		if ( function_exists( 'is_product' ) && is_product() ) {
			// The page WooCommerce answers a classic Add to cart form with is the same product
			// page again - an add to cart (recorded as one), not a second view.
			if ( isset( $_REQUEST['add-to-cart'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only.
				return null;
			}
			$id = (int) get_queried_object_id();
			return $id > 0 ? array( 'type' => Quissly_Event_Body::VIEW, 'product_id' => (string) $id ) : null;
		}
		if ( ! is_search() || (int) get_query_var( 'paged' ) > 1 ) {
			return null;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only: which results page this is.
		foreach ( array( Quissly_Proxy::QUERY_VAR_VOICE => 'voice', Quissly_Proxy::QUERY_VAR_IMG => 'image' ) as $var => $modality ) {
			if ( ! empty( $_GET[ $var ] ) ) {
				return array( 'type' => Quissly_Event_Body::SEARCH, 'query' => '', 'modality' => $modality );
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$query = trim( (string) get_search_query( false ) );

		return '' !== $query ? array( 'type' => Quissly_Event_Body::SEARCH, 'query' => $query, 'modality' => 'text' ) : null;
	}

	/**
	 * `?wc-ajax=quissly_event`: a product page view or a search, from assets/js/events.js.
	 *
	 * Anonymous like the other storefront endpoints, with the same protections: 404 while off or
	 * not connected, a body cap, a per-IP throttle (higher than the paid media proxies'). The
	 * browser says only what was viewed or searched; a product id that is not a published product
	 * is refused. Writes a queue row and nothing else.
	 */
	public function handle_collect() {
		$this->respond( self::collect( (string) file_get_contents( 'php://input' ) ) );
	}

	/**
	 * Read and record one beacon (the testable half of handle_collect()).
	 *
	 * @param string $raw Request body.
	 * @return int HTTP status.
	 */
	public static function collect( $raw ) {
		if ( ! self::enabled() ) {
			return 404;
		}
		if ( self::rate_limited() ) {
			return 429;
		}
		if ( strlen( $raw ) > self::MAX_BODY_BYTES ) {
			return 413;
		}
		$event = json_decode( $raw, true );
		$type  = is_array( $event ) && isset( $event['type'] ) ? (string) $event['type'] : '';
		if ( Quissly_Event_Body::VIEW === $type ) {
			$id      = isset( $event['product_id'] ) && is_scalar( $event['product_id'] ) && preg_match( '/^[1-9]\d{0,18}$/', (string) $event['product_id'] ) ? (int) $event['product_id'] : 0;
			$product = $id > 0 && function_exists( 'wc_get_product' ) ? wc_get_product( $id ) : null;
			if ( ! $product || 'publish' !== $product->get_status() ) {
				return 400;
			}
			self::record_shopper( Quissly_Event_Body::VIEW, Quissly_Event_Body::view( Quissly_Languages::to_main( $id ) ) );
			return 204;
		}
		if ( Quissly_Event_Body::SEARCH === $type ) {
			$metadata = Quissly_Event_Body::search(
				isset( $event['query'] ) && is_string( $event['query'] ) ? $event['query'] : '',
				isset( $event['modality'] ) && is_string( $event['modality'] ) ? $event['modality'] : ''
			);
			if ( null === $metadata ) {
				return 400;
			}
			self::record_shopper( Quissly_Event_Body::SEARCH, $metadata );
			return 204;
		}

		return 400;
	}

	/**
	 * The Action Scheduler action: send what is due, then come back for the rest.
	 *
	 * @return array{sent:int,dropped:int,retried:int}
	 */
	public function send() {
		$counts = array(
			'sent'    => 0,
			'dropped' => 0,
			'retried' => 0,
		);
		$now    = time();
		if ( ! self::enabled() ) {
			// Switched off (or disconnected): nothing more leaves the store.
			$counts['dropped'] = Quissly_Event_Queue::clear();
			return $counts;
		}
		$counts['dropped'] += Quissly_Event_Queue::clear( $now - Quissly_Event_Outcome::MAX_AGE_SECONDS );
		$counts['dropped'] += Quissly_Event_Queue::trim( self::KEEP );

		$private = ( new Quissly_Key_Store() )->get_private_key();
		if ( $private ) {
			$client   = new Quissly_Http_Client( Quissly_Env::token(), Quissly_Env::environment(), new Quissly_Signer( $private ) );
			$started  = microtime( true );
			$done     = array();
			$failures = 0;
			foreach ( Quissly_Event_Queue::due( $now, self::BATCH ) as $row ) {
				if ( microtime( true ) - $started > self::TIME_BUDGET ) {
					break;
				}
				if ( empty( $row['body'] ) ) {
					$done[] = $row['queue_id'];
					++$counts['dropped'];
					continue;
				}
				$response = $client->post_v2( self::PATH, $row['body'], self::TIMEOUT );
				$status   = is_wp_error( $response ) ? 0 : (int) $response['code'];
				$next     = Quissly_Event_Outcome::after( $status, $row['attempts'] );
				if ( Quissly_Event_Outcome::RETRY === $next['action'] ) {
					Quissly_Event_Queue::retry( $row['queue_id'], $row['attempts'] + 1, $now + $next['delay'] );
					++$counts['retried'];
					// Quissly is down or refusing this store: the rest waits for the next run.
					if ( ++$failures >= self::FAILURES ) {
						break;
					}
					continue;
				}
				$failures = 0;
				$done[]   = $row['queue_id'];
				if ( Quissly_Event_Outcome::SENT === $next['action'] ) {
					++$counts['sent'];
				} else {
					++$counts['dropped'];
				}
			}
			Quissly_Event_Queue::remove( $done );
		}

		$next_due = Quissly_Event_Queue::next_due();
		if ( null !== $next_due ) {
			self::schedule( max( $now + MINUTE_IN_SECONDS, $next_due ) );
		}
		if ( $counts['sent'] || $counts['dropped'] || $counts['retried'] ) {
			Quissly_Sync_Log::log( sprintf( 'events sent=%d dropped=%d retried=%d', $counts['sent'], $counts['dropped'], $counts['retried'] ) );
		}

		return $counts;
	}

	/**
	 * Queue a body and make sure a send is scheduled.
	 *
	 * @param array $body Event body.
	 * @return bool
	 */
	private static function queue( array $body ) {
		if ( ! Quissly_Event_Queue::add( $body, time() ) ) {
			return false;
		}
		self::schedule( time() + MINUTE_IN_SECONDS );

		return true;
	}

	/**
	 * Schedule the send unless one is already waiting.
	 *
	 * @param int $when Time.
	 */
	private static function schedule( $when ) {
		if ( function_exists( 'as_has_scheduled_action' ) && ! as_has_scheduled_action( self::SEND_HOOK ) ) {
			as_schedule_single_action( (int) $when, self::SEND_HOOK, array(), 'quissly' );
		}
	}

	/**
	 * The store's host ("shop.example.com") - Shopify sends its myshopify domain.
	 *
	 * @return string
	 */
	private static function shop() {
		return strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	}

	/**
	 * A fixed-window per-IP counter in a transient (Quissly_Proxy's, with its own bucket).
	 *
	 * @return bool
	 */
	private static function rate_limited() {
		$ip    = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- an opaque throttle-bucket key, never rendered or stored raw.
		$key   = 'quissly_rl_events_' . md5( (string) apply_filters( 'quissly_client_ip', $ip ) );
		$count = (int) get_transient( $key );
		if ( $count >= self::RATE_MAX ) {
			return true;
		}
		set_transient( $key, $count + 1, self::RATE_WINDOW );

		return false;
	}

	/**
	 * End the wc-ajax request with a status and no body.
	 *
	 * @param int $status HTTP status.
	 */
	private function respond( $status ) {
		status_header( $status );
		nocache_headers();
		wp_die( '', '', array( 'response' => $status ) );
	}
}
