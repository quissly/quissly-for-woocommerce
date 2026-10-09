<?php
/**
 * Automatic updates from the plugin's public GitHub repository.
 *
 * Once a day the plugin asks GitHub for the repository's latest release - and every HINT_EVERY
 * it reads just the version line of the repository's main file (raw.githubusercontent.com,
 * GitHub's CDN: no API rate limit), so a new release is noticed within minutes instead of a day
 * (hint()). A newer version is
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
	 * The repository's main file on GitHub's CDN: its `Version:` line is the latest version
	 * pushed. Only a hint - what is installed still comes from the signed release.
	 */
	const HINT_URL = 'https://raw.githubusercontent.com/quissly/quissly-for-woocommerce/main/quissly-for-woocommerce.php';

	/** How often the hint is read: 2 minutes while the update flow is being tested (then 30). */
	const HINT_EVERY = 120;

	/** The WP-Cron event that reads it. */
	const HINT_HOOK = 'quissly_update_hint';

	/**
	 * Hook into WordPress's updater.
	 */
	public static function register() {
		add_filter( 'update_plugins_github.com', array( __CLASS__, 'offer' ), 10, 3 );
		add_filter( 'auto_update_plugin', array( __CLASS__, 'auto_update' ), 10, 2 );
		add_filter( 'plugins_api', array( __CLASS__, 'details' ), 10, 3 );
		add_filter( 'upgrader_pre_download', array( __CLASS__, 'download' ), 10, 3 );
		add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected -- HINT_EVERY, 2 minutes while testing.
		add_action( self::HINT_HOOK, array( __CLASS__, 'hint' ) );
		add_action( 'init', array( __CLASS__, 'schedule_hint' ) );
	}

	/**
	 * `cron_schedules`: the hint's interval.
	 *
	 * @param array $schedules WordPress's schedules.
	 * @return array
	 */
	public static function cron_schedules( $schedules ) {
		$schedules[ self::HINT_HOOK ] = array(
			'interval' => self::HINT_EVERY,
			'display'  => 'Quissly update hint',
		);

		return $schedules;
	}

	/**
	 * Keep the hint scheduled (WP-Cron; a store without visitors runs it on its next visit).
	 */
	public static function schedule_hint() {
		if ( ! wp_next_scheduled( self::HINT_HOOK ) ) {
			wp_schedule_event( time() + self::HINT_EVERY, self::HINT_HOOK, self::HINT_HOOK );
		}
	}

	/**
	 * Stop reading the hint (deactivation, uninstall).
	 */
	public static function unschedule_hint() {
		wp_clear_scheduled_hook( self::HINT_HOOK );
	}

	/**
	 * The WP-Cron event: when GitHub's main file names a version newer than this one (and newer
	 * than the release already known), ask GitHub for the release now instead of at the day's
	 * end, refresh WordPress's update data, and run WordPress's background installer - the same
	 * signed path a daily check takes, minutes after the release instead of up to a day and a
	 * half. A version pushed but not released yet (the workflow takes a minute or two) is asked
	 * for again on the next tick; nothing is kept for a day.
	 *
	 * @return string What happened.
	 */
	public static function hint() {
		if ( self::is_checkout() ) {
			return 'git_checkout';
		}
		$hinted = self::hinted_version();
		if ( null === $hinted ) {
			return 'no_hint';
		}
		$cached = get_site_transient( self::CACHE );
		$known  = is_array( $cached ) && is_array( $cached['release'] ?? null ) ? (string) $cached['release']['version'] : '';
		if ( ! self::is_news( $hinted, QUISSLY_VERSION, $known ) ) {
			return 'nothing_new';
		}
		delete_site_transient( self::CACHE );
		$release = self::latest();
		if ( null === $release || version_compare( $release['version'], $hinted, '<' ) ) {
			delete_site_transient( self::CACHE );
			return 'release_not_ready';
		}
		// WordPress's own update data, then its background installer: the normal path, now.
		delete_site_transient( 'update_plugins' );
		wp_update_plugins();
		if ( function_exists( 'wp_maybe_auto_update' ) ) {
			wp_maybe_auto_update();
		}

		return 'updating:' . $release['version'];
	}

	/**
	 * Whether a hinted version is worth asking GitHub about: newer than the installed one and
	 * than the release already known (so a release that cannot be installed is not asked for
	 * every few minutes). Pure.
	 *
	 * @param string $hinted    The version on GitHub's main file.
	 * @param string $installed The running version.
	 * @param string $known     The latest release already known ('' = none).
	 * @return bool
	 */
	public static function is_news( $hinted, $installed, $known ) {
		return version_compare( $hinted, $installed, '>' ) && ( '' === $known || version_compare( $hinted, $known, '>' ) );
	}

	/**
	 * The `Version:` header in a plugin file's opening bytes, or null. Pure.
	 *
	 * @param string $text File contents (or their start).
	 * @return string|null
	 */
	public static function version_header( $text ) {
		return preg_match( '/^[ \t\/*#@]*Version:[ \t]*(\d+\.\d+\.\d+)[ \t]*$/mi', (string) $text, $m ) ? $m[1] : null;
	}

	/**
	 * The version GitHub's main file names, or null when it cannot be read.
	 *
	 * @return string|null
	 */
	private static function hinted_version() {
		$response = wp_remote_get(
			defined( 'QUISSLY_UPDATE_HINT_URL' ) ? (string) QUISSLY_UPDATE_HINT_URL : self::HINT_URL,
			array(
				'timeout' => 5,
				'headers' => array(
					'Range'      => 'bytes=0-2047', // the header block is at the top
					'User-Agent' => 'quissly-for-woocommerce/' . QUISSLY_VERSION,
				),
			)
		);
		if ( is_wp_error( $response ) || ! in_array( (int) wp_remote_retrieve_response_code( $response ), array( 200, 206 ), true ) ) {
			return null;
		}

		return self::version_header( (string) wp_remote_retrieve_body( $response ) );
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
