<?php
/**
 * Account provisioning seam — the swappable boundary between the wizard and Quissly.
 *
 * @package Quissly_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates the store's Quissly account so the merchant never has to leave wp-admin.
 *
 * Mirrors the other seams in this plugin (Quissly_Search_Client, Quissly_Catalog_Client):
 * the wizard depends only on this interface, so the live console-signed provisioner and
 * a dev/offline mock are interchangeable via the `quissly_provisioner_client` filter. NO
 * live call is ever made by the wizard directly.
 */
interface Quissly_Provisioner_Client {

	/**
	 * Create a Quissly account for this store and register the given public key against it.
	 *
	 * @param string      $domain         Storefront host (never user-typed — resolved from
	 *                                    home_url()).
	 * @param string      $email          Account owner; also the console sign-in identity.
	 * @param string      $public_key_pem Registered as part of account creation.
	 * @param string|null $store_name     Display name in the Quissly console; null lets
	 *                                    Quissly name the account from the domain.
	 * @param string|null $first_name     Admin's first name, for the account record.
	 * @param string|null $last_name      Admin's last name, for the account record.
	 * @param string|null $description    What the store is (Quissly Setup's drafted, editable
	 *                                    description); null sends the plugin's own one-liner.
	 * @param string|null $verification_code The code Quissly mailed to $email (send_code()); its
	 *                                    sign-up refuses without it since 2026-10-19.
	 * @return array{ok:bool,api_key:string,project_id:string,store_id:string,error:string}
	 */
	public function provision( $domain, $email, $public_key_pem, $store_name, $first_name, $last_name, $description = null, $verification_code = null );

	/**
	 * Ask Quissly to mail a sign-up code to $email for this store's domain (six digits, ten
	 * minutes, five tries, a minute before a resend).
	 *
	 * @param string $domain Storefront host, the one provision() will send.
	 * @param string $email  The address the account will be created with.
	 * @return array{ok:bool,error:string} error in Quissly's own words when it gave some.
	 */
	public function send_code( $domain, $email );
}
