<?php
/**
 * Admin surfaces (menu, wizard, dashboard, settings, key-gen, sync log).
 *
 * @package Quissly_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the top-level Quissly menu - Configuration, Dashboard, Quissly Admin Panel,
 * Billing, the same four entries as the Magento plugin - renders those screens, and handles
 * their form submissions (nonce + capability checked, PRG redirect). Until setup is finished
 * every one of them opens Quissly Setup instead (Quissly_Setup, the Shopify app's onboarding), and
 * the sync log lives on the Dashboard; neither has a page of its own. The WooCommerce Integrations tab is a separate class
 * (Quissly_WC_Integration) that reads/writes the SAME options via Quissly_Settings.
 *
 * Output is escaped at the point of echo; input is sanitized through Quissly_Settings.
 */
class Quissly_Admin {

	const CAP        = 'manage_woocommerce';
	const MENU_SLUG  = 'quissly';
	const PAGE_SETTINGS = 'quissly-settings';
	const PAGE_PANEL    = 'quissly-panel';
	const PAGE_BILLING  = 'quissly-billing';

	/** Sync log lines shown on the Dashboard (its only home: there is no Sync Log page). */
	const LOG_LINES = 200;

	/**
	 * Wire admin hooks.
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'register_menus' ) );
		add_action( 'admin_notices', array( $this, 'maybe_setup_notice' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_quissly_save_settings', array( $this, 'handle_save_settings' ) );
		add_action( 'admin_post_quissly_save_dashboard', array( $this, 'handle_save_dashboard' ) );
		add_action( 'admin_post_quissly_wizard', array( $this, 'handle_wizard' ) );
	}

	/**
	 * Enqueue the dashboard sync-progress script (only on the Quissly dashboard page).
	 *
	 * @param string $hook Current admin page hook suffix.
	 */
	public function enqueue_assets( $hook ) {
		if ( false !== strpos( (string) $hook, self::MENU_SLUG ) && ! Quissly_Setup::is_complete() ) {
			Quissly_Setup::enqueue();
			return;
		}
		if ( false !== strpos( (string) $hook, self::PAGE_BILLING ) ) {
			Quissly_Billing::enqueue();
			return;
		}
		if ( false !== strpos( (string) $hook, self::PAGE_SETTINGS ) ) {
			wp_enqueue_style( 'quissly-config', QUISSLY_PLUGIN_URL . 'assets/css/quissly-config.css', array(), QUISSLY_VERSION );
			wp_enqueue_script( 'quissly-config', QUISSLY_PLUGIN_URL . 'assets/js/quissly-config.js', array(), QUISSLY_VERSION, true );
			return;
		}
		if ( 'toplevel_page_' . self::MENU_SLUG !== $hook ) {
			return;
		}
		wp_enqueue_script( 'quissly-admin-sync', QUISSLY_PLUGIN_URL . 'assets/js/quissly-admin.js', array(), QUISSLY_VERSION, true );
		wp_localize_script(
			'quissly-admin-sync',
			'QuisslySync',
			array(
				'restUrl' => esc_url_raw( rest_url( Quissly_Admin_Rest::NAMESPACE . '/' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'i18n'    => array(
					'starting'   => __( 'Starting…', 'quissly-for-woocommerce' ),
					'resync'     => __( 'Re-sync catalog', 'quissly-for-woocommerce' ),
					'testing'    => __( 'Testing…', 'quissly-for-woocommerce' ),
					'syncing'    => __( 'Syncing…', 'quissly-for-woocommerce' ),
					'complete'   => __( 'Sync complete.', 'quissly-for-woocommerce' ),
					/* translators: %d: HTTP status code. */
					'refused'    => __( 'Paused: Quissly is refusing catalog updates (HTTP %d). Products stay queued and send automatically once your Quissly account is active again.', 'quissly-for-woocommerce' ),
					'blocked'    => __( 'The sync finished without sending any products to Quissly. Search stays on WooCommerce\'s own search - check the sync log below, then re-sync.', 'quissly-for-woocommerce' ),
					'idle'       => __( 'Idle.', 'quissly-for-woocommerce' ),
					'eta'        => __( 'ETA', 'quissly-for-woocommerce' ),
					'etaMinute'  => __( 'about a minute', 'quissly-for-woocommerce' ),
					'etaMinutes' => __( 'about %d minutes', 'quissly-for-woocommerce' ),
					/* translators: 1: synced count, 2: total. */
					'synced'     => __( 'Synced %1$d of %2$d', 'quissly-for-woocommerce' ),
					/* translators: %d: already-present count. */
					'already'    => __( '(%d already present)', 'quissly-for-woocommerce' ),
					/* translators: %d: failed count. */
					'failed'     => __( '%d failed', 'quissly-for-woocommerce' ),
					/* translators: %d: pending count. */
					'pending'    => __( '%d pending', 'quissly-for-woocommerce' ),
				),
			)
		);
	}

	/**
	 * The menu's icon: Quissly's own mark (the speech-bubble q, as the Shopify app's icon and the
	 * Magento plugin's menu entry) instead of a dashicon. An SVG data URI, which WordPress
	 * repaints in the admin colour scheme's icon colours, like its own icons.
	 *
	 * @return string
	 */
	public static function menu_icon() {
		$svg = (string) file_get_contents( QUISSLY_PLUGIN_DIR . 'assets/images/quissly-mark.svg' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local plugin file.
		if ( '' === $svg ) {
			return 'dashicons-search';
		}
		// WordPress's svg-painter recolours the fill it finds, so the mark needs one.
		$svg = str_replace( '<path ', '<path fill="black" ', $svg );

		return 'data:image/svg+xml;base64,' . base64_encode( $svg ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- WordPress's own format for an SVG menu icon.
	}

	/**
	 * Register the top-level menu and submenus.
	 */
	public function register_menus() {
		add_menu_page(
			__( 'Quissly', 'quissly-for-woocommerce' ),
			__( 'Quissly', 'quissly-for-woocommerce' ),
			self::CAP,
			self::MENU_SLUG,
			array( $this, 'render_dashboard' ),
			self::menu_icon(),
			58
		);
		// Magento's three entries in Magento's order: Configuration, Dashboard, Quissly Admin
		// Panel. Dashboard is REGISTERED first all the same - it has the parent's slug, and a
		// first submenu with any other slug makes WordPress add a duplicate link to the parent
		// ("Quissly") - then Configuration is moved to the top, which also makes the
		// top-level "Quissly" link open it (where setup is until it is done).
		add_submenu_page( self::MENU_SLUG, __( 'Dashboard', 'quissly-for-woocommerce' ), __( 'Dashboard', 'quissly-for-woocommerce' ), self::CAP, self::MENU_SLUG, array( $this, 'render_dashboard' ) );
		add_submenu_page( self::MENU_SLUG, __( 'Configuration', 'quissly-for-woocommerce' ), __( 'Configuration', 'quissly-for-woocommerce' ), self::CAP, self::PAGE_SETTINGS, array( $this, 'render_settings' ) );
		// Last: Quissly's own panel is not part of setting the plugin up.
		add_submenu_page( self::MENU_SLUG, __( 'Quissly Admin Panel', 'quissly-for-woocommerce' ), __( 'Quissly Admin Panel', 'quissly-for-woocommerce' ), self::CAP, self::PAGE_PANEL, array( $this, 'render_panel' ) );
		// Fourth, as in Magento: the store's plans, usage and invoices.
		add_submenu_page( self::MENU_SLUG, __( 'Billing', 'quissly-for-woocommerce' ), __( 'Billing', 'quissly-for-woocommerce' ), self::CAP, self::PAGE_BILLING, array( $this, 'render_billing' ) );

		global $submenu;
		if ( isset( $submenu[ self::MENU_SLUG ] ) ) {
			$order = array( self::PAGE_SETTINGS, self::MENU_SLUG, self::PAGE_PANEL, self::PAGE_BILLING );
			usort(
				$submenu[ self::MENU_SLUG ],
				static function ( $a, $b ) use ( $order ) {
					return (int) array_search( $a[2], $order, true ) - (int) array_search( $b[2], $order, true );
				}
			);
		}
	}

	/**
	 * Nudge to setup (at the top of Configuration) until it has been completed (no
	 * auto-redirect).
	 */
	public function maybe_setup_notice() {
		if ( ! current_user_can( self::CAP ) || Quissly_Wizard::is_complete() ) {
			return;
		}
		// Don't nag on Quissly's own pages: every one of them shows setup until it is done.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 0 === strpos( $page, self::MENU_SLUG ) ) {
			return;
		}
		printf(
			'<div class="notice notice-info"><p>%s <a href="%s" class="button button-primary">%s</a></p></div>',
			esc_html__( 'Finish setting up Quissly to start serving QSearch.', 'quissly-for-woocommerce' ),
			esc_url( admin_url( 'admin.php?page=' . self::PAGE_SETTINGS ) ),
			esc_html__( 'Run setup', 'quissly-for-woocommerce' )
		);
	}

	// ---------------------------------------------------------------------------------
	// Page renderers.
	// ---------------------------------------------------------------------------------

	/**
	 * Dashboard: connection status, sync status, feature toggles, log preview, links.
	 */
	public function render_dashboard() {
		$this->guard();
		if ( $this->render_setup_instead() ) {
			return;
		}

		$store      = new Quissly_Key_Store();
		$has_key    = $store->has_stored_key() || $store->is_dev_override_active();
		$has_token  = '' !== Quissly_Env::token();
		$connected  = $has_key && $has_token;
		$queue      = new Quissly_Dirty_Queue();
		$pending    = $queue->count_pending();
		$synced     = Quissly_Sync_State::is_initial_sync_complete();
		$log_lines  = Quissly_Sync_Log::tail( self::LOG_LINES );

		echo '<div class="wrap quissly-dashboard">';
		echo '<h1>' . esc_html__( 'Quissly', 'quissly-for-woocommerce' ) . '</h1>';
		$this->print_flash();

		$this->render_search_status();

		echo '<h2>' . esc_html__( 'Connection', 'quissly-for-woocommerce' ) . '</h2>';
		echo '<table class="widefat striped" style="max-width:640px"><tbody>';
		$this->status_row( __( 'API key', 'quissly-for-woocommerce' ), $has_key );
		$this->status_row( __( 'Bearer token', 'quissly-for-woocommerce' ), $has_token );
		$this->status_row( __( 'Connected', 'quissly-for-woocommerce' ), $connected );
		$this->status_row( __( 'Initial sync complete', 'quissly-for-woocommerce' ), $synced );
		echo '<tr><td>' . esc_html__( 'Products awaiting sync', 'quissly-for-woocommerce' ) . '</td><td>' . esc_html( (string) $pending ) . '</td></tr>';
		echo '<tr><td>' . esc_html__( 'Environment', 'quissly-for-woocommerce' ) . '</td><td>' . esc_html( Quissly_Env::environment() ) . '</td></tr>';
		echo '</tbody></table>';

		// Connection test (on-demand live probe) — wired by quissly-admin.js.
		echo '<p>';
		echo '<button type="button" id="quissly-test-connection" class="button">' . esc_html__( 'Test connection', 'quissly-for-woocommerce' ) . '</button> ';
		echo '<span id="quissly-connection-result" class="quissly-conn-result" role="status" aria-live="polite"></span>';
		echo '</p>';

		// Catalog sync + live progress.
		$this->render_sync_section( $synced );

		// Feature toggles.
		echo '<h2>' . esc_html__( 'Features', 'quissly-for-woocommerce' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="quissly_save_dashboard" />';
		wp_nonce_field( 'quissly_dashboard' );
		echo '<table class="form-table" role="presentation"><tbody>';
		$this->toggle_row( 'quissly_enable_search', __( 'QSearch', 'quissly-for-woocommerce' ) );
		$this->toggle_row( 'quissly_enable_overlay', __( 'Search overlay takes over the theme\'s search box', 'quissly-for-woocommerce' ) );
		$this->toggle_row( 'quissly_enable_quick', __( 'Quick Recommendations', 'quissly-for-woocommerce' ) );
		$this->toggle_row( 'quissly_enable_voice', __( 'Voice search', 'quissly-for-woocommerce' ) );
		$this->toggle_row( 'quissly_enable_image', __( 'Image search', 'quissly-for-woocommerce' ) );
		$this->toggle_row( 'quissly_enable_qchat', __( 'QChat assistant', 'quissly-for-woocommerce' ) );
		$this->toggle_row( 'quissly_enable_events', __( 'Shopping activity for Quissly analytics', 'quissly-for-woocommerce' ) );
		echo '</tbody></table>';
		submit_button( __( 'Save features', 'quissly-for-woocommerce' ) );
		echo '</form>';

		// The sync log, newest first - its only place in the admin.
		echo '<h2 id="quissly-sync-log">' . esc_html__( 'Sync log', 'quissly-for-woocommerce' ) . '</h2>';
		$this->print_log( array_reverse( $log_lines ) );

		echo '<p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE_PANEL ) ) . '">' . esc_html__( 'Open the Quissly Admin Panel →', 'quissly-for-woocommerce' ) . '</a></p>';
		echo '</div>';
	}

	/**
	 * The "Search status" indicator: is Quissly search ACTIVE / INACTIVE (and why), and is
	 * the store in DEV/MOCK mode. State comes from Quissly_Search_Status (mirrors the
	 * registration logic), so the dashboard never disagrees with what the storefront does.
	 */
	private function render_search_status() {
		$status = Quissly_Search_Status::current();
		$colors = array( 'active' => '#1a7f37', 'inactive' => '#b32d2e', 'mock' => '#8a6d00' );
		$icons  = array( 'active' => '●', 'inactive' => '○', 'mock' => '▲' );
		$color  = isset( $colors[ $status['state'] ] ) ? $colors[ $status['state'] ] : '#1d2327';
		$icon   = isset( $icons[ $status['state'] ] ) ? $icons[ $status['state'] ] : '';

		echo '<h2>' . esc_html__( 'Search status', 'quissly-for-woocommerce' ) . '</h2>';
		echo '<div id="quissly-search-status" data-state="' . esc_attr( $status['state'] ) . '" data-mock="' . esc_attr( $status['mock'] ? '1' : '0' ) . '" class="quissly-search-status notice inline" style="margin:0 0 12px;padding:10px 12px;border-left:4px solid ' . esc_attr( $color ) . '">';
		echo '<p style="margin:0"><strong>' . esc_html__( 'QSearch interception:', 'quissly-for-woocommerce' ) . '</strong> ';
		echo '<span class="quissly-status-label" style="color:' . esc_attr( $color ) . ';font-weight:600">' . esc_html( $icon . ' ' . $status['label'] ) . '</span></p>';
		echo '<p class="description quissly-status-detail" style="margin:4px 0 0">' . esc_html( $status['detail'] ) . '</p>';
		if ( $status['mock'] ) {
			echo '<p class="quissly-status-mock" style="margin:4px 0 0;color:' . esc_attr( $colors['mock'] ) . ';font-weight:600">' . esc_html__( '⚠ DEV/MOCK MODE — results are canned, not live.', 'quissly-for-woocommerce' ) . '</p>';
		}
		echo '</div>';
	}

	/**
	 * The Catalog sync section: a live progress bar + counts (polled from the REST endpoint
	 * by quissly-admin.js), the start/re-sync control, and HONEST timing expectations.
	 *
	 * @param bool $synced Whether the initial sync has completed at least once.
	 */
	private function render_sync_section( $synced ) {
		$progress = Quissly_Sync_Progress::get();
		$percent  = (int) $progress['percent'];
		$button   = $synced
			? __( 'Re-sync catalog', 'quissly-for-woocommerce' )
			: __( 'Start initial sync', 'quissly-for-woocommerce' );

		echo '<h2>' . esc_html__( 'Catalog sync', 'quissly-for-woocommerce' ) . '</h2>';

		// The two states where the sync cannot finish on its own, shown on load (the JS
		// status line below updates while polling, but a merchant must not have to wait
		// for it to learn why nothing is moving).
		$refusal = Quissly_Sync_State::refusal();
		if ( $refusal ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html(
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'Quissly is refusing catalog updates (HTTP %d). This usually means there is no active Quissly plan or trial, or your credentials are no longer accepted. Your products stay queued and nothing is lost - the sync resumes automatically once this is resolved.', 'quissly-for-woocommerce' ),
					(int) $refusal['code']
				)
			) . '</p></div>';
		} elseif ( Quissly_Sync_State::is_gate_blocked() ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'The last full sync finished without sending any products to Quissly, so storefront search stays on WooCommerce\'s own search. Check the sync log below for the cause, then re-sync.', 'quissly-for-woocommerce' ) . '</p></div>';
		}

		// HONEST timing — adds/updates index in ~tens of seconds; removals lag (~couple min).
		echo '<p class="description">' . esc_html__( 'Syncing sends your catalog to Quissly in the background. New and updated products usually appear in search within a minute or two; product removals can take a couple of minutes to drop out of search results. You can leave this page — the sync continues.', 'quissly-for-woocommerce' ) . '</p>';

		echo '<div id="quissly-sync-progress" class="quissly-progress" role="status" aria-live="polite">';
		echo '<p><progress id="quissly-progress-bar" max="100" value="' . esc_attr( (string) $percent ) . '" style="width:320px;max-width:100%;height:18px;vertical-align:middle"></progress> ';
		echo '<strong id="quissly-progress-percent">' . esc_html( $percent . '%' ) . '</strong></p>';
		echo '<p id="quissly-progress-status"></p>';
		echo '<p id="quissly-progress-counts" class="description"></p>';
		echo '</div>';

		echo '<p><button type="button" id="quissly-sync-start" class="button button-primary">' . esc_html( $button ) . '</button></p>';
	}

	/**
	 * Configuration: setup (until it is finished), then the settings in collapsible sections, as
	 * the Magento plugin's Configuration page has them - Features, Search bar suggestions,
	 * Catalog data, Advanced. One form and one Save button for all of them (the save handler is
	 * unchanged); a section is a <details>, so it opens and closes without script, and the page
	 * remembers which ones the merchant left open. Reads/writes the same options as the WC
	 * Integrations tab.
	 */
	public function render_settings() {
		$this->guard();
		if ( $this->render_setup_instead() ) {
			return;
		}

		echo '<div class="wrap quissly-config">';
		echo '<h1>' . esc_html__( 'Quissly Configuration', 'quissly-for-woocommerce' ) . '</h1>';
		$this->print_flash();

		if ( ! Quissly_Wizard::is_complete() ) {
			$this->render_wizard();
		}
		$this->print_section_assets();

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="quissly_save_settings" />';
		wp_nonce_field( 'quissly_settings' );

		// No credential fields here (bearer token, QChat service id): the
		// setup wizard's connect stores them, and a merchant never needs to see or type them
		// (the Magento plugin hides the same values). The save handler only writes a text
		// option that is POSTed, so leaving them off the form keeps the stored values.

		// --- Features (the Magento plugin's order) -------------------------------------------
		$this->section_open( 'features', __( 'Features', 'quissly-for-woocommerce' ), true );
		$this->toggle_row( 'quissly_enable_search', __( 'Enable QSearch', 'quissly-for-woocommerce' ), __( 'Quissly answers your store\'s searches. Search interception activates only after the first full catalog sync completes.', 'quissly-for-woocommerce' ) );
		$this->toggle_row( 'quissly_enable_overlay', __( 'Search overlay takes over the theme\'s search box', 'quissly-for-woocommerce' ), __( 'When on, clicking the theme\'s search box opens a full-width search bar at the top of a blurred page, with Quick\'s suggestions under it when Quick is on. Presentation only - results are unaffected. If your theme has no search box at all, Quissly adds a search button whatever this is set to; the mount point below says where it goes.', 'quissly-for-woocommerce' ) );
		$this->toggle_row( 'quissly_overlay_suggestions', __( 'Suggestions under the search bar', 'quissly-for-woocommerce' ), __( 'The overlay shows suggestions under the bar, as buttons a shopper can click to search, while the bar is empty. On by default.', 'quissly-for-woocommerce' ) );

		// Which ones: the Shopify app's "Search suggestions" setting (here "Search Examples"), drawn
		// as its segmented "Automatic | Manual" control (two radios; the list shows only for Manual).
		$mode = Quissly_Settings::get( 'quissly_overlay_suggestions_mode' );
		echo '<tr><th scope="row">' . esc_html__( 'Search Examples', 'quissly-for-woocommerce' ) . '</th><td>';
		echo '<div class="q-segmented" role="radiogroup" aria-label="' . esc_attr__( 'Search Examples', 'quissly-for-woocommerce' ) . '">';
		foreach ( array( 'automatic' => __( 'Automatic', 'quissly-for-woocommerce' ), 'manual' => __( 'Manual', 'quissly-for-woocommerce' ) ) as $value => $label ) {
			echo '<input type="radio" name="quissly_overlay_suggestions_mode" id="quissly_overlay_suggestions_mode_' . esc_attr( $value ) . '" value="' . esc_attr( $value ) . '"' . checked( $mode, $value, false ) . ' />';
			echo '<label for="quissly_overlay_suggestions_mode_' . esc_attr( $value ) . '">' . esc_html( $label ) . '</label>';
		}
		echo '</div>';
		echo '<p class="description">' . esc_html__( 'Buttons shown when search opens. Automatic: Quissly picks them from your catalog. Manual: you type them.', 'quissly-for-woocommerce' ) . '</p></td></tr>';
		echo '<tr id="quissly_overlay_suggestions_manual_row"' . ( 'manual' === $mode ? '' : ' hidden' ) . '><th scope="row"><label for="quissly_overlay_suggestions_manual">' . esc_html__( 'Your examples', 'quissly-for-woocommerce' ) . '</label></th><td>';
		$manual_by_language = (array) Quissly_Settings::get( 'quissly_overlay_suggestions_manual_by_language' );
		$this->language_lists(
			'quissly_overlay_suggestions_manual',
			(string) Quissly_Settings::get( 'quissly_overlay_suggestions_manual' ),
			'quissly_overlay_suggestions_manual_by_language',
			$manual_by_language,
			array(),
			array(),
			Quissly_Search_Suggestions::MAX_CHIPS,
			__( 'Add an example, e.g. black shorts size 32', 'quissly-for-woocommerce' ),
			__( 'No examples yet - with none, no buttons are shown.', 'quissly-for-woocommerce' )
		);
		echo '</td></tr>';
		$this->text_row( 'quissly_overlay_mount_selector', __( 'Search button mount point', 'quissly-for-woocommerce' ), __( 'Only used when your theme has no search box. A CSS selector for an existing header control - your cart, account or language switcher - and the search button is placed just to its left. Leave empty to auto-detect: the cart, then the account link, then the language switcher, else a floating button at the bottom-left. Example: .site-header-cart', 'quissly-for-woocommerce' ) );

		$this->toggle_row( 'quissly_enable_quick', __( 'Quick Recommendations', 'quissly-for-woocommerce' ), __( 'As a shopper types, show recommended products straight away in a dropdown under the search box, so they can jump to an item without running a full search.', 'quissly-for-woocommerce' ) );
		$layout = Quissly_Settings::get( 'quissly_quick_layout' );
		// the Magento plugin's name for the same choice ("Quick Recommendations style").
		echo '<tr><th scope="row"><label for="quissly_quick_layout">' . esc_html__( 'Quick Recommendations style', 'quissly-for-woocommerce' ) . '</label></th><td>';
		echo '<select name="quissly_quick_layout" id="quissly_quick_layout">';
		echo '<option value="vertical"' . selected( $layout, 'vertical', false ) . '>' . esc_html__( 'List - thumbnail, name and price per row', 'quissly-for-woocommerce' ) . '</option>';
		echo '<option value="horizontal"' . selected( $layout, 'horizontal', false ) . '>' . esc_html__( 'Cards - large cards with image and price, in a grid', 'quissly-for-woocommerce' ) . '</option>';
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'A compact list of names, or larger cards with images and prices. Phones always get the list.', 'quissly-for-woocommerce' ) . '</p></td></tr>';
		$this->text_row( 'quissly_see_all_label', __( '"See all results" label', 'quissly-for-woocommerce' ), __( 'The link at the foot of the Quick Recommendations dropdown.', 'quissly-for-woocommerce' ) );

		$this->toggle_row( 'quissly_enable_voice', __( 'Enable voice search', 'quissly-for-woocommerce' ), __( 'A microphone button in the search bar: the shopper says what they want.', 'quissly-for-woocommerce' ) );
		$this->toggle_row( 'quissly_enable_image', __( 'Enable image search', 'quissly-for-woocommerce' ), __( 'A camera button in the search bar: the shopper searches with a photo.', 'quissly-for-woocommerce' ) );
		$this->toggle_row( 'quissly_enable_qchat', __( 'Enable QChat', 'quissly-for-woocommerce' ), __( 'Before switching this on, set up your chat in the Quissly Admin Panel (languages, colors, greeting): the chat bubble appears on every storefront page the moment this is saved, exactly as configured there.', 'quissly-for-woocommerce' ) );
		$this->toggle_row( 'quissly_enable_events', __( 'Shopping activity for Quissly analytics', 'quissly-for-woocommerce' ), __( 'Sends product page views, searches, adds to cart and to the wishlist (YITH WooCommerce Wishlist), and paid orders to Quissly, for the analytics in the Quissly Admin Panel. Products and order totals only - never a customer\'s name, email, address or IP. A guest who has not consented to statistics cookies is not tracked. On by default.', 'quissly-for-woocommerce' ) );
		$this->section_close();

		// --- Search bar suggestions -----------------------------------------------------------
		$this->section_open( 'search-suggestions', __( 'Search bar suggestions', 'quissly-for-woocommerce' ), false, 'quissly-search-suggestions' );
		$this->render_search_suggestions();
		$this->section_close();

		// --- Catalog data --------------------------------------------------------------------
		$this->section_open( 'catalog-data', __( 'Catalog data', 'quissly-for-woocommerce' ), false, 'quissly-catalog-data' );
		$this->render_catalog_data();
		$this->section_close();

		// --- Advanced ------------------------------------------------------------------------
		$this->section_open( 'advanced', __( 'Advanced', 'quissly-for-woocommerce' ), false );
		$this->text_row( 'quissly_search_selector_desktop', __( 'Search box selector (desktop)', 'quissly-for-woocommerce' ), __( 'Only if Quissly does not find your theme\'s search box on its own: a CSS selector for it on desktop.', 'quissly-for-woocommerce' ) );
		$this->text_row( 'quissly_search_selector_mobile', __( 'Search box selector (mobile)', 'quissly-for-woocommerce' ), __( 'The same, for the mobile layout.', 'quissly-for-woocommerce' ) );
		$this->toggle_row( 'quissly_preserve_on_uninstall', __( 'Preserve configuration on uninstall', 'quissly-for-woocommerce' ), __( 'Keeps your settings and the connection to Quissly when the plugin is deleted, for a reinstall.', 'quissly-for-woocommerce' ) );
		$every = Quissly_Updater::interval();
		echo '<tr><th scope="row"><label for="quissly_update_check_every">' . esc_html__( 'Update check frequency', 'quissly-for-woocommerce' ) . '</label></th><td>';
		echo '<select name="quissly_update_check_every" id="quissly_update_check_every">';
		$labels = array(
			300   => __( 'Every 5 minutes (For testing)', 'quissly-for-woocommerce' ),
			1800  => __( 'Every 30 minutes', 'quissly-for-woocommerce' ),
			3600  => __( 'Every hour', 'quissly-for-woocommerce' ),
			21600 => __( 'Every 6 hours', 'quissly-for-woocommerce' ),
			43200 => __( 'Every 12 hours', 'quissly-for-woocommerce' ),
			86400 => __( 'Once a day', 'quissly-for-woocommerce' ),
			0     => __( 'Never', 'quissly-for-woocommerce' ),
		);
		foreach ( Quissly_Updater::FREQUENCIES as $seconds ) {
			echo '<option value="' . esc_attr( (string) $seconds ) . '"' . selected( $every, $seconds, false ) . '>' . esc_html( $labels[ $seconds ] ) . '</option>';
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'How often Quissly checks for a new version of this plugin. A new version installs itself in the background, so this is at most how long your store waits for it. Never: no automatic updates - a new version shows on the Plugins page, to install with "Update now".', 'quissly-for-woocommerce' ) . '</p></td></tr>';
		$this->section_close();

		submit_button();
		echo '</form>';
		echo '</div>';
	}

	/**
	 * Open a collapsible section (its settings table inside).
	 *
	 * @param string $key    Remembered open/closed under this key.
	 * @param string $title  Title.
	 * @param bool   $open   Open the first time the page is shown.
	 * @param string $anchor Optional id for links to it.
	 */
	private function section_open( $key, $title, $open, $anchor = '' ) {
		echo '<details class="q-section" data-q-section="' . esc_attr( $key ) . '"' . ( '' !== $anchor ? ' id="' . esc_attr( $anchor ) . '"' : '' ) . ( $open ? ' open' : '' ) . '>';
		echo '<summary><span class="q-section__title">' . esc_html( $title ) . '</span><span class="q-section__chevron" aria-hidden="true"></span></summary>';
		echo '<div class="q-section__body"><table class="form-table" role="presentation"><tbody>';
	}

	/**
	 * Close a section.
	 */
	private function section_close() {
		echo '</tbody></table></div></details>';
	}

	/**
	 * The sections' look (the Magento plugin's Configuration groups: a title bar, a round chevron
	 * on the right, a rule between them), the segmented control's, and the small script that
	 * remembers open sections and shows "Your examples" only for Manual.
	 */
	private function print_section_assets() {
		echo '<style>'
			. '.quissly-config .q-section{max-width:1100px;border-top:1px solid #c3c4c7;background:none}'
			. '.quissly-config .q-section:last-of-type{border-bottom:1px solid #c3c4c7}'
			. '.quissly-config .q-section>summary{display:flex;align-items:center;justify-content:space-between;padding:18px 4px;cursor:pointer;list-style:none}'
			. '.quissly-config .q-section>summary::-webkit-details-marker{display:none}'
			. '.quissly-config .q-section__title{font-size:20px;font-weight:600;color:#1d2327}'
			. '.quissly-config .q-section__chevron{width:28px;height:28px;border:1.5px solid #3c434a;border-radius:50%;position:relative;flex:none}'
			. '.quissly-config .q-section__chevron::after{content:"";position:absolute;left:9px;top:7px;width:7px;height:7px;border-right:1.5px solid #3c434a;border-bottom:1.5px solid #3c434a;transform:rotate(45deg)}'
			. '.quissly-config .q-section[open] .q-section__chevron::after{top:11px;transform:rotate(-135deg)}'
			. '.quissly-config .q-section>summary:hover .q-section__title{color:#2271b1}'
			. '.quissly-config .q-section__body{padding:0 4px 18px}'
			. '.quissly-config .q-section__body>.form-table{margin-top:0}'
			. '.quissly-config .q-section__body .form-table td .form-table{margin:0}'
			. '.q-segmented{display:inline-flex;gap:2px;padding:4px;border-radius:12px;background:#dcdcde}.q-segmented input{position:absolute;opacity:0;pointer-events:none}.q-segmented label{min-width:118px;padding:8px 22px;border-radius:9px;text-align:center;font-size:14px;line-height:20px;color:#303030;cursor:pointer}.q-segmented label:hover{background:rgba(0,0,0,.04)}.q-segmented input:checked+label{background:#fff;box-shadow:0 1px 2px rgba(0,0,0,.16),0 0 0 1px rgba(0,0,0,.06)}.q-segmented input:focus-visible+label{outline:2px solid #2271b1;outline-offset:1px}'
			. '</style>';
		echo '<script>document.addEventListener("DOMContentLoaded",function(){'
			. 'var key="quissly_config_sections",open={};try{open=JSON.parse(localStorage.getItem(key)||"{}")||{};}catch(e){}'
			. 'document.querySelectorAll("details[data-q-section]").forEach(function(d){var k=d.getAttribute("data-q-section");if(k in open){d.open=!!open[k];}'
			. 'if(location.hash&&d.id&&location.hash==="#"+d.id){d.open=true;}'
			. 'd.addEventListener("toggle",function(){open[k]=d.open;try{localStorage.setItem(key,JSON.stringify(open));}catch(e){}});});'
			. 'document.querySelectorAll(\'input[name="quissly_overlay_suggestions_mode"]\').forEach(function(r){r.addEventListener("change",function(){document.getElementById("quissly_overlay_suggestions_manual_row").hidden=r.value!=="manual";});});'
			. '});</script>';
	}

	/**
	 * Configuration's "Search bar suggestions" section (the Shopify app's): the queries the
	 * search overlay types into its empty bar. They live in Quissly
	 * (Quissly_Search_Suggestions), so the section shows what Quissly holds now and the save
	 * handler writes a changed list back.
	 */
	private function render_search_suggestions() {
		echo '<tr><td colspan="2" style="padding-left:0"><p class="description" style="max-width:760px;margin:0">' . esc_html__( 'Example searches the search overlay types, letter by letter, into its empty search bar, in random order, so shoppers see what they can ask. They stop the moment a shopper starts typing.', 'quissly-for-woocommerce' ) . '</p></td></tr>';
		$current = Quissly_Search_Suggestions::read();
		if ( null === $current ) {
			echo '<tr><td colspan="2" style="padding-left:0"><p><em>' . esc_html__( 'Available once the store is connected and its first catalog sync has created search in Quissly - or Quissly could not be reached just now; reload to try again.', 'quissly-for-woocommerce' ) . '</em></p></td></tr>';
			return;
		}
		echo '<tr hidden><td colspan="2"><input type="hidden" name="quissly_suggestions_present" value="1" /></td></tr>';
		// The Magento plugin's layout: a Yes/No select, then the list as pills.
		echo '<tr><th scope="row"><label for="quissly_typing_enabled">' . esc_html__( 'Show typing suggestions', 'quissly-for-woocommerce' ) . '</label></th><td>';
		echo '<select name="quissly_typing_enabled" id="quissly_typing_enabled">';
		echo '<option value="1"' . selected( $current['enabled'], true, false ) . '>' . esc_html__( 'Yes', 'quissly-for-woocommerce' ) . '</option>';
		echo '<option value="0"' . selected( $current['enabled'], false, false ) . '>' . esc_html__( 'No', 'quissly-for-woocommerce' ) . '</option>';
		echo '</select></td></tr>';
		echo '<tr><th scope="row"><label for="quissly_suggestions">' . esc_html__( 'Suggestions', 'quissly-for-woocommerce' ) . '</label></th><td>';
		// "Reset to generated" (the Shopify app's): the list Quissly built from the catalog.
		$generated = ! empty( $current['generated'] ) ? $current['generated'] : Quissly_Showcase_Runner::generated();
		$empty     = empty( $current['queries'] ) && ! Quissly_Showcase_Runner::finished() && Quissly_Showcase_Runner::enabled()
			? __( 'Left empty, suggestions are generated from your catalog after the first catalog sync - each one checked to find products.', 'quissly-for-woocommerce' )
			: __( 'No suggestions - the bar keeps its plain placeholder.', 'quissly-for-woocommerce' );
		$this->language_lists(
			'quissly_suggestions',
			implode( "\n", $current['queries'] ),
			'quissly_suggestions_lang',
			array_map(
				static function ( $list ) {
					return implode( "\n", $list );
				},
				$current['by_language']
			),
			$generated,
			$current['generated_by_language'],
			Quissly_Search_Suggestions::MAX_COUNT,
			__( 'Add a suggestion, e.g. waterproof jacket', 'quissly-for-woocommerce' ),
			$empty
		);
		echo '</td></tr>';
	}

	/**
	 * A suggestion list per language (the Magento plugin's Language select): on a multilingual
	 * store a Language select over one pill list per language, the main language's first (its
	 * field is $name; another's is $name_by_language[key]); else the one list.
	 *
	 * @param string                 $name                  The main list's field.
	 * @param string                 $value                 The main list, one per line.
	 * @param string                 $name_by_language      The other languages' field.
	 * @param array<string,string>   $by_language           Their lists, {key: lines}.
	 * @param string[]               $generated             The main language's generated list.
	 * @param array<string,string[]> $generated_by_language The others' generated lists.
	 * @param int                    $max                   At most this many each.
	 * @param string                 $placeholder           The add box's placeholder.
	 * @param string                 $empty                 Shown while a list is empty.
	 */
	private function language_lists( $name, $value, $name_by_language, array $by_language, array $generated, array $generated_by_language, $max, $placeholder, $empty ) {
		if ( ! Quissly_Languages::is_multilingual() ) {
			$this->pills_list( $name, $name, $value, $max, $generated, $placeholder, $empty );
			return;
		}
		$languages = Quissly_Languages::languages();
		$select    = $name . '_language';
		echo '<div class="q-languages" data-q-languages>';
		echo '<p class="q-languages__select"><label for="' . esc_attr( $select ) . '">' . esc_html__( 'Language', 'quissly-for-woocommerce' ) . '</label><br />';
		echo '<select id="' . esc_attr( $select ) . '" data-q-language-select>';
		foreach ( $languages as $language ) {
			/* translators: %s: a language's name. */
			$label = $language['main'] ? sprintf( __( '%s (default)', 'quissly-for-woocommerce' ), $language['name'] ) : $language['name'];
			echo '<option value="' . esc_attr( $language['key'] ) . '">' . esc_html( $label ) . '</option>';
		}
		echo '</select></p>';
		echo '<p class="description">' . esc_html__( 'Shoppers see the list for the language they browse your store in. A language with no list of its own shows none.', 'quissly-for-woocommerce' ) . '</p>';
		foreach ( $languages as $index => $language ) {
			$key = $language['key'];
			echo '<div data-q-language="' . esc_attr( $key ) . '"' . ( 0 === $index ? '' : ' hidden' ) . '>';
			if ( $language['main'] ) {
				$this->pills_list( $name, $name, $value, $max, $generated, $placeholder, $empty );
			} else {
				$this->pills_list( $name_by_language . '[' . $key . ']', $name_by_language . '_' . $key, (string) ( $by_language[ $key ] ?? '' ), $max, (array) ( $generated_by_language[ $key ] ?? array() ), $placeholder, $empty );
			}
			echo '</div>';
		}
		echo '</div>';
	}

	/**
	 * A suggestion list edited as removable pills (assets/js/quissly-config.js, the Magento
	 * plugin's editor): a textarea with one per line - what the form saves - which the script
	 * hides and draws as pills, an add row, "N of max" and, with a generated list, "Reset to
	 * generated". Without the script the textarea stays.
	 *
	 * @param string   $name        Field name.
	 * @param string   $id          Field id.
	 * @param string   $value       One per line.
	 * @param int      $max         At most this many.
	 * @param string[] $generated   The generated list ("Reset to generated"), or none.
	 * @param string   $placeholder The add box's placeholder.
	 * @param string   $empty       Shown while the list is empty.
	 */
	private function pills_list( $name, $id, $value, $max, array $generated, $placeholder, $empty ) {
		$config = array(
			'max'       => (int) $max,
			'maxLength' => Quissly_Search_Suggestions::MAX_LENGTH,
			'generated' => array_values( $generated ),
			'text'      => array(
				'placeholder' => $placeholder,
				'empty'       => $empty,
				'add'         => __( 'Add', 'quissly-for-woocommerce' ),
				'remove'      => __( 'Remove', 'quissly-for-woocommerce' ),
				'reset'       => __( 'Reset to generated', 'quissly-for-woocommerce' ),
				/* translators: 1: number in the list, 2: the most allowed - keep %1 and %2 as they are. */
				'count'       => 20 === (int) $max ? __( '%1 of %2. 10 is usually more than enough, but you can add up to %2.', 'quissly-for-woocommerce' ) : __( '%1 of %2.', 'quissly-for-woocommerce' ),
				/* translators: %1: maximum length - keep %1 as it is. */
				'tooLong'     => __( 'Keep it under %1 characters.', 'quissly-for-woocommerce' ),
				'duplicate'   => __( 'That one is already in the list.', 'quissly-for-woocommerce' ),
				/* translators: %d: maximum length. */
				'help'        => sprintf( __( 'Each under %d characters. Click × to remove one.', 'quissly-for-woocommerce' ), Quissly_Search_Suggestions::MAX_LENGTH ),
			),
		);
		echo '<textarea name="' . esc_attr( $name ) . '" id="' . esc_attr( $id ) . '" rows="5" class="large-text" style="max-width:520px" data-q-pills data-config="' . esc_attr( (string) wp_json_encode( $config ) ) . '">' . esc_textarea( $value ) . '</textarea>';
	}

	/**
	 * Configuration's "Catalog data" section (the Magento plugin's): the attributes sent to
	 * Quissly, pre-ticked with what the product pages show. Part of the settings form; the
	 * save handler turns the ticks into Quissly_Catalog_Attributes choices.
	 */
	private function render_catalog_data() {
		$catalog = Quissly_Catalog_Attributes::catalog();
		$choices = Quissly_Catalog_Attributes::choices();

		echo '<tr><th scope="row">' . esc_html__( 'Product attributes sent to Quissly', 'quissly-for-woocommerce' ) . '</th><td>';
		if ( empty( $catalog ) ) {
			echo '<p>' . esc_html__( 'Your products have no attributes yet.', 'quissly-for-woocommerce' ) . '</p></td></tr>';
			return;
		}
		echo '<fieldset style="columns:2 260px;max-width:760px">';
		foreach ( $catalog as $label => $counts ) {
			$checked = Quissly_Catalog_Attributes::included( $label, $counts['shown'] > 0, $choices );
			$notes   = array();
			if ( $counts['shown'] > 0 && $counts['hidden'] > 0 ) {
				/* translators: %d: number of products. */
				$notes[] = sprintf( _n( 'hidden on %d product', 'hidden on %d products', $counts['hidden'], 'quissly-for-woocommerce' ), $counts['hidden'] );
			} elseif ( 0 === $counts['shown'] ) {
				$notes[] = __( 'hidden on product pages', 'quissly-for-woocommerce' );
			}
			if ( $counts['variation'] > 0 ) {
				/* translators: %d: number of products. */
				$notes[] = sprintf( _n( 'a variation option on %d product - always sent there', 'a variation option on %d products - always sent there', $counts['variation'], 'quissly-for-woocommerce' ), $counts['variation'] );
			}
			echo '<input type="hidden" name="quissly_catalog_offered[]" value="' . esc_attr( $label ) . '" />';
			echo '<label style="display:block;break-inside:avoid;margin:0 0 6px"><input type="checkbox" name="quissly_catalog_attributes[]" value="' . esc_attr( $label ) . '"' . checked( $checked, true, false ) . ' /> ' . esc_html( $label );
			if ( $notes ) {
				echo ' <span class="description">(' . esc_html( implode( '; ', $notes ) ) . ')</span>';
			}
			echo '</label>';
		}
		echo '</fieldset>';
		echo '<p class="description" style="max-width:760px">' . esc_html__( 'Each ticked attribute travels with every product as metadata, which Quissly searches, so "linen", "merino" or a brand name match even when the description never says so. Pre-ticked: the attributes your product pages show ("Visible on the product page"). Variation options (such as color and size on products with variants) always go - they tell the variants apart.', 'quissly-for-woocommerce' ) . ' <strong>' . esc_html__( 'Saving a changed list re-sends your whole catalog', 'quissly-for-woocommerce' ) . '</strong> ' . esc_html__( 'so existing products pick it up - allow the sync a few minutes on a large store.', 'quissly-for-woocommerce' ) . '</p>';
		echo '</td></tr>';
	}

	/**
	 * The Quissly Admin Panel, embedded and already signed in (port of the Magento plugin's
	 * Panel block + panel.phtml).
	 *
	 * The store's API key is exchanged server-side (Quissly_Panel_Session) for short-lived
	 * session tokens, which ride in the iframe URL - so the key never reaches the browser, and
	 * the merchant never signs in a second time. One exchange per render. When the panel can't
	 * be embedded, the page says why in the merchant's terms, and offers the panel in a new tab
	 * only where that can help.
	 */
	public function render_panel() {
		$this->guard();
		if ( $this->render_setup_instead() ) {
			return;
		}

		echo '<div class="wrap quissly-panel">';
		echo '<h1>' . esc_html__( 'Quissly Admin Panel', 'quissly-for-woocommerce' ) . '</h1>';

		$panel_url = Quissly_Panel_Session::panel_url();

		if ( '' === Quissly_Env::token() ) {
			echo '<div class="notice notice-warning inline quissly-panel__notice"><p>';
			echo esc_html__( 'This store is not connected to Quissly yet. Your panel will load here automatically once it is.', 'quissly-for-woocommerce' ) . ' ';
			echo '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE_SETTINGS ) ) . '">' . esc_html__( 'Run setup to connect', 'quissly-for-woocommerce' ) . '</a>';
			echo '</p></div></div>';
			return;
		}

		if ( '' === (string) Quissly_Settings::get( 'quissly_project_id' ) || '' === (string) Quissly_Settings::get( 'quissly_account_email' ) ) {
			// Connected by pasting a token: there is no project id / account email to sign in
			// with, so the panel can't be opened for the merchant.
			$this->panel_unavailable(
				__( 'This store was connected with a pasted token, so the panel can\'t sign you in automatically. Open it in a new tab and sign in with your Quissly account.', 'quissly-for-woocommerce' ),
				$panel_url
			);
			return;
		}

		$session = Quissly_Panel_Session::open_for_store();
		if ( $session['ok'] ) {
			// referrerpolicy: the session tokens are in this URL; without it the panel's own
			// outbound requests would carry them to third parties in a Referer header.
			echo '<iframe class="quissly-panel__frame" src="' . esc_url( Quissly_Panel_Session::embed_url( $panel_url, $session['access_token'], $session['refresh_token'] ) ) . '"';
			echo ' referrerpolicy="no-referrer" title="' . esc_attr__( 'Quissly panel', 'quissly-for-woocommerce' ) . '"';
			echo ' style="display:block;width:100%;height:calc(100vh - 150px);min-height:600px;border:1px solid #c3c4c7;border-radius:4px;background:#fff;margin-top:12px"></iframe>';
			echo '</div>';
			return;
		}

		$console = Quissly_Live_Provisioner_Client::console_url();
		switch ( $session['error'] ) {
			case 'rejected':
				// Name the host: the commonest cause is credentials valid for another Quissly
				// environment (as the Magento plugin does).
				/* translators: %s: the Quissly console URL that refused the sign-in. */
				$reason = sprintf( __( 'Quissly refused the sign-in at %s. Check that this store\'s account email and project id belong to that Quissly environment - the account Quissly created for this store, not a personal login.', 'quissly-for-woocommerce' ), $console );
				break;
			case 'transport_error':
				/* translators: %s: the Quissly console URL. */
				$reason = sprintf( __( 'Could not reach Quissly at %s. Check the connection and try again.', 'quissly-for-woocommerce' ), $console );
				break;
			default:
				$reason = __( 'The panel is unavailable right now. Try again in a moment.', 'quissly-for-woocommerce' );
		}
		$this->panel_unavailable( $reason, $panel_url );
	}

	/**
	 * The panel page's "can't embed" state: the reason, and the panel in a new tab.
	 *
	 * @param string $reason    Merchant-facing reason.
	 * @param string $panel_url Panel base URL.
	 */
	private function panel_unavailable( $reason, $panel_url ) {
		echo '<div class="notice notice-warning inline quissly-panel__notice"><p>' . esc_html( $reason ) . '</p></div>';
		echo '<p><a class="button" href="' . esc_url( $panel_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Open the Quissly panel in a new tab', 'quissly-for-woocommerce' ) . '</a></p>';
		echo '</div>';
	}

	/**
	 * Quissly Billing: plans, usage and invoices (Quissly_Billing). Setup until it is finished.
	 */
	public function render_billing() {
		$this->guard();
		if ( $this->render_setup_instead() ) {
			return;
		}
		echo '<div class="wrap quissly-billing-wrap">';
		echo '<h1>' . esc_html__( 'Quissly Billing', 'quissly-for-woocommerce' ) . '</h1>';
		( new Quissly_Billing() )->render();
		echo '</div>';
	}

	/**
	 * Quissly Setup in place of the page asked for, until setup is finished - the menu's
	 * three entries all lead to it. `?quissly_manual` on Configuration is the one way past
	 * it: the paste-a-token fallback (render_wizard()), for an account Quissly made by hand.
	 *
	 * @return bool Whether Setup was rendered.
	 */
	private function render_setup_instead() {
		if ( Quissly_Setup::is_complete() || ! empty( $_GET['quissly_manual'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display toggle.
			return false;
		}
		echo '<div class="wrap quissly-setup-wrap">';
		echo '<h1>' . esc_html__( 'Quissly Setup', 'quissly-for-woocommerce' ) . '</h1>';
		$this->print_flash();
		( new Quissly_Setup() )->render();
		echo '</div>';

		return true;
	}

	/**
	 * The paste-a-token fallback at the top of Configuration (the current wizard step), shown
	 * until setup is finished - reached with `?quissly_manual` from Quissly Setup.
	 */
	public function render_wizard() {
		$this->guard();

		$step = Quissly_Wizard::current_step();

		echo '<div class="quissly-wizard">';
		echo '<h2>' . esc_html__( 'Setup', 'quissly-for-woocommerce' ) . '</h2>';
		$this->wizard_steplist( $step, false );
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="quissly_wizard" />';
		echo '<input type="hidden" name="step" value="' . esc_attr( (string) $step ) . '" />';
		wp_nonce_field( 'quissly_wizard' );
		$this->wizard_step_body( $step );
		echo '</form></div>';
		echo '<hr />';
	}

	// ---------------------------------------------------------------------------------
	// POST handlers (PRG: validate, mutate, redirect).
	// ---------------------------------------------------------------------------------

	/**
	 * Save the full settings form.
	 */
	public function handle_save_settings() {
		$this->verify( 'quissly_settings' );

		foreach ( Quissly_Settings::schema() as $key => $def ) {
			if ( 'bool' === $def['type'] ) {
				Quissly_Settings::update( $key, isset( $_POST[ $key ] ) ? '1' : '0' );
			} elseif ( isset( $_POST[ $key ] ) ) {
				Quissly_Settings::update( $key, wp_unslash( $_POST[ $key ] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized inside Settings::update.
			}
		}

		$status = 'saved';

		// Search bar suggestions: only when the section was on the form (Quissly readable).
		if ( isset( $_POST['quissly_suggestions_present'] ) ) {
			$text  = isset( $_POST['quissly_suggestions'] ) ? sanitize_textarea_field( wp_unslash( $_POST['quissly_suggestions'] ) ) : '';
			// A multilingual store's other languages' lists ({key: lines}; absent otherwise).
			$other = null;
			if ( isset( $_POST['quissly_suggestions_lang'] ) && is_array( $_POST['quissly_suggestions_lang'] ) ) {
				$other = array();
				foreach ( wp_unslash( $_POST['quissly_suggestions_lang'] ) as $language => $lines ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each key and list is sanitized below.
					$other[ sanitize_key( (string) $language ) ] = sanitize_textarea_field( is_string( $lines ) ? $lines : '' );
				}
			}
			$error = $this->save_search_suggestions( isset( $_POST['quissly_typing_enabled'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['quissly_typing_enabled'] ) ), $text, $other );
			if ( '' !== $error ) {
				set_transient( 'quissly_flash_error_' . get_current_user_id(), $error, MINUTE_IN_SECONDS );
				$status = 'error';
			}
		}

		// Catalog data: only when the form listed attributes (the WC Integrations tab doesn't).
		if ( isset( $_POST['quissly_catalog_offered'] ) ) {
			$offered = array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['quissly_catalog_offered'] ) );
			$ticked  = isset( $_POST['quissly_catalog_attributes'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['quissly_catalog_attributes'] ) ) : array();
			if ( Quissly_Catalog_Attributes::save( $offered, $ticked ) && Quissly_Wizard::is_complete() ) {
				// Existing products carry the old list until they are sent again (Magento's
				// CatalogAttributes backend model does the same).
				( new Quissly_Sync_Worker() )->start_full_sync( true );
				if ( 'saved' === $status ) {
					$status = 'saved_resync';
				}
			}
		}

		$this->redirect_back( self::PAGE_SETTINGS, $status );
	}

	/**
	 * Write the search bar suggestions to Quissly when they changed. '' when saved (or
	 * nothing changed), else the reason.
	 *
	 * @param bool                      $enabled Show typing suggestions.
	 * @param string                    $text    One suggestion per line (the main language's).
	 * @param array<string,string>|null $other   A multilingual store's other languages' lists,
	 *                                           {key: lines}; null = none on the form.
	 * @return string
	 */
	private function save_search_suggestions( $enabled, $text, $other = null ) {
		$queries = Quissly_Search_Suggestions::clean( preg_split( '/\r\n|\r|\n/', (string) $text ) );
		$error   = Quissly_Search_Suggestions::validate( $queries );
		if ( '' !== $error ) {
			return $error;
		}
		$by_language = null;
		if ( is_array( $other ) ) {
			$by_language = array();
			foreach ( $other as $language => $lines ) {
				$list  = Quissly_Search_Suggestions::clean( preg_split( '/\r\n|\r|\n/', (string) $lines ) );
				$error = Quissly_Search_Suggestions::validate( $list );
				if ( '' !== $error ) {
					return $error;
				}
				if ( '' !== $language && ! empty( $list ) ) {
					$by_language[ $language ] = $list;
				}
			}
		}
		$current = Quissly_Search_Suggestions::read();
		if ( null !== $current && $current['enabled'] === (bool) $enabled && $current['queries'] === $queries
			&& ( null === $by_language || $current['by_language'] === $by_language ) ) {
			return ''; // unchanged: no write.
		}
		// The merchant's own lists from now on: Quissly's generator keeps any list that differs
		// from what it generated, so nothing is recorded here.
		return Quissly_Search_Suggestions::save( $enabled, $queries, $by_language );
	}

	/**
	 * Save the dashboard feature toggles (only the seven enable flags).
	 */
	public function handle_save_dashboard() {
		$this->verify( 'quissly_dashboard' );

		foreach ( array( 'quissly_enable_search', 'quissly_enable_overlay', 'quissly_enable_quick', 'quissly_enable_voice', 'quissly_enable_image', 'quissly_enable_qchat', 'quissly_enable_events' ) as $key ) {
			Quissly_Settings::update( $key, isset( $_POST[ $key ] ) ? '1' : '0' );
		}

		$this->redirect_back( self::MENU_SLUG, 'saved' );
	}

	/**
	 * Process a wizard step.
	 */
	public function handle_wizard() {
		$this->verify( 'quissly_wizard' );

		$step  = isset( $_POST['step'] ) ? (int) $_POST['step'] : Quissly_Wizard::current_step();
		$input = array(
			'token'    => isset( $_POST['token'] ) ? wp_unslash( $_POST['token'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized inside Settings::update.
			'mode'     => isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : 'qsearch',
			'connect'  => isset( $_POST['connect'] ),
			'email'    => isset( $_POST['email'] ) ? wp_unslash( $_POST['email'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated with is_email() inside connect_automatically().
		);

		Quissly_Wizard::process_step( $step, $input );

		$error = Quissly_Wizard::last_connect_error();
		if ( '' !== $error ) {
			// PRG: the error can't ride the redirect URL (arbitrary length/content from
			// the API's own wording), so it rides a short-lived per-user transient instead
			// — read once and deleted by print_flash().
			set_transient( 'quissly_connect_error_' . get_current_user_id(), $error, MINUTE_IN_SECONDS );
			$this->redirect_back( self::PAGE_SETTINGS, 'connect_failed' );
		}

		$this->redirect_back( self::PAGE_SETTINGS, Quissly_Wizard::is_complete() ? 'setup_done' : 'step' );
	}

	// ---------------------------------------------------------------------------------
	// Render helpers.
	// ---------------------------------------------------------------------------------

	/**
	 * Capability guard for page renderers.
	 */
	private function guard() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'quissly-for-woocommerce' ) );
		}
	}

	/**
	 * Verify nonce + capability for a POST handler.
	 *
	 * @param string $nonce_action Nonce action.
	 */
	private function verify( $nonce_action ) {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'quissly-for-woocommerce' ) );
		}
		check_admin_referer( $nonce_action );
	}

	/**
	 * Redirect back to a Quissly admin page with a status flag.
	 *
	 * @param string $page   Page slug.
	 * @param string $status Status value.
	 */
	private function redirect_back( $page, $status ) {
		wp_safe_redirect( admin_url( 'admin.php?page=' . $page . '&quissly_status=' . rawurlencode( $status ) ) );
		exit;
	}

	/**
	 * Show a success flash if redirected with a status flag.
	 */
	private function print_flash() {
		$status = isset( $_GET['quissly_status'] ) ? sanitize_key( wp_unslash( $_GET['quissly_status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' === $status ) {
			return;
		}

		if ( 'error' === $status ) {
			$key     = 'quissly_flash_error_' . get_current_user_id();
			$message = get_transient( $key );
			delete_transient( $key ); // single-use.
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $message ? $message : __( 'Something went wrong. Please try again.', 'quissly-for-woocommerce' ) ) . '</p></div>';
			return;
		}

		if ( 'connect_failed' === $status ) {
			$key     = 'quissly_connect_error_' . get_current_user_id();
			$message = get_transient( $key );
			delete_transient( $key ); // single-use.
			if ( ! $message ) {
				$message = __( 'Could not connect to Quissly.', 'quissly-for-woocommerce' );
			}
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
			return;
		}

		$messages = array(
			'saved'      => __( 'Settings saved.', 'quissly-for-woocommerce' ),
			'saved_resync' => __( 'Settings saved. The attributes sent to Quissly changed, so your whole catalog is being re-sent - see the Dashboard for progress.', 'quissly-for-woocommerce' ),
			'step'       => __( 'Step saved.', 'quissly-for-woocommerce' ),
			'setup_done' => __( 'Setup is complete. Quissly is connected and your catalog has been queued for sync.', 'quissly-for-woocommerce' ),
			'live'       => __( 'Quissly search is live on your store.', 'quissly-for-woocommerce' ),
			'saved_setup' => __( 'Setup saved. QSearch stays off until you switch it on in Configuration, once your catalog has synced.', 'quissly-for-woocommerce' ),
		);
		$message = isset( $messages[ $status ] ) ? $messages[ $status ] : __( 'Done.', 'quissly-for-woocommerce' );
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
	}

	/**
	 * A yes/no status table row.
	 *
	 * @param string $label Label.
	 * @param bool   $ok    State.
	 */
	private function status_row( $label, $ok ) {
		echo '<tr><td>' . esc_html( $label ) . '</td><td>';
		echo $ok
			? '<span style="color:#1a7f37">✔ ' . esc_html__( 'Yes', 'quissly-for-woocommerce' ) . '</span>'
			: '<span style="color:#b32d2e">✘ ' . esc_html__( 'No', 'quissly-for-woocommerce' ) . '</span>';
		echo '</td></tr>';
	}

	/**
	 * A checkbox form row bound to a bool option.
	 *
	 * @param string $key   Option key.
	 * @param string $label Label.
	 * @param string $help  Optional description.
	 */
	private function toggle_row( $key, $label, $help = '' ) {
		$checked = Quissly_Settings::get( $key ) ? ' checked' : '';
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>';
		echo '<label><input type="checkbox" name="' . esc_attr( $key ) . '" value="1"' . $checked . ' /> ' . esc_html__( 'Enabled', 'quissly-for-woocommerce' ) . '</label>';
		if ( '' !== $help ) {
			echo '<p class="description">' . esc_html( $help ) . '</p>';
		}
		echo '</td></tr>';
	}

	/**
	 * A text input form row bound to an option.
	 *
	 * @param string $key   Option key.
	 * @param string $label Label.
	 * @param string $help  Optional description.
	 * @param string $type  Input type - 'password' masks the value on screen (the field
	 *                      is still pre-filled and still submits in the clear, same as
	 *                      any HTML form; this is on-screen masking against shoulder-
	 *                      surfing / a screen-share, not transport security).
	 */
	private function text_row( $key, $label, $help = '', $type = 'text' ) {
		$value = (string) Quissly_Settings::get( $key );
		echo '<tr><th scope="row"><label for="' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td>';
		echo '<input type="' . esc_attr( $type ) . '" class="regular-text" id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '" />';
		if ( '' !== $help ) {
			echo '<p class="description">' . esc_html( $help ) . '</p>';
		}
		echo '</td></tr>';
	}

	/**
	 * Render the log lines (or an empty-state message).
	 *
	 * @param string[] $lines Log lines.
	 */
	private function print_log( array $lines ) {
		if ( empty( $lines ) ) {
			echo '<p>' . esc_html__( 'No sync activity logged yet.', 'quissly-for-woocommerce' ) . '</p>';
			return;
		}
		echo '<textarea readonly rows="12" style="width:100%;font-family:monospace" class="quissly-log">';
		echo esc_textarea( implode( "\n", $lines ) );
		echo '</textarea>';
	}

	/**
	 * The wizard step breadcrumb.
	 *
	 * @param int  $current Current step.
	 * @param bool $done    Whether complete.
	 */
	private function wizard_steplist( $current, $done ) {
		$labels = array(
			Quissly_Wizard::STEP_TOKEN  => __( 'Connect account', 'quissly-for-woocommerce' ),
			Quissly_Wizard::STEP_TEST   => __( 'Test connection', 'quissly-for-woocommerce' ),
			Quissly_Wizard::STEP_MODE   => __( 'Choose mode', 'quissly-for-woocommerce' ),
			Quissly_Wizard::STEP_DETECT => __( 'Detect search box', 'quissly-for-woocommerce' ),
			Quissly_Wizard::STEP_SYNC   => __( 'Initial sync', 'quissly-for-woocommerce' ),
		);
		echo '<ol class="quissly-steps">';
		foreach ( $labels as $num => $label ) {
			$state = ( $done || $num < $current ) ? '✓ ' : ( $num === $current ? '➤ ' : '' );
			echo '<li' . ( $num === $current && ! $done ? ' style="font-weight:bold"' : '' ) . '>' . esc_html( $state . $label ) . '</li>';
		}
		echo '</ol>';
	}

	/**
	 * Render the body for the current wizard step.
	 *
	 * @param int $step Step.
	 */
	private function wizard_step_body( $step ) {
		switch ( $step ) {
			case Quissly_Wizard::STEP_TOKEN:
				$manual = ! empty( $_GET['quissly_manual'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display toggle, no state change.
				if ( $manual ) {
					// A hand-issued token is minted for this store's public key, so show it here
					// (creating the keys if none exist yet - never replacing existing ones).
					$store  = new Quissly_Key_Store();
					$public = $store->get_public_key();
					if ( ! $public ) {
						$public = $store->generate_and_store();
					}
					echo '<p>' . esc_html__( 'Paste the bearer token Quissly minted for your public key.', 'quissly-for-woocommerce' ) . '</p>';
					echo '<details><summary>' . esc_html__( 'Show public key', 'quissly-for-woocommerce' ) . '</summary>';
					echo '<textarea readonly rows="6" style="width:100%;font-family:monospace">' . esc_textarea( (string) $public ) . '</textarea>';
					echo '</details>';
					echo '<input type="password" class="regular-text" name="token" value="' . esc_attr( (string) Quissly_Settings::get( 'quissly_token' ) ) . '" />';
					echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Save token & continue', 'quissly-for-woocommerce' ) . '</button></p>';
					echo '<p><a href="' . esc_url( remove_query_arg( 'quissly_manual' ) ) . '">' . esc_html__( '← Back to automatic connect', 'quissly-for-woocommerce' ) . '</a></p>';
				} else {
					$current_user = wp_get_current_user();
					echo '<p>' . esc_html__( 'Connect your Quissly account automatically — no copying or pasting required. This creates a new Quissly account for this store.', 'quissly-for-woocommerce' ) . '</p>';
					echo '<label for="quissly-connect-email">' . esc_html__( 'Email address', 'quissly-for-woocommerce' ) . '</label><br />';
					echo '<input type="email" id="quissly-connect-email" class="regular-text" name="email" value="' . esc_attr( $current_user->user_email ) . '" required="required" />';
					echo '<p><button type="submit" name="connect" value="1" class="button button-primary">' . esc_html__( 'Connect to Quissly', 'quissly-for-woocommerce' ) . '</button></p>';
					echo '<p><a href="' . esc_url( add_query_arg( 'quissly_manual', '1' ) ) . '">' . esc_html__( 'Already have a token? Paste it manually.', 'quissly-for-woocommerce' ) . '</a></p>';
				}
				break;

			case Quissly_Wizard::STEP_TEST:
				$result = $this->connection_probe();
				echo '<p>' . esc_html( $result ) . '</p>';
				echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Continue', 'quissly-for-woocommerce' ) . '</button></p>';
				break;

			case Quissly_Wizard::STEP_MODE:
				echo '<p>' . esc_html__( 'Which Quissly features do you want to enable?', 'quissly-for-woocommerce' ) . '</p>';
				foreach ( array(
					'qsearch' => __( 'QSearch (replace WooCommerce search + autocomplete)', 'quissly-for-woocommerce' ),
					'qchat'   => __( 'QChat assistant only', 'quissly-for-woocommerce' ),
					'both'    => __( 'Both search and QChat', 'quissly-for-woocommerce' ),
				) as $value => $label ) {
					echo '<p><label><input type="radio" name="mode" value="' . esc_attr( $value ) . '"' . checked( 'qsearch', $value, false ) . ' /> ' . esc_html( $label ) . '</label></p>';
				}
				echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Continue', 'quissly-for-woocommerce' ) . '</button></p>';
				break;

			case Quissly_Wizard::STEP_DETECT:
				$detect_url = add_query_arg(
					array( 's' => 'test', 'post_type' => 'product', 'quissly_detect' => '1' ),
					home_url( '/' )
				);
				echo '<p>' . esc_html__( 'Quissly auto-detects your theme\'s search box and attaches the autocomplete + voice/image controls at runtime. To cache the selector now, open your search-results page (some block themes, like the default, only show the search box there):', 'quissly-for-woocommerce' ) . '</p>';
				echo '<p><a class="button" href="' . esc_url( $detect_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Open storefront & detect', 'quissly-for-woocommerce' ) . '</a></p>';
				echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Continue', 'quissly-for-woocommerce' ) . '</button></p>';
				break;

			case Quissly_Wizard::STEP_SYNC:
				$count = function_exists( 'wp_count_posts' ) ? (int) wp_count_posts( 'product' )->publish : 0;
				echo '<p>' . sprintf(
					/* translators: 1: product count, 2: ETA. */
					esc_html__( 'Ready to sync %1$d products to Quissly (estimated %2$s). The sync runs in the background.', 'quissly-for-woocommerce' ),
					(int) $count,
					esc_html( Quissly_Wizard::sync_eta( $count ) )
				) . '</p>';
				echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Start initial sync & finish', 'quissly-for-woocommerce' ) . '</button></p>';
				break;
		}
	}

	/**
	 * A REAL connection probe for the wizard's test step: a live signed qsearch via the
	 * shared Quissly_Connection::probe(). Reports actual success/failure (or "not configured").
	 *
	 * @return string
	 */
	private function connection_probe() {
		return Quissly_Connection::probe()['message'];
	}
}
