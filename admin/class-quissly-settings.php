<?php
/**
 * Settings model — the single source of truth for Quissly options.
 *
 * @package Quissly_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Defines every persisted option (key, default, sanitizer), and reads/writes them with
 * sanitization on input. Both admin entry points (the top-level Quissly menu and the
 * WooCommerce Integrations tab) go through this class, so they share one set of options.
 *
 * The value-level sanitizers (bool / layout / service_id / label) are PURE and
 * unit-tested; text values use sanitize_text_field at write time.
 */
class Quissly_Settings {

	/**
	 * Option schema: key => array{default:mixed, type:string}.
	 *
	 * @return array<string,array{default:mixed,type:string}>
	 */
	public static function schema() {
		return array(
			'quissly_token'                   => array( 'default' => '', 'type' => 'text' ),
			'quissly_service_id'              => array( 'default' => '', 'type' => 'service_id' ),
			// Set by automatic provisioning (Quissly_Wizard::connect_automatically()); not
			// user-editable. Kept as plain text options (not a Key_Store secret like the
			// token) — they identify the account, they don't authenticate it.
			'quissly_project_id'              => array( 'default' => '', 'type' => 'text' ),
			'quissly_store_id'                => array( 'default' => '', 'type' => 'text' ),
			'quissly_account_email'           => array( 'default' => '', 'type' => 'text' ),
			// The qsearch service's quissly_service_link: namespace of the product ids the chat
			// widget uses (Quissly_Chat_Cart). Looked up at connect, or lazily.
			'quissly_search_namespace'        => array( 'default' => '', 'type' => 'text' ),
			// QSearch is the core feature and works today -> ON by default on a fresh install
			// (still gated behind first-sync-complete, so it only engages after the first sync).
			'quissly_enable_search'           => array( 'default' => true, 'type' => 'bool' ),
			// quick / voice / image / qchat are backend-blocked (those endpoints are not live
			// yet) -> OFF by default, so a fresh install never exposes a broken feature.
			'quissly_enable_quick'            => array( 'default' => false, 'type' => 'bool' ),
			'quissly_enable_voice'            => array( 'default' => false, 'type' => 'bool' ),
			'quissly_enable_image'            => array( 'default' => false, 'type' => 'bool' ),
			'quissly_enable_qchat'            => array( 'default' => false, 'type' => 'bool' ),
			'quissly_quick_layout'            => array( 'default' => 'vertical', 'type' => 'layout' ),
			'quissly_see_all_label'           => array( 'default' => 'See all results', 'type' => 'label' ),
			'quissly_search_selector_desktop' => array( 'default' => '', 'type' => 'text' ),
			'quissly_search_selector_mobile'  => array( 'default' => '', 'type' => 'text' ),
			'quissly_active_theme'            => array( 'default' => '', 'type' => 'text' ),
			// Search overlay (mirrors the Magento plugin's enable_overlay, same default).
			// Governs ONE thing: whether an EXISTING theme search box gets taken over. A
			// theme with NO search box gets the overlay regardless — see
			// Quissly_Overlay's docblock.
			'quissly_enable_overlay'          => array( 'default' => true, 'type' => 'bool' ),
			'quissly_overlay_mount_selector'  => array( 'default' => '', 'type' => 'text' ),
			// Explicit X-Environment override (empty = auto-detect from WP_ENVIRONMENT_TYPE). The
			// only way, besides QUISSLY_ENV, to select demo/test (no WP type maps to those).
			'quissly_environment'             => array( 'default' => '', 'type' => 'text' ),
			'quissly_preserve_on_uninstall'   => array( 'default' => false, 'type' => 'bool' ),
		);
	}

	/**
	 * Read an option (typed default applied).
	 *
	 * @param string $key Option key.
	 * @return mixed
	 */
	public static function get( $key ) {
		// The bearer token is a real credential, stored AES-256-GCM-encrypted like the
		// private key - not a plain option - so it is special-cased here rather than
		// gaining its own 'type' in the schema, to keep every other caller (the wizard,
		// both settings screens) unchanged: they already just call get()/update().
		if ( 'quissly_token' === $key ) {
			return ( new Quissly_Key_Store() )->get_token();
		}

		$schema = self::schema();
		if ( ! isset( $schema[ $key ] ) ) {
			return null;
		}
		$default = $schema[ $key ]['default'];
		$value   = get_option( $key, $default );

		if ( 'bool' === $schema[ $key ]['type'] ) {
			return self::sanitize_bool( $value );
		}

		return $value;
	}

	/**
	 * Sanitize then persist an option.
	 *
	 * @param string $key Option key.
	 * @param mixed  $raw Raw (unsanitized) value.
	 * @return bool Whether the value was a known key and stored.
	 */
	public static function update( $key, $raw ) {
		if ( 'quissly_token' === $key ) {
			( new Quissly_Key_Store() )->set_token( sanitize_text_field( (string) $raw ) );
			return true;
		}

		$schema = self::schema();
		if ( ! isset( $schema[ $key ] ) ) {
			return false;
		}
		$value = self::sanitize( $key, $raw );

		// Store bools as the canonical strings '1'/'0', NOT a boolean. WordPress stores a
		// boolean `false` as '' in the options table, which get_option() cannot distinguish
		// from "option absent" — so with a TRUE default (e.g. quissly_enable_search) a stored
		// `false` would read back as the default `true` (i.e. the merchant couldn't turn it
		// OFF). '0' is a real value get_option returns verbatim, so disabling persists.
		if ( 'bool' === $schema[ $key ]['type'] ) {
			$value = $value ? '1' : '0';
		}

		update_option( $key, $value, false );

		return true;
	}

	/**
	 * Sanitize a value for a key per its schema type.
	 *
	 * @param string $key Option key.
	 * @param mixed  $raw Raw value.
	 * @return mixed
	 */
	public static function sanitize( $key, $raw ) {
		$schema = self::schema();
		$type   = isset( $schema[ $key ] ) ? $schema[ $key ]['type'] : 'text';

		switch ( $type ) {
			case 'bool':
				return self::sanitize_bool( $raw );
			case 'layout':
				return self::sanitize_layout( $raw );
			case 'service_id':
				return self::sanitize_service_id( $raw );
			case 'label':
				return self::sanitize_label( $raw, $schema[ $key ]['default'] );
			case 'text':
			default:
				return sanitize_text_field( (string) $raw );
		}
	}

	/**
	 * All option keys this plugin manages (for the uninstall wipe). Includes keys owned by
	 * other components (key store, sync state, wizard) so "all quissly_ options" is real.
	 *
	 * @return string[]
	 */
	public static function all_option_keys() {
		return array_merge(
			array_keys( self::schema() ),
			array(
				'quissly_encrypted_private_key',
				'quissly_service_uuid', // retired X-Service-UUID option: still wiped where an older version stored one.
				'quissly_public_key',
				'quissly_initial_sync_complete',
				'quissly_sync_refused',
				'quissly_sync_gate_blocked',
				'quissly_wizard_complete',
				'quissly_wizard_step',
				'quissly_catalog_attribute_choices',
				'quissly_search_service_id',
				'quissly_showcase_state',
			)
		);
	}

	// --- Pure sanitizers (unit-tested) ------------------------------------------------

	/**
	 * Coerce a checkbox/loose value to bool. '0'/'false'/''/0 are false.
	 *
	 * @param mixed $value Raw.
	 * @return bool
	 */
	public static function sanitize_bool( $value ) {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( is_string( $value ) ) {
			$v = strtolower( trim( $value ) );

			return ! ( '' === $v || '0' === $v || 'false' === $v || 'no' === $v || 'off' === $v );
		}

		return (bool) $value;
	}

	/**
	 * Dropdown layout: 'horizontal' or 'vertical' (default).
	 *
	 * @param mixed $value Raw.
	 * @return string
	 */
	public static function sanitize_layout( $value ) {
		return ( 'horizontal' === $value ) ? 'horizontal' : 'vertical';
	}

	/**
	 * A QChat service_id must be a UUID; anything else becomes ''.
	 *
	 * @param mixed $value Raw.
	 * @return string
	 */
	public static function sanitize_service_id( $value ) {
		$value = trim( (string) $value );

		return preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value ) ? strtolower( $value ) : '';
	}

	/**
	 * "See all results" label: tags stripped, trimmed; falls back to the default if empty.
	 *
	 * @param mixed  $value   Raw.
	 * @param string $default Fallback.
	 * @return string
	 */
	public static function sanitize_label( $value, $default = 'See all results' ) {
		$value = trim( strip_tags( (string) $value ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- pure (unit-tested, no WP); display still escaped.

		return '' === $value ? $default : $value;
	}
}
