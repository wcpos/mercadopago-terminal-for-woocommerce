<?php
namespace WCPOS\WooCommercePOS\MercadoPagoTerminal;

use WCPOS\WooCommercePOS\MercadoPagoTerminal\Services\MercadoPagoClient;

class WebhookHandler {
	private $client_factory;

	public function __construct( ?callable $client_factory = null ) {
		$this->client_factory = $client_factory ?: function ( string $token ): MercadoPagoClient { return new MercadoPagoClient( $token ); };
		add_action( 'wp_ajax_mptfwc_webhook', array( $this, 'handle' ) );
		add_action( 'wp_ajax_nopriv_mptfwc_webhook', array( $this, 'handle' ) );
	}

	public function handle(): void {
		$result = $this->process( file_get_contents( 'php://input' ), $_SERVER['HTTP_X_SIGNATURE'] ?? '', $_SERVER['HTTP_X_REQUEST_ID'] ?? '', $_GET );
		status_header( $result['code'] );
		echo $result['body'];
		exit;
	}

	public function process( string $raw_body, string $signature, string $request_id, array $query ): array {
		$body = json_decode( $raw_body, true );
		$body = is_array( $body ) ? $body : array();
		$data_id = $body['data']['id'] ?? '';
		$data_id = sanitize_text_field( is_string( $data_id ) && '' !== $data_id ? $data_id : ( $query['data_id'] ?? '' ) );
		$settings = new Settings();
		$secret = $settings->webhook_secret();
		if ( '' !== $secret && ! WebhookSignature::verify( $signature, $request_id, $data_id, $secret ) ) {
			Logger::log( 'Mercado Pago webhook signature is invalid.', array(), 'warning' );
			return array( 'code' => 401, 'body' => 'Invalid signature' );
		}
		if ( '' === $secret ) {
			Logger::log( 'Mercado Pago webhook signature was not verified: no webhook secret configured.', array(), 'warning' );
		}
		if ( array_key_exists( 'type', $body ) && 'order' !== $body['type'] ) {
			return array( 'code' => 200, 'body' => 'Ignored' );
		}
		if ( '' === $data_id ) {
			Logger::log( 'Mercado Pago webhook received without order ID.', array(), 'warning' );
			return array( 'code' => 200, 'body' => 'OK' );
		}
		try {
			$mp_order = ( $this->client_factory )( $settings->access_token() )->get_order( $data_id );
			$order_id = PaymentAttempt::order_id_from_external_reference( $mp_order['external_reference'] ?? '' );
			if ( $order_id ) {
				$order = wc_get_order( $order_id );
			} else {
				$orders = wc_get_orders( array( 'limit' => 1, 'meta_key' => PaymentAttempt::META_CURRENT_MP_ORDER_ID, 'meta_value' => $data_id ) );
				$order = $orders[0] ?? null;
			}
			if ( ! $order ) {
				Logger::log( 'Mercado Pago webhook received for unknown order.', array( 'mp_order_id' => $data_id ), 'warning' );
				return array( 'code' => 200, 'body' => 'OK' );
			}
			$result = ( new PaymentReconciler( $settings ) )->reconcile( $order, $mp_order, 'webhook' );
			Logger::log( 'Mercado Pago webhook reconciled.', array( 'mp_order_id' => $data_id, 'order_id' => $order_id, 'status' => $result['status'] ), 'info' );
			return array( 'code' => 200, 'body' => 'OK' );
		} catch ( \Exception $e ) {
			Logger::log( $e->getMessage(), array(), 'error' );
			return array( 'code' => 500, 'body' => 'Error' );
		}
	}
}
