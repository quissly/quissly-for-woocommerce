<?php
/**
 * Uninstall cleanup for Quissly for WooCommerce.
 *
 * Runs when the plugin is DELETED (not merely deactivated). Delegates to
 * Quissly_Uninstall::run() so the same logic is exercised by integration tests.
 *
 * Full wipe by default: every `quissly_` option (including the encrypted private key),
 * the dirty-queue table, and the protected sync log. With "Preserve configuration on
 * uninstall" enabled, options + keys are KEPT (reinstall reconnects); the queue table and
 * log are removed in either case.
 *
 * @package Quissly_For_WooCommerce
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( ! defined( 'QUISSLY_PLUGIN_DIR' ) ) {
	define( 'QUISSLY_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}

require_once QUISSLY_PLUGIN_DIR . 'includes/class-quissly-uninstall.php';

Quissly_Uninstall::run( (bool) get_option( 'quissly_preserve_on_uninstall', false ) );
