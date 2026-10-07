<?php
/**
 * Automatic updates from the plugin's public GitHub repository.
 *
 * Once a day the plugin asks GitHub for the repository's latest release. A newer version is
 * handed to WordPress's own update system (the `Update URI` header in the main plugin file
 * routes this plugin's update check to the `update_plugins_github.com` filter instead of
 * WordPress.org), and auto-updates are always on for this plugin - so WordPress downloads,
 * installs and, on failure, rolls back the update itself, like any other plugin's.
 *
 * Every release zip is signed (Ed25519) by the release workflow with Quissly's release key;
 * the zip is downloaded here and installed only when its signature verifies against
 * PUBLIC_KEY (`upgrader_pre_download`), so a release nobody holding that key made is never
 * installed - not even by someone who can push to the repository.
 *
 * Nothing else is installed by this class: it answers WordPress's questions ("is there a
 * newer version?", "what is in it?", "may it update itself?") and checks the download.
 *
 * @package Quissly_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Offers GitHub releases to WordPress's updater.
 */
final class Quissly_Updater {

	/** The public repository releases are published to. */
	const REPOSITORY = 'quissly/quissly-for-woocommerce';

	/** The release asset WordPress installs (its top folder is the plugin's folder). */
	const ASSET = 'quissly-for-woocommerce.zip';

	/** Quissly's release-signing public key (Ed25519, base64): ASSET.sig is the zip's signature. */
	const PUBLIC_KEY = 'zAXZVzzPPk0LR8MjMrnoTZi96kcvNUvolgQ6fax9c4E=';

	/** Site transient holding the last answer from GitHub. */
	const CACHE = 'quissly_update_release';

	/** How long an answer is kept: the check runs once a day. */
	const CHECK_EVERY = DAY_IN_SECONDS;

	/** A failed check is tried again sooner. */
	const RETRY_AFTER = HOUR_IN_SECONDS;

	/** The plugin's slug (its folder), as WordPress's update screens name it. */
	const SLUG = 'quissly-for-woocommerce';

	/**
	 * Hook into WordPress's updater.
	 */
	public static function register() {
		add_filter( 'update_plugins_github.com', array( __CLASS__, 'offer' ), 10, 3 );
		add_filter( 'auto_update_plugin', array( __CLASS__, 'auto_update' ), 10, 2 );
		add_filter( 'plugins_api', array( __CLASS__, 'details' ), 10, 3 );
		add_filter( 'upgrader_pre_download', array( __CLASS__, 'download' ), 10, 3 );
	}

	/**
	 * `upgrader_pre_download`: download this plugin's release zip and hand it to WordPress only
	 * when its signature verifies; any other package is left to WordPress.
	 *
	 * @param false|string|WP_Error $reply   What an earlier filter returned.
	 * @param string                $package The package URL being installed.
	 * @param object                $upgrader The upgrader.
	 * @return false|string|WP_Error The verified zip's local path, or why it was refused.
	 */
	public static function download( $reply, $package, $upgrader ) {
		if ( false !== $reply ) {
			return $reply;
		}
		$release = self::latest();
		if ( null === $release || $package !== $release['package'] ) {
			return $reply;
		}
		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$signature = '' !== ( $release['signature'] ?? '' ) ? wp_remote_get( $release['signature'], array( 'timeout' => 15 ) ) : null;
		if ( null === $signature ) {
			return new WP_Error( 'quissly_no_signature', __( 'The Quissly update\'s signature could not be downloaded, so it was not installed.', 'quissly-for-woocommerce' ) );
		}
		if ( is_wp_error( $signature ) || 200 !== (int) wp_remote_retrieve_response_code( $signature ) ) {
			return new WP_Error( 'quissly_no_signature', __( 'The Quissly update\'s signature could not be downloaded, so it was not installed.', 'quissly-for-woocommerce' ) );
		}
		$file = download_url( $package, 300 );
		if ( is_wp_error( $file ) ) {
			return $file;
		}
		if ( ! self::signature_valid( (string) file_get_contents( $file ), trim( (string) wp_remote_retrieve_body( $signature ) ), self::PUBLIC_KEY ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local temp file.
			wp_delete_file( $file );

			return new WP_Error( 'quissly_bad_signature', __( 'The Quissly update is not signed by Quissly, so it was not installed.', 'quissly-for-woocommerce' ) );
		}

		return $file;
	}

	/**
	 * Whether $signature (base64) is a valid Ed25519 signature of $data by $public_key
	 * (base64). Pure. WordPress bundles sodium_compat, so the check runs on any PHP.
	 *
	 * @param string $data       The signed bytes (the zip).
	 * @param string $signature  The detached signature, base64.
	 * @param string $public_key The public key, base64.
	 * @return bool
	 */
	public static function signature_valid( $data, $signature, $public_key ) {
		$sig = base64_decode( $signature, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- a signature, not code.
		$key = base64_decode( $public_key, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- a public key, not code.
		if ( false === $sig || false === $key || 64 !== strlen( $sig ) || 32 !== strlen( $key ) || ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
			return false;
		}
		try {
			return sodium_crypto_sign_verify_detached( $sig, $data, $key );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * `update_plugins_github.com`: the latest release, for WordPress to compare with the
	 * installed version (WordPress itself decides whether it is newer).
	 *
	 * @param array|false $update      What an earlier filter returned.
	 * @param array       $plugin_data The plugin's header data.
	 * @param string      $plugin_file The plugin's basename.
	 * @return array|false
	 */
	public static function offer( $update, $plugin_data, $plugin_file ) {
		if ( QUISSLY_PLUGIN_BASENAME !== $plugin_file || self::is_checkout() ) {
			return $update;
		}
		$release = self::latest();
		if ( null === $release ) {
			return $update;
		}

		return array(
			'slug'         => self::SLUG,
			'version'      => $release['version'],
			'url'          => 'https://github.com/' . self::REPOSITORY,
			'package'      => $release['package'],
			'requires_php' => '7.4',
		);
	}

	/**
	 * `auto_update_plugin`: this plugin always updates itself.
	 *
	 * @param bool|null $update Whether to update.
	 * @param object    $item   The update offer.
	 * @return bool|null
	 */
	public static function auto_update( $update, $item ) {
		if ( is_object( $item ) && isset( $item->plugin ) && QUISSLY_PLUGIN_BASENAME === $item->plugin ) {
			return true;
		}

		return $update;
	}

	/**
	 * `plugins_api`: the "View version details" window for this plugin, from the release.
	 *
	 * @param false|object|array $result What an earlier filter returned.
	 * @param string             $action The information asked for.
	 * @param object             $args   The request, with the slug.
	 * @return false|object|array
	 */
	public static function details( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! is_object( $args ) || ( $args->slug ?? '' ) !== self::SLUG ) {
			return $result;
		}
		$release = self::latest();
		if ( null === $release ) {
			return $result;
		}

		return (object) array(
			'name'          => 'Quissly for WooCommerce',
			'slug'          => self::SLUG,
			'version'       => $release['version'],
			'author'        => 'Quissly',
			'homepage'      => 'https://github.com/' . self::REPOSITORY,
			'download_link' => $release['package'],
			'requires_php'  => '7.4',
			'sections'      => array(
				'changelog' => '' !== $release['notes'] ? nl2br( esc_html( $release['notes'] ) ) : esc_html__( 'See the release on GitHub.', 'quissly-for-woocommerce' ),
			),
		);
	}

	/**
	 * The latest release, asked of GitHub at most once a day.
	 *
	 * @return array{version:string, package:string, signature:string, notes:string}|null
	 */
	public static function latest() {
		$cached = get_site_transient( self::CACHE );
		if ( is_array( $cached ) ) {
			return $cached['release'] ?? null;
		}

		$response = wp_remote_get(
			self::release_url(),
			array(
				'timeout' => 10,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'quissly-for-woocommerce/' . QUISSLY_VERSION,
				),
			)
		);
		$release  = null;
		if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
			$release = self::parse_release( json_decode( (string) wp_remote_retrieve_body( $response ), true ), self::package_prefix() );
		}
		set_site_transient( self::CACHE, array( 'release' => $release ), null === $release ? self::RETRY_AFTER : self::CHECK_EVERY );

		return $release;
	}

	/**
	 * Read a GitHub "latest release" answer. Pure.
	 *
	 * The version comes from the tag (`v1.2.3` or `1.2.3`); the package is the release's
	 * plugin zip and the signature its `.sig`, both accepted only from this repository's own
	 * release downloads. A release without a signature is not offered.
	 *
	 * @param mixed  $json           The decoded answer.
	 * @param string $package_prefix What the zip's URL must start with.
	 * @return array{version:string, package:string, signature:string, notes:string}|null
	 */
	public static function parse_release( $json, $package_prefix ) {
		if ( ! is_array( $json ) || ! empty( $json['draft'] ) || ! empty( $json['prerelease'] ) ) {
			return null;
		}
		if ( ! preg_match( '/^v?(\d+\.\d+\.\d+)$/', (string) ( $json['tag_name'] ?? '' ), $m ) ) {
			return null;
		}
		$urls = array();
		foreach ( (array) ( $json['assets'] ?? array() ) as $asset ) {
			$url = is_array( $asset ) ? (string) ( $asset['browser_download_url'] ?? '' ) : '';
			if ( 0 === strpos( $url, $package_prefix ) ) {
				$urls[ (string) ( $asset['name'] ?? '' ) ] = $url;
			}
		}
		if ( ! isset( $urls[ self::ASSET ], $urls[ self::ASSET . '.sig' ] ) ) {
			return null;
		}

		return array(
			'version'   => $m[1],
			'package'   => $urls[ self::ASSET ],
			'signature' => $urls[ self::ASSET . '.sig' ],
			'notes'     => trim( (string) ( $json['body'] ?? '' ) ),
		);
	}

	/**
	 * Whether the plugin's folder is a git checkout (a developer's copy, e.g. a dev store that
	 * mounts the repository): never updated from a release - the update would replace the
	 * folder, repository and all. WordPress itself skips a site that is one; this is the
	 * plugin's own folder.
	 *
	 * @return bool
	 */
	private static function is_checkout() {
		return file_exists( QUISSLY_PLUGIN_DIR . '.git' );
	}

	/**
	 * GitHub's "latest release" address; QUISSLY_UPDATE_URL replaces it on a dev store.
	 *
	 * @return string
	 */
	private static function release_url() {
		if ( defined( 'QUISSLY_UPDATE_URL' ) ) {
			return (string) QUISSLY_UPDATE_URL;
		}

		return 'https://api.github.com/repos/' . self::REPOSITORY . '/releases/latest';
	}

	/**
	 * Where a package may come from: this repository's release downloads (or, on a dev store
	 * with QUISSLY_UPDATE_URL, that address's host).
	 *
	 * @return string
	 */
	private static function package_prefix() {
		if ( defined( 'QUISSLY_UPDATE_URL' ) ) {
			$parts = wp_parse_url( (string) QUISSLY_UPDATE_URL );

			return ( $parts['scheme'] ?? 'https' ) . '://' . ( $parts['host'] ?? '' ) . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ) . '/';
		}

		return 'https://github.com/' . self::REPOSITORY . '/releases/download/';
	}
}
