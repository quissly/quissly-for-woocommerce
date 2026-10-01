<?php
/**
 * Mock Quick/voice/image client — canned suggestions for offline development.
 *
 * @package Quissly_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * DEV-ONLY stand-in for the live quick/qimage calls. Makes NO network call. Hydrates rows
 * from REAL sample-store products (so the dropdown shows real thumbnails/titles/prices),
 * and returns canned ordered ids for voice/image. Reachable only under the dev mock flag
 * (wired in the orchestrator), never in a production path.
 */
class Quissly_Mock_Quick_Client implements Quissly_Quick_Client {

	/** Canned, search-visible sample product ids (matches the mock search client pool). */
	const POOL = array( 18, 17, 16, 15, 14, 13, 12, 11, 34, 33, 32, 31, 24, 23, 22, 21, 20 );

	/**
	 * @param string $query Term.
	 * @param int    $limit Max rows.
	 * @return array<int,array>
	 */
	public function suggest( $query, $limit ) {
		$limit = max( 1, min( 10, (int) $limit ) );
		$rows  = array();

		foreach ( array_slice( self::POOL, 0, $limit ) as $id ) {
			$row = $this->hydrate( $id );
			if ( null !== $row ) {
				$rows[] = $row;
			}
		}

		return $rows;
	}

	/**
	 * @param string $image_base64 Image.
	 * @return array{ids:int[],variants:array<int,int>}
	 */
	public function visual( $image_base64 ) {
		return array( 'ids' => array_slice( self::POOL, 0, 6 ), 'variants' => array() );
	}

	/**
	 * @param string $audio_base64 Audio.
	 * @return array{ids:int[],variants:array<int,int>,transcription:string}
	 */
	public function voice( $audio_base64 ) {
		return array(
			'ids'           => array_slice( self::POOL, 0, 6 ),
			'variants'      => array(),
			'transcription' => 'red running shoes', // canned echoed transcription.
		);
	}

	/**
	 * @return bool
	 */
	public function is_configured() {
		return true;
	}

	/**
	 * Build a suggestion row from a real WooCommerce product (graceful if missing).
	 *
	 * @param int $id Product id.
	 * @return array|null
	 */
	private function hydrate( $id ) {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return array( 'id' => $id, 'title' => 'Product ' . $id, 'url' => '#', 'price' => null, 'original_price' => null, 'image_url' => null );
		}
		$product = wc_get_product( $id );
		if ( ! $product instanceof WC_Product ) {
			return null;
		}
		$image_id = $product->get_image_id();

		return array(
			'id'             => (int) $id,
			'title'          => $product->get_name(),
			'url'            => get_permalink( $id ),
			'price'          => '' !== $product->get_sale_price() ? (float) $product->get_sale_price() : (float) $product->get_price(),
			'original_price' => (float) $product->get_regular_price(),
			'image_url'      => $image_id ? (string) wp_get_attachment_image_url( $image_id, 'woocommerce_thumbnail' ) : null,
		);
	}
}
