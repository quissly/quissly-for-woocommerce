<?php
/**
 * Quick/voice/image suggestion seam — the swappable boundary for the storefront proxy.
 *
 * @package Quissly_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A source of Quick autocomplete suggestions and voice/image search results.
 *
 * Mirrors Quissly_Search_Client / Quissly_Catalog_Client: the proxy depends only on this
 * interface, so the live (v2-signed quick/qimage) client and the dev mock are
 * interchangeable via the `quissly_quick_client` filter, and the proxy never makes a live
 * call directly.
 */
interface Quissly_Quick_Client {

	/**
	 * Autocomplete suggestions for a typed query (quick; include_metadata true).
	 *
	 * @param string $query Search term (>= 2 chars expected).
	 * @param int    $limit Max rows (<= 10).
	 * @return array<int,array{id:int,title:?string,url:?string,price:?float,original_price:?float,image_url:?string}>
	 */
	public function suggest( $query, $limit );

	/**
	 * Image search: returns ordered product ids for a base64 image (qimage; ids only), plus
	 * the matched-variant map (product_id => top_variant_id) for the result-link pre-select.
	 *
	 * @param string $image_base64 Base64-encoded JPEG.
	 * @return array{ids:int[],variants:array<int,int>}
	 */
	public function visual( $image_base64 );

	/**
	 * Voice search: returns ordered ids, the matched-variant map and the echoed transcription
	 * for base64 audio.
	 *
	 * @param string $audio_base64 Base64-encoded 16 kHz mono WAV.
	 * @return array{ids:int[],variants:array<int,int>,transcription:string}
	 */
	public function voice( $audio_base64 );

	/**
	 * Whether credentials are configured so a real call could succeed.
	 *
	 * @return bool
	 */
	public function is_configured();
}
