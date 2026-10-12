<?php
/**
 * Which version of the plugin a store runs, reported to Quissly.
 *
 * @package Quissly_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A `quissly_version_update` event (Quissly's events endpoint) tells Quissly which version a
 * store runs, which one it ran before and since when: when the store is first connected, right
 * after each update, and once a day (so a store whose report was missed is right again within a
 * day). Quissly knows which store sent it from its key; the event carries its own time.
 *
 * Metadata: {platform, version, previous_version, updated_at} ('' previous_version before the
 * first update; updated_at = when the store moved to this version, ISO 8601 UTC).
 *
 * The version is the store's own fact, not a shopper's: it is reported whenever the store is
 * connected, whatever the "Shopping activity" switch says, and it carries no user id.
 *
 * track() is pure (unit-tested).
 */
final class Quissly_Version_Report {

	const EVENT    = 'quissly_version_update';
	const PLATFORM = 'woocommerce';
	/** {version, previous, updated_at, reported_at} */
	const OPTION = 'quissly_version_report';
	const HOOK   = 'quissly_version_report';
	const EVERY  = DAY_IN_SECONDS;

	/**
	 * Hook the daily report and the check on every request.
	 */
	public function register() {
		add_action( self::HOOK, array( __CLASS__, 'daily' ) );
		add_action( 'init', array( __CLASS__, 'check' ), 20 );
	}

	/**
	 * What to keep and whether to report. PURE.
	 *
	 * @param array  $state   The stored record ([] before the first one).
	 * @param string $version The running version.
	 * @param int    $now     Now.
	 * @return array{state:array, report:bool}
	 */
	public static function track( array $state, $version, $now ) {
		$known = isset( $state['version'] ) ? (string) $state['version'] : '';
		if ( $known !== (string) $version ) {
			$state = array(
				'version'     => (string) $version,
				'previous'    => $known,
				'updated_at'  => (int) $now,
				'reported_at' => isset( $state['reported_at'] ) ? (int) $state['reported_at'] : 0,
			);

			return array( 'state' => $state, 'report' => true );
		}
		$due = (int) ( $state['reported_at'] ?? 0 ) <= 0 || (int) $now - (int) $state['reported_at'] >= self::EVERY;

		return array( 'state' => $state, 'report' => $due );
	}

	/**
	 * The event's metadata. PURE.
	 *
	 * @param array $state The stored record.
	 * @return array{platform:string, version:string, previous_version:string, updated_at:string}
	 */
	public static function metadata( array $state ) {
		return array(
			'platform'         => self::PLATFORM,
			'version'          => (string) ( $state['version'] ?? '' ),
			'previous_version' => (string) ( $state['previous'] ?? '' ),
			'updated_at'       => gmdate( 'Y-m-d\TH:i:s\Z', (int) ( $state['updated_at'] ?? 0 ) ),
		);
	}

	/**
	 * `init`: a version change is reported at once, and the daily report when a day has passed
	 * (one option read otherwise).
	 */
	public static function check() {
		self::run();
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	/**
	 * The daily cron: makes sure a store with no visitors still reports once a day.
	 */
	public static function daily() {
		self::run();
	}

	/**
	 * Track the running version and report it when due - only from a connected store (an
	 * unconnected one keeps tracking, so its first report says where it came from).
	 */
	private static function run() {
		try {
			$state  = (array) get_option( self::OPTION, array() );
			$result = self::track( $state, QUISSLY_VERSION, time() );
			$moved  = (string) ( $result['state']['previous'] ?? '' );
			if ( ( $state['version'] ?? '' ) !== QUISSLY_VERSION && '' !== $moved ) {
				Quissly_Sync_Log::log( sprintf( 'Quissly updated from %s to %s.', $moved, QUISSLY_VERSION ) );
				delete_option( Quissly_Updater::PROBLEM_OPTION );
			}
			$report = $result['report'] && Quissly_Events::store_connected();
			if ( $report && Quissly_Events::record_store( self::EVENT, self::metadata( $result['state'] ) ) ) {
				$result['state']['reported_at'] = time();
			}
			if ( $result['state'] !== $state ) {
				update_option( self::OPTION, $result['state'], false );
			}
		} catch ( Throwable $e ) {
			return; // Never in a shopper's way.
		}
	}
}
