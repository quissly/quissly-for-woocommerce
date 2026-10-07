<?php
/**
 * Quissly Billing: the store's plans, this month's usage and its invoices.
 *
 * @package Quissly_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Everything Quissly's store billing API offers a store after Setup (STORE_BILLING_API.md),
 * laid out as the Shopify app's Settings billing column: a current-plan card per product
 * (search, chat) with its status, banners and the plan picker, then Usage and Extra requests;
 * invoices and the card on file on the right. A product with no plan offers the plans to
 * start one. The same page as quissly-for-magento's and quissly-for-cs-cart's (the stylesheet
 * and script in assets/ are shared copies); the plan figures come from Quissly Setup (this
 * class extends it).
 *
 * Every action posts to one AJAX endpoint (op= plus the subscription or invoice id). Previews
 * come back already in words, and anything that charges is confirmed first. Quissly checks the
 * id belongs to this store (another store's answers 404).
 */
class Quissly_Billing extends Quissly_Setup {

	/** The products a store can buy, in the order they are shown. */
	const FAMILIES = array( 'qsearch', 'qchat' );

	const NONCE = 'quissly_billing';

	/** One message for the reload after an action (user meta key prefix). */
	const FLASH = 'quissly_billing_flash';

	/** @var Quissly_Store_Billing */
	private $billing;

	/**
	 * @param Quissly_Store_Billing|null $billing Billing client (tests pass a stand-in).
	 */
	public function __construct( $billing = null ) {
		$this->billing = $billing ? $billing : new Quissly_Store_Billing();
	}

	/**
	 * Wire the AJAX endpoint.
	 */
	public function register() {
		add_action( 'wp_ajax_quissly_billing_manage', array( $this, 'ajax_manage' ) );
	}

	/**
	 * Enqueue the shared stylesheet and script.
	 */
	public static function enqueue() {
		wp_enqueue_style( 'quissly-billing', QUISSLY_PLUGIN_URL . 'assets/css/quissly-billing.css', array(), QUISSLY_VERSION );
		wp_enqueue_script( 'quissly-billing', QUISSLY_PLUGIN_URL . 'assets/js/quissly-billing.js', array(), QUISSLY_VERSION, true );
	}

	// ---------------------------------------------------------------------------------
	// Actions.
	// ---------------------------------------------------------------------------------

	/**
	 * Run one billing action (admin-ajax.php?action=quissly_billing_manage).
	 */
	public function ajax_manage() {
		if ( ! current_user_can( Quissly_Admin::CAP ) ) {
			wp_send_json( array( 'ok' => false, 'message' => __( 'You do not have permission to do this.', 'quissly-for-woocommerce' ) ), 403 );
		}
		check_ajax_referer( self::NONCE );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked just above.
		$op      = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : '';
		$id      = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '';
		$plan_id = isset( $_POST['plan_id'] ) ? sanitize_text_field( wp_unslash( $_POST['plan_id'] ) ) : '';
		$cycle   = isset( $_POST['billing_cycle'] ) ? sanitize_key( wp_unslash( $_POST['billing_cycle'] ) ) : '';
		$family  = isset( $_POST['family'] ) ? sanitize_key( wp_unslash( $_POST['family'] ) ) : '';
		$key     = isset( $_POST['idempotency_key'] ) ? sanitize_text_field( wp_unslash( $_POST['idempotency_key'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		wp_send_json( $this->manage( $op, $id, $plan_id, $cycle, $family, $key ) );
	}

	/**
	 * One billing action, as the answer the page reads.
	 *
	 * @param string $op      Operation.
	 * @param string $id      Subscription (or invoice) id.
	 * @param string $plan_id Plan id (checkout, change).
	 * @param string $cycle   monthly|annual (checkout).
	 * @param string $family  qsearch|qchat (check).
	 * @param string $key     Idempotency key (topup).
	 * @return array
	 */
	public function manage( $op, $id, $plan_id = '', $cycle = '', $family = '', $key = '' ) {
		switch ( $op ) {
			case 'checkout':
				$checkout = $this->billing->checkout( $plan_id, $cycle );
				if ( $checkout['ok'] && 'free_activated' === $checkout['kind'] ) {
					self::flash( __( 'The free plan is on.', 'quissly-for-woocommerce' ) );
					return array( 'ok' => true, 'done' => true );
				}
				return $checkout['ok']
					? array( 'ok' => true, 'pay_url' => $checkout['pay_url'] )
					: array( 'ok' => false, 'message' => $checkout['message'] );

			case 'check':
				$subscriptions = $this->billing->subscriptions();
				$active        = null !== $subscriptions && isset( $this->billing->live_plans( $subscriptions )[ $family ] );
				if ( $active ) {
					self::flash( __( 'Payment received - your plan is on.', 'quissly-for-woocommerce' ) );
				}
				return array( 'ok' => true, 'active' => $active );

			case 'change_preview':
				$result = $this->billing->manage( $op, $id, array( 'plan_id' => $plan_id ) );
				return $result['ok']
					? array( 'ok' => true, 'summary' => $this->change_summary( $result['data'] ), 'confirm' => __( 'Change plan', 'quissly-for-woocommerce' ) )
					: array( 'ok' => false, 'message' => $result['message'] );

			case 'change':
				$result = $this->billing->manage( $op, $id, array( 'plan_id' => $plan_id ) );
				if ( $result['ok'] ) {
					self::flash( $this->change_done( $result['data'] ) );
				}
				return $this->done( $result );

			case 'topup_preview':
				$result = $this->billing->manage( $op, $id );
				$data   = $result['data'];
				return $result['ok']
					? array(
						'ok'      => true,
						'confirm' => __( 'Buy now', 'quissly-for-woocommerce' ),
						'summary' => sprintf(
							/* translators: 1: number of requests, 2: price. */
							__( '%1$s extra requests for %2$s, charged to your saved card now. They carry over to next month while the plan continues.', 'quissly-for-woocommerce' ),
							number_format_i18n( isset( $data['requests'] ) ? (int) $data['requests'] : 0 ),
							$this->amount( isset( $data['charge_now'] ) ? $data['charge_now'] : 0, isset( $data['currency'] ) ? (string) $data['currency'] : 'USD' )
						),
					)
					: array( 'ok' => false, 'message' => $result['message'] );

			case 'topup':
				$result = $this->billing->manage( $op, $id, array( 'idempotency_key' => $key ) );
				if ( $result['ok'] ) {
					self::flash(
						isset( $result['data']['kind'] ) && 'processing' === $result['data']['kind']
							? __( 'Payment is being confirmed; the extra requests are added as soon as it lands.', 'quissly-for-woocommerce' )
							/* translators: %s: number of requests. */
							: sprintf( __( '%s extra requests added.', 'quissly-for-woocommerce' ), number_format_i18n( isset( $result['data']['requests'] ) ? (int) $result['data']['requests'] : 0 ) )
					);
				}
				return $this->done( $result );

			case 'cancel':
			case 'resume':
			case 'abort':
				$result = $this->billing->manage( $op, $id );
				if ( $result['ok'] ) {
					$done = array(
						'cancel' => __( 'Your plan is cancelled and ends at the end of the paid period.', 'quissly-for-woocommerce' ),
						'resume' => __( 'Your plan continues.', 'quissly-for-woocommerce' ),
						'abort'  => __( 'The scheduled change is undone.', 'quissly-for-woocommerce' ),
					);
					self::flash( $done[ $op ] );
				}
				return $this->done( $result );

			case 'payment_method':
			case 'invoice_pdf':
				$result = $this->billing->manage( $op, $id );
				$data   = $result['data'];
				return $result['ok']
					? array( 'ok' => true, 'url' => isset( $data['url'] ) ? (string) $data['url'] : (string) ( isset( $data['pay_url'] ) ? $data['pay_url'] : '' ) )
					: array( 'ok' => false, 'message' => $result['message'] );
		}

		return array( 'ok' => false, 'message' => __( 'Unknown request.', 'quissly-for-woocommerce' ) );
	}

	/**
	 * What a plan change does, before it is made.
	 *
	 * @param array $preview {kind, charge_now, currency, effective_at}.
	 * @return string
	 */
	private function change_summary( array $preview ) {
		$kind = isset( $preview['kind'] ) ? (string) $preview['kind'] : '';
		if ( 'upgrade' === $kind ) {
			return sprintf(
				/* translators: %s: amount charged now. */
				__( '%s is charged to your saved card now (the rest of this period), and the new plan starts at once.', 'quissly-for-woocommerce' ),
				$this->amount( isset( $preview['charge_now'] ) ? $preview['charge_now'] : 0, isset( $preview['currency'] ) ? (string) $preview['currency'] : 'USD' )
			);
		}
		if ( 'downgrade' === $kind ) {
			/* translators: %s: date the plan changes. */
			return sprintf( __( 'Nothing is charged or refunded. Your plan changes on %s.', 'quissly-for-woocommerce' ), $this->date( isset( $preview['effective_at'] ) ? (string) $preview['effective_at'] : '' ) );
		}
		if ( 'trial_swap' === $kind ) {
			return __( 'Free during your trial. The plan changes now.', 'quissly-for-woocommerce' );
		}

		return __( 'Your plan changes.', 'quissly-for-woocommerce' );
	}

	/**
	 * What a plan change did.
	 *
	 * @param array $result {kind, subscription}.
	 * @return string
	 */
	private function change_done( array $result ) {
		$kind = isset( $result['kind'] ) ? (string) $result['kind'] : '';
		if ( 'scheduled' === $kind ) {
			/* translators: %s: date the plan changes. */
			return sprintf( __( 'Your plan changes on %s.', 'quissly-for-woocommerce' ), $this->date( isset( $result['subscription']['current_period_end'] ) ? (string) $result['subscription']['current_period_end'] : '' ) );
		}
		if ( 'processing' === $kind ) {
			return __( 'Payment is being confirmed; your new plan starts as soon as it lands.', 'quissly-for-woocommerce' );
		}

		return __( 'Your plan has changed.', 'quissly-for-woocommerce' );
	}

	/**
	 * The answer to an op that is finished once it succeeds.
	 *
	 * @param array $result What Quissly_Store_Billing::manage() answered.
	 * @return array
	 */
	private function done( array $result ) {
		return $result['ok'] ? array( 'ok' => true, 'done' => true ) : array( 'ok' => false, 'message' => $result['message'] );
	}

	/**
	 * Keep a message for the page the action reloads.
	 *
	 * @param string $message Message.
	 */
	private static function flash( $message ) {
		update_user_meta( get_current_user_id(), self::FLASH, (string) $message );
	}

	/**
	 * The kept message, once.
	 *
	 * @return string
	 */
	private static function take_flash() {
		$user    = get_current_user_id();
		$message = (string) get_user_meta( $user, self::FLASH, true );
		if ( '' !== $message ) {
			delete_user_meta( $user, self::FLASH );
		}

		return $message;
	}

	// ---------------------------------------------------------------------------------
	// Page.
	// ---------------------------------------------------------------------------------

	/**
	 * The page's script configuration (data-config).
	 *
	 * @return array
	 */
	private function billing_config() {
		return array(
			'endpoint' => admin_url( 'admin-ajax.php' ),
			'params'   => array( 'action' => 'quissly_billing_manage' ),
			'reload'   => admin_url( 'admin.php?page=' . Quissly_Admin::PAGE_BILLING ),
			'csrf'     => array( '_ajax_nonce' => wp_create_nonce( self::NONCE ) ),
			'text'     => array(
				'working'       => __( 'Working...', 'quissly-for-woocommerce' ),
				'error'         => __( 'Could not reach the server. Try again in a minute.', 'quissly-for-woocommerce' ),
				'payWaiting'    => __( 'Finish the payment in the tab that opened. This page updates by itself once Quissly has it.', 'quissly-for-woocommerce' ),
				'payTimeout'    => __( 'We have not seen the payment yet. Reload this page once you have paid.', 'quissly-for-woocommerce' ),
				'close'         => __( 'Close', 'quissly-for-woocommerce' ),
				'titleChange'   => __( 'Change your plan', 'quissly-for-woocommerce' ),
				'titleTopup'    => __( 'Buy extra requests', 'quissly-for-woocommerce' ),
				'titleCancel'   => __( 'Cancel your plan?', 'quissly-for-woocommerce' ),
				'titlePay'      => __( 'Waiting for the payment', 'quissly-for-woocommerce' ),
				'titleError'    => __( 'Something went wrong', 'quissly-for-woocommerce' ),
				'confirmCancel' => __( 'Cancel plan', 'quissly-for-woocommerce' ),
			),
		);
	}

	/**
	 * Everything the page shows; null when Quissly could not be reached.
	 *
	 * @return array|null
	 */
	public function view() {
		if ( ! self::is_connected() ) {
			return null;
		}
		$plans         = $this->billing->plans();
		$subscriptions = $this->billing->subscriptions();
		if ( null === $plans || null === $subscriptions ) {
			return null;
		}
		$currency   = isset( $plans['currency'] ) ? (string) $plans['currency'] : 'USD';
		$trial_days = isset( $plans['trial_days'] ) ? (int) $plans['trial_days'] : 0;
		$open       = ! empty( $plans['billing_open'] );
		$eligible   = isset( $subscriptions['trial_eligible'] ) && is_array( $subscriptions['trial_eligible'] ) ? $subscriptions['trial_eligible'] : array();

		$cards    = array();
		$discount = 0;
		foreach ( $plans['plans'] as $plan ) {
			$card = $this->card( $plan, $currency, $trial_days, $open, $eligible );
			$cards[ (string) $plan['id'] ] = $card + $this->card_words( $plan, $card, $trial_days, $eligible, $currency ) + array( 'raw' => $plan );
			$discount = max( $discount, isset( $plan['annual_discount_pct'] ) ? (int) $plan['annual_discount_pct'] : 0 );
		}
		$live = $this->billing->live_plans( $subscriptions );

		$families = array();
		$meters   = array();
		$extras   = array();
		$windows  = array();
		foreach ( self::FAMILIES as $family ) {
			$family_cards = array_values(
				array_filter(
					$cards,
					static function ( $c ) use ( $family ) {
						return $c['family'] === $family;
					}
				)
			);
			usort(
				$family_cards,
				static function ( $a, $b ) {
					return $a['sort'] <=> $b['sort'];
				}
			);
			$plan                = isset( $live[ $family ] ) ? $this->live_plan( $live[ $family ], $cards, $currency ) : null;
			$families[ $family ] = array(
				'label' => 'qchat' === $family ? __( 'Chat', 'quissly-for-woocommerce' ) : __( 'Search', 'quissly-for-woocommerce' ),
				'cards' => $family_cards,
				'plan'  => $plan,
			);
			if ( null === $plan ) {
				continue;
			}
			$label = 'qchat' === $family ? 'QChat' : 'QSearch';
			$usage = $this->usage( $plan['id'], $label );
			if ( null !== $usage ) {
				$meters[]  = $usage;
				$windows[] = array( $usage['from'], $plan['period_end'] );
				if ( $usage['extra'] > 0 || null !== $plan['extra'] ) {
					$extras[] = $usage + array( 'plan' => $plan );
				}
			} elseif ( null !== $plan['extra'] ) {
				$extras[] = array( 'label' => $label, 'extra' => 0, 'plan' => $plan );
			}
		}

		return array(
			'billing_open' => $open,
			'discount_pct' => $discount,
			'families'     => $families,
			'meters'       => $meters,
			'window'       => $this->window( $windows ),
			'extras'       => $extras,
			'invoices'     => $this->invoices(),
		);
	}

	/**
	 * What a full plan card says, as Shopify's Settings cards do: the note under the price for
	 * each cadence, the trial pill and the checklist.
	 *
	 * @param array  $plan       Plan from GET /plans.
	 * @param array  $card       Setup's card for it.
	 * @param int    $trial_days Trial length.
	 * @param array  $eligible   Per-family trial eligibility.
	 * @param string $currency   Currency code.
	 * @return array
	 */
	private function card_words( array $plan, array $card, $trial_days, array $eligible, $currency ) {
		$family   = (string) $plan['family'];
		$free     = $card['free'];
		$quota    = isset( $plan['quotas'][0] ) ? $plan['quotas'][0] : array();
		$requests = isset( $quota['requests_per_month'] ) ? (int) $quota['requests_per_month'] : 0;
		$extra    = isset( $quota['extra_block'] ) ? $quota['extra_block'] : null;

		$feats = array( 'qchat' === $family ? __( 'Includes QChat', 'quissly-for-woocommerce' ) : __( 'Includes QSearch', 'quissly-for-woocommerce' ) );
		if ( 'qchat' === $family && 0 === $requests ) {
			$feats[] = __( 'Human agents only - no AI messages', 'quissly-for-woocommerce' );
		} else {
			$feats[] = 'qchat' === $family
				/* translators: %s: number of AI messages. */
				? sprintf( __( '%s AI messages / month', 'quissly-for-woocommerce' ), number_format_i18n( $requests ) )
				/* translators: %s: number of search requests. */
				: sprintf( __( '%s search requests / month', 'quissly-for-woocommerce' ), number_format_i18n( $requests ) );
		}
		if ( is_array( $extra ) ) {
			/* translators: 1: price, 2: number of requests. */
			$feats[] = sprintf( __( 'Extra requests: %1$s per %2$s', 'quissly-for-woocommerce' ), $this->money( (float) $extra['price'], $currency ), number_format_i18n( (int) $extra['requests'] ) );
			$feats[] = __( 'Unused extra requests carry over', 'quissly-for-woocommerce' );
		} else {
			$feats[] = __( 'No extra requests', 'quissly-for-woocommerce' );
		}
		$free_note = __( 'Free forever - no card required', 'quissly-for-woocommerce' );

		return array(
			'note_month' => $free ? $free_note : __( 'Billed monthly', 'quissly-for-woocommerce' ),
			'note_year'  => $free
				? $free_note
				/* translators: 1: monthly price, 2: yearly saving. */
				: ( '' !== $card['saving'] ? sprintf( __( '%1$s/mo billed monthly · save %2$s/yr', 'quissly-for-woocommerce' ), $card['monthly'], $card['saving'] ) : __( 'Billed yearly', 'quissly-for-woocommerce' ) ),
			'trial'      => ( ! $free && $trial_days > 0 && ( ! isset( $eligible[ $family ] ) || $eligible[ $family ] ) )
				/* translators: %d: trial length in days. */
				? sprintf( __( '%d-day free trial', 'quissly-for-woocommerce' ), $trial_days )
				: '',
			'feats'      => $feats,
		);
	}

	/**
	 * One live plan, in words, with the actions it allows.
	 *
	 * @param array  $row      {subscription, plan} from GET /subscriptions.
	 * @param array  $cards    Plan cards keyed by plan id.
	 * @param string $currency Currency code.
	 * @return array
	 */
	private function live_plan( array $row, array $cards, $currency ) {
		$sub        = $row['subscription'];
		$plan_id    = isset( $sub['plan_id'] ) ? (string) $sub['plan_id'] : '';
		$card       = isset( $cards[ $plan_id ] ) ? $cards[ $plan_id ] : null;
		$status     = isset( $sub['status'] ) ? (string) $sub['status'] : '';
		$paddle     = isset( $sub['channel'] ) && 'paddle' === $sub['channel'];
		$annual     = isset( $sub['billing_cycle'] ) && 'annual' === $sub['billing_cycle'];
		$free       = null !== $card && $card['free'];
		$period_end = isset( $sub['current_period_end'] ) ? (string) $sub['current_period_end'] : '';
		$ends       = $this->date( $period_end );
		$cancelling = ! empty( $sub['cancel_after_period_end'] );
		$pending_id = isset( $sub['pending_plan_id'] ) ? (string) $sub['pending_plan_id'] : '';
		$pending    = isset( $cards[ $pending_id ] ) ? $cards[ $pending_id ] : null;
		$extra      = isset( $card['raw']['quotas'][0]['extra_block'] ) ? $card['raw']['quotas'][0]['extra_block'] : null;
		$id         = isset( $sub['id'] ) ? (string) $sub['id'] : '';

		$meta = array();
		if ( $free ) {
			$meta[] = __( 'Free · no card needed', 'quissly-for-woocommerce' );
		} elseif ( null !== $card ) {
			/* translators: %s: price. */
			$meta[] = $annual ? sprintf( __( '%s / year', 'quissly-for-woocommerce' ), $card['annual_total'] ) : sprintf( __( '%s / month', 'quissly-for-woocommerce' ), $card['monthly'] );
		}
		if ( '' !== $ends && ! $free ) {
			if ( $cancelling ) {
				/* translators: %s: date. */
				$meta[] = sprintf( __( 'ends %s', 'quissly-for-woocommerce' ), $ends );
			} else {
				/* translators: %s: date. */
				$meta[] = 'trial' === $status ? sprintf( __( 'trial ends %s', 'quissly-for-woocommerce' ), $ends ) : sprintf( __( 'renews %s', 'quissly-for-woocommerce' ), $ends );
			}
		}

		$banners = array();
		if ( 'trial' === $status && ! $cancelling ) {
			/* translators: %s: date the trial ends. */
			$banners[] = array( 'tone' => 'info', 'text' => sprintf( __( 'You are in a free trial. Charges begin when it ends, on %s.', 'quissly-for-woocommerce' ), $ends ) );
		}
		if ( 'past_due' === $status ) {
			$banners[] = array( 'tone' => 'critical', 'text' => __( 'Your last payment failed. Update your card to keep the plan.', 'quissly-for-woocommerce' ), 'op' => 'payment_method', 'action' => __( 'Update card', 'quissly-for-woocommerce' ) );
		}
		if ( $cancelling ) {
			/* translators: %s: date the plan ends. */
			$banners[] = array( 'tone' => 'warning', 'text' => sprintf( __( 'Your plan ends on %s. Nothing more is charged.', 'quissly-for-woocommerce' ), $ends ), 'op' => 'resume', 'action' => __( 'Keep my plan', 'quissly-for-woocommerce' ) );
		}
		if ( null !== $pending ) {
			/* translators: 1: new plan name, 2: date. */
			$banners[] = array( 'tone' => 'info', 'text' => sprintf( __( 'Your plan changes to %1$s on %2$s. You keep your current plan until then.', 'quissly-for-woocommerce' ), $pending['name'], $ends ), 'op' => 'abort', 'action' => __( 'Undo the change', 'quissly-for-woocommerce' ) );
		}
		$promo = isset( $sub['promo_code'] ) ? (string) $sub['promo_code'] : '';
		if ( '' !== $promo && isset( $sub['promo_discount_amount'] ) ) {
			/* translators: 1: promo code, 2: discount. */
			$banners[] = array( 'tone' => 'success', 'text' => sprintf( __( 'Promo %1$s: %2$s off each payment.', 'quissly-for-woocommerce' ), $promo, $this->money( (float) $sub['promo_discount_amount'], $currency ) ) );
		}

		$labels = array(
			'trial'    => __( 'Trial', 'quissly-for-woocommerce' ),
			'active'   => __( 'Active', 'quissly-for-woocommerce' ),
			'past_due' => __( 'Payment failed', 'quissly-for-woocommerce' ),
		);
		$chips  = array( 'trial' => 'running', 'active' => 'done' );

		return array(
			'id'               => $id,
			'name'             => null !== $card ? $card['name'] : $this->plan_name( isset( $row['plan']['family'] ) ? (string) $row['plan']['family'] : '', isset( $row['plan']['tier'] ) ? (string) $row['plan']['tier'] : '' ),
			'plan_id'          => $plan_id,
			'status_label'     => isset( $labels[ $status ] ) ? $labels[ $status ] : ucfirst( $status ),
			'chip'             => isset( $chips[ $status ] ) ? $chips[ $status ] : 'failed',
			'meta'             => implode( ' · ', $meta ),
			'banners'          => $banners,
			'period_end'       => $period_end,
			'can_change'       => $paddle && null === $pending && ! $cancelling && 'past_due' !== $status,
			'can_upgrade_free' => ! $paddle,
			'can_cancel'       => $paddle && ! $cancelling,
			'can_card'         => $paddle,
			'can_topup'        => $paddle && is_array( $extra ) && 'active' === $status,
			'extra'            => is_array( $extra ) ? array(
				'price'    => $this->money( (float) $extra['price'], $currency ),
				'requests' => number_format_i18n( (int) $extra['requests'] ),
				/* translators: 1: number of requests, 2: price. */
				'label'    => sprintf( __( 'Buy %1$s more for %2$s', 'quissly-for-woocommerce' ), number_format_i18n( (int) $extra['requests'] ), $this->money( (float) $extra['price'], $currency ) ),
			) : null,
		);
	}

	/**
	 * One usage meter, as Shopify's UsageMeter draws it; null when it could not be read.
	 *
	 * @param string $subscription_id Subscription id.
	 * @param string $label           Service name.
	 * @return array|null
	 */
	private function usage( $subscription_id, $label ) {
		$usage   = '' === $subscription_id ? null : $this->billing->usage( $subscription_id );
		$service = isset( $usage['services'][0] ) && is_array( $usage['services'][0] ) ? $usage['services'][0] : null;
		if ( null === $service ) {
			return null;
		}
		$limit = isset( $service['limit'] ) ? (int) $service['limit'] : 0;
		// granted is the plan's requests plus any bought this period.
		$granted   = max( $limit, isset( $service['granted'] ) ? (int) $service['granted'] : $limit );
		$used      = isset( $service['used'] ) ? (int) $service['used'] : 0;
		$remaining = isset( $service['remaining'] ) ? (int) $service['remaining'] : max( 0, $granted - $used );
		$pct       = $granted > 0 ? min( 100, $used / $granted * 100 ) : 0;
		$over      = $granted > 0 && $remaining <= 0;
		$near      = ! $over && $granted > 0 && $pct >= 80;
		$note      = '';
		if ( $over ) {
			$note = __( 'This month\'s requests are used up. Buy extra requests to keep answering.', 'quissly-for-woocommerce' );
		} elseif ( $near ) {
			$note = __( 'Approaching your included requests for this period.', 'quissly-for-woocommerce' );
		}

		return array(
			'label'    => $label,
			'used'     => number_format_i18n( $used ),
			'used_raw' => $used,
			'of'       => $granted > 0 ? number_format_i18n( $granted ) : '',
			'of_raw'   => $granted,
			/* translators: %s: requests left. */
			'left'     => $granted > 0 ? sprintf( __( '%s left', 'quissly-for-woocommerce' ), number_format_i18n( $remaining ) ) : '',
			'out'      => $remaining <= 0,
			'pct'      => round( $pct, 1 ),
			'tone'     => $over ? 'is-over' : ( $near ? 'is-near' : '' ),
			'note'     => $note,
			'extra'    => max( 0, $granted - $limit ),
			'from'     => isset( $service['period_start'] ) ? (string) $service['period_start'] : '',
		);
	}

	/**
	 * "Current window: 2 Oct → 2 Nov", when the plans agree on one; '' otherwise.
	 *
	 * @param array $windows [period start, period end] per plan.
	 * @return string
	 */
	private function window( array $windows ) {
		$windows = array_unique(
			array_map(
				static function ( $w ) {
					return implode( '|', $w );
				},
				$windows
			)
		);
		if ( 1 !== count( $windows ) ) {
			return '';
		}
		list( $from, $to ) = explode( '|', reset( $windows ) );
		$from              = $this->date( $from, 'j M' );
		$to                = $this->date( $to, 'j M' );

		/* translators: 1: start date, 2: end date. */
		return ( '' !== $from && '' !== $to ) ? sprintf( __( 'Current window: %1$s → %2$s', 'quissly-for-woocommerce' ), $from, $to ) : '';
	}

	/**
	 * The store's last invoices, newest first; null when they could not be read.
	 *
	 * @return array|null
	 */
	private function invoices() {
		$list = $this->billing->invoices();
		if ( null === $list ) {
			return null;
		}
		$rows = array();
		foreach ( $list['invoices'] as $invoice ) {
			$issued = isset( $invoice['issued_at'] ) ? (string) $invoice['issued_at'] : ( isset( $invoice['period_start'] ) ? (string) $invoice['period_start'] : '' );
			$rows[] = array(
				'id'     => isset( $invoice['id'] ) ? (string) $invoice['id'] : '',
				'date'   => $this->date( $issued ),
				/* translators: 1: period start, 2: period end. */
				'period' => sprintf( __( '%1$s - %2$s', 'quissly-for-woocommerce' ), $this->date( isset( $invoice['period_start'] ) ? (string) $invoice['period_start'] : '' ), $this->date( isset( $invoice['period_end'] ) ? (string) $invoice['period_end'] : '' ) ),
				'amount' => $this->money( isset( $invoice['amount_charged'] ) ? (float) $invoice['amount_charged'] : 0.0, isset( $invoice['currency'] ) ? (string) $invoice['currency'] : 'USD' ),
				'status' => ucfirst( str_replace( '_', ' ', isset( $invoice['status'] ) ? (string) $invoice['status'] : '' ) ),
				'pdf'    => isset( $invoice['channel'] ) && 'paddle' === $invoice['channel'],
			);
		}

		return $rows;
	}

	/**
	 * "$50.00".
	 *
	 * @param mixed  $value    Amount.
	 * @param string $currency Currency code.
	 * @return string
	 */
	private function amount( $value, $currency ) {
		$symbols = array( 'USD' => '$', 'EUR' => '€', 'GBP' => '£' );
		$number  = number_format_i18n( (float) $value, 2 );

		return isset( $symbols[ $currency ] ) ? $symbols[ $currency ] . $number : $currency . ' ' . $number;
	}

	/**
	 * A date as the admin reads it ("2 Nov 2026"), '' when there is none.
	 *
	 * @param string $iso    ISO 8601 timestamp.
	 * @param string $format Date format.
	 * @return string
	 */
	private function date( $iso, $format = 'j M Y' ) {
		$time = '' === $iso ? false : strtotime( $iso );

		return false === $time ? '' : date_i18n( $format, $time );
	}

	/**
	 * Render the Billing page (inside the admin page's .wrap).
	 */
	public function render() {
		$view   = $this->view();
		$config = $this->billing_config();
		$flash  = self::take_flash();

		include QUISSLY_PLUGIN_DIR . 'admin/views/billing.php';
	}
}
