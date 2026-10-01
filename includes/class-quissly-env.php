<?php
/**
 * Configuration & dev-scaffold resolver (environment, token, dev key override).
 *
 * @package Quissly_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves runtime configuration and the dev-mode `.env` override (Mechanism 1).
 *
 * The plugin must NOT depend on `.env` for normal operation: load() is a no-op when no
 * `.env` is present (the shipped case). When a dev `.env` exists at the plugin root, it
 * populates QUISSLY_* constants so the build can be tested against a pre-registered key.
 * Multi-line PEM values are expected on a single line with literal `\n` escapes
 * (matching .env.example).
 */
class Quissly_Env {

	/**
	 * Parse the dev `.env` (if any) into QUISSLY_* constants. Never overwrites a
	 * constant already defined (e.g. in wp-config.php).
	 */
	public static function load() {
		$file = QUISSLY_PLUGIN_DIR . '.env';
		if ( ! is_readable( $file ) ) {
			return;
		}

		foreach ( file( $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) as $line ) {
			$line = trim( $line );
			if ( '' === $line || '#' === $line[0] || ! str_contains( $line, '=' ) ) {
				continue;
			}

			list( $key, $value ) = explode( '=', $line, 2 );
			$key = trim( $key );
			// Allow QUISSLY_* plus the dev-only QCHAT_SERVICE_UUID (the dev QChat agent-id source).
			if ( 0 !== strncmp( $key, 'QUISSLY_', 8 ) && 'QCHAT_SERVICE_UUID' !== $key ) {
				continue;
			}

			$value = trim( $value );
			if ( strlen( $value ) >= 2 && '"' === $value[0] && '"' === substr( $value, -1 ) ) {
				$value = str_replace( '\n', "\n", substr( $value, 1, -1 ) );
			} elseif ( strlen( $value ) >= 2 && "'" === $value[0] && "'" === substr( $value, -1 ) ) {
				$value = substr( $value, 1, -1 );
			}

			if ( ! defined( $key ) ) {
				define( $key, $value );
			}
		}
	}

	/**
	 * X-Environment value sent for BOTH v1 catalog and v2 search. Delegates to
	 * current_environment(); kept as the stable name used across the codebase.
	 *
	 * @return string
	 */
	public static function environment() {
		return self::current_environment();
	}

	/**
	 * Resolve the Quissly environment, auto-detecting from WP_ENVIRONMENT_TYPE with explicit
	 * overrides on top. Precedence (highest first):
	 *   1. QUISSLY_ENV  — the dev `.env` scaffold (or a wp-config constant / env var). Also the
	 *      only way to reach `demo`/`test`, which no WP type maps to.
	 *   2. the stored `quissly_environment` option — an explicit admin setting (also reaches
	 *      demo/test).
	 *   3. wp_get_environment_type() auto-mapping (see map_wp_environment()).
	 * One Quissly key is registered for all 5 envs, so switching env never breaks auth.
	 *
	 * @return string One of dev|demo|test|stage|prod (overrides pass through verbatim).
	 */
	public static function current_environment() {
		$override = '';
		if ( defined( 'QUISSLY_ENV' ) && QUISSLY_ENV ) {
			$override = (string) QUISSLY_ENV;
		} else {
			$env_var = getenv( 'QUISSLY_ENV' );
			if ( false !== $env_var ) {
				$override = (string) $env_var;
			}
		}

		$stored  = (string) get_option( 'quissly_environment', '' );
		$wp_type = function_exists( 'wp_get_environment_type' ) ? (string) wp_get_environment_type() : '';

		return self::resolve_environment( $override, $stored, $wp_type );
	}

	/**
	 * Pure precedence resolver: an explicit override (QUISSLY_ENV) wins, else the stored
	 * setting, else the WP_ENVIRONMENT_TYPE auto-mapping. Explicit overrides pass through
	 * verbatim (trimmed) so demo/test/stage/dev/prod can all be chosen. PURE + unit-testable.
	 *
	 * @param string $override Tier-1 override (QUISSLY_ENV constant/env var).
	 * @param string $stored   Tier-2 stored setting (quissly_environment option).
	 * @param string $wp_type  WP_ENVIRONMENT_TYPE for the tier-3 auto-map.
	 * @return string
	 */
	public static function resolve_environment( $override, $stored, $wp_type ) {
		$override = trim( (string) $override );
		if ( '' !== $override ) {
			return $override;
		}
		$stored = trim( (string) $stored );
		if ( '' !== $stored ) {
			return $stored;
		}

		return self::map_wp_environment( $wp_type );
	}

	/**
	 * Map a WP_ENVIRONMENT_TYPE to the Quissly X-Environment. Only the 3 WP types with a Quissly
	 * counterpart map; `local` folds into `dev`. Everything else (incl. unset — WP returns
	 * "production" — and any unrecognized value) defaults to `prod`, the safe default for real
	 * installs. `demo` and `test` have NO WP type and are reachable only via an override. PURE.
	 *
	 * @param string $wp_type WP_ENVIRONMENT_TYPE value.
	 * @return string
	 */
	public static function map_wp_environment( $wp_type ) {
		switch ( strtolower( trim( (string) $wp_type ) ) ) {
			case 'staging':
				return 'staging';
			case 'development':
			case 'local':
				return 'dev';
			case 'production':
			default:
				return 'prod';
		}
	}

	/**
	 * Bearer token: dev override (constant/env) first, else the stored option.
	 *
	 * @return string
	 */
	public static function token() {
		if ( defined( 'QUISSLY_TOKEN' ) && QUISSLY_TOKEN ) {
			return QUISSLY_TOKEN;
		}
		$env = getenv( 'QUISSLY_TOKEN' );
		if ( $env ) {
			return $env;
		}

		// Encrypted at rest, like the private key - Quissly_Settings::get() routes this
		// specific key to Quissly_Key_Store rather than a plain option read.
		return (string) Quissly_Settings::get( 'quissly_token' );
	}

	/**
	 * The PUBLIC QChat service id (the <ai-chatbot> agent-id). NEVER the bearer token. Same two-mechanism pattern as the token: a dev `.env` override
	 * (QCHAT_SERVICE_UUID) first, else the shipped plugin's stored option (quissly_service_id).
	 * Production does NOT depend on `.env`.
	 *
	 * @return string
	 */
	public static function qchat_service_id() {
		if ( defined( 'QCHAT_SERVICE_UUID' ) && QCHAT_SERVICE_UUID ) {
			return QCHAT_SERVICE_UUID;
		}
		$env = getenv( 'QCHAT_SERVICE_UUID' );
		if ( $env ) {
			return $env;
		}

		return (string) get_option( 'quissly_service_id', '' );
	}

	/**
	 * Dev-mode private key PEM, or null if no override is configured.
	 *
	 * @return string|null
	 */
	public static function dev_private_pem() {
		if ( defined( 'QUISSLY_DEV_PRIVATE_PEM' ) && QUISSLY_DEV_PRIVATE_PEM ) {
			return str_replace( '\n', "\n", QUISSLY_DEV_PRIVATE_PEM );
		}
		$env = getenv( 'QUISSLY_DEV_PRIVATE_PEM' );
		if ( $env ) {
			return str_replace( '\n', "\n", $env );
		}

		return null;
	}
}
