<?php
/**
 * WooCommerce → Settings → Integrations tab for Quissly.
 *
 * @package Quissly_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WC_Integration' ) ) {
	return; // Loaded lazily via the woocommerce_integrations filter; bail if WC isn't ready.
}

/**
 * Surfaces the core Quissly settings under WooCommerce → Settings → Integrations, the
 * second entry point. Reads and writes the SAME options as the top-level Quissly menu
 * (via Quissly_Settings), so the two stay in sync, and links to the full settings page.
 */
class Quissly_WC_Integration extends WC_Integration {

	/**
	 * The option keys exposed on this tab, with their field type.
	 *
	 * @var array<string,string>
	 */
	private $fields = array(
		'quissly_enable_search' => 'checkbox',
		'quissly_enable_qchat'  => 'checkbox',
	);

	/**
	 * Set up the integration.
	 */
	public function __construct() {
		$this->id                 = 'quissly';
		$this->method_title       = __( 'Quissly', 'quissly-for-woocommerce' );
		$this->method_description  = __( 'AI product discovery. These options are shared with the Quissly menu (Quissly → Settings), where the full configuration and setup wizard live.', 'quissly-for-woocommerce' );

		$this->init_form_fields();
		$this->settings = $this->shared_values();

		add_action( 'woocommerce_update_options_integration_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	/**
	 * Declare the form fields shown on the tab.
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'quissly_enable_search' => array(
				'title' => __( 'QSearch', 'quissly-for-woocommerce' ),
				'type'  => 'checkbox',
				'label' => __( 'Replace WooCommerce search with Quissly', 'quissly-for-woocommerce' ),
			),
			'quissly_enable_qchat'  => array(
				'title' => __( 'QChat assistant', 'quissly-for-woocommerce' ),
				'type'  => 'checkbox',
				'label' => __( 'Inject the QChat widget', 'quissly-for-woocommerce' ),
			),
		);
	}

	/**
	 * Render the tab, prefixed with a link to the full settings page.
	 */
	public function admin_options() {
		echo '<h2>' . esc_html__( 'Quissly', 'quissly-for-woocommerce' ) . '</h2>';
		echo '<p>' . wp_kses_post(
			sprintf(
				/* translators: %s: link to the full Quissly settings page. */
				__( 'Core options below are shared with the full Quissly settings. Open %s for the dashboard, sync log, and setup wizard.', 'quissly-for-woocommerce' ),
				'<a href="' . esc_url( admin_url( 'admin.php?page=quissly-settings' ) ) . '">' . esc_html__( 'Quissly → Settings', 'quissly-for-woocommerce' ) . '</a>'
			)
		) . '</p>';
		echo '<table class="form-table">';
		$this->generate_settings_html();
		echo '</table>';
	}

	/**
	 * Save the tab's fields back to the SHARED options (sanitized via Quissly_Settings).
	 * WooCommerce verifies the nonce before firing the update hook that calls this.
	 */
	public function process_admin_options() {
		foreach ( $this->fields as $key => $type ) {
			$field = $this->get_field_key( $key );
			if ( 'checkbox' === $type ) {
				Quissly_Settings::update( $key, isset( $_POST[ $field ] ) ? '1' : '0' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified by WC before this hook.
			} elseif ( isset( $_POST[ $field ] ) ) {
				Quissly_Settings::update( $key, wp_unslash( $_POST[ $field ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified by WC; sanitized in Settings::update.
			}
		}
		$this->settings = $this->shared_values();
	}

	/**
	 * Current shared values mapped into WC's settings format (checkboxes as yes/no).
	 *
	 * @return array<string,string>
	 */
	private function shared_values() {
		$values = array();
		foreach ( $this->fields as $key => $type ) {
			if ( 'checkbox' === $type ) {
				$values[ $key ] = Quissly_Settings::get( $key ) ? 'yes' : 'no';
			} else {
				$values[ $key ] = (string) Quissly_Settings::get( $key );
			}
		}

		return $values;
	}
}
