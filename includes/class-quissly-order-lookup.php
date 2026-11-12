<?php
/**
 * Order status for QChat: the endpoint Quissly asks when a shopper asks the chat about an order.
 *
 * @package Quissly_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `POST /wp-json/quissly/v1/orders/lookup` - Quissly's chat asks it "where is order N?" with the email or
 * phone the shopper gave (the Shopify app's order lookup, for WooCommerce):
 *
 *  - Only Quissly may ask: the raw body is signed with Quissly's order lookup key (Ed25519; the public
 *    halves are KEYS, by key id), names this store's project, is at most CLOCK_SKEW old and its nonce is
 *    used once. Anything else is a 401 with no body - and nothing is read before that.
 *  - The contact is checked HERE: the order is answered only when the email or phone is the order's own,
 *    otherwise exactly `not_found` (never saying which one failed), so nothing about an order leaves the
 *    store for a wrong guess.
 *  - The answer has the shape Quissly's engine already reads for Shopify orders (statuses in Shopify's
 *    words: fulfillment UNFULFILLED / IN_PROGRESS / FULFILLED / ON_HOLD, payment PENDING / PAID /
 *    PARTIALLY_REFUNDED / REFUNDED), tracking from the Shipment Tracking plugins when one stored it.
 *
 * Quissly learns the endpoint's address from the store: maybe_register() PUTs it (with the store API key)
 * once a day and whenever it or the switch changes. Off with "Order status in QChat" in Configuration.
 */
class Quissly_Order_Lookup {

	const NAMESPACE = 'quissly/v1';
	const ROUTE     = '/orders/lookup';

	/** Quissly's order lookup public keys (Ed25519, base64), by key id. */
	const KEYS = array(
		'k1' => '4oqUKgUeo6tWq0wECjgNycyb8E9LRWPEijAkJ/xyYfU=',
	);

	const MAX_BODY       = 2048;
	const CLOCK_SKEW     = 300;
	const NONCE_TTL      = 600;
	const RATE_MAX       = 60; // lookups a minute, for the whole store.
	const MIN_PHONE      = 7;
	const MAX_ITEMS      = 50;
	const REGISTER_PATH  = '/api/v1/store/order-lookup';
	const STATE_OPTION   = 'quissly_order_lookup';
	const REGISTER_EVERY = 86400;

	/**
	 * Hook the route and the registration.
	 */
	public function register() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( Quissly_Version_Report::HOOK, array( __CLASS__, 'daily' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_register' ) );
	}

	/**
	 * The route. Public at WordPress's level: the signature is the permission (handle()).
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Answer one lookup.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function handle( $request ) {
		$refused = new WP_REST_Response( null, 401 );
		if ( ! self::enabled() ) {
			return new WP_REST_Response( null, 404 );
		}
		$body = (string) $request->get_body();
		$ask  = self::verify(
			$body,
			(string) $request->get_header( 'x_quissly_signature' ),
			(string) $request->get_header( 'x_quissly_key_id' ),
			(string) Quissly_Settings::get( 'quissly_project_id' ),
			time()
		);
		if ( null === $ask || ! self::nonce_unused( $ask['nonce'] ) ) {
			return $refused;
		}
		if ( self::rate_limited() ) {
			return new WP_REST_Response( null, 429 );
		}

		return new WP_REST_Response( self::answer( $ask['order_number'], $ask['contact'] ), 200 );
	}

	/**
	 * The signed request, or null when it is not Quissly's for this store, or too old. PURE.
	 *
	 * @param string $body       The raw body.
	 * @param string $signature  X-Quissly-Signature (base64).
	 * @param string $key_id     X-Quissly-Key-Id.
	 * @param string $project_id This store's project.
	 * @param int    $now        Unix time.
	 * @param array  $keys       Public keys by id.
	 * @return array{order_number:string,contact:string,nonce:string}|null
	 */
	public static function verify( $body, $signature, $key_id, $project_id, $now, array $keys = self::KEYS ) {
		if ( '' === $body || strlen( $body ) > self::MAX_BODY || '' === $project_id || ! isset( $keys[ $key_id ] ) || ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
			return null;
		}
		$sig = base64_decode( $signature, true );
		$key = base64_decode( (string) $keys[ $key_id ], true );
		if ( false === $sig || false === $key || 64 !== strlen( $sig ) || 32 !== strlen( $key ) ) {
			return null;
		}
		try {
			if ( ! sodium_crypto_sign_verify_detached( $sig, $body, $key ) ) {
				return null;
			}
		} catch ( Throwable $e ) {
			return null;
		}
		$ask = json_decode( $body, true );
		if ( ! is_array( $ask ) ) {
			return null;
		}
		$number  = isset( $ask['order_number'] ) && is_string( $ask['order_number'] ) ? trim( $ask['order_number'] ) : '';
		$contact = isset( $ask['contact'] ) && is_string( $ask['contact'] ) ? trim( $ask['contact'] ) : '';
		$nonce   = isset( $ask['nonce'] ) && is_string( $ask['nonce'] ) ? $ask['nonce'] : '';
		$at      = isset( $ask['timestamp'] ) && is_int( $ask['timestamp'] ) ? $ask['timestamp'] : 0;
		$ours    = isset( $ask['project_id'] ) && is_string( $ask['project_id'] ) && hash_equals( strtolower( $project_id ), strtolower( $ask['project_id'] ) );
		if ( ! $ours || abs( $now - $at ) > self::CLOCK_SKEW || ! preg_match( '/^[A-Za-z0-9-]{8,64}$/', $nonce )
			|| '' === $number || strlen( $number ) > 64 || '' === $contact || strlen( $contact ) > 254 ) {
			return null;
		}

		return array( 'order_number' => $number, 'contact' => $contact, 'nonce' => $nonce );
	}

	/**
	 * Whether two order numbers are the same: letters and digits only, any case ("#1024" = "1024"). PURE.
	 *
	 * @param string $given  What the shopper typed.
	 * @param string $number The order's number.
	 * @return bool
	 */
	public static function same_number( $given, $number ) {
		$a = strtolower( (string) preg_replace( '/[^0-9A-Za-z]/', '', (string) $given ) );

		return '' !== $a && strtolower( (string) preg_replace( '/[^0-9A-Za-z]/', '', (string) $number ) ) === $a;
	}

	/**
	 * Whether the shopper's email or phone is the order's own. PURE - the middleware's rule for Shopify:
	 * an email is equal (any case); a phone's digits (at least MIN_PHONE) are the end of the other's
	 * ("+995 555 12 34 56" = "555123456").
	 *
	 * @param string   $contact What the shopper gave.
	 * @param string[] $emails  The order's emails.
	 * @param string[] $phones  The order's phones.
	 * @return bool
	 */
	public static function contact_matches( $contact, array $emails, array $phones ) {
		$contact = strtolower( trim( (string) $contact ) );
		if ( false !== strpos( $contact, '@' ) ) {
			foreach ( $emails as $email ) {
				if ( '' !== trim( (string) $email ) && strtolower( trim( (string) $email ) ) === $contact ) {
					return true;
				}
			}

			return false;
		}
		$given = (string) preg_replace( '/\D/', '', $contact );
		if ( strlen( $given ) < self::MIN_PHONE ) {
			return false;
		}
		foreach ( $phones as $phone ) {
			$known = (string) preg_replace( '/\D/', '', (string) $phone );
			if ( strlen( $known ) >= self::MIN_PHONE && ( self::ends_with( $known, $given ) || self::ends_with( $given, $known ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * A WooCommerce order status in the engine's (Shopify's) words; null for an order a shopper never
	 * placed (a checkout draft). PURE.
	 *
	 * @param string $status   The status, without "wc-".
	 * @param bool   $paid     WC_Order::is_paid().
	 * @param float  $refunded Total refunded.
	 * @param float  $total    Order total.
	 * @return array{fulfillment_status:string,financial_status:string,cancelled:bool}|null
	 */
	public static function statuses( $status, $paid, $refunded, $total ) {
		if ( 'checkout-draft' === $status || 'trash' === $status ) {
			return null;
		}
		$fulfillment = array(
			'pending'    => 'UNFULFILLED',
			'on-hold'    => 'ON_HOLD',
			'processing' => 'IN_PROGRESS',
			'completed'  => 'FULFILLED',
			'refunded'   => 'FULFILLED',
		);
		$financial = $paid ? 'PAID' : 'PENDING';
		if ( 'refunded' === $status || ( $total > 0 && $refunded >= $total ) ) {
			$financial = 'REFUNDED';
		} elseif ( $refunded > 0 ) {
			$financial = 'PARTIALLY_REFUNDED';
		}

		return array(
			// A status a plugin added is "being handled".
			'fulfillment_status' => isset( $fulfillment[ $status ] ) ? $fulfillment[ $status ] : 'IN_PROGRESS',
			'financial_status'   => $financial,
			'cancelled'          => in_array( $status, array( 'cancelled', 'failed' ), true ),
		);
	}

	/**
	 * Tracking stored by WooCommerce Shipment Tracking / Advanced Shipment Tracking (order meta
	 * `_wc_shipment_tracking_items`). PURE.
	 *
	 * @param mixed $items The meta value.
	 * @return array<int, array{company:string,number:string,url:string}>
	 */
	public static function tracking_from( $items ) {
		$out = array();
		foreach ( is_array( $items ) ? $items : array() as $item ) {
			if ( ! is_array( $item ) || empty( $item['tracking_number'] ) ) {
				continue;
			}
			$company = ! empty( $item['custom_tracking_provider'] ) ? $item['custom_tracking_provider'] : ( isset( $item['tracking_provider'] ) ? $item['tracking_provider'] : '' );
			$url     = isset( $item['custom_tracking_link'] ) ? (string) $item['custom_tracking_link'] : '';
			$out[]   = array(
				'company' => (string) $company,
				'number'  => (string) $item['tracking_number'],
				'url'     => preg_match( '#^https?://#i', $url ) ? $url : '',
			);
		}

		return $out;
	}

	/**
	 * Whether order status in QChat is on and the store is connected.
	 *
	 * @return bool
	 */
	public static function enabled() {
		return (bool) Quissly_Settings::get( 'quissly_enable_order_status' ) && Quissly_Events::store_connected();
	}

	/**
	 * The answer for a verified ask.
	 *
	 * @param string $number  The order number given.
	 * @param string $contact The email or phone given.
	 * @return array
	 */
	private static function answer( $number, $contact ) {
		$order = self::find_order( $number );
		if ( ! $order ) {
			return array( 'status' => 'not_found' );
		}
		$emails = array( $order->get_billing_email() );
		$user   = $order->get_user();
		if ( $user ) {
			$emails[] = $user->user_email;
		}
		$phones = array( $order->get_billing_phone() );
		if ( method_exists( $order, 'get_shipping_phone' ) ) {
			$phones[] = $order->get_shipping_phone();
		}
		$statuses = self::statuses( $order->get_status(), $order->is_paid(), (float) $order->get_total_refunded(), (float) $order->get_total() );
		if ( null === $statuses || ! self::contact_matches( $contact, $emails, $phones ) ) {
			return array( 'status' => 'not_found' );
		}
		$items = array();
		foreach ( $order->get_items() as $item ) {
			if ( count( $items ) >= self::MAX_ITEMS ) {
				break;
			}
			$items[] = array( 'title' => wp_strip_all_tags( $item->get_name() ), 'quantity' => (int) $item->get_quantity() );
		}
		$created = $order->get_date_created();

		return array(
			'status' => 'found',
			'order'  => array(
				'order_number'       => (string) $order->get_order_number(),
				'created_at'         => $created ? gmdate( 'Y-m-d\TH:i:s\Z', $created->getTimestamp() ) : '',
				'cancelled'          => $statuses['cancelled'],
				'fulfillment_status' => $statuses['fulfillment_status'],
				'financial_status'   => $statuses['financial_status'],
				'tracking'           => self::tracking_from( $order->get_meta( '_wc_shipment_tracking_items' ) ),
				'items'              => $items,
				// WooCommerce's own order page for the shopper (it asks a guest for the email again).
				'status_page_url'    => (string) $order->get_checkout_order_received_url(),
			),
		);
	}

	/**
	 * The order a shopper's number names: its id, or what a sequential-numbers plugin maps it to (the
	 * filter WooCommerce's own order tracking page runs).
	 *
	 * @param string $number The number given.
	 * @return WC_Order|null
	 */
	private static function find_order( $number ) {
		$clean = (string) preg_replace( '/[^0-9A-Za-z-]/', '', $number );
		if ( '' === $clean ) {
			return null;
		}
		$id    = apply_filters( 'woocommerce_shortcode_order_tracking_order_id', $clean );
		$order = is_numeric( $id ) ? wc_get_order( (int) $id ) : false;
		if ( ! $order instanceof WC_Order || $order instanceof WC_Order_Refund || ! self::same_number( $number, $order->get_order_number() ) ) {
			return null;
		}

		return $order;
	}

	/**
	 * Claim a nonce for NONCE_TTL; false when it was used already.
	 *
	 * @param string $nonce The request's nonce.
	 * @return bool
	 */
	private static function nonce_unused( $nonce ) {
		$key = 'quissly_oln_' . md5( $nonce );
		if ( false !== get_transient( $key ) ) {
			return false;
		}
		set_transient( $key, 1, self::NONCE_TTL );

		return true;
	}

	/**
	 * The store-wide limit: RATE_MAX lookups a minute.
	 *
	 * @return bool
	 */
	private static function rate_limited() {
		$key   = 'quissly_olr_' . gmdate( 'YmdHi' );
		$count = (int) get_transient( $key );
		if ( $count >= self::RATE_MAX ) {
			return true;
		}
		set_transient( $key, $count + 1, 120 );

		return false;
	}

	/**
	 * The daily cron: tell Quissly the address again.
	 */
	public static function daily() {
		self::maybe_register( true );
	}

	/**
	 * Tell Quissly where to ask (or that it should not), when the address or the switch changed or a
	 * day passed. Never throws; a failure is logged once and tried again the next day.
	 *
	 * @param bool $force Even when nothing changed today.
	 */
	public static function maybe_register( $force = false ) {
		try {
			$key     = (string) Quissly_Settings::get( 'quissly_token' );
			$project = (string) Quissly_Settings::get( 'quissly_project_id' );
			if ( '' === $key || '' === $project ) {
				return;
			}
			$url   = self::enabled() ? rest_url( self::NAMESPACE . self::ROUTE ) : null;
			$state = (array) get_option( self::STATE_OPTION, array() );
			$same  = array_key_exists( 'url', $state ) && $state['url'] === $url;
			if ( $same && ! $force && time() - (int) ( $state['at'] ?? 0 ) < self::REGISTER_EVERY ) {
				return;
			}
			// Claimed before the network: an admin page meanwhile does not ask again.
			update_option( self::STATE_OPTION, array( 'url' => $url, 'at' => time(), 'ok' => $state['ok'] ?? false ), false );
			$response = wp_remote_request(
				rtrim( Quissly_Live_Provisioner_Client::console_url(), '/' ) . self::REGISTER_PATH,
				array(
					'method'  => 'PUT',
					'timeout' => 10,
					'headers' => array( 'X-Store-Api-Key' => $key, 'Content-Type' => 'application/json', 'Accept' => 'application/json' ),
					'body'    => wp_json_encode( array( 'project_id' => $project, 'url' => $url, 'version' => QUISSLY_VERSION ) ),
				)
			);
			$status = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
			$ok     = $status >= 200 && $status < 300;
			if ( ! $ok && ( $state['ok'] ?? true ) ) {
				Quissly_Sync_Log::log( sprintf( 'Quissly could not be told where to check order status (HTTP %d); tried again tomorrow.', $status ) );
			}
			update_option( self::STATE_OPTION, array( 'url' => $url, 'at' => time(), 'ok' => $ok ), false );
		} catch ( Throwable $e ) {
			return;
		}
	}

	/**
	 * @param string $haystack Digits.
	 * @param string $needle   Digits.
	 * @return bool
	 */
	private static function ends_with( $haystack, $needle ) {
		return '' !== $needle && substr( $haystack, -strlen( $needle ) ) === $needle;
	}
}
