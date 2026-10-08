<?php
namespace WCPOS\WooCommercePOS\MercadoPagoTerminal;

use WCPOS\WooCommercePOS\Payments\Contract\Ledger;
use WCPOS\WooCommercePOSPro\Payments\Server\Abstract_Provider_Adapter;
use WCPOS\WooCommercePOSPro\Payments\Server\Reader_Curation;
use WCPOS\WooCommercePOSPro\Payments\Server\Redactor;
use WCPOS\WooCommercePOS\MercadoPagoTerminal\Services\MercadoPagoApiException;
use WCPOS\WooCommercePOS\MercadoPagoTerminal\Services\MercadoPagoClient;
use WCPOS\WooCommercePOS\MercadoPagoTerminal\Services\TerminalService;
use WCPOS\WooCommercePOS\MercadoPagoTerminal\Utils\Money;

class Provider_Adapter extends Abstract_Provider_Adapter {
	public function provider(): string { return 'mercadopago'; }
	public function describe( \WC_Payment_Gateway $gateway ): array { return array( 'capabilities' => array( 'tips' => 'none' ) ); }

	/** All transport exceptions stay inside the provider boundary; never retry a money POST here. */
	private function call( string $method, array $args = array() ) {
		try {
			return ( new MercadoPagoClient( ( new Settings() )->access_token() ) )->$method( ...$args );
		} catch ( \Throwable $e ) {
			$status = $e instanceof MercadoPagoApiException ? $e->http_status() : 0;
			$code = 'mercadopago_' . ( $e instanceof MercadoPagoApiException && $e->error_code() ? $e->error_code() : $status );
			$message = Redactor::message( $e->getMessage() );
			if ( $status < 400 || $status >= 500 || in_array( $status, array( 409, 429 ), true ) ) {
				return $this->indeterminate( $code, $message );
			}
			return new \WP_Error( $code, $message, array( 'status' => 502, 'http_status' => $status ) );
		}
	}

	public function list_readers() {
		$result = $this->call( 'list_terminals', array( 50, 0, '', '', 8 ) );
		if ( is_wp_error( $result ) ) { return $result; }
		return array_map( static function ( $terminal ) {
			return array( 'id' => $terminal['id'], 'label' => $terminal['label'], 'status' => 'PDV' === $terminal['operating_mode'] ? 'online' : 'offline' );
		}, TerminalService::normalize( $result ) );
	}

	public function create_reader_action( array $row, string $reader_id ) {
		$order = wc_get_order( $row['order_id'] );
		$duration = (string) apply_filters( 'mptfwc_order_expiration_time', 'PT5M', $order );
		$result = $this->call( 'create_order', array( array(
			'type' => 'point', 'external_reference' => 'wcpos:' . $row['id'],
			'expiration_time' => $duration, 'description' => 'Order #' . $order->get_order_number(),
			'transactions' => array( 'payments' => array( array( 'amount' => $row['amount'] ) ) ),
			'config' => array( 'point' => array( 'terminal_id' => $reader_id, 'print_on_terminal' => 'no_ticket' ) ),
		), $row['id'] ) );
		if ( is_wp_error( $result ) ) { return $result; }
		if ( empty( $result['id'] ) ) { return $this->indeterminate( 'mercadopago_response', 'Mercado Pago returned no order id.' ); }
		$expires = null;
		try {
			if ( ! empty( $result['created_date'] ) ) { $expires = ( new \DateTimeImmutable( $result['created_date'] ) )->add( new \DateInterval( $result['expiration_time'] ?? $duration ) )->format( DATE_ATOM ); }
		} catch ( \Throwable $e ) { /* Pro supplies its default deadline when the provider omits a usable one. */ }
		return array( 'ref' => $result['id'], 'expires_at' => $expires );
	}

	public function fetch( string $ref ) {
		$result = $this->call( 'get_order', array( $ref ) );
		if ( is_wp_error( $result ) ) {
			if ( 404 !== ( $result->get_error_data()['http_status'] ?? 0 ) ) { return $result; }
			// Read the durable row, including after a PHP restart; never restart grace on a poll.
			foreach ( wc_get_orders( array( 'type' => 'shop_order', 'limit' => 5, 'meta_key' => Ledger::META_KEY, 'meta_value' => $ref, 'meta_compare' => 'LIKE' ) ) as $order ) { // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- One action id lives on one order; the bound is a guard.
				foreach ( Ledger::instance()->read( $order ) as $row ) {
					if ( ( $row['provider_refs']['action'] ?? '' ) === $ref && time() - strtotime( $row['created_at_gmt'] ) < 60 ) { return array( 'status' => 'pending' ); }
				}
			}
			return array( 'status' => 'failed', 'failure_reason' => 'provider_error' );
		}
		return $this->observation( $result );
	}

	/** One Orders-API map serves both polling and signed authoritative webhook reads. */
	private function observation( array $order ): array {
		$status = array( 'action_required' => 'in_progress', 'processed' => 'completed', 'refunded' => 'completed', 'canceled' => 'cancelled', 'expired' => 'expired', 'failed' => 'failed' )[ $order['status'] ?? '' ] ?? 'pending';
		$result = array( 'status' => $status );
		if ( 'failed' === $status ) { $result['failure_reason'] = $order['status_detail'] ?? 'provider_error'; }
		if ( 'completed' === $status ) {
			$payment = $order['transactions']['payments'][0] ?? array();
			$result['amount'] = $payment['paid_amount'] ?? $payment['amount'] ?? null;
			// Orders API carries no currency: Point charges in the account's local currency.
			$result['currency'] = get_woocommerce_currency();
			$result['provider_refs'] = array( 'transaction_id' => $order['id'], 'payment_id' => $payment['id'] ?? null );
			if ( isset( $payment['payment_method'] ) ) {
				$result['receipt'] = array( 'card_brand' => $payment['payment_method']['id'] ?? null, 'card_type' => $payment['payment_method']['type'] ?? null );
			}
		}
		return $result;
	}

	public function cancel( string $ref ) {
		$result = $this->call( 'cancel_order', array( $ref, 'cancel-' . $ref ) );
		// Mercado Pago only cancels an order while it is `created`; once the terminal has it, the
		// API refuses with a 4xx. The exact error code is unverified against a live account, so
		// any determinate refusal that is not an auth or not-found problem means "already on the
		// terminal": Pro shows the cancel-on-terminal copy and waits for the provider's expiry.
		$http_status = is_wp_error( $result ) ? (int) ( $result->get_error_data()['http_status'] ?? 0 ) : 0;
		if ( is_wp_error( $result ) && empty( $result->get_error_data()['indeterminate'] ) && $http_status >= 400 && $http_status < 500 && ! in_array( $http_status, array( 401, 403, 404 ), true ) ) {
			return new \WP_Error( 'wcpos_capture_mode_unsupported', __( 'The payment is already on the terminal. Cancel it on the terminal, or wait for it to expire.', 'mercadopago-terminal-for-woocommerce' ), array( 'status' => 501 ) );
		}
		return is_wp_error( $result ) ? $result : 'requested';
	}

	public function refund( array $row, int $refund_id, string $amount ) {
		$ref = ( $row['provider_refs']['action'] ?? '' ) ?: ( $row['provider_refs']['transaction_id'] ?? '' );
		$body = null;
		if ( ! Money::equals( $row['amount'], $amount ) ) {
			$payment_id = $row['provider_refs']['payment_id'] ?? null;
			if ( ! $payment_id ) {
				$order = $this->call( 'get_order', array( $ref ) );
				if ( is_wp_error( $order ) ) { return $order; }
				$payment_id = $order['transactions']['payments'][0]['id'] ?? null;
			}
			if ( ! $payment_id ) { return new \WP_Error( 'mercadopago_payment_missing', 'No payment reference for partial refund.', array( 'status' => 502 ) ); }
			$body = array( 'amount' => $amount, 'transaction_id' => $payment_id );
		}
		$result = $this->call( 'refund_order', array( $ref, 'refund-' . $refund_id, $body ) );
		if ( is_wp_error( $result ) ) { return $result; }
		$refunds = $result['transactions']['refunds'] ?? array();
		$refund = end( $refunds );
		return array( 'status' => array( 'processed' => 'succeeded', 'pending' => 'pending', 'in_process' => 'pending' )[ $refund['status'] ?? '' ] ?? 'failed', 'provider_ref' => $refund['id'] ?? null );
	}

	public function verify_webhook( \WP_REST_Request $request ) {
		$body = $request->get_json_params();
		$ref = (string) ( $body['data']['id'] ?? '' );
		$request_id = (string) $request->get_header( 'x-request-id' );
		if ( ! WebhookSignature::verify( (string) $request->get_header( 'x-signature' ), $request_id, $ref, ( new Settings() )->webhook_secret() ) ) {
			return new \WP_Error( 'mercadopago_signature', 'Invalid webhook signature.', array( 'status' => 401 ) );
		}
		$order = $this->call( 'get_order', array( $ref ) );
		if ( is_wp_error( $order ) ) { return $order; }
		$reference = $order['external_reference'] ?? '';
		$id = 0 === strpos( $reference, 'wcpos:' ) && wp_is_uuid( substr( $reference, 6 ) ) ? substr( $reference, 6 ) : wcpos_pro_payment_id_for_action( 'mercadopago', $ref );
		if ( ! $id ) { return new \WP_Error( 'mercadopago_payment_missing', 'No payment for order.', array( 'status' => 404 ) ); }
		$patch = $this->observation( $order );
		$patch['status'] = array( 'completed' => ! empty( $patch['authorized'] ) ? 'authorized' : 'captured', 'failed' => 'failed', 'expired' => 'failed', 'cancelled' => 'voided', 'pending' => 'pending', 'in_progress' => 'pending' )[ $patch['status'] ];
		unset( $patch['failure_reason'] );
		$patch['event_id'] = (string) ( $body['id'] ?? $request_id );
		update_option( 'mptfwc_last_verified_webhook', time(), false );
		return array( 'payment_id' => $id, 'patch' => $patch );
	}

	public function diagnostics( \WC_Payment_Gateway $gateway ): array {
		$readers = Reader_Curation::readers( $gateway->id );
		$readers = is_wp_error( $readers ) ? array() : $readers;
		$last = get_option( 'mptfwc_last_verified_webhook', 0 );
		return array( 'log_source' => 'mercadopago-terminal', 'mode' => ( new Settings() )->mode(), 'terminals' => count( $readers ), 'terminals_pdv' => count( array_filter( $readers, static function ( $r ) { return 'online' === $r['status']; } ) ), 'last_verified_webhook' => $last ? gmdate( 'c', $last ) : 'never' );
	}
}
