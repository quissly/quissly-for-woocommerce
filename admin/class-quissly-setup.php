<?php
/**
 * Quissly Setup: the one-click onboarding, as the Shopify app does it.
 *
 * @package Quissly_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Three steps - Your details (Connect), Choose a plan, Go live - in the Shopify app's design,
 * the same screen as quissly-for-magento's and quissly-for-cs-cart's (the stylesheet and
 * script in assets/ are shared copies). Until it is finished every Quissly menu entry opens
 * it (Quissly_Admin).
 *
 * The first catalog sync starts on its own once the service Connect created has settled
 * (HOLD_SECONDS), from an Action Scheduler action or from the page's own polling. Going live
 * is the merchant's click: it switches QSearch on, and only once the first sync has opened the
 * gate. Completion is Quissly_Wizard's flag, so a store that finished the old wizard is never
 * sent through Setup again.
 */
class Quissly_Setup {

	const STEP_DETAILS = 'details';
	const STEP_PLAN    = 'plan';
	const STEP_GOLIVE  = 'golive';

	const OPT_CONNECTED_AT = 'quissly_setup_connected_at';
	const OPT_PLAN         = 'quissly_setup_plan';
	const OPT_SYNC         = 'quissly_setup_sync';

	const ACTION_ADVANCE = 'quissly_setup_advance';
	const NONCE          = 'quissly_setup';

	/**
	 * Quissly answers Connect as soon as the account exists; the search service behind it is
	 * still being built. The first sync waits this long (quissly-for-magento's ConnectHold).
	 */
	const HOLD_SECONDS = 90;

	/**
	 * Wire the AJAX endpoints and the scheduled advance.
	 */
	public function register() {
		add_action( 'wp_ajax_quissly_setup_connect', array( $this, 'ajax_connect' ) );
		add_action( 'wp_ajax_quissly_setup_plan', array( $this, 'ajax_plan' ) );
		add_action( 'wp_ajax_quissly_setup_status', array( $this, 'ajax_status' ) );
		add_action( 'wp_ajax_quissly_setup_finish', array( $this, 'ajax_finish' ) );
		add_action( self::ACTION_ADVANCE, array( __CLASS__, 'advance' ) );
	}

	// ---------------------------------------------------------------------------------
	// State.
	// ---------------------------------------------------------------------------------

	/**
	 * Whether Setup is behind this store.
	 *
	 * @return bool
	 */
	public static function is_complete() {
		return Quissly_Wizard::is_complete();
	}

	/**
	 * Whether the store holds credentials.
	 *
	 * @return bool
	 */
	public static function is_connected() {
		return '' !== (string) Quissly_Settings::get( 'quissly_token' );
	}

	/**
	 * The step the store is on.
	 *
	 * @return string
	 */
	public static function step() {
		if ( ! self::is_connected() ) {
			return self::STEP_DETAILS;
		}

		return get_option( self::OPT_PLAN ) ? self::STEP_GOLIVE : self::STEP_PLAN;
	}

	/**
	 * Seconds still to wait before the first sync.
	 *
	 * @return int
	 */
	public static function hold_remaining() {
		$at = (int) get_option( self::OPT_CONNECTED_AT, 0 );

		return $at > 0 ? max( 0, $at + self::HOLD_SECONDS - time() ) : 0;
	}

	/**
	 * Start the first catalog sync once the new service can take it. Idempotent: the sync
	 * starts once, and never over one that already ran.
	 */
	public static function advance() {
		if ( self::is_complete() || ! self::is_connected() || get_option( self::OPT_SYNC ) || self::hold_remaining() > 0 ) {
			return;
		}
		update_option( self::OPT_SYNC, 1, false );
		self::look_up_chat();
		// QSearch ships on, and would go live the moment the first sync opens the gate; in
		// Setup it waits for the merchant's Go live instead.
		Quissly_Settings::update( 'quissly_enable_search', false );
		$progress = Quissly_Sync_Progress::get();
		if ( 'idle' !== $progress['status'] || Quissly_Sync_State::is_initial_sync_complete() ) {
			return;
		}
		( new Quissly_Sync_Worker() )->start_full_sync();
	}

	/**
	 * Look up the chat service once more if Connect could not (it is built with the search one).
	 */
	private static function look_up_chat() {
		if ( '' !== (string) Quissly_Settings::get( 'quissly_service_id' ) ) {
			return;
		}
		try {
			$directory = apply_filters( 'quissly_service_directory_client', null );
			$directory = ( $directory instanceof Quissly_Service_Directory_Client ) ? $directory : new Quissly_Live_Service_Directory_Client();
			$agent_id  = $directory->qchat_agent_id( (string) Quissly_Settings::get( 'quissly_project_id' ), (string) Quissly_Settings::get( 'quissly_account_email' ), (string) Quissly_Settings::get( 'quissly_token' ) );
			if ( null !== $agent_id ) {
				Quissly_Settings::update( 'quissly_service_id', $agent_id );
			}
		} catch ( \Throwable $e ) {
			return; // Chat can still be switched on later; the Dashboard looks it up again.
		}
	}

	/**
	 * Finish Setup without going live (the Shopify app's "Save changes"): for a merchant not
	 * ready to switch search on yet - or whose first sync needs another look - so nobody is held
	 * on this screen. Needs the services built (the hold over); starts the first sync if it has
	 * not started, and leaves QSearch off until the merchant switches it on.
	 *
	 * @return bool False while the new services are still being built.
	 */
	public static function save_for_later() {
		if ( ! self::is_connected() || self::hold_remaining() > 0 ) {
			return false;
		}
		self::advance();
		update_option( Quissly_Wizard::OPT_COMPLETE, true, false );
		Quissly_Sync_Log::log( 'Quissly Setup saved without going live; QSearch stays off until it is switched on.' );

		return true;
	}

	/**
	 * Whether the first sync ended without the catalog getting through.
	 *
	 * @return bool
	 */
	private static function sync_failed() {
		return Quissly_Sync_State::is_gate_blocked() || null !== Quissly_Sync_State::refusal();
	}

	/**
	 * Whether the catalog is in Quissly: the gate is open and no sync is running.
	 *
	 * @return bool
	 */
	public static function is_ready() {
		return self::is_connected() && Quissly_Sync_State::is_initial_sync_complete() && ! Quissly_Sync_Progress::is_running();
	}

	/**
	 * The progress list on the Go live step: the Shopify app's rows - store identity,
	 * organization, project, QSearch service, QChat service, catalog import. Here one Connect
	 * call creates the first three at once, and the services are built during the hold.
	 *
	 * @return array{ready:bool,failed:bool,saveable:bool,rows:array}
	 */
	public static function status() {
		$connected = self::is_connected();
		$hold      = $connected ? self::hold_remaining() : 0;
		$ready     = self::is_ready();
		$failed    = ! $ready && self::sync_failed();
		$progress  = Quissly_Sync_Progress::get();
		$sent      = (int) $progress['ok'] + (int) $progress['already'];

		if ( $ready ) {
			/* translators: %d: products sent. */
			$catalog = self::row( 'done', $sent > 0 ? sprintf( __( '%d products sent.', 'quissly-for-woocommerce' ), $sent ) : '' );
		} elseif ( $failed ) {
			$refusal = Quissly_Sync_State::refusal();
			$catalog = self::row(
				'failed',
				null !== $refusal
					/* translators: %d: HTTP status code. */
					? sprintf( __( 'Quissly is refusing catalog updates (HTTP %d).', 'quissly-for-woocommerce' ), (int) $refusal )
					: __( 'No products reached Quissly. Check the sync log on the Dashboard, then start the sync again.', 'quissly-for-woocommerce' )
			);
		} elseif ( 'idle' !== $progress['status'] ) {
			/* translators: 1: products sent, 2: products in the sync. */
			$catalog = self::row( 'running', sprintf( __( '%1$d of %2$d products sent.', 'quissly-for-woocommerce' ), $sent, (int) $progress['total'] ) );
		} elseif ( $connected && 0 === $hold ) {
			$catalog = self::row( 'running', __( 'Starting...', 'quissly-for-woocommerce' ) );
		} else {
			$catalog = self::row( 'pending', __( 'Starts as soon as the search service is ready.', 'quissly-for-woocommerce' ) );
		}

		$created  = self::row( $connected ? 'done' : 'pending', '' );
		$building = ! $connected ? 'pending' : ( $hold > 0 ? 'running' : 'done' );
		$chat     = '' !== (string) Quissly_Settings::get( 'quissly_service_id' );

		return array(
			'ready'    => $ready,
			'failed'   => $failed,
			'saveable' => $connected && 0 === $hold,
			'rows'     => array(
				'store'        => $created,
				'organization' => $created,
				'project'      => $created,
				'qsearch'      => self::row( $building, $hold > 0 ? __( 'Quissly is building your search service.', 'quissly-for-woocommerce' ) : '' ),
				'qchat'        => ( $chat || 'done' !== $building )
					? self::row( $building, '' )
					: self::row( 'pending', __( 'Not found yet - chat can still be switched on later.', 'quissly-for-woocommerce' ) ),
				'catalog'      => $catalog,
			),
		);
	}

	/**
	 * One progress row.
	 *
	 * @param string $state  done|running|pending|failed.
	 * @param string $detail Line under the label.
	 * @return array{state:string,detail:string}
	 */
	private static function row( $state, $detail ) {
		return array( 'state' => $state, 'detail' => $detail );
	}

	// ---------------------------------------------------------------------------------
	// AJAX endpoints (capability + nonce checked, JSON answers).
	// ---------------------------------------------------------------------------------

	/**
	 * Refuse a caller who may not manage the store, or whose nonce is stale.
	 */
	private function verify() {
		if ( ! current_user_can( Quissly_Admin::CAP ) ) {
			wp_send_json( array( 'ok' => false, 'message' => __( 'You do not have permission to do this.', 'quissly-for-woocommerce' ) ), 403 );
		}
		check_ajax_referer( self::NONCE );
	}

	/**
	 * Step 1: create the account and connect.
	 */
	public function ajax_connect() {
		$this->verify();
		$email      = isset( $_POST['email'] ) ? sanitize_text_field( wp_unslash( $_POST['email'] ) ) : '';
		$store_name  = isset( $_POST['store_name'] ) ? sanitize_text_field( wp_unslash( $_POST['store_name'] ) ) : '';
		$description = isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '';

		// What Quissly's backend accepts (the Shopify app's checks), refused here rather than
		// half-way through creating the account.
		$problems = array(
			'required' => __( 'Enter the email address for your Quissly account.', 'quissly-for-woocommerce' ),
			'too_long' => __( 'Email address is too long.', 'quissly-for-woocommerce' ),
			'invalid'  => __( 'Enter a valid email address, like name@example.com.', 'quissly-for-woocommerce' ),
			'public'   => __( 'Use an address on a real, public domain.', 'quissly-for-woocommerce' ),
		);
		$problem = Quissly_Setup_Input::emailProblem( $email );
		if ( '' !== $problem ) {
			wp_send_json( array( 'ok' => false, 'message' => $problems[ $problem ] ) );
		}
		if ( ! Quissly_Setup_Input::isNameValid( $store_name ) ) {
			wp_send_json( array( 'ok' => false, 'message' => __( 'Use letters, numbers, spaces and hyphens in the store name.', 'quissly-for-woocommerce' ) ) );
		}

		$result = Quissly_Wizard::connect_automatically(
			$email,
			'' !== $store_name ? mb_substr( $store_name, 0, 100 ) : null,
			'' !== $description ? $description : null
		);
		if ( ! $result['ok'] ) {
			wp_send_json( array( 'ok' => false, 'message' => $result['message'] ) );
		}

		update_option( self::OPT_CONNECTED_AT, time(), false );
		delete_option( self::OPT_SYNC );
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + self::HOLD_SECONDS + 5, self::ACTION_ADVANCE, array(), 'quissly' );
		}
		wp_send_json( array( 'ok' => true ) );
	}

	/**
	 * Step 2: checkout / check / keep / later (see quissly-for-magento's Setup\Plan).
	 */
	public function ajax_plan() {
		$this->verify();
		if ( ! self::is_connected() ) {
			wp_send_json( array( 'ok' => false, 'message' => __( 'Connect the store first.', 'quissly-for-woocommerce' ) ) );
		}
		$billing = new Quissly_Store_Billing();
		$op      = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : '';

		if ( 'checkout' === $op ) {
			$checkout = $billing->checkout(
				isset( $_POST['plan_id'] ) ? sanitize_text_field( wp_unslash( $_POST['plan_id'] ) ) : '',
				isset( $_POST['billing_cycle'] ) ? sanitize_key( wp_unslash( $_POST['billing_cycle'] ) ) : 'monthly'
			);
			if ( ! $checkout['ok'] ) {
				wp_send_json( array( 'ok' => false, 'message' => $checkout['message'] ) );
			}
			if ( 'free_activated' === $checkout['kind'] ) {
				update_option( self::OPT_PLAN, 1, false );
				wp_send_json( array( 'ok' => true, 'kind' => 'active' ) );
			}
			wp_send_json( array( 'ok' => true, 'kind' => 'pay', 'pay_url' => $checkout['pay_url'] ) );
		}

		if ( 'check' === $op || 'keep' === $op ) {
			$subscriptions = $billing->subscriptions();
			if ( null === $subscriptions ) {
				wp_send_json( array( 'ok' => false, 'message' => $billing->message( 0, '' ) ) );
			}
			$live   = $billing->live_plans( $subscriptions );
			$family = isset( $_POST['family'] ) ? sanitize_key( wp_unslash( $_POST['family'] ) ) : '';
			$active = '' !== $family ? isset( $live[ $family ] ) : ! empty( $live );
			if ( $active ) {
				update_option( self::OPT_PLAN, 1, false );
			}
			wp_send_json(
				array(
					'ok'      => true,
					'active'  => $active,
					'message' => $active ? '' : __( 'Payment not received yet.', 'quissly-for-woocommerce' ),
				)
			);
		}

		// Only while the plans cannot be loaded, so a Quissly outage never strands a merchant.
		if ( 'later' === $op && null === $billing->plans() ) {
			update_option( self::OPT_PLAN, 1, false );
			wp_send_json( array( 'ok' => true ) );
		}

		wp_send_json( array( 'ok' => false, 'message' => __( 'Choose a plan to continue.', 'quissly-for-woocommerce' ) ) );
	}

	/**
	 * Step 3: advance and answer where Setup stands (polled while the page is open).
	 */
	public function ajax_status() {
		$this->verify();
		if ( ! empty( $_POST['retry'] ) && self::sync_failed() ) {
			Quissly_Sync_State::clear_refusal();
			( new Quissly_Sync_Worker() )->start_full_sync();
		}
		self::advance();
		wp_send_json( array( 'ok' => true ) + self::status() );
	}

	/**
	 * Finish Setup: QSearch switches on, Setup is done, the Dashboard takes over. With
	 * later=1 it is the Shopify app's "Save changes": done, but QSearch stays off.
	 */
	public function ajax_finish() {
		$this->verify();
		if ( ! empty( $_POST['later'] ) ) { // "Save changes".
			if ( ! self::save_for_later() ) {
				wp_send_json( array( 'ok' => false, 'message' => __( 'Quissly is still building your services. Try again in a minute.', 'quissly-for-woocommerce' ) ) );
			}
			wp_send_json(
				array(
					'ok'       => true,
					'redirect' => admin_url( 'admin.php?page=' . Quissly_Admin::MENU_SLUG . '&quissly_status=saved_setup' ),
				)
			);
		}
		if ( ! self::is_ready() ) {
			wp_send_json( array( 'ok' => false, 'message' => __( 'Your catalog is still on its way to Quissly. Finish Setup unlocks when it is ready.', 'quissly-for-woocommerce' ) ) );
		}
		Quissly_Settings::update( 'quissly_enable_search', true );
		update_option( Quissly_Wizard::OPT_COMPLETE, true, false );
		Quissly_Sync_Log::log( 'Quissly Setup finished; QSearch is live.' );
		wp_send_json(
			array(
				'ok'       => true,
				'redirect' => admin_url( 'admin.php?page=' . Quissly_Admin::MENU_SLUG . '&quissly_status=live' ),
			)
		);
	}

	// ---------------------------------------------------------------------------------
	// Page.
	// ---------------------------------------------------------------------------------

	/**
	 * Enqueue the shared stylesheet and script.
	 */
	public static function enqueue() {
		wp_enqueue_style( 'quissly-setup', QUISSLY_PLUGIN_URL . 'assets/css/quissly-setup.css', array(), QUISSLY_VERSION );
		wp_enqueue_script( 'quissly-setup', QUISSLY_PLUGIN_URL . 'assets/js/quissly-setup.js', array(), QUISSLY_VERSION, true );
	}

	/**
	 * The script's configuration (data-config).
	 *
	 * @param string     $step  Current step.
	 * @param array|null $plans Plan view.
	 * @return array
	 */
	private function config( $step, $plans ) {
		$ajax     = admin_url( 'admin-ajax.php' );
		$discount = null !== $plans ? (int) $plans['discount_pct'] : 0;

		return array(
			'step'      => $step,
			'reached'   => $step,
			'reload'    => admin_url( 'admin.php?page=' . Quissly_Admin::PAGE_SETTINGS ),
			'csrf'      => array( '_ajax_nonce' => wp_create_nonce( self::NONCE ) ),
			'endpoints' => array(
				'connect' => array( 'url' => $ajax, 'params' => array( 'action' => 'quissly_setup_connect' ) ),
				'plan'    => array( 'url' => $ajax, 'params' => array( 'action' => 'quissly_setup_plan' ) ),
				'status'  => array( 'url' => $ajax, 'params' => array( 'action' => 'quissly_setup_status' ) ),
				'finish'  => array( 'url' => $ajax, 'params' => array( 'action' => 'quissly_setup_finish' ) ),
			),
			'text'      => array(
				'connecting'  => __( 'Creating your Quissly account...', 'quissly-for-woocommerce' ),
				'saving'      => __( 'Saving...', 'quissly-for-woocommerce' ),
				'goingLive'   => __( 'Going live...', 'quissly-for-woocommerce' ),
				'pickPlan'    => __( 'Pick a plan to continue', 'quissly-for-woocommerce' ),
				/* translators: %1: yearly saving, e.g. $160. */
				'saveYear'    => __( 'Save %1/yr', 'quissly-for-woocommerce' ),
				/* translators: %d: annual discount percentage. */
				'saveDefault' => $discount > 0 ? sprintf( __( 'Save %d%%', 'quissly-for-woocommerce' ), $discount ) : '',
				'payTimeout'  => __( 'We have not seen the payment yet. Finish it in the other tab, then press "I have paid".', 'quissly-for-woocommerce' ),
				'payNotYet'   => __( 'Payment not received yet.', 'quissly-for-woocommerce' ),
				'error'       => __( 'Could not reach the server. Try again in a minute.', 'quissly-for-woocommerce' ),
				'compare'       => __( 'Compare all features', 'quissly-for-woocommerce' ),
				'compareBack'   => __( '← Back to plan cards', 'quissly-for-woocommerce' ),
				'emailRequired' => __( 'Email is required.', 'quissly-for-woocommerce' ),
				'emailInvalid'  => __( 'Enter a valid email address, like name@example.com.', 'quissly-for-woocommerce' ),
				'emailTooLong'  => __( 'Email address is too long.', 'quissly-for-woocommerce' ),
				'emailPublic'   => __( 'Use an address on a real, public domain.', 'quissly-for-woocommerce' ),
				'nameInvalid'   => __( 'Use letters, numbers, spaces and hyphens in the store name.', 'quissly-for-woocommerce' ),
				'chipWorking' => __( 'Setting up...', 'quissly-for-woocommerce' ),
				'chipReady'   => __( 'Ready', 'quissly-for-woocommerce' ),
				'chipFailed'  => __( 'Needs attention', 'quissly-for-woocommerce' ),
				'states'      => array(
					'done'    => __( 'Done', 'quissly-for-woocommerce' ),
					'running' => __( 'Running', 'quissly-for-woocommerce' ),
					'pending' => __( 'Waiting', 'quissly-for-woocommerce' ),
					'failed'  => __( 'Failed', 'quissly-for-woocommerce' ),
				),
			),
		);
	}

	/**
	 * Everything the plan step shows; null when Quissly's plans could not be loaded.
	 *
	 * @return array|null
	 */
	private function plan_view() {
		$billing = new Quissly_Store_Billing();
		$plans   = $billing->plans();
		if ( null === $plans ) {
			return null;
		}
		$subscriptions  = $billing->subscriptions();
		$trial_eligible = isset( $subscriptions['trial_eligible'] ) && is_array( $subscriptions['trial_eligible'] ) ? $subscriptions['trial_eligible'] : array();
		$live           = null === $subscriptions ? array() : $billing->live_plans( $subscriptions );
		$currency       = isset( $plans['currency'] ) ? (string) $plans['currency'] : 'USD';
		$trial_days     = isset( $plans['trial_days'] ) ? (int) $plans['trial_days'] : 0;
		$open           = ! empty( $plans['billing_open'] );

		$families = array( 'qsearch' => array(), 'qchat' => array() );
		foreach ( $plans['plans'] as $plan ) {
			$family = isset( $plan['family'] ) ? (string) $plan['family'] : '';
			if ( isset( $families[ $family ] ) ) {
				$families[ $family ][] = $this->card( $plan, $currency, $trial_days, $open, $trial_eligible );
			}
		}
		foreach ( $families as $family => $cards ) {
			usort(
				$cards,
				static function ( $a, $b ) {
					return $a['sort'] <=> $b['sort'];
				}
			);
			$families[ $family ] = $cards;
		}

		$current = null;
		foreach ( $live as $row ) {
			$current = array(
				'name'   => $this->plan_name( (string) $row['plan']['family'], isset( $row['plan']['tier'] ) ? (string) $row['plan']['tier'] : '' ),
				'status' => (string) $row['subscription']['status'],
			);
			break;
		}

		return array(
			'billing_open' => $open,
			'families'     => $families,
			'current'      => $current,
			'discount_pct' => isset( $plans['plans'][0]['annual_discount_pct'] ) ? (int) $plans['plans'][0]['annual_discount_pct'] : 0,
		);
	}

	/**
	 * One plan card, every figure already in words.
	 *
	 * @param array  $plan           Plan from GET /plans.
	 * @param string $currency       Currency code.
	 * @param int    $trial_days     Trial length.
	 * @param bool   $open           Whether paid plans are on sale.
	 * @param array  $trial_eligible Per-family trial eligibility.
	 * @return array
	 */
	protected function card( array $plan, $currency, $trial_days, $open, array $trial_eligible ) {
		$family     = (string) $plan['family'];
		$name       = $this->plan_name( $family, isset( $plan['tier'] ) ? (string) $plan['tier'] : '' );
		$free       = ! empty( $plan['is_free'] );
		$monthly    = (float) ( isset( $plan['price_monthly'] ) ? $plan['price_monthly'] : 0 );
		$annual     = (float) ( isset( $plan['price_annual'] ) ? $plan['price_annual'] : 0 );
		$month_text = $this->money( $monthly, $currency );
		$year_month = $this->money( $annual / 12, $currency );
		$saving     = (int) round( $monthly * 12 - $annual );
		$trial      = ! $free && $trial_days > 0 && ( ! isset( $trial_eligible[ $family ] ) || $trial_eligible[ $family ] );
		$quota      = isset( $plan['quotas'][0] ) ? $plan['quotas'][0] : array();
		$requests   = isset( $quota['requests_per_month'] ) ? (int) $quota['requests_per_month'] : 0;
		$extra      = isset( $quota['extra_block'] ) ? $quota['extra_block'] : null;

		if ( 'qchat' === $family && 0 === $requests ) {
			$usage = array( __( 'Human agents only', 'quissly-for-woocommerce' ), __( 'no AI messages', 'quissly-for-woocommerce' ) );
		} else {
			$usage = array( number_format_i18n( $requests ), 'qchat' === $family ? __( 'AI messages / mo', 'quissly-for-woocommerce' ) : __( 'searches / mo', 'quissly-for-woocommerce' ) );
		}
		$extra_figure = is_array( $extra )
			/* translators: 1: price, 2: number of requests. */
			? array( sprintf( __( '%1$s per %2$s', 'quissly-for-woocommerce' ), $this->money( (float) $extra['price'], $currency ), number_format_i18n( (int) $extra['requests'] ) ), __( 'extra requests', 'quissly-for-woocommerce' ) )
			: array( __( 'None', 'quissly-for-woocommerce' ), __( 'extra requests', 'quissly-for-woocommerce' ) );

		$free_note = __( 'Free forever - no card', 'quissly-for-woocommerce' );
		/* translators: %s: plan name. */
		$free_line = sprintf( __( '%s · free', 'quissly-for-woocommerce' ), $name );

		return array(
			'id'           => (string) $plan['id'],
			'family'       => $family,
			'name'         => $name,
			'free'         => $free,
			'popular'      => ! empty( $plan['is_popular'] ),
			'disabled'     => ! $free && ! $open,
			'monthly'      => $month_text,
			'annual_month' => $year_month,
			/* translators: %d: trial length in days. */
			'monthly_note' => $free ? $free_note : ( $trial ? sprintf( __( '%d-day free trial', 'quissly-for-woocommerce' ), $trial_days ) : __( 'billed monthly', 'quissly-for-woocommerce' ) ),
			/* translators: %s: yearly price. */
			'annual_note'  => $free ? $free_note : sprintf( __( 'billed %s yearly', 'quissly-for-woocommerce' ), $this->money( $annual, $currency ) ),
			'saving'       => ( $free || $saving <= 0 ) ? '' : $this->money( (float) $saving, $currency ),
			'figures'      => array( $usage, $extra_figure ),
			'annual_total' => $free ? '—' : $this->money( $annual, $currency ),
			/* translators: %d: trial length in days. */
			'trial_label'  => $free ? __( 'Free plan', 'quissly-for-woocommerce' ) : ( $trial ? sprintf( __( '%d days', 'quissly-for-woocommerce' ), $trial_days ) : '—' ),
			/* translators: %s: plan name. */
			'cta'          => $free ? __( 'Start the free plan', 'quissly-for-woocommerce' ) : sprintf( __( 'Continue with %s', 'quissly-for-woocommerce' ), $name ),
			/* translators: 1: plan name, 2: monthly price. */
			'note_monthly' => $free ? $free_line : sprintf( __( '%1$s · %2$s/mo · billed monthly', 'quissly-for-woocommerce' ), $name, $month_text ),
			/* translators: 1: plan name, 2: monthly price on a yearly plan. */
			'note_annual'  => $free ? $free_line : sprintf( __( '%1$s · %2$s/mo · billed yearly', 'quissly-for-woocommerce' ), $name, $year_month ),
			'sort'         => $monthly,
		);
	}

	/**
	 * The data-* attributes the script reads off a plan, for a card and its comparison column alike.
	 *
	 * @param array $card Card model.
	 * @return string Escaped attribute markup.
	 */
	private function plan_attributes( array $card ) {
		$html = 'data-q-plan role="radio" tabindex="-1" aria-checked="false" aria-disabled="' . ( $card['disabled'] ? 'true' : 'false' ) . '"';
		foreach (
			array(
				'data-plan-id'      => $card['id'],
				'data-family'       => $card['family'],
				'data-free'         => $card['free'] ? '1' : '0',
				'data-saving'       => $card['saving'],
				'data-cta'          => $card['cta'],
				'data-note-monthly' => $card['note_monthly'],
				'data-note-annual'  => $card['note_annual'],
			) as $name => $value
		) {
			$html .= ' ' . $name . '="' . esc_attr( (string) $value ) . '"';
		}

		return $html;
	}

	/**
	 * "QSearch Growth", from the plan's family and tier.
	 *
	 * @param string $family qsearch|qchat.
	 * @param string $tier   Tier slug.
	 * @return string
	 */
	protected function plan_name( $family, $tier ) {
		$families = array( 'qsearch' => 'QSearch', 'qchat' => 'QChat' );
		$tiers    = array(
			'free'    => __( 'Free', 'quissly-for-woocommerce' ),
			'basic'   => __( 'Basic', 'quissly-for-woocommerce' ),
			'starter' => __( 'Starter', 'quissly-for-woocommerce' ),
			'growth'  => __( 'Growth', 'quissly-for-woocommerce' ),
			'scale'   => __( 'Scale', 'quissly-for-woocommerce' ),
		);

		return trim( ( isset( $families[ $family ] ) ? $families[ $family ] : ucfirst( $family ) ) . ' ' . ( isset( $tiers[ $tier ] ) ? $tiers[ $tier ] : ucfirst( $tier ) ) );
	}

	/**
	 * A price as the merchant reads it: "$89", "$75.65".
	 *
	 * @param float  $amount   Amount.
	 * @param string $currency Currency code.
	 * @return string
	 */
	protected function money( $amount, $currency ) {
		$symbols = array( 'USD' => '$', 'EUR' => '€', 'GBP' => '£' );
		$whole   = abs( $amount - round( $amount ) ) < 0.005;
		$number  = number_format_i18n( $amount, $whole ? 0 : 2 );

		return isset( $symbols[ $currency ] ) ? $symbols[ $currency ] . $number : $currency . ' ' . $number;
	}

	/**
	 * The workspace description, drafted from the store's own facts (the Shopify app's draft,
	 * Quissly_Description_Draft): the tagline, the published products and their categories,
	 * the store address, the price range and the brands. Any failure gives no draft.
	 *
	 * @return string
	 */
	private function description_draft() {
		try {
			global $wpdb;
			$count       = (int) wp_count_posts( 'product' )->publish;
			$collections = array();
			$terms       = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => true ) );
			foreach ( is_array( $terms ) ? $terms : array() as $term ) {
				$collections[] = array( 'title' => wp_specialchars_decode( $term->name, ENT_QUOTES ), 'handle' => $term->slug, 'product_count' => (int) $term->count );
			}
			$vendors = array();
			if ( taxonomy_exists( 'product_brand' ) ) {
				$brands = get_terms( array( 'taxonomy' => 'product_brand', 'hide_empty' => true ) );
				foreach ( is_array( $brands ) ? $brands : array() as $brand ) {
					// One entry per product, as the draft ranks brands by how many products carry them.
					$vendors = array_merge( $vendors, array_fill( 0, min( 50, max( 1, (int) $brand->count ) ), wp_specialchars_decode( $brand->name, ENT_QUOTES ) ) );
				}
			}
			$prices = null;
			if ( $count > 0 ) {
				$row = $wpdb->get_row( "SELECT MIN(l.min_price) AS lo, MAX(l.max_price) AS hi FROM {$wpdb->wc_product_meta_lookup} l JOIN {$wpdb->posts} p ON p.ID = l.product_id WHERE p.post_type = 'product' AND p.post_status = 'publish'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one read for a draft.
				if ( $row && null !== $row->hi ) {
					$prices = array( 'min' => (float) $row->lo, 'max' => (float) $row->hi, 'currency' => get_woocommerce_currency() );
				}
			}
			$countries = WC()->countries;

			return (string) Quissly_Description_Draft::compose(
				array(
					'shop_name'        => (string) Quissly_Wizard::store_display_name(),
					'meta_description' => wp_specialchars_decode( (string) get_bloginfo( 'description' ), ENT_QUOTES ),
					'product_count'    => $count,
					'product_kinds'    => array(),
					'collections'      => $collections,
					'location'         => array(
						'city'    => (string) $countries->get_base_city(),
						'country' => (string) ( $countries->get_countries()[ $countries->get_base_country() ] ?? '' ),
					),
					'prices'           => $prices,
					'vendors'          => $vendors,
				)
			);
		} catch ( \Throwable $e ) {
			return '';
		}
	}

	/**
	 * The storefront host Quissly registers the account against.
	 *
	 * @return string
	 */
	private function domain() {
		return (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );
	}

	/**
	 * Render the Setup screen (inside the admin page's .wrap).
	 */
	public function render() {
		$step      = self::step();
		$connected = self::STEP_DETAILS !== $step;
		$plans     = $connected ? $this->plan_view() : null;
		$steps     = array(
			self::STEP_DETAILS => __( 'Your details', 'quissly-for-woocommerce' ),
			self::STEP_PLAN    => __( 'Choose a plan', 'quissly-for-woocommerce' ),
			self::STEP_GOLIVE  => __( 'Go live', 'quissly-for-woocommerce' ),
		);
		$done      = (int) array_search( $step, array_keys( $steps ), true );
		$domain    = $this->domain();
		$user      = wp_get_current_user();
		$draft     = $connected ? '' : $this->description_draft();

		include QUISSLY_PLUGIN_DIR . 'admin/views/setup.php';
	}
}
