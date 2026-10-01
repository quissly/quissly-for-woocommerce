<?php
/**
 * AES-256-GCM crypto core for private-key storage.
 *
 * @package Quissly_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Authenticated symmetric encryption for the merchant's private key at rest.
 *
 * Pure (openssl only) so it is unit-testable without WordPress. The WP-coupled wrapper
 * (Quissly_Key_Store) supplies the secret from wp-config (AUTH_KEY/SECURE_AUTH_KEY) and
 * persists the ciphertext in the options table. GCM gives tamper detection, so a wrong
 * secret or a modified ciphertext fails closed (returns false).
 */
class Quissly_Key_Crypto {

	const CIPHER  = 'aes-256-gcm';
	const IV_LEN  = 12;
	const TAG_LEN = 16;

	/**
	 * Encrypt plaintext. Returns base64( iv | tag | ciphertext ).
	 *
	 * @param string $plaintext Data to encrypt.
	 * @param string $secret    Secret (any length; hashed to a 256-bit key).
	 * @return string
	 */
	public static function encrypt( $plaintext, $secret ) {
		$key = self::derive_key( $secret );
		$iv  = random_bytes( self::IV_LEN );
		$tag = '';

		$ciphertext = openssl_encrypt( $plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag, '', self::TAG_LEN );

		return base64_encode( $iv . $tag . $ciphertext );
	}

	/**
	 * Decrypt a payload produced by encrypt(). Returns false on any failure
	 * (wrong secret, tampering, malformed input).
	 *
	 * @param string $payload Base64 payload.
	 * @param string $secret  Secret used at encryption time.
	 * @return string|false
	 */
	public static function decrypt( $payload, $secret ) {
		$decoded = base64_decode( $payload, true );
		if ( false === $decoded || strlen( $decoded ) < self::IV_LEN + self::TAG_LEN ) {
			return false;
		}

		$iv         = substr( $decoded, 0, self::IV_LEN );
		$tag        = substr( $decoded, self::IV_LEN, self::TAG_LEN );
		$ciphertext = substr( $decoded, self::IV_LEN + self::TAG_LEN );

		return openssl_decrypt( $ciphertext, self::CIPHER, self::derive_key( $secret ), OPENSSL_RAW_DATA, $iv, $tag );
	}

	/**
	 * Derive a 256-bit key from an arbitrary-length secret.
	 *
	 * @param string $secret Secret.
	 * @return string 32 raw bytes.
	 */
	private static function derive_key( $secret ) {
		return hash( 'sha256', $secret, true );
	}
}
