<?php
/**
 * Key store — RSA keygen + AES-encrypted private-key storage (Mechanism 2).
 *
 * @package Quissly_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generates the merchant's RSA keypair and stores the private key AES-256-GCM encrypted
 * in the options table (Mechanism 2, shipped). The dev `.env` override (Mechanism 1)
 * takes precedence when present, so dev signing uses a pre-registered key.
 *
 * The private key is decrypted in memory only, at read time, and is never logged.
 */
class Quissly_Key_Store {

	const OPT_PRIVATE = 'quissly_encrypted_private_key';
	const OPT_PUBLIC  = 'quissly_public_key';
	const OPT_TOKEN   = 'quissly_encrypted_token';

	/**
	 * Generate a fresh RSA-2048 keypair.
	 *
	 * @return array{private:string,public:string}
	 */
	public function generate_keypair() {
		$res = openssl_pkey_new(
			array(
				'private_key_bits' => 2048,
				'private_key_type' => OPENSSL_KEYTYPE_RSA,
			)
		);
		openssl_pkey_export( $res, $private );
		$details = openssl_pkey_get_details( $res );

		return array(
			'private' => $private,
			'public'  => $details['key'],
		);
	}

	/**
	 * Generate a keypair, store it (private encrypted), and return the PUBLIC key.
	 *
	 * @return string Public key PEM (to upload at admin.quissly.com).
	 */
	public function generate_and_store() {
		$keypair = $this->generate_keypair();
		update_option( self::OPT_PRIVATE, Quissly_Key_Crypto::encrypt( $keypair['private'], $this->secret() ), false );
		update_option( self::OPT_PUBLIC, $keypair['public'], false );

		return $keypair['public'];
	}

	/**
	 * Whether an encrypted private key is stored in the DB (Mechanism 2).
	 *
	 * @return bool
	 */
	public function has_stored_key() {
		return (bool) get_option( self::OPT_PRIVATE );
	}

	/**
	 * Whether the dev `.env` override is supplying the private key (Mechanism 1).
	 *
	 * @return bool
	 */
	public function is_dev_override_active() {
		return null !== Quissly_Env::dev_private_pem();
	}

	/**
	 * The private key PEM for signing: dev override first, else decrypted from DB.
	 *
	 * @return string|null
	 */
	public function get_private_key() {
		$dev = Quissly_Env::dev_private_pem();
		if ( null !== $dev ) {
			return $dev;
		}

		$cipher = get_option( self::OPT_PRIVATE );
		if ( ! $cipher ) {
			return null;
		}
		$pem = Quissly_Key_Crypto::decrypt( $cipher, $this->secret() );

		return false === $pem ? null : $pem;
	}

	/**
	 * The public key PEM (stored, or derived from the dev override key).
	 *
	 * @return string|null
	 */
	public function get_public_key() {
		$stored = get_option( self::OPT_PUBLIC );
		if ( $stored ) {
			return $stored;
		}

		$private = $this->get_private_key();
		if ( $private ) {
			$res = openssl_pkey_get_private( $private );
			if ( $res ) {
				$details = openssl_pkey_get_details( $res );

				return isset( $details['key'] ) ? $details['key'] : null;
			}
		}

		return null;
	}

	/**
	 * Remove stored keys (uninstall path).
	 */
	public function delete_keys() {
		delete_option( self::OPT_PRIVATE );
		delete_option( self::OPT_PUBLIC );
	}

	/**
	 * The bearer token, decrypted. '' if none stored or decryption fails (a wrong/rotated
	 * secret, same failure mode as get_private_key() - fails closed, never returns
	 * garbage as if it were a real token).
	 *
	 * @return string
	 */
	public function get_token() {
		$cipher = get_option( self::OPT_TOKEN );
		if ( ! $cipher ) {
			return '';
		}
		$token = Quissly_Key_Crypto::decrypt( $cipher, $this->secret() );

		return false === $token ? '' : $token;
	}

	/**
	 * Encrypt and store the bearer token - the same AES-256-GCM-at-rest treatment the
	 * private key already gets, keyed off the same wp-config secret. An empty value
	 * clears the stored token (matches how every other text setting behaves on a blank
	 * submission - there is no separate "leave blank to keep the current value" UI here).
	 *
	 * @param string $token Plaintext bearer token.
	 */
	public function set_token( $token ) {
		$token = (string) $token;
		if ( '' === $token ) {
			delete_option( self::OPT_TOKEN );
			return;
		}
		update_option( self::OPT_TOKEN, Quissly_Key_Crypto::encrypt( $token, $this->secret() ), false );
	}

	/**
	 * Whether a token is stored but the current secret can't decrypt it - the wp-config
	 * AUTH_KEY/SECURE_AUTH_KEY salts rotated since it was saved. Distinct from "no token
	 * configured": the admin UI uses this to show a clear "re-enter your token" prompt
	 * instead of a generic "not configured" that hides the actual cause.
	 *
	 * @return bool
	 */
	public function is_token_undecryptable() {
		$cipher = get_option( self::OPT_TOKEN );

		return (bool) $cipher && '' === $this->get_token();
	}

	/**
	 * Whether a stored private key exists but the current secret can't decrypt it - same
	 * salt-rotation failure mode as is_token_undecryptable(), for the key.
	 *
	 * @return bool
	 */
	public function is_private_key_undecryptable() {
		if ( null !== Quissly_Env::dev_private_pem() ) {
			return false; // the dev override bypasses stored-key decryption entirely.
		}
		$cipher = get_option( self::OPT_PRIVATE );

		return (bool) $cipher && null === $this->get_private_key();
	}

	/**
	 * Encryption secret derived from wp-config secrets.
	 *
	 * @return string
	 */
	private function secret() {
		$secret = '';
		if ( defined( 'AUTH_KEY' ) ) {
			$secret .= AUTH_KEY;
		}
		if ( defined( 'SECURE_AUTH_KEY' ) ) {
			$secret .= SECURE_AUTH_KEY;
		}

		return $secret;
	}
}
