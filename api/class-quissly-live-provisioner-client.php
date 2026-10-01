<?php
/**
 * Live account provisioner — one call to console.quissly.com creates the account.
 *
 * @package Quissly_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * POSTs to the same open-source provisioning endpoint the Magento plugin's Provisioner
 * uses (`/api/v1/services/external/open-source` on console.quissly.com, a DIFFERENT host
 * from the api.quissly.com search/catalog API — see Quissly_Http_Client::BASE_URL). The
 * endpoint takes exactly what WordPress already knows — its own domain, an admin email,
 * and a public key the plugin generated — and returns a bearer token and account ids.
 *
 * Creating an account is NOT idempotent: calling it twice makes a second tenant and
 * strands the first along with everything already synced to it. The caller (the wizard)
 * must refuse when a token is already stored.
 */
class Quissly_Live_Provisioner_Client implements Quissly_Provisioner_Client {

	const PATH                = '/api/v1/services/external/open-source';
	const DEFAULT_CONSOLE_URL = 'https://console.quissly.com';

	/**
	 * Account creation does real work upstream; a search-request budget is meaningless
	 * here (the Magento plugin's Provisioner found 60s was not enough — provisioning
	 * regularly outran it). Still a synchronous request: the merchant's own web-server
	 * timeout and PHP max_execution_time can cut it shorter than this.
	 */
	const TIMEOUT_SECONDS = 300;

	/**
	 * The console base URL: a dev override first (QUISSLY_CONSOLE_URL constant or env
	 * var, same two-source pattern as every other dev override in Quissly_Env), else the
	 * default. Not a stored option — v1 has no admin UI field for this.
	 *
	 * @return string
	 */
	public static function console_url() {
		if ( defined( 'QUISSLY_CONSOLE_URL' ) && QUISSLY_CONSOLE_URL ) {
			return QUISSLY_CONSOLE_URL;
		}
		$env = getenv( 'QUISSLY_CONSOLE_URL' );
		if ( $env ) {
			return $env;
		}

		return self::DEFAULT_CONSOLE_URL;
	}

	/**
	 * @param string      $domain         Storefront host.
	 * @param string      $email          Account owner.
	 * @param string      $public_key_pem Registered as part of creation.
	 * @param string|null $store_name     Display name, or null.
	 * @param string|null $first_name     Admin's first name, or null.
	 * @param string|null $last_name      Admin's last name, or null.
	 * @return array{ok:bool,api_key:string,project_id:string,store_id:string,error:string}
	 */
	public function provision( $domain, $email, $public_key_pem, $store_name, $first_name, $last_name ) {
		$payload = array(
			'domain'            => $domain,
			'public_key'        => $public_key_pem,
			'email'             => $email,
			'platform'          => Quissly_Http_Client::X_PLATFORM,
			'service_type_slug' => 'qsearch',
			// null lets Quissly name the account from the domain instead — sending a
			// generic WordPress default (see Quissly_Wizard::store_display_name()) would
			// name every fresh install's account the same generic thing.
			'name'              => $store_name,
			'description'       => 'WooCommerce store connected via quissly-for-woocommerce.',
			'environment'       => Quissly_Env::environment(),
		);

		// Who connected, so the account is not anonymous in the console. Sent only when
		// known: the endpoint accepts unknown/empty values silently, so an absent field
		// fails quietly rather than loudly - exactly the case for leaving it out.
		$display_name = trim( sprintf( '%s %s', (string) $first_name, (string) $last_name ) );
		if ( null !== $first_name && '' !== $first_name ) {
			$payload['first_name'] = $first_name;
		}
		if ( null !== $last_name && '' !== $last_name ) {
			$payload['last_name'] = $last_name;
		}
		if ( '' !== $display_name ) {
			$payload['display_name'] = $display_name;
		}

		$response = wp_remote_post(
			self::console_url() . self::PATH,
			array(
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $payload ),
				'timeout' => self::TIMEOUT_SECONDS,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $this->failure( $response->get_error_message() );
		}

		$status  = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $status || ! is_array( $decoded ) ) {
			// The detail is Quissly's own wording (e.g. a domain already registered); it
			// is the only useful thing to show the merchant.
			$detail = is_array( $decoded ) ? (string) ( isset( $decoded['detail'] ) ? $decoded['detail'] : '' ) : '';

			/* translators: %d: HTTP status code. */
			return $this->failure( '' !== $detail ? $detail : sprintf( __( 'unexpected response (HTTP %d)', 'quissly-for-woocommerce' ), $status ) );
		}

		$api_key    = isset( $decoded['api_key'] ) ? (string) $decoded['api_key'] : '';
		$project_id = isset( $decoded['project_id'] ) ? (string) $decoded['project_id'] : '';
		$store_id   = isset( $decoded['store_id'] ) ? (string) $decoded['store_id'] : '';

		if ( '' === $api_key || '' === $project_id ) {
			return $this->failure( __( 'unexpected response (no credentials)', 'quissly-for-woocommerce' ) );
		}

		return array(
			'ok'         => true,
			'api_key'    => $api_key,
			// Falls back to the project id: an empty value stored would be worse than
			// the known copy (the Magento plugin's Provisioner does the same — the two
			// have always been observed equal there).
			'store_id'   => '' !== $store_id ? $store_id : $project_id,
			'project_id' => $project_id,
			'error'      => '',
		);
	}

	/**
	 * A failed provisioning, shaped like a successful one.
	 *
	 * @param string $error Error detail.
	 * @return array{ok:bool,api_key:string,project_id:string,store_id:string,error:string}
	 */
	private function failure( $error ) {
		return array(
			'ok'         => false,
			'api_key'    => '',
			'project_id' => '',
			'store_id'   => '',
			'error'      => $error,
		);
	}
}
