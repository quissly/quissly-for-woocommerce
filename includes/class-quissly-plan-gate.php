<?php
/**
 * Which feature switches the store's plan leaves out: a store on a Search plan
 * only gets QChat's switch disabled ("not in your plan"), one on a Chat plan the search switches -
 * search and everything that only works with it. A disabled switch counts as off everywhere
 * (Quissly_Settings::get()), and what was saved is kept for when the plan includes it again.
 *
 * What the plan includes, best known first: Quissly's live plans (GET /subscriptions, kept in
 * option OPTION by remember(); asked by Configuration, Billing, Setup and an Action Scheduler action
 * every 10 minutes), plus a plan chosen in Setup for manual invoice (Quissly does not know of that
 * one yet); while Quissly's answer has never been read, the plan chosen in Setup. Nothing known (a
 * store connected before Setup, a Quissly outage at Setup) disables nothing - a store already live
 * is never switched off on a guess.
 *
 * @package Quissly_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * The plan's feature lock.
 */
class Quissly_Plan_Gate {

	const OPTION  = 'quissly_plan_families';
	const HOOK    = 'quissly_plan_check';
	const MAX_AGE = 600;

	/** The products a plan can include. */
	const SERVICES = array( 'qsearch', 'qchat' );

	/** Switches that need a Search plan: search itself and what only works with it. */
	const SEARCH_SWITCHES = array( 'quissly_enable_search', 'quissly_enable_overlay', 'quissly_overlay_suggestions', 'quissly_overlay_recent', 'quissly_enable_quick', 'quissly_enable_voice', 'quissly_enable_image', 'quissly_most_loved' );

	/** Switches that need a Chat plan. */
	const CHAT_SWITCHES = array( 'quissly_enable_qchat' );

	/**
	 * This request's answer.
	 *
	 * @var array|null
	 */
	private static $locked = null;

	/**
	 * Register the background check (Action Scheduler, group quissly - uninstall removes it).
	 */
	public function register() {
		add_action( self::HOOK, array( __CLASS__, 'tick' ) );
		add_action( 'admin_init', array( $this, 'ensure_scheduled' ) );
	}

	/**
	 * Schedule the check every 10 minutes (from an admin page: no query on the storefront).
	 */
	public function ensure_scheduled() {
		if ( function_exists( 'as_has_scheduled_action' ) && ! as_has_scheduled_action( self::HOOK ) ) {
			as_schedule_recurring_action( time() + MINUTE_IN_SECONDS, self::MAX_AGE, self::HOOK, array(), 'quissly' );
		}
	}

	/**
	 * The background check: ask Quissly once its last answer is MAX_AGE old.
	 */
	public static function tick() {
		self::refresh( false );
	}

	/**
	 * The disabled switches for what a plan includes.
	 *
	 * @param string[] $families The plan's products (qsearch, qchat); none = not known.
	 * @return array<string, string> Option key => the product it needs.
	 */
	public static function locked_for( array $families ) {
		$families = array_intersect( self::SERVICES, $families );
		if ( array() === $families ) {
			return array();
		}
		$locked = array();
		if ( ! in_array( 'qsearch', $families, true ) ) {
			$locked += array_fill_keys( self::SEARCH_SWITCHES, 'qsearch' );
		}
		if ( ! in_array( 'qchat', $families, true ) ) {
			$locked += array_fill_keys( self::CHAT_SWITCHES, 'qchat' );
		}

		return $locked;
	}

	/**
	 * What the plan includes, from what is known.
	 *
	 * @param string[]|null $live    Quissly's live plans' products; null = never read.
	 * @param string        $invoice The product of a plan chosen for manual invoice, or ''.
	 * @param string[]      $setup   The products of the plan chosen in Setup.
	 * @return string[]
	 */
	public static function families( $live, $invoice, array $setup ) {
		$known = null === $live ? $setup : $live;
		if ( '' !== $invoice ) {
			$known[] = $invoice;
		}

		return array_values( array_intersect( self::SERVICES, array_unique( $known ) ) );
	}

	/**
	 * The switches this store's plan leaves out, read once a request.
	 *
	 * @return array<string, string> Option key => the product it needs.
	 */
	public static function locked() {
		if ( null === self::$locked ) {
			$kept    = get_option( self::OPTION, null );
			$invoice = get_option( 'quissly_setup_invoice', array() );
			$setup   = get_option( 'quissly_setup_services', array() );

			self::$locked = self::locked_for(
				self::families(
					is_array( $kept ) && isset( $kept['families'] ) && is_array( $kept['families'] ) ? $kept['families'] : null,
					is_array( $invoice ) && isset( $invoice['family'] ) ? (string) $invoice['family'] : '',
					array_values( array_intersect( self::SERVICES, (array) $setup ) )
				)
			);
		}

		return self::$locked;
	}

	/**
	 * Keep Quissly's answer about the store's plans (Quissly_Store_Billing::subscriptions()).
	 *
	 * @param array|null $subscriptions Null is no answer: the last one is kept.
	 * @param int        $now           Now.
	 */
	public static function remember( $subscriptions, $now ) {
		if ( ! is_array( $subscriptions ) ) {
			return;
		}
		$families = array();
		foreach ( (array) ( $subscriptions['subscriptions'] ?? array() ) as $row ) {
			$status = (string) ( $row['subscription']['status'] ?? '' );
			$family = (string) ( $row['plan']['family'] ?? '' );
			if ( '' !== $family && in_array( $status, Quissly_Store_Billing::LIVE_STATUSES, true ) ) {
				$families[ $family ] = true;
			}
		}
		update_option(
			self::OPTION,
			array(
				'families' => array_keys( $families ),
				'at'       => (int) $now,
			),
			false
		);
		self::$locked = null;
	}

	/**
	 * Ask Quissly about the store's plans: always, or once its last answer is MAX_AGE old.
	 *
	 * @param bool $always Ask now whatever the last answer's age.
	 */
	public static function refresh( $always ) {
		if ( '' === (string) Quissly_Settings::get( 'quissly_token' ) ) {
			return;
		}
		$kept = get_option( self::OPTION, null );
		if ( ! $always && is_array( $kept ) && (int) ( $kept['at'] ?? 0 ) + self::MAX_AGE > time() ) {
			return;
		}
		self::remember( ( new Quissly_Store_Billing() )->subscriptions(), time() );
	}

	/**
	 * Why a disabled switch is disabled, for its note.
	 *
	 * @param string $product The product it needs (qsearch, qchat).
	 * @return string
	 */
	public static function reason( $product ) {
		return 'qchat' === $product
			? __( "Your plan doesn't include the AI shopping assistant.", 'quissly-for-woocommerce' )
			: __( "Your plan doesn't include Search.", 'quissly-for-woocommerce' );
	}
}
