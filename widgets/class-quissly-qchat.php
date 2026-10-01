<?php
/**
 * QChat widget injection.
 *
 * @package Quissly_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Injects the hosted QChat tags into the storefront footer so Quissly's universal_woocommerce.js loads:
 *
 *   <ai-chatbot agent-id="{qchat_service_id}"></ai-chatbot>
 *   <script src="https://cdn.quissly.com/scripts/universal_woocommerce.js" async></script>
 *
 * The plugin ONLY outputs the two tags on front-end pages — universal_woocommerce.js self-places and
 * renders the widget, so there is NO placement/positioning/container/styling here. The tags
 * are emitted ONLY when the QChat feature toggle is ON (defaults OFF) AND a QChat service id
 * is present; otherwise nothing is output.
 *
 * `agent-id` is the PUBLIC QChat service id (quissly_service_id), NEVER the bearer token. The dev value can come
 * from `.env` (QCHAT_SERVICE_UUID); the shipped plugin reads the stored option.
 */
class Quissly_QChat {

	const SCRIPT_URL = 'https://cdn.quissly.com/scripts/universal_woocommerce.js';

	/**
	 * Hook the front-end footer.
	 */
	public function register() {
		add_action( 'wp_footer', array( $this, 'inject' ) );
	}

	/**
	 * Output the QChat tags when enabled + configured (else nothing).
	 */
	public function inject() {
		if ( is_admin() ) {
			return; // wp_footer is front-end, but be defensive.
		}
		$service_id = Quissly_Env::qchat_service_id();
		if ( ! self::should_inject( Quissly_Settings::get( 'quissly_enable_qchat' ), $service_id ) ) {
			return;
		}

		echo self::tags( $service_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in tags().
	}

	/**
	 * Gating decision (PURE, unit-testable): inject only when QChat is enabled AND a non-empty
	 * service id is configured.
	 *
	 * @param mixed  $enabled    The quissly_enable_qchat toggle.
	 * @param string $service_id The QChat service id.
	 * @return bool
	 */
	public static function should_inject( $enabled, $service_id ) {
		return (bool) $enabled && '' !== trim( (string) $service_id );
	}

	/**
	 * Build the two tags with the agent-id escaped (esc_attr) and the script URL escaped.
	 * Preserves the `async` attribute on the script.
	 *
	 * @param string $service_id The public QChat service id (agent-id).
	 * @return string
	 */
	public static function tags( $service_id ) {
		return '<ai-chatbot agent-id="' . esc_attr( (string) $service_id ) . '"></ai-chatbot>' . "\n"
			. '<script src="' . esc_url( self::SCRIPT_URL ) . '" async></script>' . "\n";
	}
}
