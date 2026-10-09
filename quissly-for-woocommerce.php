<?php
// #anita checking for updates,pls work
/**
 * Plugin Name:       Quissly for WooCommerce
 * Plugin URI:        https://quissly.com/woocommerce
 * Description:        Replace native WooCommerce search with Quissly AI product discovery: semantic search, voice & image search, autocomplete, and the QChat assistant.
 * Version:           1.0.3
 * Author:            Quissly
 * Author URI:        https://quissly.com
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       quissly-for-woocommerce
 * Domain Path:       /languages
 * Update URI:        https://github.com/quissly/quissly-for-woocommerce
 *
 * @package Quissly_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'QUISSLY_VERSION', '1.0.3' );
define( 'QUISSLY_PLUGIN_FILE', __FILE__ );
define( 'QUISSLY_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'QUISSLY_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'QUISSLY_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Declare High-Performance Order Storage (HPOS) compatibility.
 *
 * Trivially true — the plugin never touches orders — but WooCommerce requires the
 * declaration so it does not flag the plugin as incompatible. Runs on
 * `before_woocommerce_init`, which only fires when WooCommerce is present.
 */
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				QUISSLY_PLUGIN_FILE,
				true
			);
		}
	}
);

/**
 * Activation: create the dirty-product and shopping-event queue tables.
 *
 * Runs on activation only (not every load). The table creation needs no WooCommerce APIs,
 * so it is safe even though WooCommerce may not be loaded yet on the activation request.
 */
function quissly_activate() {
	require_once QUISSLY_PLUGIN_DIR . 'sync/class-quissly-dirty-queue.php';
	require_once QUISSLY_PLUGIN_DIR . 'includes/class-quissly-event-queue.php';

	Quissly_Dirty_Queue::create_table();
	Quissly_Event_Queue::create_table();
	update_option( 'quissly_installed_version', QUISSLY_VERSION, false );
}
register_activation_hook( __FILE__, 'quissly_activate' );

/**
 * After an update: WordPress does not run the activation hook when a plugin updates, so
 * what activation sets up (new tables) is set up here the first time a new version loads.
 */
function quissly_after_update() {
	if ( get_option( 'quissly_installed_version' ) !== QUISSLY_VERSION ) {
		quissly_activate();
	}
}
add_action( 'plugins_loaded', 'quissly_after_update', 5 );

// Updates come from the plugin's GitHub releases, checked once a day and always installed
// automatically by WordPress. Registered before the WooCommerce check, so a store whose
// WooCommerce is off still receives them.
require_once QUISSLY_PLUGIN_DIR . 'includes/class-quissly-updater.php';
Quissly_Updater::register();

/**
 * Bootstrap the plugin once all plugins are loaded.
 *
 * Defensively confirms WooCommerce is active in PHP rather than relying solely on the
 * `Requires Plugins` header (older WordPress ignores that header). If WooCommerce is
 * not active, the plugin no-ops and shows an admin notice instead of fataling on a
 * missing WooCommerce API.
 */
function quissly_bootstrap() {
	if ( ! quissly_is_woocommerce_active() ) {
		add_action( 'admin_notices', 'quissly_woocommerce_missing_notice' );

		return;
	}

	require_once QUISSLY_PLUGIN_DIR . 'includes/class-quissly-plugin.php';

	Quissly_Plugin::instance();
}
add_action( 'plugins_loaded', 'quissly_bootstrap' );

/**
 * Whether WooCommerce is loaded and active.
 *
 * @return bool
 */
function quissly_is_woocommerce_active() {
	return class_exists( 'WooCommerce' );
}

/**
 * Render the "WooCommerce required" admin notice.
 */
function quissly_woocommerce_missing_notice() {
	echo '<div class="notice notice-error"><p>';
	echo esc_html__( 'Quissly for WooCommerce requires WooCommerce to be installed and active.', 'quissly-for-woocommerce' );
	echo '</p></div>';
}
