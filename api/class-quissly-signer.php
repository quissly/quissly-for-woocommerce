<?php
/**
 * Request signer (v1 catalog + v2 search schemes).
 *
 * @package Quissly_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds RSA-SHA256 signatures for Quissly requests.
 *
 * TWO schemes that sign DIFFERENT strings with DIFFERENT timestamp formats — do not
 * conflate them:
 *  - v2 (qsearch/quick/qimage): signs "{method}\n{path}\n{ts_ms}\n{nonce}".
 *  - v1 (catalog):              signs "{payload_param}.{ts_iso}".
 */
class Quissly_Signer {

	/**
	 * PEM-encoded RSA private key.
	 *
	 * @var string
	 */
	private $private_key_pem;

	/**
	 * @param string $private_key_pem PEM-encoded RSA private key.
	 */
	public function __construct( $private_key_pem ) {
		$this->private_key_pem = $private_key_pem;
	}

	/**
	 * v2 signature (search/quick/image).
	 *
	 * @param string $method HTTP method, e.g. POST.
	 * @param string $path   Request path, e.g. /v2beta/qsearch.
	 * @param int    $ts_ms  Timestamp in integer milliseconds (same value sent in X-Timestamp).
	 * @param string $nonce  UUID (same value sent in X-Nonce).
	 * @return string Base64-encoded signature.
	 */
	public function sign_v2( $method, $path, $ts_ms, $nonce ) {
		$data = $method . "\n" . $path . "\n" . $ts_ms . "\n" . $nonce;

		return $this->sign( $data );
	}

	/**
	 * v1 signature (catalog add/update/delete/status).
	 *
	 * @param string $payload_param First product id in the batch, or the operation_id.
	 * @param string $ts_iso        Python-style microsecond datetime with offset (also in the body).
	 * @return string Base64-encoded signature.
	 */
	public function sign_v1( $payload_param, $ts_iso ) {
		$data = $payload_param . '.' . $ts_iso;

		return $this->sign( $data );
	}

	/**
	 * RSA-SHA256 sign then base64-encode.
	 *
	 * @param string $data String to sign.
	 * @return string
	 * @throws Quissly_Signer_Exception If openssl_sign() fails (e.g. a corrupt/invalid key).
	 */
	private function sign( $data ) {
		$signature = '';
		// The return value is checked immediately below and turned into a typed exception,
		// so PHP's own E_WARNING on failure ("Supplied key param cannot be coerced...") would
		// only be noise on top of that - silenced here, not swallowed.
		$signed = @openssl_sign( $data, $signature, $this->private_key_pem, OPENSSL_ALGO_SHA256 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $signed ) {
			$openssl_error = openssl_error_string();
			throw new Quissly_Signer_Exception(
				'openssl_sign() failed - the stored private key could not be used to sign a request'
				. ( $openssl_error ? ': ' . $openssl_error : '' )
			);
		}

		return base64_encode( $signature );
	}

	/**
	 * v2 timestamp formatter: integer milliseconds since epoch.
	 *
	 * @param float $unixtime Seconds since epoch (e.g. microtime( true )).
	 * @return int
	 */
	public static function format_timestamp_ms( $unixtime ) {
		return (int) round( $unixtime * 1000 );
	}

	/**
	 * v1 timestamp formatter: Python-style microsecond datetime with +00:00 offset, UTC.
	 *
	 * Example: 2025-01-09 08:44:14.318804+00:00
	 *
	 * @param float $unixtime Seconds since epoch (e.g. microtime( true )).
	 * @return string
	 */
	public static function format_timestamp_iso( $unixtime ) {
		$dt = \DateTime::createFromFormat( 'U.u', sprintf( '%.6f', $unixtime ), new \DateTimeZone( 'UTC' ) );

		return $dt->format( 'Y-m-d H:i:s.uP' );
	}

	/**
	 * ISO 8601 timestamp for the catalog STATUS request, matching Python's
	 * datetime.now(timezone.utc).isoformat(): 'T' separator, 6-digit microseconds, +00:00.
	 *
	 * This string is BOTH the `timestamp` query param AND the signed `{op}.{ts}` payload, so
	 * it must reconstruct byte-for-byte on the backend. It differs from format_timestamp_iso
	 * (the v1 body timestamp) ONLY in the date/time separator ('T' vs a space).
	 *
	 * Example: 2026-05-31T12:24:49.249639+00:00
	 *
	 * @param float $unixtime Seconds since epoch (e.g. microtime( true )).
	 * @return string
	 */
	public static function format_timestamp_iso8601( $unixtime ) {
		$dt = \DateTime::createFromFormat( 'U.u', sprintf( '%.6f', $unixtime ), new \DateTimeZone( 'UTC' ) );

		return $dt->format( 'Y-m-d\TH:i:s.uP' );
	}
}
