<?php
/**
 * Mock account provisioner — for OFFLINE wizard development/testing.
 *
 * @package Quissly_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * DEV/TEST stand-in for the live provisioner. Makes NO network call: it records every
 * call it is handed (so tests can assert the exact domain/email/key/name sent) and
 * returns either a canned success or a canned failure.
 */
class Quissly_Mock_Provisioner_Client implements Quissly_Provisioner_Client {

	/**
	 * Whether provision() should report success.
	 *
	 * @var bool
	 */
	private $succeed;

	/**
	 * Error detail to return when simulating a failure.
	 *
	 * @var string
	 */
	private $error;

	/**
	 * Canned successful response fields.
	 *
	 * @var array{api_key:string,project_id:string,store_id:string}
	 */
	private $response;

	/**
	 * Recorded calls, each the full argument list passed to provision().
	 *
	 * @var array<int,array>
	 */
	public $calls = array();

	/**
	 * @param bool   $succeed  Whether provision() reports success.
	 * @param string $error    Error detail when $succeed is false.
	 * @param array  $response Overrides for the canned success fields.
	 */
	public function __construct( $succeed = true, $error = 'mock_error', array $response = array() ) {
		$this->succeed  = (bool) $succeed;
		$this->error    = (string) $error;
		$this->response = array_merge(
			array(
				'api_key'    => 'mock-api-key',
				'project_id' => 'mock-project-id',
				'store_id'   => 'mock-store-id',
			),
			$response
		);
	}

	/**
	 * @param string      $domain         Storefront host.
	 * @param string      $email          Account owner.
	 * @param string      $public_key_pem Registered as part of creation.
	 * @param string|null $store_name     Display name, or null.
	 * @param string|null $first_name     Admin's first name, or null.
	 * @param string|null $last_name      Admin's last name, or null.
	 * @param string|null $description    What the store is, or null for the plugin's one-liner.
	 * @return array{ok:bool,api_key:string,project_id:string,store_id:string,error:string}
	 */
	public function provision( $domain, $email, $public_key_pem, $store_name, $first_name, $last_name, $description = null ) {
		$this->calls[] = array(
			'domain'         => $domain,
			'email'          => $email,
			'public_key_pem' => $public_key_pem,
			'store_name'     => $store_name,
			'first_name'     => $first_name,
			'description'    => $description,
			'last_name'      => $last_name,
		);

		if ( ! $this->succeed ) {
			return array(
				'ok'         => false,
				'api_key'    => '',
				'project_id' => '',
				'store_id'   => '',
				'error'      => $this->error,
			);
		}

		return array(
			'ok'         => true,
			'api_key'    => $this->response['api_key'],
			'project_id' => $this->response['project_id'],
			'store_id'   => $this->response['store_id'],
			'error'      => '',
		);
	}
}
