<?php
/**
 * QChat agent-id lookup seam — the swappable boundary between the wizard and Quissly.
 *
 * @package Quissly_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves the QChat agent id Quissly created for this account, so the merchant never
 * has to find and paste a UUID by hand.
 *
 * Mirrors the other seams in this plugin: the wizard depends only on this interface, so
 * the live console-authenticated resolver and a dev/offline mock are interchangeable via
 * the `quissly_service_directory_client` filter. NO live call is ever made by the wizard
 * directly.
 */
interface Quissly_Service_Directory_Client {

	/**
	 * The QChat agent id for the connected account, or null if it cannot be determined.
	 *
	 * Null is a normal outcome (the tenant may have no qchat service, or the lookup may
	 * fail transiently) — the caller treats it as "chat not available yet", never as an
	 * error worth showing the merchant.
	 *
	 * @param string $project_id Quissly project id (from provisioning).
	 * @param string $email      Account email (from provisioning).
	 * @param string $api_key    Bearer token (from provisioning).
	 * @return string|null
	 */
	public function qchat_agent_id( $project_id, $email, $api_key );

	/**
	 * The qsearch service's `quissly_service_link` for the connected account, or null.
	 *
	 * It is the namespace of Quissly's own product ids: the chat widget names a product
	 * by uuid5( this, "<product id>" ), so the chat cart bridge (Quissly_Chat_Cart) needs
	 * it to map the widget's ids back to WooCommerce ids. Null is a normal outcome.
	 *
	 * @param string $project_id Quissly project id.
	 * @param string $email      Account email.
	 * @param string $api_key    Bearer token.
	 * @return string|null
	 */
	public function qsearch_namespace( $project_id, $email, $api_key );
}
