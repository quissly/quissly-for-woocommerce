<?php
/**
 * Quissly admin-panel session — signs the store in to the Quissly panel.
 *
 * @package Quissly_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exchanges the store's API key for a short-lived Quissly panel session, SERVER-SIDE, so the
 * key itself never reaches the browser - only the session tokens do. Mirrors
 * the Magento plugin's PanelSession: POST {project_id, api_key, email, platform} to the
 * console's /api/v1/auth/service-login/store.
 *
 * Two consumers: the embedded "Quissly Admin Panel" page (Quissly_Admin::render_panel(),
 * which puts the tokens in the panel iframe's URL) and the QChat agent-id lookup
 * (Quissly_Live_Service_Directory_Client, which lists the account's services with the
 * access token).
 *
 * Not routed through Quissly_Http_Client: this endpoint takes no signature and lives on the
 * console host, not the search API.
 */
class Quissly_Panel_Session {

	const PATH_SERVICE_LOGIN = '/api/v1/auth/service-login/store';
	const DEFAULT_PANEL_URL  = 'https://admin.quissly.com';

	/** An admin waiting on a page, not a shopper waiting on results. */
	const TIMEOUT_SECONDS = 10;

	/**
	 * Open a session for the connected store, from its stored credentials.
	 *
	 * @return array{ok:bool,access_token:string,refresh_token:string,error:string}
	 */
	public static function open_for_store() {
		return self::open(
			(string) Quissly_Settings::get( 'quissly_project_id' ),
			(string) Quissly_Settings::get( 'quissly_account_email' ),
			Quissly_Env::token()
		);
	}

	/**
	 * Exchange the store key for panel tokens.
	 *
	 * Errors: `not_configured` (no credentials to exchange - nothing is sent), `rejected`
	 * (401: usually an email / project id from another Quissly environment),
	 * `transport_error` (Quissly unreachable), `unexpected` (anything else).
	 *
	 * @param string $project_id Quissly project id.
	 * @param string $email      Account email.
	 * @param string $api_key    The store's API (bearer) key.
	 * @return array{ok:bool,access_token:string,refresh_token:string,error:string}
	 */
	public static function open( $project_id, $email, $api_key ) {
		if ( '' === (string) $project_id || '' === (string) $email || '' === (string) $api_key ) {
			return self::failure( 'not_configured' );
		}

		$response = wp_remote_post(
			Quissly_Live_Provisioner_Client::console_url() . self::PATH_SERVICE_LOGIN,
			array(
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode(
					array(
						'project_id' => (string) $project_id,
						'api_key'    => (string) $api_key,
						'email'      => (string) $email,
						'platform'   => Quissly_Http_Client::X_PLATFORM,
					)
				),
				'timeout' => self::TIMEOUT_SECONDS,
			)
		);

		if ( is_wp_error( $response ) ) {
			return self::failure( 'transport_error' );
		}

		return self::interpret_login(
			(int) wp_remote_retrieve_response_code( $response ),
			json_decode( wp_remote_retrieve_body( $response ), true )
		);
	}

	/**
	 * A console sign-in response as a session. PURE + unit-testable. Only a 200 carrying BOTH
	 * tokens opens one - the panel needs the refresh token to stay signed in.
	 *
	 * @param int   $code HTTP status.
	 * @param mixed $body Decoded body.
	 * @return array{ok:bool,access_token:string,refresh_token:string,error:string}
	 */
	public static function interpret_login( $code, $body ) {
		if ( 401 === (int) $code ) {
			return self::failure( 'rejected' );
		}
		$access  = is_array( $body ) && isset( $body['access_token'] ) ? (string) $body['access_token'] : '';
		$refresh = is_array( $body ) && isset( $body['refresh_token'] ) ? (string) $body['refresh_token'] : '';
		if ( 200 !== (int) $code || '' === $access || '' === $refresh ) {
			return self::failure( 'unexpected' );
		}

		return array( 'ok' => true, 'access_token' => $access, 'refresh_token' => $refresh, 'error' => '' );
	}

	/**
	 * The panel's base URL: QUISSLY_PANEL_URL (constant or env - e.g. a staging panel) when it
	 * is safe to put session tokens in, else admin.quissly.com.
	 *
	 * @return string
	 */
	public static function panel_url() {
		$override = defined( 'QUISSLY_PANEL_URL' ) && QUISSLY_PANEL_URL ? (string) QUISSLY_PANEL_URL : (string) getenv( 'QUISSLY_PANEL_URL' );
		$override = rtrim( trim( $override ), '/' );

		return ( '' !== $override && self::is_safe_panel_url( $override ) ) ? $override : self::DEFAULT_PANEL_URL;
	}

	/**
	 * Whether session tokens may ride in a URL to this host: https, or plain http only to this
	 * machine (a local panel build). PURE + unit-testable.
	 *
	 * @param string $url Candidate panel URL.
	 * @return bool
	 */
	public static function is_safe_panel_url( $url ) {
		$parts = parse_url( (string) $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- pure, unit-tested without WordPress.
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['scheme'] ) ) {
			return false;
		}
		$scheme = strtolower( $parts['scheme'] );
		$host   = strtolower( $parts['host'] );

		return 'https' === $scheme || ( 'http' === $scheme && in_array( $host, array( 'localhost', '127.0.0.1', '::1', '[::1]' ), true ) );
	}

	/**
	 * The iframe URL: the panel, bootstrapped with the session. PURE + unit-testable.
	 *
	 * @param string $panel_url     Panel base URL.
	 * @param string $access_token  Session access token.
	 * @param string $refresh_token Session refresh token.
	 * @return string
	 */
	public static function embed_url( $panel_url, $access_token, $refresh_token ) {
		return rtrim( (string) $panel_url, '/' ) . '/?' . http_build_query(
			array(
				'access_token'  => (string) $access_token,
				'refresh_token' => (string) $refresh_token,
			),
			'',
			'&',
			PHP_QUERY_RFC3986
		);
	}

	/**
	 * A failed session, shaped like a successful one.
	 *
	 * @param string $error Error code.
	 * @return array{ok:bool,access_token:string,refresh_token:string,error:string}
	 */
	private static function failure( $error ) {
		return array( 'ok' => false, 'access_token' => '', 'refresh_token' => '', 'error' => $error );
	}
}
