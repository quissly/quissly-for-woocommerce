<?php
/**
 * Live Quick/voice/image client (v2-signed).
 *
 * @package Quissly_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The real v2-signed quick (/v2beta/quick, include_metadata true) and voice (qsearch with
 * `audio`) sender. Image (qimage) is wired at Step 4 once the qimage timestamp/signing
 * contract is confirmed live — until then visual() returns empty (no call).
 *
 * Uses the same verified v2 header set as qsearch (X-Platform constant) and
 * sends the required user_id. Returns empty on not-configured / non-200 (graceful).
 */
class Quissly_Live_Quick_Client implements Quissly_Quick_Client {

	/**
	 * @param string $query Term.
	 * @param int    $limit Max rows.
	 * @return array<int,array>
	 */
	public function suggest( $query, $limit ) {
		if ( ! $this->is_configured() ) {
			return array();
		}

		// NO page_number/page_size: verified against the live API that /v2beta/quick rejects
		// both as unrecognized fields (422 "Extra inputs are not permitted") - there is
		// currently no server-side pagination/limit field for this endpoint at all (a `limit`
		// field was tried too and is equally rejected). $limit is applied client-side below
		// instead, so the method's own contract (cap the row count) still holds.
		$res = $this->http()->post_v2( '/v2beta/quick', array(
			'query'            => (string) $query,
			'user_id'          => Quissly_User_Id::resolve(),
			'include_metadata' => true,
			'channel'          => 'web',
		) );

		if ( is_wp_error( $res ) || 200 !== $res['code'] ) {
			return array();
		}

		$rows = Quissly_Response_Parser::parse_quick( $this->rows( $res['body'] ) );

		return array_slice( $rows, 0, max( 1, min( 10, (int) $limit ) ) );
	}

	/**
	 * @param string $image_base64 Image.
	 * @return array{ids:int[],variants:array<int,int>}
	 */
	public function visual( $image_base64 ) {
		if ( ! $this->is_configured() ) {
			return array( 'ids' => array(), 'variants' => array() );
		}

		// qimage uses the plain v2 header signing scheme, same as qsearch (verified against the live
		// API): NO body `timestamp` field. (History: an earlier live pass found the
		// endpoint REQUIRED a body `timestamp`; the backend has since flipped to REJECTING one
		// - 422 "Extra inputs are not permitted" - so that field is no longer sent. Re-check
		// live before assuming either shape is permanent; this endpoint has changed once already.)
		$res = $this->http()->post_v2( '/v2beta/qimage', array(
			'image'            => (string) $image_base64,
			'user_id'          => Quissly_User_Id::resolve(),
			// include_metadata FALSE: the WC post id is at top-level documents[].id again.
			'include_metadata' => false,
			'channel'          => 'web',
			'page_number'      => 1,
			'page_size'        => 24,
		) );

		// NOTE: the qimage service returned 503 during live verification (down/unprovisioned
		// for this env). This degrades gracefully to empty until the service is available.
		if ( is_wp_error( $res ) || 200 !== $res['code'] ) {
			return array( 'ids' => array(), 'variants' => array() );
		}

		// top_variant_id is returned per document here too (verified against the live API: a
		// variation's own photo came back as its parent + that variation).
		$parsed = Quissly_Response_Parser::parse_search( $res['body'] );

		return array( 'ids' => $parsed['ids'], 'variants' => $parsed['variants'] );
	}

	/**
	 * @param string $audio_base64 Audio.
	 * @return array{ids:int[],variants:array<int,int>,transcription:string}
	 */
	public function voice( $audio_base64 ) {
		if ( ! $this->is_configured() ) {
			return array( 'ids' => array(), 'variants' => array(), 'transcription' => '' );
		}

		// Voice rides qsearch with `audio` instead of `query` (same v2 scheme as text).
		$res = $this->http()->post_v2( '/v2beta/qsearch', array(
			'audio'            => (string) $audio_base64,
			'user_id'          => Quissly_User_Id::resolve(),
			// include_metadata FALSE: the WC post id is at top-level documents[].id again.
			'include_metadata' => false,
			'channel'          => 'web',
			'page_number'      => 1,
			'page_size'        => 24,
		) );

		if ( is_wp_error( $res ) || 200 !== $res['code'] ) {
			return array( 'ids' => array(), 'variants' => array(), 'transcription' => '' );
		}

		$parsed = Quissly_Response_Parser::parse_search( $res['body'] );

		return array(
			'ids'           => $parsed['ids'],
			'variants'      => $parsed['variants'], // verified against the live API, same as text qsearch.
			'transcription' => isset( $res['body']['query'] ) ? (string) $res['body']['query'] : '', // echoed transcription
		);
	}

	/**
	 * @return bool
	 */
	public function is_configured() {
		$store = new Quissly_Key_Store();

		return (bool) $store->get_private_key() && '' !== Quissly_Env::token();
	}

	/**
	 * Normalize the quick response into a list of {id, score, metadata} rows, tolerating a
	 * bare list or an object wrapper (documents/results).
	 *
	 * @param mixed $body Decoded response.
	 * @return array
	 */
	private function rows( $body ) {
		if ( ! is_array( $body ) ) {
			return array();
		}
		if ( isset( $body[0] ) ) {
			return $body; // bare list
		}
		foreach ( array( 'documents', 'results', 'suggestions' ) as $key ) {
			if ( isset( $body[ $key ] ) && is_array( $body[ $key ] ) ) {
				return $body[ $key ];
			}
		}

		return array();
	}

	/**
	 * @return Quissly_Http_Client
	 */
	private function http() {
		$store = new Quissly_Key_Store();

		return new Quissly_Http_Client(
			Quissly_Env::token(),
			Quissly_Env::environment(),
			new Quissly_Signer( $store->get_private_key() )
		);
	}
}
