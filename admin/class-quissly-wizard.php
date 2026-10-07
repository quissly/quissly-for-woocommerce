<?php
/**
 * Setup wizard (first-run): keys -> token -> test -> mode -> detect -> initial sync.
 *
 * @package Quissly_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The guided first-run setup. State (current step + completion) lives in options. Step
 * PROCESSING (process_step) is separated from the HTTP form/nonce handling (done in
 * Quissly_Admin) so it is integration-testable directly.
 *
 * Order: connect (-> test) before the local-only selector step, then the initial sync. The
 * selector step only records the active theme - the storefront script finds the search box
 * itself at runtime.
 */
class Quissly_Wizard {

	const OPT_STEP     = 'quissly_wizard_step';
	const OPT_COMPLETE = 'quissly_wizard_complete';

	// Numbering starts at 2: step 1 was a separate "Generate keys" step, removed because
	// connecting creates the keys itself (as the Magento plugin's one-click connect does).
	// The remaining numbers are kept so a store part-way through setup resumes correctly.
	const STEP_TOKEN  = 2;
	const STEP_TEST   = 3;
	const STEP_MODE   = 4;
	const STEP_DETECT = 5;
	const STEP_SYNC   = 6;
	const STEP_LAST   = 6;

	/**
	 * Error from the most recent failed connect_automatically() call, for the render
	 * layer to flash. '' when the last attempt succeeded or none has been made yet.
	 * Static, same request only — Quissly_Admin::handle_wizard() reads it right after
	 * calling process_step() and moves it into a transient for the PRG redirect.
	 *
	 * @var string
	 */
	private static $last_connect_error = '';

	/**
	 * Current step (2..6). A stored step 1 (the removed keys step) reads as the connect step.
	 *
	 * @return int
	 */
	public static function current_step() {
		$step = (int) get_option( self::OPT_STEP, self::STEP_TOKEN );

		return max( self::STEP_TOKEN, min( self::STEP_LAST, $step ) );
	}

	/**
	 * Whether setup has been completed.
	 *
	 * @return bool
	 */
	public static function is_complete() {
		return (bool) get_option( self::OPT_COMPLETE, false );
	}

	/**
	 * Set the current step.
	 *
	 * @param int $step Step number.
	 */
	public static function set_step( $step ) {
		update_option( self::OPT_STEP, (int) $step, false );
	}

	/**
	 * Process one wizard step's submitted data and advance. Returns the next step.
	 *
	 * NOTE: nonce + capability are verified by the caller (Quissly_Admin) BEFORE this runs.
	 *
	 * @param int   $step  Step being submitted.
	 * @param array $input Sanitized-on-use input (raw $_POST subset).
	 * @return int Next step.
	 */
	public static function process_step( $step, array $input ) {
		self::$last_connect_error = '';

		switch ( (int) $step ) {
			case self::STEP_TOKEN:
				if ( ! empty( $input['connect'] ) ) {
					$result = self::connect_automatically( isset( $input['email'] ) ? $input['email'] : '' );
					if ( ! $result['ok'] ) {
						self::$last_connect_error = $result['message'];
						$next                     = self::STEP_TOKEN; // stay; let the merchant retry or fall back to manual.
						break;
					}
					$next = self::STEP_TEST;
					break;
				}
				Quissly_Settings::update( 'quissly_token', isset( $input['token'] ) ? $input['token'] : '' );
				$next = self::STEP_TEST;
				break;

			case self::STEP_TEST:
				// The connection result is computed live on render; nothing to store.
				$next = self::STEP_MODE;
				break;

			case self::STEP_MODE:
				self::apply_mode( isset( $input['mode'] ) ? $input['mode'] : 'qsearch' );
				// Skip selector detection entirely for QChat-only setups.
				$next = ( Quissly_Settings::get( 'quissly_enable_search' ) || Quissly_Settings::get( 'quissly_enable_quick' ) )
					? self::STEP_DETECT
					: self::STEP_SYNC;
				break;

			case self::STEP_DETECT:
				// The storefront script finds the theme's search box itself, at runtime, so
				// there is nothing to detect server-side: record the active theme and advance.
				update_option( 'quissly_active_theme', self::active_theme_signature(), false );
				$next = self::STEP_SYNC;
				break;

			case self::STEP_SYNC:
				self::run_initial_sync();
				update_option( self::OPT_COMPLETE, true, false );
				$next = self::STEP_LAST;
				break;

			default:
				$next = self::current_step();
				break;
		}

		self::set_step( $next );

		return $next;
	}

	/**
	 * The error from the most recent failed connect_automatically() call. '' when the
	 * last attempt succeeded or none has been made this request.
	 *
	 * @return string
	 */
	public static function last_connect_error() {
		return self::$last_connect_error;
	}

	/**
	 * Create the store's Quissly account automatically: no key to copy, no token to
	 * paste. Mirrors the Magento plugin's Provisioner/Connect controller exactly —
	 * same endpoint, same payload shape, same non-idempotency guard.
	 *
	 * Generates a keypair first if none is stored yet - there is no separate keys step, so
	 * this is where a fresh install gets its keys.
	 * The keypair is safe to persist before the call succeeds — unlike Magento's
	 * in-memory-until-success guard, nothing here has been sent to Quissly yet, so a
	 * failed attempt strands nothing; the SAME stored key is simply retried.
	 *
	 * @param string      $email      Account owner / console sign-in identity.
	 * @param string|null $store_name  The name typed on Quissly Setup; null = store_display_name().
	 * @param string|null $description The description on Quissly Setup; null = the plugin's one-liner.
	 * @return array{ok:bool,message:string}
	 */
	public static function connect_automatically( $email, $store_name = null, $description = null ) {
		$email = trim( (string) $email );
		if ( '' === $email || ! is_email( $email ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'Enter the email address for your Quissly account.', 'quissly-for-woocommerce' ),
			);
		}

		// Refuse when already connected: creating a second account would strand the
		// first, along with everything already synced to it (same guard as Magento's
		// Connect controller — "the most important line in the class").
		if ( '' !== (string) Quissly_Settings::get( 'quissly_token' ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'This site is already connected to Quissly. Connecting again would create a second account and abandon the products already synced to this one.', 'quissly-for-woocommerce' ),
			);
		}

		$key_store = new Quissly_Key_Store();
		$public    = $key_store->get_public_key();
		if ( ! $public ) {
			$public = $key_store->generate_and_store();
		}

		$domain = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		if ( '' === $domain ) {
			return array(
				'ok'      => false,
				'message' => __( 'Could not resolve this site\'s domain.', 'quissly-for-woocommerce' ),
			);
		}

		$user       = wp_get_current_user();
		$first_name = trim( (string) $user->first_name );
		$last_name  = trim( (string) $user->last_name );

		$client   = apply_filters( 'quissly_provisioner_client', null );
		$client   = ( $client instanceof Quissly_Provisioner_Client ) ? $client : new Quissly_Live_Provisioner_Client();
		$response = $client->provision(
			$domain,
			$email,
			$public,
			null !== $store_name ? $store_name : self::store_display_name(),
			'' !== $first_name ? $first_name : null,
			'' !== $last_name ? $last_name : null,
			$description
		);

		if ( ! $response['ok'] ) {
			return array(
				'ok'      => false,
				/* translators: %s: error detail from Quissly. */
				'message' => sprintf( __( 'Could not connect to Quissly: %s', 'quissly-for-woocommerce' ), $response['error'] ),
			);
		}

		Quissly_Settings::update( 'quissly_token', $response['api_key'] );
		Quissly_Settings::update( 'quissly_project_id', $response['project_id'] );
		Quissly_Settings::update( 'quissly_store_id', $response['store_id'] );
		Quissly_Settings::update( 'quissly_account_email', $email );

		// Provisioning creates the QChat service but does not return its id directly, so
		// it is looked up now - the merchant should never have to find and paste it. A
		// null here is a normal outcome (mirrors the Magento plugin's ServiceDirectory):
		// chat just stays off (Quissly_QChat already no-ops without a service id) until
		// it can be resolved; it is NOT treated as a reason to fail the connect itself.
		$directory = apply_filters( 'quissly_service_directory_client', null );
		$directory = ( $directory instanceof Quissly_Service_Directory_Client ) ? $directory : new Quissly_Live_Service_Directory_Client();
		$agent_id  = $directory->qchat_agent_id( $response['project_id'], $email, $response['api_key'] );
		if ( null !== $agent_id ) {
			Quissly_Settings::update( 'quissly_service_id', $agent_id );
		}
		// Same lookup, for the chat cart bridge (Quissly_Chat_Cart); retried lazily if null.
		$namespace = $directory->qsearch_namespace( $response['project_id'], $email, $response['api_key'] );
		if ( null !== $namespace ) {
			Quissly_Settings::update( 'quissly_search_namespace', $namespace );
		}

		return array( 'ok' => true, 'message' => '' );
	}

	/**
	 * The site's display name for the Quissly console, or null to let Quissly name the
	 * account from the domain instead. Sending a generic, unconfigured WordPress default
	 * would name every fresh install's account the same generic thing (the exact mistake
	 * the Magento plugin's storeDisplayName() documents having made once already).
	 *
	 * @return string|null
	 */
	public static function store_display_name() {
		// blogname is stored HTML-entity-encoded (WordPress runs it through esc_html() at
		// save time - see sanitize_option()); get_bloginfo('name') returns it encoded too,
		// which is correct for echoing into a page but wrong for a JSON API field. A title
		// with an apostrophe would otherwise be sent to Quissly as "Joe&#039;s Shop".
		$name    = trim( wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ) );
		$generic = array( '', 'my site', 'my wordpress site', 'my wordpress website', 'woocommerce', 'site title' );

		return in_array( strtolower( $name ), $generic, true ) ? null : $name;
	}

	/**
	 * Enable the features a chosen mode implies. ENABLE-ONLY (intentional): the wizard turns
	 * features ON; it must NEVER silently disable a feature the merchant configured elsewhere
	 * (that violated "a flow not explicitly about a feature must not silently disable it" and
	 * made QSearch drift off when the wizard was re-run). To DISABLE a feature the merchant
	 * uses its explicit toggle on the dashboard/settings. We also do NOT auto-enable `quick`
	 * here — it is a separate, backend-blocked, default-off feature (see toggle defaults).
	 *
	 * @param string $mode 'qsearch' | 'qchat' | 'both'.
	 */
	private static function apply_mode( $mode ) {
		$mode = in_array( $mode, array( 'qsearch', 'qchat', 'both' ), true ) ? $mode : 'qsearch';

		if ( 'qsearch' === $mode || 'both' === $mode ) {
			Quissly_Settings::update( 'quissly_enable_search', true );
		}
		if ( 'qchat' === $mode || 'both' === $mode ) {
			Quissly_Settings::update( 'quissly_enable_qchat', true );
		}
	}

	/**
	 * Trigger the initial catalog sync: enqueue all published products and begin progress
	 * tracking via the worker. The Action Scheduler worker drains the queue live; the
	 * first-sync gate opens when the sync COMPLETES (in the worker) — NOT here — so search
	 * interception activates only after the catalog is actually in Quissly.
	 */
	private static function run_initial_sync() {
		( new Quissly_Sync_Worker() )->start_full_sync();
	}

	/**
	 * A "Name x.y" signature of the active theme (for selector-cache invalidation later).
	 *
	 * @return string
	 */
	private static function active_theme_signature() {
		if ( ! function_exists( 'wp_get_theme' ) ) {
			return '';
		}
		$theme = wp_get_theme();

		return trim( $theme->get( 'Name' ) . ' ' . $theme->get( 'Version' ) );
	}

	/**
	 * Rough ETA string for the initial sync (~1 hour per 50k products).
	 *
	 * @param int $product_count Number of products.
	 * @return string
	 */
	public static function sync_eta( $product_count ) {
		$seconds = (int) ceil( ( max( 0, (int) $product_count ) / 50000 ) * 3600 );
		if ( $seconds < 60 ) {
			return __( 'under a minute', 'quissly-for-woocommerce' );
		}
		$minutes = (int) ceil( $seconds / 60 );

		/* translators: %d: estimated minutes. */
		return sprintf( _n( 'about %d minute', 'about %d minutes', $minutes, 'quissly-for-woocommerce' ), $minutes );
	}
}
