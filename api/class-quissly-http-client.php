<?php
/**
 * HTTP client for the Quissly API.
 *
 * @package Quissly_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Assembles signed request headers and (in WordPress) performs the request.
 *
 * Header assembly is separated from the network call so it is unit-testable without
 * hitting the wire. The environment is read from configuration (QUISSLY_ENV), never
 * hardcoded, and sent in X-Environment for BOTH schemes.
 */
class Quissly_Http_Client {

	const BASE_URL = 'https://api.quissly.com';

	/**
	 * X-Platform header value. The single place the value lives — do NOT hardcode it elsewhere.
	 * (History: the LB temporarily required 'shopify'; it now accepts 'woocommerce', the real
	 * platform value for this plugin — verified against the live API to authenticate with HTTP 200.)
	 *
	 * @var string
	 */
	const X_PLATFORM = 'woocommerce';

	/**
	 * @var string
	 */
	private $token;

	/**
	 * @var string
	 */
	private $environment;

	/**
	 * @var Quissly_Signer
	 */
	private $signer;

	/**
	 * @param string         $token        Bearer token.
	 * @param string         $environment  QUISSLY_ENV value (e.g. dev|prod) — NOT hardcoded.
	 * @param Quissly_Signer $signer       Configured signer.
	 */
	public function __construct( $token, $environment, Quissly_Signer $signer ) {
		$this->token       = $token;
		$this->environment = $environment;
		$this->signer      = $signer;
	}

	/**
	 * Build the header set for a v2 request (qsearch/quick/qimage).
	 *
	 * The SAME $ts_ms and $nonce must appear in the headers and in the signed string,
	 * so they are passed in and reused here. No X-Service-UUID: a merchant's tenant token
	 * authenticates without it (only admin tokens need it).
	 *
	 * @param string $method HTTP method.
	 * @param string $path   Request path.
	 * @param int    $ts_ms  Timestamp in milliseconds.
	 * @param string $nonce  Request nonce (UUID).
	 * @return array<string,string>
	 */
	public function build_v2_headers( $method, $path, $ts_ms, $nonce ) {
		return array(
			'Authorization'  => 'Bearer ' . $this->token,
			'X-Environment'  => $this->environment,
			'X-Timestamp'    => (string) $ts_ms,
			'X-Nonce'        => $nonce,
			'X-Signature'    => $this->signer->sign_v2( $method, $path, $ts_ms, $nonce ),
			'X-Platform'     => self::X_PLATFORM,
		);
	}

	/**
	 * Build the header set for a v1 catalog request.
	 *
	 * v1 carries NO X-Timestamp / X-Nonce headers; the timestamp lives in the body and
	 * is part of the signed string.
	 *
	 * @param string $payload_param First product id in the batch, or operation_id.
	 * @param string $ts_iso        Python-style microsecond datetime with offset.
	 * @return array<string,string>
	 */
	public function build_v1_headers( $payload_param, $ts_iso ) {
		return array(
			'Authorization'  => 'Bearer ' . $this->token,
			'X-Environment'  => $this->environment,
			'X-Signature'    => $this->signer->sign_v1( $payload_param, $ts_iso ),
			'X-Platform'     => self::X_PLATFORM,
		);
	}

	/**
	 * Send a v2 POST (generates the timestamp + nonce once, reuses them in the signature).
	 *
	 * WordPress-coupled (wp_remote_post); exercised at the live checkpoint, not in unit
	 * tests. Returns the decoded body and HTTP status, or a WP_Error on transport failure.
	 *
	 * @param string $path    Request path, e.g. /v2beta/qsearch.
	 * @param array  $body    Request body.
	 * @param int    $timeout Timeout in seconds.
	 * @return array{code:int,body:mixed}|WP_Error
	 */
	public function post_v2( $path, array $body, $timeout = 10 ) {
		$ts_ms = Quissly_Signer::format_timestamp_ms( microtime( true ) );
		$nonce = wp_generate_uuid4();
		try {
			$headers = $this->build_v2_headers( 'POST', $path, $ts_ms, $nonce );
		} catch ( Quissly_Signer_Exception $e ) {
			return new WP_Error( 'quissly_signing_failed', $e->getMessage() );
		}
		$headers['Content-Type'] = 'application/json';

		$response = wp_remote_post(
			self::BASE_URL . $path,
			array(
				'headers' => $headers,
				'body'    => wp_json_encode( $body ),
				'timeout' => $timeout,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return array(
			'code' => (int) wp_remote_retrieve_response_code( $response ),
			'body' => json_decode( wp_remote_retrieve_body( $response ), true ),
		);
	}

	/**
	 * Send a v1 catalog request (POST add / PUT update / DELETE). The timestamp that is
	 * SIGNED must equal the timestamp carried in the body — the caller generates one
	 * $ts_iso, places it in $body under the right key, and passes it here for signing.
	 *
	 * @param string $method        HTTP method (POST|PUT|DELETE).
	 * @param string $path          Request path, e.g. /v1beta/catalog.
	 * @param array  $body          Request body (already including its timestamp field).
	 * @param string $payload_param First product id in the batch (signed param).
	 * @param string $ts_iso        Microsecond ISO timestamp used in the signature + body.
	 * @param int    $timeout       Timeout (seconds).
	 * @return array{code:int,body:mixed,raw:string}|WP_Error
	 */
	public function request_v1( $method, $path, array $body, $payload_param, $ts_iso, $timeout = 30 ) {
		try {
			$headers = $this->build_v1_headers( $payload_param, $ts_iso );
		} catch ( Quissly_Signer_Exception $e ) {
			return new WP_Error( 'quissly_signing_failed', $e->getMessage() );
		}
		$headers['Content-Type'] = 'application/json';

		$response = wp_remote_request(
			self::BASE_URL . $path,
			array(
				'method'  => $method,
				'headers' => $headers,
				'body'    => wp_json_encode( $body ),
				'timeout' => $timeout,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$raw = wp_remote_retrieve_body( $response );

		return array(
			'code' => (int) wp_remote_retrieve_response_code( $response ),
			'body' => json_decode( $raw, true ),
			'raw'  => $raw,
		);
	}

	/**
	 * GET a v1 catalog operation status. The form verified against the live API: GET /v1beta/catalog with QUERY
	 * PARAMS (operation_id, timestamp, service=search) — NOT path segments. The timestamp is
	 * a FRESH current-UTC ISO 8601 string (T separator, 6-digit microseconds, +00:00); the
	 * signed payload `{operation_id}.{timestamp}` uses that SAME fresh timestamp. (The old
	 * path-segment form 404'd.)
	 *
	 * @param string $operation_id Operation id.
	 * @param string $ts_iso       FRESH ISO 8601 timestamp (query param + signed). The caller
	 *                             must pass Quissly_Signer::format_timestamp_iso8601(...).
	 * @param int    $timeout      Timeout (seconds).
	 * @return array{code:int,body:mixed,raw:string}|WP_Error
	 */
	public function get_v1_status( $operation_id, $ts_iso, $timeout = 30 ) {
		try {
			$headers = $this->build_v1_headers( $operation_id, $ts_iso );
		} catch ( Quissly_Signer_Exception $e ) {
			return new WP_Error( 'quissly_signing_failed', $e->getMessage() );
		}
		// Build the query string explicitly (rawurlencode -> space %20, : %3A, + %2B) so the
		// timestamp is encoded once and decodes server-side to the exact SIGNED string.
		$query = 'operation_id=' . rawurlencode( $operation_id )
			. '&timestamp=' . rawurlencode( $ts_iso )
			. '&service=search';
		$url   = self::BASE_URL . '/v1beta/catalog?' . $query;

		$response = wp_remote_get(
			$url,
			array(
				'headers' => $headers,
				'timeout' => $timeout,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$raw = wp_remote_retrieve_body( $response );

		return array(
			'code' => (int) wp_remote_retrieve_response_code( $response ),
			'body' => json_decode( $raw, true ),
			'raw'  => $raw,
		);
	}
}
