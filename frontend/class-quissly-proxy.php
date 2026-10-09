<?php
/**
 * Public storefront proxy (quick / voice / image).
 *
 * @package Quissly_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Public REST endpoints that sign and forward quick, voice, and image requests to Quissly
 * (server-side), and the short-lived transient-token store for the voice/image results
 * hand-off.
 *
 * PUBLIC by design (search is public; visitors can't forge a signed Quissly call because
 * signing is server-side). Endpoints are POST, so not full-page cached. The voice/image
 * flow returns a random, short-lived, repeatably-readable plugin token (NOT a Quissly token); the
 * results page reads the stored ids from the transient via that token (see the
 * interceptor). The actual Quissly call is behind the swappable Quissly_Quick_Client seam
 * — no live call in this build.
 */
class Quissly_Proxy {

	const NAMESPACE      = 'quissly/v1';
	const TOKEN_PREFIX   = 'quissly_tok_';
	// Long enough to page, sort and come back to a photo/voice result while browsing; it
	// only holds product ids, so a longer life carries no real exposure.
	const TOKEN_TTL      = 600; // seconds.
	const QUERY_VAR_IMG  = 'quissly_img';
	const QUERY_VAR_VOICE = 'quissly_voice';

	// Per-IP throttle: a fixed window, counted in a transient. Quick's client already
	// debounces at 200ms, so a real typing burst stays well under its cap; voice/image
	// are deliberate one-shot actions, so their caps are tighter.
	const RATE_WINDOW     = 60; // seconds.
	const RATE_MAX_QUICK  = 60;
	const RATE_MAX_VOICE  = 10;
	const RATE_MAX_IMAGE  = 10;

	// Base64 body caps, sized to the documented capture limits (<=5s 16 kHz mono WAV,
	// <=1024px JPEG) plus headroom - not a precise ceiling, just enough to reject a
	// grossly oversized payload before it becomes a signed upstream call.
	// ~5s of 16-bit mono PCM at 16 kHz is ~160 KB raw, ~213 KB base64-inflated.
	const MAX_AUDIO_BASE64_BYTES = 300000;
	const MAX_IMAGE_BASE64_BYTES = 2000000;

	/**
	 * Register REST routes.
	 */
	public function register() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the quick/voice/image routes (all public).
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/quick',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_quick' ),
				'permission_callback' => array( $this, 'quick_permission' ),
				'args'                => array(
					'q' => array( 'type' => 'string', 'required' => true ),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/voice',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_voice' ),
				'permission_callback' => array( $this, 'voice_permission' ),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/image',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_image' ),
				'permission_callback' => array( $this, 'image_permission' ),
			)
		);
		// Onboarding selector-detection cache (admin only): the storefront probe posts the
		// resolved search-box selector back here during the wizard's "detect" step.
		register_rest_route(
			self::NAMESPACE,
			'/selectors',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_selectors' ),
				'permission_callback' => static function () {
					return current_user_can( 'manage_woocommerce' );
				},
			)
		);
	}

	/**
	 * Gate /quick: the feature toggle (or the dev mock seam), then the rate limit.
	 *
	 * @return true|WP_Error
	 */
	public function quick_permission() {
		return $this->permission( 'quissly_enable_quick', self::RATE_MAX_QUICK );
	}

	/**
	 * Gate /voice.
	 *
	 * @return true|WP_Error
	 */
	public function voice_permission() {
		return $this->permission( 'quissly_enable_voice', self::RATE_MAX_VOICE );
	}

	/**
	 * Gate /image.
	 *
	 * @return true|WP_Error
	 */
	public function image_permission() {
		return $this->permission( 'quissly_enable_image', self::RATE_MAX_IMAGE );
	}

	/**
	 * Shared gate: 404 when the feature is off (so an anonymous caller cannot tell a
	 * disabled feature from a route that doesn't exist), then a per-IP rate limit.
	 *
	 * The dev mock seam counts as "on" - assets load under it independent of the
	 * stored toggle (see Quissly_Plugin::register_widgets()), so the endpoint must
	 * match that or the widget it powers would 404 in mock mode.
	 *
	 * @param string $toggle_key     Settings key.
	 * @param int    $max_per_window Requests per RATE_WINDOW before a 429.
	 * @return true|WP_Error
	 */
	private function permission( $toggle_key, $max_per_window ) {
		$mock = defined( 'QUISSLY_USE_MOCK_SEARCH' ) && QUISSLY_USE_MOCK_SEARCH;
		if ( ! $mock && ! Quissly_Settings::get( $toggle_key ) ) {
			return new WP_Error( 'quissly_not_found', 'Not found.', array( 'status' => 404 ) );
		}
		if ( $this->rate_limited( $toggle_key, $max_per_window ) ) {
			return new WP_Error( 'quissly_rate_limited', 'Too many requests.', array( 'status' => 429 ) );
		}

		return true;
	}

	/**
	 * A fixed-window per-IP counter, stored in a transient. Not exact under a burst
	 * (get-then-set, no atomic increment) - deliberately simple, matching the scope of
	 * this being a throttle against casual abuse, not a hardened distributed limiter.
	 *
	 * @param string $bucket Endpoint identifier.
	 * @param int    $max    Requests allowed per RATE_WINDOW.
	 * @return bool
	 */
	private function rate_limited( $bucket, $max ) {
		$key   = 'quissly_rl_' . $bucket . '_' . md5( $this->client_ip() );
		$count = (int) get_transient( $key );
		if ( $count >= $max ) {
			return true;
		}
		set_transient( $key, $count + 1, self::RATE_WINDOW );

		return false;
	}

	/**
	 * The caller's IP. Deliberately REMOTE_ADDR only - X-Forwarded-For is
	 * client-spoofable unless a trusted proxy strips/rewrites it, and trusting it
	 * blindly would let one request claim a fresh throttle bucket per header value.
	 * A merchant behind a CDN/proxy that legitimately forwards client IPs can filter
	 * this.
	 *
	 * @return string
	 */
	private function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- used only as an opaque throttle-bucket key, never rendered or stored raw.

		return (string) apply_filters( 'quissly_client_ip', $ip );
	}

	/**
	 * Cache the resolved search-box selector(s) detected on the storefront.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function handle_selectors( $request ) {
		$desktop = trim( (string) $request->get_param( 'desktop' ) );
		$mobile  = trim( (string) $request->get_param( 'mobile' ) );

		if ( '' !== $desktop ) {
			Quissly_Settings::update( 'quissly_search_selector_desktop', $desktop );
		}
		if ( '' !== $mobile ) {
			Quissly_Settings::update( 'quissly_search_selector_mobile', $mobile );
		}

		return new WP_REST_Response( array( 'stored' => true ), 200 );
	}

	/**
	 * Quick autocomplete: return suggestion rows.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function handle_quick( $request ) {
		$query = trim( (string) $request->get_param( 'q' ) );
		if ( strlen( $query ) < 2 ) {
			return new WP_REST_Response( array( 'suggestions' => array() ), 200 );
		}

		$rows = $this->client()->suggest( $query, 10 );
		$rows = self::rows_in_language( $rows, Quissly_Languages::code_for( (string) $request->get_param( 'lang' ) ) );

		return new WP_REST_Response( array( 'suggestions' => array_values( $rows ) ), 200 );
	}

	/**
	 * Quick's rows in a language: Quissly holds the main language's products, so on another
	 * language's page each row becomes its translation - that product's name and link (its
	 * price and image are the same product's). A row without a translation stays as it is.
	 *
	 * @param array  $rows Rows from Quissly ({id, title, url, ...}).
	 * @param string $code The page's language (plugin code; '' = none given).
	 * @return array
	 */
	public static function rows_in_language( array $rows, $code ) {
		if ( '' === $code || $code === Quissly_Languages::main_code() ) {
			return $rows;
		}
		$out  = array();
		$seen = array();
		foreach ( $rows as $row ) {
			$id = Quissly_Languages::translate( (int) ( $row['id'] ?? 0 ), $code );
			if ( isset( $seen[ $id ] ) ) {
				continue;
			}
			$seen[ $id ] = true;
			$product     = $id !== (int) ( $row['id'] ?? 0 ) ? wc_get_product( $id ) : null;
			if ( $product ) {
				$row['id']    = $id;
				$row['title'] = $product->get_name();
				$row['url']   = get_permalink( $id );
			}
			$out[] = $row;
		}

		return $out;
	}

	/**
	 * Voice search: store ordered ids, return a token + transcription.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function handle_voice( $request ) {
		$audio = (string) $request->get_param( 'audio' );
		if ( strlen( $audio ) > self::MAX_AUDIO_BASE64_BYTES ) {
			return new WP_REST_Response( array( 'error' => 'payload_too_large' ), 400 );
		}
		$result = $this->client()->voice( $audio );
		$ids    = isset( $result['ids'] ) ? array_map( 'intval', (array) $result['ids'] ) : array();
		$token  = self::store_ids( $ids, isset( $result['variants'] ) ? (array) $result['variants'] : array() );

		return new WP_REST_Response(
			array(
				'token'         => $token,
				'transcription' => isset( $result['transcription'] ) ? (string) $result['transcription'] : '',
				'query_var'     => self::QUERY_VAR_VOICE,
			),
			200
		);
	}

	/**
	 * Image search: store ordered ids, return a token.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function handle_image( $request ) {
		$image = (string) $request->get_param( 'image' );
		if ( strlen( $image ) > self::MAX_IMAGE_BASE64_BYTES ) {
			return new WP_REST_Response( array( 'error' => 'payload_too_large' ), 400 );
		}
		$result = $this->client()->visual( $image );
		$token  = self::store_ids(
			isset( $result['ids'] ) ? (array) $result['ids'] : array(),
			isset( $result['variants'] ) ? (array) $result['variants'] : array()
		);

		return new WP_REST_Response(
			array(
				'token'     => $token,
				'query_var' => self::QUERY_VAR_IMG,
			),
			200
		);
	}

	/**
	 * Store an id list under a fresh token (readable repeatedly until TOKEN_TTL), with the
	 * matched-variant map so the results page can still deep-link each product to the
	 * variation the photo/voice matched (the Magento plugin's TokenStore keeps both too).
	 *
	 * @param int[]           $ids      Ordered product ids.
	 * @param array<int,int>  $variants product_id => top_variant_id.
	 * @return string Token (UUID).
	 */
	public static function store_ids( array $ids, array $variants = array() ) {
		$token = wp_generate_uuid4();
		$map   = array();
		foreach ( $variants as $product_id => $variant_id ) {
			if ( (int) $product_id > 0 && (int) $variant_id > 0 ) {
				$map[ (int) $product_id ] = (int) $variant_id;
			}
		}
		set_transient(
			self::TOKEN_PREFIX . $token,
			array(
				'ids'      => array_values( array_map( 'intval', $ids ) ),
				'variants' => $map,
			),
			self::TOKEN_TTL
		);

		return $token;
	}

	/**
	 * Read the ids for a token — repeatably, for the token's whole lifetime.
	 *
	 * This used to delete on first read, which made the results vanish the moment a
	 * shopper did anything ordinary: re-sort, page, go back, reload. A photo or a voice
	 * clip can't be re-asked from a URL, so every later load of the results page can only
	 * hand back the same token. (the Magento plugin's TokenStore made the same change for
	 * the same reason.)
	 *
	 * @param string $token Token.
	 * @return int[]|null Ordered ids, or null if unknown/expired.
	 */
	public static function read_ids( $token ) {
		$stored = self::read_token( $token );

		return null === $stored ? null : $stored['ids'];
	}

	/**
	 * Read the matched-variant map for a token (empty if unknown/expired or none matched).
	 *
	 * @param string $token Token.
	 * @return array<int,int> product_id => variation id.
	 */
	public static function read_variants( $token ) {
		$stored = self::read_token( $token );

		return null === $stored ? array() : $stored['variants'];
	}

	/**
	 * @param string $token Token.
	 * @return array{ids:int[],variants:array<int,int>}|null
	 */
	private static function read_token( $token ) {
		$token = preg_replace( '/[^0-9a-f-]/i', '', (string) $token );
		if ( '' === $token ) {
			return null;
		}
		$stored = get_transient( self::TOKEN_PREFIX . $token );
		if ( false === $stored ) {
			return null;
		}
		// A token written before variants were stored holds a bare id list (still live for
		// up to TOKEN_TTL across a plugin update).
		if ( ! isset( $stored['ids'] ) ) {
			$stored = array( 'ids' => (array) $stored, 'variants' => array() );
		}

		return array(
			'ids'      => array_map( 'intval', (array) $stored['ids'] ),
			'variants' => isset( $stored['variants'] ) ? array_map( 'intval', (array) $stored['variants'] ) : array(),
		);
	}

	/**
	 * Resolve the swappable quick client (mock in dev, live stub otherwise).
	 *
	 * @return Quissly_Quick_Client
	 */
	private function client() {
		$client = apply_filters( 'quissly_quick_client', null );
		if ( $client instanceof Quissly_Quick_Client ) {
			return $client;
		}

		return new Quissly_Live_Quick_Client();
	}
}
