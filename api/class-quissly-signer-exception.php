<?php
/**
 * Signing failure exception.
 *
 * @package Quissly_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thrown by Quissly_Signer when openssl_sign() fails - a corrupt/invalid stored private
 * key, not a transport or credentials problem. Distinguishing this from a real 401 is the
 * point: unchecked, a signing failure used to produce a silent EMPTY signature, which
 * Quissly then rejected with a plain 401 indistinguishable from a bad token or an
 * unregistered key. Quissly_Http_Client catches this and reports it as a WP_Error, the
 * same shape every caller already treats as "fall back / show clearly" - no caller needed
 * to change to get a clear message instead of a mystery 401.
 */
class Quissly_Signer_Exception extends \Exception {
}
