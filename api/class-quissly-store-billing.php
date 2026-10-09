<?php
/**
 * Quissly's store billing API: the plans a store can buy, buying one, and managing it.
 *
 * @package Quissly_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Client for Quissly's store billing API, on the console host. `GET /plans` is public; every other route
 * takes the store's API key in `X-Store-Api-Key` - the key Connect stored, sent from this
 * server only and never to the browser. Paid plans are sold only while Quissly's billing is
 * open (`billing_open`, otherwise a 403); the Free plan and reading the store's own plans
 * never wait. Same client as quissly-for-magento's Model/Api/StoreBilling.
 */
class Quissly_Store_Billing {

	const PATH    = '/api/v1/billing/store';
	const TIMEOUT = 15;

	/** The subscription states that mean the store has a live plan. */
	const LIVE_STATUSES = array( 'trial', 'active', 'past_due' );

	/**
	 * Every plan a store can buy, or null when Quissly could not be reached.
	 *
	 * @return array|null {billing_open, currency, trial_days, plans[]}
	 */
	public function plans() {
		$result = $this->request( 'GET', '/plans', null, false );

		return ( $result['ok'] && isset( $result['data']['plans'] ) && is_array( $result['data']['plans'] ) ) ? $result['data'] : null;
	}

	/**
	 * The store's plans, newest first, or null when they could not be read.
	 *
	 * @return array|null {project_id, subscriptions[], trial_eligible}
	 */
	public function subscriptions() {
		$result = $this->request( 'GET', '/subscriptions' );

		return ( $result['ok'] && isset( $result['data']['subscriptions'] ) && is_array( $result['data']['subscriptions'] ) ) ? $result['data'] : null;
	}

	/**
	 * The live plan of each product the store has, keyed by family.
	 *
	 * @param array $subscriptions What subscriptions() answered.
	 * @return array<string, array>
	 */
	public function live_plans( array $subscriptions ) {
		$live = array();
		$rows = isset( $subscriptions['subscriptions'] ) && is_array( $subscriptions['subscriptions'] ) ? $subscriptions['subscriptions'] : array();
		foreach ( $rows as $row ) {
			$status = isset( $row['subscription']['status'] ) ? (string) $row['subscription']['status'] : '';
			$family = isset( $row['plan']['family'] ) ? (string) $row['plan']['family'] : '';
			// Newest first, so the first live row of a family is its plan.
			if ( '' !== $family && ! isset( $live[ $family ] ) && in_array( $status, self::LIVE_STATUSES, true ) ) {
				$live[ $family ] = $row;
			}
		}

		return $live;
	}

	/**
	 * Start a plan: a payment link to open, or the free plan switched on now.
	 *
	 * @param string $plan_id         Plan id.
	 * @param string $billing_cycle   monthly|annual.
	 * @param float  $auto_topup_max  Automatic top-up's maximum a month; 0 = off.
	 * @return array{ok:bool,kind:string,pay_url:string,message:string}
	 */
	public function checkout( $plan_id, $billing_cycle, $auto_topup_max = 0.0 ) {
		$body = array(
			'plan_id'       => (string) $plan_id,
			'billing_cycle' => 'annual' === $billing_cycle ? 'annual' : 'monthly',
		);
		if ( (float) $auto_topup_max > 0 ) {
			// Asked before the card form opens: Paddle's form cannot carry it.
			$body['auto_topup_max_usd'] = round( (float) $auto_topup_max, 2 );
		}
		$result = $this->request( 'POST', '/checkout', $body );
		if ( ! $result['ok'] ) {
			return array( 'ok' => false, 'kind' => '', 'pay_url' => '', 'message' => $result['message'] );
		}

		$kind    = isset( $result['data']['kind'] ) ? (string) $result['data']['kind'] : '';
		$pay_url = isset( $result['data']['pay_url'] ) ? (string) $result['data']['pay_url'] : '';
		// The link opens in the merchant's browser, so it must be a real https page.
		if ( ( 'pay' === $kind && 0 !== strpos( $pay_url, 'https://' ) ) || ! in_array( $kind, array( 'pay', 'free_activated' ), true ) ) {
			Quissly_Sync_Log::log( 'Store billing checkout answered an unexpected kind=' . sanitize_key( $kind ) . '.' );
			return array( 'ok' => false, 'kind' => '', 'pay_url' => '', 'message' => $this->message( 0, '' ) );
		}

		return array( 'ok' => true, 'kind' => $kind, 'pay_url' => $pay_url, 'message' => '' );
	}

	/**
	 * Used and left this month, per service, for one plan; null when it could not be read.
	 *
	 * @param string $subscription_id Subscription id.
	 * @return array|null {subscription_id, services[]}
	 */
	public function usage( $subscription_id ) {
		$result = $this->request( 'GET', '/subscriptions/' . rawurlencode( (string) $subscription_id ) . '/usage' );

		return ( $result['ok'] && isset( $result['data']['services'] ) && is_array( $result['data']['services'] ) ) ? $result['data'] : null;
	}

	/**
	 * The store's payments, newest first; null when they could not be read.
	 *
	 * @param int $limit How many.
	 * @return array|null {invoices[], total, limit, offset}
	 */
	public function invoices( $limit = 12 ) {
		$result = $this->request( 'GET', '/invoices?limit=' . max( 1, min( 100, (int) $limit ) ) . '&offset=0' );

		return ( $result['ok'] && isset( $result['data']['invoices'] ) && is_array( $result['data']['invoices'] ) ) ? $result['data'] : null;
	}

	/**
	 * One plan-management call on a subscription.
	 *
	 * Ops: invoice_pdf (GET, id is the invoice), change_preview / change {plan_id},
	 * topup_preview / topup {idempotency_key}, cancel, resume, abort, payment_method,
	 * auto_topup {max_usd}, refund_preview, refund, refund_claim {reason}.
	 *
	 * @param string $op   Operation.
	 * @param string $id   Subscription id (invoice id for invoice_pdf).
	 * @param array  $body JSON body.
	 * @return array{ok:bool,data:array,message:string}
	 */
	public function manage( $op, $id, array $body = array() ) {
		$routes = array(
			'invoice_pdf'    => array( 'GET', '/invoices/%s/pdf' ),
			'change_preview' => array( 'POST', '/subscriptions/%s/change-plan/preview' ),
			'change'         => array( 'POST', '/subscriptions/%s/change-plan' ),
			'topup_preview'  => array( 'POST', '/subscriptions/%s/topup/preview' ),
			'topup'          => array( 'POST', '/subscriptions/%s/topup' ),
			'cancel'         => array( 'POST', '/subscriptions/%s/cancel' ),
			'resume'         => array( 'POST', '/subscriptions/%s/resume' ),
			'abort'          => array( 'POST', '/subscriptions/%s/abort-scheduled-change' ),
			'payment_method' => array( 'POST', '/subscriptions/%s/payment-method' ),
			'auto_topup'     => array( 'POST', '/subscriptions/%s/auto-topup' ),
			'refund_preview' => array( 'POST', '/subscriptions/%s/refund/preview' ),
			'refund'         => array( 'POST', '/subscriptions/%s/refund' ),
			'refund_claim'   => array( 'POST', '/subscriptions/%s/refund-claim' ),
		);
		if ( ! isset( $routes[ $op ] ) || ! preg_match( '/^[0-9a-fA-F-]{36}$/', (string) $id ) ) {
			return array( 'ok' => false, 'data' => array(), 'message' => $this->message( 0, '' ) );
		}
		list( $method, $path ) = $routes[ $op ];
		$result = $this->request( $method, sprintf( $path, $id ), 'POST' === $method ? $body : null );
		$data   = $result['data'];
		$url    = isset( $data['url'] ) ? (string) $data['url'] : ( isset( $data['pay_url'] ) ? (string) $data['pay_url'] : '' );
		// A link opened in the merchant's browser must be a real https page.
		if ( $result['ok'] && in_array( $op, array( 'invoice_pdf', 'payment_method' ), true ) && 0 !== strpos( $url, 'https://' ) ) {
			return array( 'ok' => false, 'data' => array(), 'message' => $this->message( 0, '' ) );
		}

		return array( 'ok' => $result['ok'], 'data' => $data, 'message' => $result['message'] );
	}

	/**
	 * One call; never throws.
	 *
	 * @param string     $method        GET|POST.
	 * @param string     $path          Route under PATH.
	 * @param array|null $body          JSON body for POST.
	 * @param bool       $authenticated Send the store key.
	 * @return array{ok:bool,status:int,data:array,message:string}
	 */
	private function request( $method, $path, $body = null, $authenticated = true ) {
		$headers = array( 'Accept' => 'application/json' );
		if ( $authenticated ) {
			$key = (string) Quissly_Settings::get( 'quissly_token' );
			if ( '' === $key ) {
				return array( 'ok' => false, 'status' => 401, 'data' => array(), 'message' => $this->message( 401, '' ) );
			}
			$headers['X-Store-Api-Key'] = $key;
		}
		$args = array(
			'method'  => $method,
			'timeout' => self::TIMEOUT,
			'headers' => $headers,
		);
		if ( 'POST' === $method ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( null === $body ? array() : $body );
		}

		$url      = rtrim( Quissly_Live_Provisioner_Client::console_url(), '/' ) . self::PATH . $path;
		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			Quissly_Sync_Log::log( 'Store billing ' . $method . ' ' . $path . ' transport failure.' );
			return array( 'ok' => false, 'status' => 0, 'data' => array(), 'message' => $this->message( 0, '' ) );
		}

		$status  = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$data    = is_array( $decoded ) ? $decoded : array();
		if ( $status >= 200 && $status < 300 && is_array( $decoded ) ) {
			return array( 'ok' => true, 'status' => $status, 'data' => $data, 'message' => '' );
		}

		$detail = isset( $data['detail'] ) && is_string( $data['detail'] ) ? $data['detail'] : '';
		// Status and route only: the detail can carry the merchant's own words.
		Quissly_Sync_Log::log( 'Store billing ' . $method . ' ' . $path . ' http=' . $status . '.' );

		return array( 'ok' => false, 'status' => $status, 'data' => $data, 'message' => $this->message( $status, $detail ) );
	}

	/**
	 * What the merchant is told, per the contract's error table.
	 *
	 * @param int    $status HTTP status (0 = no answer).
	 * @param string $detail Quissly's own wording, shown where it is meant for the merchant.
	 * @return string
	 */
	public function message( $status, $detail ) {
		switch ( (int) $status ) {
			case 400:
			case 402:
			case 409:
				if ( '' !== $detail ) {
					return $detail;
				}
				break;
			case 401:
				return __( 'Quissly did not recognise this store. Check the connection in Configuration.', 'quissly-for-woocommerce' );
			case 403:
				return __( 'Paid plans are not on sale yet. You can start on the free plan now.', 'quissly-for-woocommerce' );
			case 502:
				return __( 'The payment provider did not answer. Try again in a minute.', 'quissly-for-woocommerce' );
			case 503:
				return __( 'Billing is not available right now. Try again later.', 'quissly-for-woocommerce' );
		}

		return __( 'Could not reach Quissly. Try again in a minute.', 'quissly-for-woocommerce' );
	}
}
