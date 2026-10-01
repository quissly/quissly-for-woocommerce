<?php
/**
 * Protected sync log (permanent failures + sync events).
 *
 * @package Quissly_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Append-only log for sync events, written to a NON-web-readable file under uploads.
 *
 * Never logs tokens, keys, or product payloads — only ids, counts, and status messages,
 * so a reader who finds this file learns nothing secret. Two independent guards, since
 * neither alone covers every host: the directory name carries a per-install suffix
 * (derived from the site's own auth salt, so it's stable but not guessable without it) -
 * this is the guard that actually works everywhere, including Nginx, which does not
 * process .htaccess at all, and Apache configured with AllowOverride None, which
 * ignores it too. The .htaccess deny + empty index.html are still written for the
 * (common) case of a plain Apache host that does honor them. The dashboard
 * reads recent lines via tail().
 */
class Quissly_Sync_Log {

	const DIR_NAME  = 'quissly-logs';
	const FILE_NAME = 'sync.log';

	/**
	 * Append a timestamped line.
	 *
	 * @param string $message Plain message (ids/counts/status only — never secrets).
	 */
	public static function log( $message ) {
		$file = self::ensure_file();
		if ( null === $file ) {
			return;
		}

		$line = '[' . gmdate( 'Y-m-d H:i:s' ) . ' UTC] ' . self::sanitize( $message ) . "\n";

		// Direct append: WP_Filesystem is geared to one-shot writes; an appending log is
		// the documented exception. Suppress to never fatal a sync on a log-write error.
		@file_put_contents( $file, $line, FILE_APPEND | LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged
	}

	/**
	 * Record permanent (post-retry) sync failures by id.
	 *
	 * @param int[]  $product_ids Ids that exhausted retries.
	 * @param string $context     Optional context (e.g. 'upsert'/'delete').
	 */
	public static function record_failures( array $product_ids, $context = 'sync' ) {
		$ids = array_filter( array_map( 'intval', $product_ids ) );
		if ( empty( $ids ) ) {
			return;
		}
		self::log( 'PERMANENT FAILURE (' . $context . ') after retries for product ids: ' . implode( ', ', $ids ) );
	}

	/**
	 * Return the last $lines lines of the log (newest last), for the dashboard viewer.
	 *
	 * @param int $lines Max lines.
	 * @return string[]
	 */
	public static function tail( $lines = 100 ) {
		$file = self::file_path();
		if ( ! is_readable( $file ) ) {
			return array();
		}
		$content = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents
		if ( false === $content || '' === $content ) {
			return array();
		}
		$all = array_values( array_filter( explode( "\n", $content ), static function ( $l ) {
			return '' !== trim( $l );
		} ) );

		return array_slice( $all, -1 * max( 1, (int) $lines ) );
	}

	/**
	 * Delete the log directory and its contents (uninstall path).
	 */
	public static function delete_log() {
		$dir = self::dir_path();
		foreach ( array( self::FILE_NAME, '.htaccess', 'index.html' ) as $name ) {
			$path = $dir . '/' . $name;
			if ( file_exists( $path ) ) {
				@unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_unlink, WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}
		if ( is_dir( $dir ) ) {
			@rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}

	/**
	 * Absolute path to the log directory.
	 *
	 * @return string
	 */
	private static function dir_path() {
		$uploads = wp_upload_dir();

		return trailingslashit( $uploads['basedir'] ) . self::DIR_NAME . '-' . self::dir_suffix();
	}

	/**
	 * A per-install, non-guessable suffix for the log directory name - stable across
	 * requests (derived from the site's own auth salt, not randomized on every call),
	 * but an outside visitor can't predict it without that salt. See the class docblock:
	 * this, not the .htaccess file, is the guard that actually works on every host.
	 *
	 * @return string
	 */
	private static function dir_suffix() {
		return substr( md5( wp_salt( 'auth' ) ), 0, 12 );
	}

	/**
	 * Absolute path to the log file.
	 *
	 * @return string
	 */
	private static function file_path() {
		return self::dir_path() . '/' . self::FILE_NAME;
	}

	/**
	 * Ensure the hardened log directory + file exist; return the file path or null.
	 *
	 * @return string|null
	 */
	private static function ensure_file() {
		$dir = self::dir_path();
		if ( ! is_dir( $dir ) ) {
			if ( ! wp_mkdir_p( $dir ) ) {
				return null;
			}
			// Harden: deny web access (Apache) + prevent directory listing.
			@file_put_contents( $dir . '/.htaccess', "Deny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged
			@file_put_contents( $dir . '/index.html', '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged
		}

		return self::file_path();
	}

	/**
	 * Strip newlines/control chars so a message can't forge log lines.
	 *
	 * @param string $message Raw message.
	 * @return string
	 */
	private static function sanitize( $message ) {
		return preg_replace( '/[\r\n\t]+/', ' ', (string) $message );
	}
}
