<?php
/**
 * Live connection probe (shared by the CLI and the admin).
 *
 * @package Quissly_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A single source of truth for "is Quissly reachable with these credentials": a minimal
 * signed v2 qsearch (HTTP 200 — even with zero results — means the key/token/environment
 * line up). Used by `wp quissly test-connection`, the wizard's test step, and the dashboard
 * "Test connection" button, so they can never drift.
 *
 * Includes a body `user_id` (required by the API until 2026-09-18, still sent); a fixed probe
 * id is used since there is no visitor.
 */
class Quissly_Connection {

	const PROBE_USER_ID = 'quissly-connection-probe';

	/**
	 * Run the probe.
	 *
	 * @param int $timeout Seconds.
	 * @return array{ok:bool,configured:bool,code:int,message:string}
	 */
	public static function probe( $timeout = 8 ) {
		$store   = new Quissly_Key_Store();
		$private  = $store->get_private_key();
		$token    = Quissly_Env::token();

		// A stored credential that fails to decrypt (the wp-config AUTH_KEY/SECURE_AUTH_KEY
		// salts rotated since it was saved) is a DIFFERENT story from never having
		// configured one - "not configured yet" would tell a merchant who already did the
		// setup to redo the whole wizard. Nor can the merchant fix it themselves: the settings
		// no longer show the credentials, and connecting again is refused on a connected
		// store (it would abandon the synced catalog). Quissly support can, so the message
		// sends them there with the project id that identifies the account. Checked before
		// the generic missing-credentials branch so it's never masked by that message.
		if ( $store->is_token_undecryptable() || $store->is_private_key_undecryptable() ) {
			$broken = array();
			if ( $store->is_private_key_undecryptable() ) {
				$broken[] = __( 'API key', 'quissly-for-woocommerce' );
			}
			if ( $store->is_token_undecryptable() ) {
				$broken[] = __( 'bearer token', 'quissly-for-woocommerce' );
			}

			$project_id = (string) Quissly_Settings::get( 'quissly_project_id' );
			$message    = sprintf(
				/* translators: %s: comma-separated credentials that can no longer be read. */
				__( 'Stored %s can no longer be read (your site\'s security keys changed since it was saved).', 'quissly-for-woocommerce' ),
				implode( ', ', $broken )
			);
			$message   .= ' ' . ( '' !== $project_id
				/* translators: %s: the store's Quissly project id. */
				? sprintf( __( 'Contact Quissly support to reconnect this store, quoting project id %s.', 'quissly-for-woocommerce' ), $project_id )
				: __( 'Contact Quissly support to reconnect this store.', 'quissly-for-woocommerce' ) );

			return array(
				'ok'         => false,
				'configured' => false,
				'code'       => 0,
				'message'    => $message,
			);
		}

		if ( ! $private || '' === $token ) {
			$missing = array();
			if ( ! $private ) {
				$missing[] = __( 'API key', 'quissly-for-woocommerce' );
			}
			if ( '' === $token ) {
				$missing[] = __( 'bearer token', 'quissly-for-woocommerce' );
			}

			return array(
				'ok'         => false,
				'configured' => false,
				'code'       => 0,
				/* translators: %s: comma-separated missing items. */
				'message'    => sprintf( __( 'Not configured yet — missing: %s.', 'quissly-for-woocommerce' ), implode( ', ', $missing ) ),
			);
		}

		$client = new Quissly_Http_Client( $token, Quissly_Env::environment(), new Quissly_Signer( $private ) );
		$res    = $client->post_v2(
			'/v2beta/qsearch',
			array(
				'query'            => 'test',
				'user_id'          => self::PROBE_USER_ID, // REQUIRED by the live API.
				'include_metadata' => false,
				'channel'          => 'web',
				'page_number'      => 1,
				'page_size'        => 1,
			),
			$timeout
		);

		if ( is_wp_error( $res ) ) {
			return array(
				'ok'         => false,
				'configured' => true,
				'code'       => 0,
				/* translators: %s: transport error message. */
				'message'    => sprintf( __( 'Transport error: %s', 'quissly-for-woocommerce' ), $res->get_error_message() ),
			);
		}

		$code = (int) $res['code'];
		$ok   = 200 === $code;

		return array(
			'ok'         => $ok,
			'configured' => true,
			'code'       => $code,
			'message'    => $ok
				/* translators: %s: environment name. */
				? sprintf( __( 'Connected — a live signed search returned HTTP 200 in environment "%s".', 'quissly-for-woocommerce' ), Quissly_Env::environment() )
				/* translators: %d: HTTP status code. */
				: sprintf( __( 'Connection failed: HTTP %d. Check the token, the registered public key, and the environment.', 'quissly-for-woocommerce' ), $code ),
		);
	}
}
