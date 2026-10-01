<?php
/**
 * Live search client — the real signed qsearch call (strict 2s timeout).
 *
 * @package Quissly_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Calls POST /v2beta/qsearch via the signed HTTP client with a STRICT 2-second timeout.
 *
 * Returns null on transport error, timeout, non-200, or when credentials are not yet
 * configured — all of which mean "native fallback". (Not exercised until `.env`/key +
 * token exist; until then it safely returns null.)
 */
class Quissly_Live_Search_Client implements Quissly_Search_Client {

	const TIMEOUT_SECONDS = 2;

	/**
	 * Why the last search() returned null: not_configured, transport_error (timeouts
	 * included), http_<status> or bad_response. For the X-Quissly-Search diagnostics only.
	 *
	 * @var string
	 */
	private $last_error = '';

	/**
	 * @return string
	 */
	public function last_error() {
		return $this->last_error;
	}

	/**
	 * @param array $request Request body.
	 * @return array|null
	 */
	public function search( array $request ) {
		$store   = new Quissly_Key_Store();
		$private = $store->get_private_key();
		$token   = Quissly_Env::token();

		$this->last_error = '';
		if ( ! $private || '' === $token ) {
			$this->last_error = 'not_configured';
			return null; // Not configured -> native fallback.
		}

		$client = new Quissly_Http_Client( $token, Quissly_Env::environment(), new Quissly_Signer( $private ) );
		$result = $client->post_v2( '/v2beta/qsearch', $request, self::TIMEOUT_SECONDS );

		if ( is_wp_error( $result ) ) {
			$this->last_error = 'transport_error';
			return null;
		}
		if ( 200 !== $result['code'] || ! is_array( $result['body'] ) ) {
			$this->last_error = 200 !== $result['code'] ? 'http_' . (int) $result['code'] : 'bad_response';
			return null;
		}

		return $result['body'];
	}
}
