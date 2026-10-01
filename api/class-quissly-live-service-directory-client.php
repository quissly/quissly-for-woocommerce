<?php
/**
 * Live QChat agent-id lookup — exchanges the store's key for a panel session, then
 * reads the resolved service list.
 *
 * @package Quissly_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Two real calls to console.quissly.com, mirroring the Magento plugin's
 * PanelSession + ServiceDirectory pair exactly (the first is Quissly_Panel_Session, shared
 * with the embedded admin panel page):
 *
 *  1. POST /api/v1/auth/service-login/store — exchanges {project_id, api_key, email,
 *     platform} for a short-lived panel-session access token. This is a DIFFERENT
 *     auth mechanism from the signed search/catalog API: no signature, no timestamp,
 *     just the store's own credentials as a login. The store's api_key never leaves
 *     the server.
 *  2. GET /api/v1/services/project/{project_id}, authenticated with THAT session's
 *     access token (never the store's own bearer token), returns every service
 *     Quissly created for the account; the qchat one's `id` is the agent id.
 *
 * Provisioning creates the qchat service but does not return its id directly, which
 * is why this is a separate follow-up lookup rather than part of the provision response.
 */
class Quissly_Live_Service_Directory_Client implements Quissly_Service_Directory_Client {

	const PATH_PROJECT_SERVICES  = '/api/v1/services/project/';
	const SLUG_QCHAT             = 'qchat';

	/** An admin waiting on a page, not a shopper waiting on results. */
	const TIMEOUT_SECONDS = 10;

	/**
	 * @param string $project_id Quissly project id.
	 * @param string $email      Account email.
	 * @param string $api_key    Bearer token.
	 * @return string|null
	 */
	public function qchat_agent_id( $project_id, $email, $api_key ) {
		return $this->service_field( $project_id, $email, $api_key, self::SLUG_QCHAT, 'id' );
	}

	/**
	 * The qsearch service's quissly_service_link: the namespace of Quissly's own product
	 * ids, uuid5( link, "<product id>" ) - the ids the chat widget uses (LIVE-CONFIRMED
	 * 2026-09-24 on the CS-Cart test store: uuid5( link, "247" ) was exactly the
	 * chat's id for product 247).
	 *
	 * @param string $project_id Quissly project id.
	 * @param string $email      Account email.
	 * @param string $api_key    Bearer token.
	 * @return string|null
	 */
	public function qsearch_namespace( $project_id, $email, $api_key ) {
		return $this->service_field( $project_id, $email, $api_key, 'qsearch', 'quissly_service_link' );
	}

	/**
	 * The qsearch service's id - where its widget_config (search bar suggestions) lives.
	 *
	 * @param string $project_id Quissly project id.
	 * @param string $email      Account email.
	 * @param string $api_key    Bearer token.
	 * @return string|null
	 */
	public function qsearch_service_id( $project_id, $email, $api_key ) {
		return $this->service_field( $project_id, $email, $api_key, 'qsearch', 'id' );
	}

	/**
	 * One field of the account's service with the given slug, via a panel session.
	 *
	 * @param string $project_id Quissly project id.
	 * @param string $email      Account email.
	 * @param string $api_key    Bearer token.
	 * @param string $slug       service_type_slug.
	 * @param string $field      Field to return.
	 * @return string|null
	 */
	private function service_field( $project_id, $email, $api_key, $slug, $field ) {
		$session = Quissly_Panel_Session::open( $project_id, $email, $api_key );
		if ( ! $session['ok'] ) {
			return null;
		}
		$access_token = $session['access_token'];

		$url = rtrim( Quissly_Live_Provisioner_Client::console_url(), '/' )
			. self::PATH_PROJECT_SERVICES . rawurlencode( $project_id );

		$response = wp_remote_get(
			$url,
			array(
				'headers' => array( 'Authorization' => 'Bearer ' . $access_token ),
				'timeout' => self::TIMEOUT_SECONDS,
			)
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}
		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $decoded ) ) {
			return null;
		}
		foreach ( $decoded as $service ) {
			if ( is_array( $service ) && $slug === ( isset( $service['service_type_slug'] ) ? $service['service_type_slug'] : null ) && ! empty( $service[ $field ] ) ) {
				return (string) $service[ $field ];
			}
		}

		return null;
	}
}
