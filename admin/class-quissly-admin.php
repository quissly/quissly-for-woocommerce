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
 * Registers the top-level Quissly menu - Configuration, Dashboard, Quissly Admin Panel, the
 * same three entries as the Magento plugin - renders those screens, and handles their form
 * submissions (nonce + capability checked, PRG redirect). Setup lives at the top of
 * Configuration until it is finished (Magento's "Keys & Connection" block), and the sync
 * log on the Dashboard; neither has a page of its own. The WooCommerce Integrations tab is a separate class
 * (Quissly_WC_Integration) that reads/writes the SAME options via Quissly_Settings.
 *
 * Output is escaped at the point of echo; input is sanitized through Quissly_Settings.
 */
class Quissly_Admin {

	const CAP        = 'manage_woocommerce';
	const MENU_SLUG  = 'quissly';
	const PAGE_SETTINGS = 'quissly-settings';
	const PAGE_PANEL    = 'quissly-panel';

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
	 * Register the top-level menu and submenus.
	 */
	public function register_menus() {
		add_menu_page(
			__( 'Quissly', 'quissly-for-woocommerce' ),
			__( 'Quissly', 'quissly-for-woocommerce' ),
			self::CAP,
			self::MENU_SLUG,
			array( $this, 'render_dashboard' ),
			'dashicons-search',
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

		global $submenu;
		if ( isset( $submenu[ self::MENU_SLUG ] ) ) {
			$order = array( self::PAGE_SETTINGS, self::MENU_SLUG, self::PAGE_PANEL );
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
		// Don't nag while already on the screen that holds setup.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( self::PAGE_SETTINGS === $page ) {
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
	 * Configuration: setup (until it is finished), then the feature flags, Quick
	 * layout/label, selectors and the uninstall toggle. Reads/writes the same options as the
	 * WC Integrations tab.
	 */
	public function render_settings() {
		$this->guard();

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Quissly Configuration', 'quissly-for-woocommerce' ) . '</h1>';
		$this->print_flash();

		if ( ! Quissly_Wizard::is_complete() ) {
			$this->render_wizard();
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="quissly_save_settings" />';
		wp_nonce_field( 'quissly_settings' );
		echo '<table class="form-table" role="presentation"><tbody>';

		// No credential fields here (bearer token, QChat service id): the
		// setup wizard's connect stores them, and a merchant never needs to see or type them
		// (the Magento plugin hides the same values). The save handler only writes a text
		// option that is POSTed, so leaving them off the form keeps the stored values.

		// Feature flags (mirrored on the dashboard).
		$this->toggle_row( 'quissly_enable_search', __( 'Enable QSearch', 'quissly-for-woocommerce' ) );
		$this->toggle_row( 'quissly_enable_quick', __( 'Enable Quick Recommendations', 'quissly-for-woocommerce' ) );
		$this->toggle_row( 'quissly_enable_voice', __( 'Enable voice search', 'quissly-for-woocommerce' ) );
		$this->toggle_row( 'quissly_enable_image', __( 'Enable image search', 'quissly-for-woocommerce' ) );
		$this->toggle_row( 'quissly_enable_qchat', __( 'Enable QChat', 'quissly-for-woocommerce' ) );

		// Quick layout.
		$layout = Quissly_Settings::get( 'quissly_quick_layout' );
		// the Magento plugin's name for the same choice ("Quick Recommendations style").
		echo '<tr><th scope="row"><label for="quissly_quick_layout">' . esc_html__( 'Quick Recommendations style', 'quissly-for-woocommerce' ) . '</label></th><td>';
		echo '<select name="quissly_quick_layout" id="quissly_quick_layout">';
		echo '<option value="vertical"' . selected( $layout, 'vertical', false ) . '>' . esc_html__( 'List - thumbnail, name and price per row', 'quissly-for-woocommerce' ) . '</option>';
		echo '<option value="horizontal"' . selected( $layout, 'horizontal', false ) . '>' . esc_html__( 'Cards - large cards with image and price, in a grid', 'quissly-for-woocommerce' ) . '</option>';
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'A compact list of names, or larger cards with images and prices. Phones always get the list.', 'quissly-for-woocommerce' ) . '</p></td></tr>';

		$this->text_row( 'quissly_see_all_label', __( '"See all results" label', 'quissly-for-woocommerce' ) );
		$this->text_row( 'quissly_search_selector_desktop', __( 'Search box selector (desktop, advanced)', 'quissly-for-woocommerce' ) );
		$this->text_row( 'quissly_search_selector_mobile', __( 'Search box selector (mobile, advanced)', 'quissly-for-woocommerce' ) );

		$this->toggle_row( 'quissly_enable_overlay', __( 'Search overlay takes over the theme\'s search box', 'quissly-for-woocommerce' ) );
		$this->text_row( 'quissly_overlay_mount_selector', __( 'Search button mount point', 'quissly-for-woocommerce' ), __( 'Only used when your theme has no search box. A CSS selector for an existing header control - your cart, account or language switcher - and the search button is placed just to its left. Leave empty to auto-detect: the cart, then the account link, then the language switcher, else a floating button at the bottom-left. Example: .site-header-cart', 'quissly-for-woocommerce' ) );

		$this->toggle_row( 'quissly_preserve_on_uninstall', __( 'Preserve configuration on uninstall', 'quissly-for-woocommerce' ) );

		echo '</tbody></table>';
		$this->render_search_suggestions();
		$this->render_catalog_data();
		submit_button();
		echo '</form>';
		echo '</div>';
	}

	/**
	 * Configuration's "Search bar suggestions" section (the Shopify app's): the queries the
	 * search overlay types into its empty bar. They live in Quissly
	 * (Quissly_Search_Suggestions), so the section shows what Quissly holds now and the save
	 * handler writes a changed list back.
	 */
	private function render_search_suggestions() {
		echo '<h2 id="quissly-search-suggestions">' . esc_html__( 'Search bar suggestions', 'quissly-for-woocommerce' ) . '</h2>';
		echo '<p class="description" style="max-width:760px">' . esc_html__( 'Example searches the search overlay types, letter by letter, into its empty search bar, in random order, so shoppers see what they can ask. They stop the moment a shopper starts typing.', 'quissly-for-woocommerce' ) . '</p>';
		$current = Quissly_Search_Suggestions::read();
		if ( null === $current ) {
			echo '<p><em>' . esc_html__( 'Available once the store is connected and its first catalog sync has created search in Quissly - or Quissly could not be reached just now; reload to try again.', 'quissly-for-woocommerce' ) . '</em></p>';
			return;
		}
		echo '<input type="hidden" name="quissly_suggestions_present" value="1" />';
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row">' . esc_html__( 'Show typing suggestions', 'quissly-for-woocommerce' ) . '</th><td>';
		echo '<label><input type="checkbox" name="quissly_typing_enabled" value="1"' . checked( $current['enabled'], true, false ) . ' /> ' . esc_html__( 'Enabled', 'quissly-for-woocommerce' ) . '</label></td></tr>';
		echo '<tr><th scope="row"><label for="quissly_suggestions">' . esc_html__( 'Suggestions', 'quissly-for-woocommerce' ) . '</label></th><td>';
		echo '<textarea id="quissly_suggestions" name="quissly_suggestions" rows="6" class="large-text" style="max-width:520px" placeholder="' . esc_attr__( 'e.g. waterproof jacket under $100', 'quissly-for-woocommerce' ) . '">' . esc_textarea( implode( "\n", $current['queries'] ) ) . '</textarea>';
		/* translators: %d: maximum length. */
		echo '<p class="description">' . esc_html( sprintf( __( 'One per line, each under %d characters. With none, the bar keeps its plain placeholder.', 'quissly-for-woocommerce' ), Quissly_Search_Suggestions::MAX_LENGTH ) ) . '</p>';
		// "N of 20" (the Shopify app's), kept current while the merchant types: distinct,
		// non-empty lines - the save cleans the list the same way.
		/* translators: 1: number of suggestions in the list, 2: maximum number of suggestions. */
		$count = sprintf( esc_html__( '%1$s of %2$d. 10 is usually more than enough, but you can add up to %2$d.', 'quissly-for-woocommerce' ), '<span id="quissly-suggestions-count">' . count( $current['queries'] ) . '</span>', Quissly_Search_Suggestions::MAX_COUNT );
		echo '<p class="description">' . wp_kses( $count, array( 'span' => array( 'id' => true ) ) ) . '</p>';
		echo '<script>' . str_replace( '__MAX__', (string) Quissly_Search_Suggestions::MAX_COUNT, '(function(){var t=document.getElementById("quissly_suggestions"),c=document.getElementById("quissly-suggestions-count");if(!t||!c){return;}function n(){var s={},k=0;t.value.split(/\r\n|\r|\n/).forEach(function(l){l=l.trim().toLowerCase();if(l&&!s[l]){s[l]=1;k++;}});c.textContent=k;c.parentNode.style.color=k>__MAX__?"#b32d2e":"";}t.addEventListener("input",n);n();})();' ) . '</script>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a fixed script.
		$generated = Quissly_Showcase_Runner::generated();
		if ( ! empty( $generated ) && $generated !== $current['queries'] ) {
			// "Reset to generated" (the Shopify app's): the list built from the catalog.
			echo '<p style="margin-top:10px"><label><input type="checkbox" name="quissly_suggestions_use_generated" value="1" /> ' . esc_html__( 'Replace the list with the suggestions generated from your catalog:', 'quissly-for-woocommerce' ) . '</label></p>';
			echo '<p class="description">' . esc_html( implode( ' · ', $generated ) ) . '</p>';
		} elseif ( empty( $current['queries'] ) && ! Quissly_Showcase_Runner::finished() && Quissly_Showcase_Runner::enabled() ) {
			echo '<p class="description">' . esc_html__( 'Left empty, suggestions are generated from your catalog after the first catalog sync - each one checked to find products.', 'quissly-for-woocommerce' ) . '</p>';
		}
		echo '</td></tr></tbody></table>';
	}

	/**
	 * Configuration's "Catalog data" section (the Magento plugin's): the attributes sent to
	 * Quissly, pre-ticked with what the product pages show. Part of the settings form; the
	 * save handler turns the ticks into Quissly_Catalog_Attributes choices.
	 */
	private function render_catalog_data() {
		$catalog = Quissly_Catalog_Attributes::catalog();
		$choices = Quissly_Catalog_Attributes::choices();

		echo '<h2 id="quissly-catalog-data">' . esc_html__( 'Catalog data', 'quissly-for-woocommerce' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody><tr><th scope="row">' . esc_html__( 'Product attributes sent to Quissly', 'quissly-for-woocommerce' ) . '</th><td>';
		if ( empty( $catalog ) ) {
			echo '<p>' . esc_html__( 'Your products have no attributes yet.', 'quissly-for-woocommerce' ) . '</p></td></tr></tbody></table>';
			return;
		}
		echo '<fieldset style="columns:2 260px;max-width:760px">';
		foreach ( $catalog as $label => $counts ) {
			$checked = Quissly_Catalog_Attributes::included( $label, $counts['shown'] > 0, $choices );
			$notes   = array();
			if ( $counts['shown'] > 0 && $counts['hidden'] > 0 ) {
				/* translators: %d: number of products. */
				$notes[] = sprintf( __( 'hidden on %d products', 'quissly-for-woocommerce' ), $counts['hidden'] );
			} elseif ( 0 === $counts['shown'] ) {
				$notes[] = __( 'hidden on product pages', 'quissly-for-woocommerce' );
			}
			if ( $counts['variation'] > 0 ) {
				/* translators: %d: number of products. */
				$notes[] = sprintf( __( 'a variation option on %d products - always sent there', 'quissly-for-woocommerce' ), $counts['variation'] );
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
		echo '</td></tr></tbody></table>';
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
	 * The setup section at the top of Configuration (the current wizard step), shown until
	 * setup is finished.
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
			$text = isset( $_POST['quissly_suggestions'] ) ? sanitize_textarea_field( wp_unslash( $_POST['quissly_suggestions'] ) ) : '';
			if ( isset( $_POST['quissly_suggestions_use_generated'] ) ) {
				$text = implode( "\n", Quissly_Showcase_Runner::generated() );
			}
			$error = $this->save_search_suggestions( isset( $_POST['quissly_typing_enabled'] ), $text );
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
	 * @param bool   $enabled Show typing suggestions.
	 * @param string $text    One suggestion per line.
	 * @return string
	 */
	private function save_search_suggestions( $enabled, $text ) {
		$queries = Quissly_Search_Suggestions::clean( preg_split( '/\r\n|\r|\n/', (string) $text ) );
		$error   = Quissly_Search_Suggestions::validate( $queries );
		if ( '' !== $error ) {
			return $error;
		}
		$current = Quissly_Search_Suggestions::read();
		if ( null !== $current && $current['enabled'] === (bool) $enabled && $current['queries'] === $queries ) {
			return ''; // unchanged: no write.
		}
		$error = Quissly_Search_Suggestions::save( $enabled, $queries );
		if ( '' === $error && ( null === $current || $current['queries'] !== $queries ) ) {
			// The merchant's own list from now on: generation never writes over it.
			Quissly_Showcase_Runner::merchant_saved();
		}

		return $error;
	}

	/**
	 * Save the dashboard feature toggles (only the six enable flags).
	 */
	public function handle_save_dashboard() {
		$this->verify( 'quissly_dashboard' );

		foreach ( array( 'quissly_enable_search', 'quissly_enable_overlay', 'quissly_enable_quick', 'quissly_enable_voice', 'quissly_enable_image', 'quissly_enable_qchat' ) as $key ) {
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
	 */
	private function toggle_row( $key, $label ) {
		$checked = Quissly_Settings::get( $key ) ? ' checked' : '';
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>';
		echo '<label><input type="checkbox" name="' . esc_attr( $key ) . '" value="1"' . $checked . ' /> ' . esc_html__( 'Enabled', 'quissly-for-woocommerce' ) . '</label>';
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
