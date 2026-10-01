<?php
/**
 * Search-interception status (for the admin "Search status" indicator).
 *
 * @package Quissly_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Answers, unambiguously, "is Quissly search actually governing the storefront right now,
 * and if not, why?" — plus whether the store is in dev/mock mode. Mirrors the registration
 * logic in Quissly_Plugin::register_search() so the dashboard never disagrees with reality.
 *
 * compute() is PURE (unit-tested); current() gathers the live flags.
 */
class Quissly_Search_Status {

	/**
	 * Evaluate the status from explicit flags. PURE.
	 *
	 * Order mirrors register_search()'s short-circuits: mock first (it forces interception
	 * with canned results), then the gate, then the toggle, then connectivity.
	 *
	 * @param bool $mock      QUISSLY_USE_MOCK_SEARCH is on.
	 * @param bool $gate      First sync complete (interception gate open).
	 * @param bool $toggle    quissly_enable_search is on.
	 * @param bool $connected API key + bearer token present.
	 * @return array{state:string,code:string,label:string,detail:string,mock:bool}
	 */
	public static function compute( $mock, $gate, $toggle, $connected ) {
		if ( $mock ) {
			return array(
				'state'  => 'mock',
				'code'   => 'mock',
				'mock'   => true,
				'label'  => __( 'Dev / mock mode', 'quissly-for-woocommerce' ),
				'detail' => __( 'Search is intercepted but serving CANNED mock results — NOT live Quissly. (QUISSLY_USE_MOCK_SEARCH is on.) Turn the dev mock flag off for live results.', 'quissly-for-woocommerce' ),
			);
		}
		if ( ! $gate ) {
			return array(
				'state'  => 'inactive',
				'code'   => 'gate',
				'mock'   => false,
				'label'  => __( 'Inactive', 'quissly-for-woocommerce' ),
				'detail' => __( 'Waiting for the initial catalog sync to finish — native WooCommerce search runs until the first sync completes.', 'quissly-for-woocommerce' ),
			);
		}
		if ( ! $toggle ) {
			return array(
				'state'  => 'inactive',
				'code'   => 'toggle',
				'mock'   => false,
				'label'  => __( 'Inactive', 'quissly-for-woocommerce' ),
				'detail' => __( 'QSearch is turned off. Enable it under Features to let Quissly handle storefront search.', 'quissly-for-woocommerce' ),
			);
		}
		if ( ! $connected ) {
			return array(
				'state'  => 'inactive',
				'code'   => 'connection',
				'mock'   => false,
				'label'  => __( 'Inactive', 'quissly-for-woocommerce' ),
				'detail' => __( 'Not connected — add your API key and bearer token. Search falls back to native until then.', 'quissly-for-woocommerce' ),
			);
		}

		return array(
			'state'  => 'active',
			'code'   => 'active',
			'mock'   => false,
			'label'  => __( 'Active', 'quissly-for-woocommerce' ),
			'detail' => __( 'Live Quissly search is governing storefront product search results.', 'quissly-for-woocommerce' ),
		);
	}

	/**
	 * Evaluate the status from the live environment.
	 *
	 * @return array{state:string,code:string,label:string,detail:string,mock:bool}
	 */
	public static function current() {
		$store     = new Quissly_Key_Store();
		$mock      = defined( 'QUISSLY_USE_MOCK_SEARCH' ) && QUISSLY_USE_MOCK_SEARCH;
		$gate      = Quissly_Sync_State::is_initial_sync_complete();
		$toggle    = (bool) Quissly_Settings::get( 'quissly_enable_search' );
		$connected = ( $store->has_stored_key() || $store->is_dev_override_active() ) && '' !== Quissly_Env::token();

		return self::compute( $mock, $gate, $toggle, $connected );
	}
}
