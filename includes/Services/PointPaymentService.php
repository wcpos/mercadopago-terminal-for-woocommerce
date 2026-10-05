<?php
namespace WCPOS\WooCommercePOS\MercadoPagoTerminal\Services;

use WCPOS\WooCommercePOS\MercadoPagoTerminal\Logger;
use WCPOS\WooCommercePOS\MercadoPagoTerminal\PaymentAttempt;
use WCPOS\WooCommercePOS\MercadoPagoTerminal\PaymentLock;
use WCPOS\WooCommercePOS\MercadoPagoTerminal\PaymentReconciler;
use WCPOS\WooCommercePOS\MercadoPagoTerminal\Settings;
use WCPOS\WooCommercePOS\MercadoPagoTerminal\Utils\Money;

class PointPaymentService {
	private $client;
	private $settings;
	private $terminals;
	private $reconciler;

	public function __construct( MercadoPagoClient $client, Settings $settings, ?TerminalService $terminals = null, ?PaymentReconciler $reconciler = null ) {
		$this->client = $client;
		$this->settings = $settings;
		$this->terminals = $terminals ?: new TerminalService( $client, $settings );
		$this->reconciler = $reconciler ?: new PaymentReconciler( $settings );
	}

	public function start_payment_for_order( $order, string $terminal_id = '' ): array {
		$start = Logger::timer();
		$result = PaymentLock::with_lock( (int) $order->get_id(), 'create_payment', function () use ( $order, $terminal_id ) {
			if ( $order->is_paid() ) { return array( 'status' => 'already_paid' ); }
			$current = PaymentAttempt::current( $order );
			if ( $current && $current['mp_order_id'] ) {
				$remote = $this->client->get_order( $current['mp_order_id'] );
				$result = $this->reconciler->reconcile( $order, $remote, 'create_reuse' );
				$status = PaymentAttempt::status( $remote );
				if ( PaymentAttempt::is_non_final( $status ) || PaymentAttempt::is_paid_status( $status ) ) {
					return array_merge( $result, array( 'reused' => true, 'mp_order_id' => $remote['id'] ) );
				}
				if ( ! PaymentAttempt::is_final_unpaid( $result['status'] ) ) {
					return array_merge( $result, array( 'reused' => true ) );
				}
			}
			$terminal_id = $terminal_id ?: $this->settings->default_terminal_id();
			$this->terminals->assert_can_receive_orders( $terminal_id );
			$pending = PaymentAttempt::prepare( $order, $terminal_id, Money::to_amount( $order->get_total() ) );
			try {
				$mp_order = $this->client->create_order( self::build_payload( $order, $pending ), $pending['idempotency_key'] );
			} catch ( \Throwable $e ) {
				if ( $e instanceof MercadoPagoApiException && $e->http_status() >= 400 && $e->http_status() <= 499 && ! in_array( $e->http_status(), array( 409, 429 ), true ) ) {
					PaymentAttempt::discard_pending( $order, $pending );
				}
				Logger::log( 'Could not create Mercado Pago Point order: ' . $e->getMessage(), array( 'order_id' => (int) $order->get_id(), 'terminal_id' => $terminal_id ), 'error' );
				throw $e;
			}
			PaymentAttempt::record_created( $order, $pending, $mp_order );
			return array( 'status' => 'created', 'mp_order_id' => $mp_order['id'], 'terminal_id' => $terminal_id );
		} );
		Logger::log( 'Mercado Pago payment start result', array( 'order_id' => $order->get_id(), 'mp_order_id' => $result['mp_order_id'] ?? (string) $order->get_meta( PaymentAttempt::META_CURRENT_MP_ORDER_ID ), 'status' => $result['status'], 'duration_ms' => Logger::elapsed_ms( $start ) ), 'info' );
		return $result;
	}

	public function poll_order( $order ): array {
		$start = Logger::timer();
		$current = PaymentAttempt::current( $order );
		if ( ! $current ) {
			Logger::log( 'Mercado Pago payment poll result', array( 'order_id' => $order->get_id(), 'mp_order_id' => '', 'status' => 'idle', 'duration_ms' => Logger::elapsed_ms( $start ) ), 'debug' );
			return array( 'status' => 'idle' );
		}
		if ( PaymentAttempt::is_non_final( $current['status'] ) ) {
			try {
				$remote = $this->client->get_order( $current['mp_order_id'] );
			} catch ( MercadoPagoApiException $e ) {
				if ( 404 !== $e->http_status() || time() - strtotime( $current['created_at'] ) >= 60 ) { throw $e; }
				Logger::log( 'Order not visible yet', array( 'order_id' => $order->get_id(), 'mp_order_id' => $current['mp_order_id'] ), 'debug' );
				return array( 'status' => 'created', 'retry_allowed' => false, 'message' => __( 'Waiting for Mercado Pago to register the payment…', 'mercadopago-terminal-for-woocommerce' ) );
			}
			$result = $this->reconciler->reconcile( $order, $remote, 'poll' );
		} else {
			$result = array( 'status' => $current['status'] );
		}
		$created = strtotime( $current['created_at'] );
		if ( 'action_required' === $result['status'] ) {
			$result['message'] = __( 'Confirm the payment on the terminal.', 'mercadopago-terminal-for-woocommerce' );
		} elseif ( $created && time() - $created > 60 && in_array( $result['status'], array( 'created', 'at_terminal' ), true ) ) {
			$result['message'] = __( 'Still waiting for the terminal. Check the terminal, or cancel and retry.', 'mercadopago-terminal-for-woocommerce' );
		}
		Logger::log( 'Mercado Pago payment poll result', array( 'order_id' => $order->get_id(), 'mp_order_id' => $current['mp_order_id'], 'status' => $result['status'], 'duration_ms' => Logger::elapsed_ms( $start ) ), 'debug' );
		return $result;
	}

	public function cancel_order_payment( $order ): array {
		$start = Logger::timer();
		$result = PaymentLock::with_lock( (int) $order->get_id(), 'cancel_payment', function () use ( $order ) {
			$current = PaymentAttempt::current( $order );
			if ( ! $current ) { return array( 'status' => 'idle' ); }
			$remote = $this->client->get_order( $current['mp_order_id'] );
			if ( PaymentAttempt::is_final( PaymentAttempt::status( $remote ) ) ) {
				return $this->reconciler->reconcile( $order, $remote, 'cancel' );
			}
			if ( 'created' === PaymentAttempt::status( $remote ) ) {
				try {
					$this->client->cancel_order( $current['mp_order_id'], 'cancel-' . $current['attempt_id'] );
				} catch ( MercadoPagoApiException $e ) {
					// The terminal may have picked up the order; re-fetch before reporting cancellation.
				}
				$remote = $this->client->get_order( $current['mp_order_id'] );
				if ( PaymentAttempt::is_final( PaymentAttempt::status( $remote ) ) ) {
					return $this->reconciler->reconcile( $order, $remote, 'cancel' );
				}
			}
			Logger::log( 'Mercado Pago Point order must be canceled on the terminal.', array( 'order_id' => (int) $order->get_id(), 'mp_order_id' => $current['mp_order_id'], 'status' => PaymentAttempt::status( $remote ) ), 'warning' );
			return array(
				'status' => 'cancel_on_terminal',
				'retry_allowed' => false,
				'message' => __( 'The payment is already on the terminal. Cancel it on the terminal, or wait for it to expire.', 'mercadopago-terminal-for-woocommerce' ),
			);
		} );
		Logger::log( 'Mercado Pago payment cancel result', array( 'order_id' => $order->get_id(), 'mp_order_id' => (string) $order->get_meta( PaymentAttempt::META_CURRENT_MP_ORDER_ID ), 'status' => $result['status'], 'duration_ms' => Logger::elapsed_ms( $start ) ), 'info' );
		return $result;
	}

	public static function build_payload( $order, array $pending ): array {
		$payload = array(
			'type'               => 'point',
			'external_reference' => $pending['external_reference'],
			'expiration_time'    => (string) apply_filters( 'mptfwc_order_expiration_time', 'PT5M', $order ),
			'description'        => sprintf( 'Order #%s', $order->get_order_number() ),
			'transactions'       => array( 'payments' => array( array( 'amount' => $pending['amount'] ) ) ),
			'config'             => array( 'point' => array( 'terminal_id' => $pending['terminal_id'], 'print_on_terminal' => 'no_ticket' ) ),
		);
		return apply_filters( 'mptfwc_order_payload', $payload, $order );
	}
}
