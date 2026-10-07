<?php
/**
 * Main plugin orchestrator.
 *
 * @package Quissly_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loads the plugin's component classes.
 *
 * Loads every component, then wires catalog sync, search, the storefront widgets and the
 * admin (boot()).
 */
final class Quissly_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Quissly_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Retrieve the singleton instance.
	 *
	 * @return Quissly_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->load_components();
			self::$instance->boot();
		}

		return self::$instance;
	}

	/**
	 * Private constructor — use instance().
	 */
	private function __construct() {}

	/**
	 * Require the component class files.
	 *
	 * Loading only — wiring (hooks, instantiation) is added in later phases.
	 */
	private function load_components() {
		$components = array(
			'includes/class-quissly-env.php',
			'includes/class-quissly-user-id.php',
			'includes/class-quissly-connection.php',
			'includes/class-quissly-search-status.php',
			'api/class-quissly-signer-exception.php',
			'api/class-quissly-signer.php',
			'api/class-quissly-http-client.php',
			'api/class-quissly-response-parser.php',
			'api/class-quissly-key-crypto.php',
			'api/class-quissly-key-store.php',
			'api/interface-quissly-provisioner-client.php',
			'api/class-quissly-live-provisioner-client.php',
			'api/class-quissly-mock-provisioner-client.php',
			'api/class-quissly-panel-session.php',
			'api/class-quissly-store-billing.php',
			'api/interface-quissly-service-directory-client.php',
			'api/class-quissly-live-service-directory-client.php',
			'api/class-quissly-mock-service-directory-client.php',
			'cli/class-quissly-cli.php',
			'sync/class-quissly-product-mapper.php',
			'sync/class-quissly-catalog-attributes.php',
			'sync/class-quissly-dirty-queue.php',
			'sync/class-quissly-sync-state.php',
			'sync/class-quissly-sync-progress.php',
			'sync/class-quissly-sync-log.php',
			'sync/interface-quissly-catalog-client.php',
			'sync/class-quissly-live-catalog-client.php',
			'sync/class-quissly-mock-catalog-client.php',
			'sync/class-quissly-sync-worker.php',
			'sync/class-quissly-sync-hooks.php',
			'frontend/interface-quissly-search-client.php',
			'frontend/class-quissly-live-search-client.php',
			'frontend/class-quissly-mock-search-client.php',
			'frontend/class-quissly-variant-deeplink.php',
			'includes/class-quissly-search-signal.php',
			'includes/class-quissly-search-suggestions.php',
			'includes/class-quissly-showcase-queries.php',
			'includes/class-quissly-showcase-runner.php',
			'frontend/class-quissly-search-origin.php',
			'frontend/class-quissly-search-interceptor.php',
			'frontend/interface-quissly-quick-client.php',
			'frontend/class-quissly-live-quick-client.php',
			'frontend/class-quissly-mock-quick-client.php',
			'frontend/class-quissly-proxy.php',
			'frontend/class-quissly-assets.php',
			'widgets/class-quissly-qchat.php',
			'frontend/class-quissly-chat-cart.php',
			'includes/class-quissly-overlay.php',
			'admin/class-quissly-settings.php',
			'admin/class-quissly-wizard.php',
			'includes/class-quissly-description-draft.php',
			'includes/class-quissly-setup-input.php',
			'admin/class-quissly-setup.php',
			'admin/class-quissly-billing.php',
			'admin/class-quissly-admin-rest.php',
			'admin/class-quissly-admin.php',
		);

		foreach ( $components as $component ) {
			require_once QUISSLY_PLUGIN_DIR . $component;
		}
	}

	/**
	 * Wire up the parts that are functional today: dev `.env` config and the WP-CLI
	 * control plane. (Sync hooks, search interception, widgets, and admin are wired in
	 * their later phases.)
	 */
	private function boot() {
		Quissly_Env::load();
		Quissly_User_Id::register_consent();
		$this->register_sync();
		$this->register_search();
		$this->register_widgets();
		$this->register_admin();

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			$cli = new Quissly_CLI();
			WP_CLI::add_command( 'quissly generate-keys', array( $cli, 'generate_keys' ) );
			WP_CLI::add_command( 'quissly status', array( $cli, 'status' ) );
			WP_CLI::add_command( 'quissly test-connection', array( $cli, 'test_connection' ) );
			WP_CLI::add_command( 'quissly sync-all', array( $cli, 'sync_all' ) );
			WP_CLI::add_command( 'quissly mark-synced', array( $cli, 'mark_synced' ) );
		}
	}

	/**
	 * Wire up catalog sync: the Action Scheduler flush handler and the WC lifecycle hooks
	 * that enqueue dirty product ids.
	 *
	 * Enqueuing is always-on (so product changes are never lost between setup steps); the
	 * worker only sends once a catalog client is configured — until then the queue simply
	 * accumulates and the worker leaves it queued. The catalog send is behind the
	 * swappable Quissly_Catalog_Client seam and makes NO live call in this build.
	 */
	private function register_sync() {
		$worker = new Quissly_Sync_Worker();
		$worker->register();
		( new Quissly_Sync_Hooks( $worker ) )->register();
		// Search bar suggestions generated from the catalog (background, once per store).
		( new Quissly_Showcase_Runner() )->register();

		// Dev/offline: route catalog batches to the recording mock (NO live call), matching
		// the search/quick seams. Live scripts/tests remove this filter to exercise the real
		// sender (see tests/live/*). Without the flag the worker uses Quissly_Live_Catalog_Client.
		if ( defined( 'QUISSLY_USE_MOCK_SEARCH' ) && QUISSLY_USE_MOCK_SEARCH ) {
			add_filter(
				'quissly_catalog_client',
				static function () {
					return new Quissly_Mock_Catalog_Client();
				}
			);
		}
	}

	/**
	 * Wire up the storefront search widget: the public quick/voice/image proxy endpoints
	 * and (when Quick is enabled, or under the dev mock flag) the front-end assets.
	 *
	 * The proxy's Quissly call is behind the swappable Quissly_Quick_Client seam; the dev
	 * mock flag swaps in canned suggestions so the widget is testable offline, and makes
	 * NO live call.
	 */
	private function register_widgets() {
		( new Quissly_Proxy() )->register();

		// QChat footer injection (front-end). The class gates internally on the QChat toggle
		// + a configured service id, so it's always safe to hook and reacts to settings
		// changes without re-registration. Independent of the search mock flag.
		( new Quissly_QChat() )->register();

		// The chat's "Add to Cart" -> the WooCommerce cart. Enqueues only where the chat
		// renders; its wc-ajax actions must exist on every request.
		( new Quissly_Chat_Cart() )->register();

		// Search overlay footer injection. Gates internally on search-enabled + the
		// first-sync gate, so always safe to hook.
		( new Quissly_Overlay() )->register();

		$use_mock = defined( 'QUISSLY_USE_MOCK_SEARCH' ) && QUISSLY_USE_MOCK_SEARCH;
		if ( $use_mock ) {
			add_filter(
				'quissly_quick_client',
				static function () {
					return new Quissly_Mock_Quick_Client();
				}
			);
		}

		// Voice/image are separate toggles from Quick and can be turned on without it (e.g.
		// mounted standalone in the search overlay's actions slot - see assets/js/overlay.js
		// + quick.js's attachOverlayControls()) - the assets
		// must load for either of those alone too, not just when Quick itself is on.
		if ( $use_mock
			|| Quissly_Settings::get( 'quissly_enable_quick' )
			|| Quissly_Settings::get( 'quissly_enable_voice' )
			|| Quissly_Settings::get( 'quissly_enable_image' )
		) {
			( new Quissly_Assets() )->register();
		}
	}

	/**
	 * Wire up the admin surfaces: the top-level Quissly menu (dashboard, settings, sync
	 * log, setup wizard) and the WooCommerce Integrations tab. The WC integration class is
	 * loaded lazily inside the filter (only once WC_Integration is available).
	 */
	private function register_admin() {
		( new Quissly_Admin() )->register();
		( new Quissly_Admin_Rest() )->register();
		// Quissly Setup's AJAX endpoints, and its scheduled first-sync start.
		( new Quissly_Setup() )->register();
		// Billing's plan actions.
		( new Quissly_Billing() )->register();

		add_filter(
			'woocommerce_integrations',
			static function ( $integrations ) {
				if ( class_exists( 'WC_Integration' ) ) {
					require_once QUISSLY_PLUGIN_DIR . 'admin/class-quissly-wc-integration.php';
					if ( class_exists( 'Quissly_WC_Integration' ) ) {
						$integrations[] = 'Quissly_WC_Integration';
					}
				}

				return $integrations;
			}
		);
	}

	/**
	 * Wire up text search interception, behind the first-sync-complete production gate.
	 *
	 * TWO INDEPENDENT controls:
	 *  - QUISSLY_USE_MOCK_SEARCH (dev seam) selects the CLIENT: the canned mock client +
	 *    a small page size so pagination is visible offline. It does NOT bypass the gate.
	 *  - The first-sync-complete gate (Quissly_Sync_State) decides WHETHER interception
	 *    runs at all. Until the catalog has been fully synced once, interception stays
	 *    OFF — even in mock mode — so a fresh install never serves an empty Quissly index;
	 *    native WooCommerce search runs instead.
	 *
	 * In production (no mock), once the gate is satisfied the live client is used; it
	 * native-falls-back (returns null) whenever credentials are absent or a call fails, so
	 * registering the interceptor is always safe.
	 */
	private function register_search() {
		$use_mock = defined( 'QUISSLY_USE_MOCK_SEARCH' ) && QUISSLY_USE_MOCK_SEARCH;

		if ( $use_mock ) {
			add_filter(
				'quissly_search_client',
				static function () {
					return new Quissly_Mock_Search_Client();
				}
			);
			add_filter(
				'quissly_search_page_size',
				static function () {
					return 6;
				}
			);
		}

		// Which engine answered: X-Quissly-Search + the ?quissly_debug=1 badge.
		( new Quissly_Search_Origin() )->register();

		// FEATURE TOGGLE: the merchant's "QSearch" switch. The dev mock flag implies
		// the feature is on (offline testing selects the client AND wants interception).
		if ( ! $use_mock && ! Quissly_Settings::get( 'quissly_enable_search' ) ) {
			$this->record_skip_on_searches( 'search-off' );
			return;
		}

		// PRODUCTION GATE: no interception until the first full sync has completed.
		if ( ! Quissly_Sync_State::is_initial_sync_complete() ) {
			$this->record_skip_on_searches( 'gate-closed' );
			return;
		}

		( new Quissly_Search_Interceptor() )->register();
	}

	/**
	 * With the interceptor not registered, a product search still says why Quissly did not
	 * answer it (the settings reasons, in the Magento plugin's order: search-off, then
	 * gate-closed). Only the storefront's main product search is stamped.
	 *
	 * @param string $reason Reason.
	 */
	private function record_skip_on_searches( $reason ) {
		add_action(
			'pre_get_posts',
			static function ( $query ) use ( $reason ) {
				if ( ! is_admin() && $query->is_main_query() && $query->is_search()
					&& Quissly_Search_Interceptor::post_type_is_product_or_unspecified( $query->get( 'post_type' ) ) ) {
					Quissly_Search_Signal::record_skip( $reason );
				}
			},
			999
		);
	}
}
