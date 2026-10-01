<?php
/**
 * Mock QChat agent-id lookup — for OFFLINE wizard development/testing.
 *
 * @package Quissly_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * DEV/TEST stand-in for the live resolver. Makes NO network call: records every call
 * it is handed and returns a canned agent id (or null, to simulate "not resolvable yet").
 */
class Quissly_Mock_Service_Directory_Client implements Quissly_Service_Directory_Client {

	/**
	 * Agent id to return, or null to simulate a failed/unresolvable lookup.
	 *
	 * @var string|null
	 */
	private $agent_id;

	/**
	 * Recorded calls, each {project_id, email, api_key}.
	 *
	 * @var array<int,array>
	 */
	public $calls = array();

	/**
	 * @param string|null $agent_id Canned agent id, or null to simulate failure.
	 */
	public function __construct( $agent_id = '11111111-2222-3333-4444-555555555555' ) {
		$this->agent_id = $agent_id;
	}

	/**
	 * @param string $project_id Quissly project id.
	 * @param string $email      Account email.
	 * @param string $api_key    Bearer token.
	 * @return string|null
	 */
	public function qchat_agent_id( $project_id, $email, $api_key ) {
		$this->calls[] = array(
			'project_id' => $project_id,
			'email'      => $email,
			'api_key'    => $api_key,
		);

		return $this->agent_id;
	}

	/**
	 * @param string $project_id Quissly project id.
	 * @param string $email      Account email.
	 * @param string $api_key    Bearer token.
	 * @return string|null
	 */
	public function qsearch_namespace( $project_id, $email, $api_key ) {
		return '6ba7b810-9dad-11d1-80b4-00c04fd430c8';
	}
}
