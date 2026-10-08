<?php
namespace WCPOS\WooCommercePOS\MercadoPagoTerminal\Tests\Conformance;

use WCPOS\WooCommercePOS\MercadoPagoTerminal\Gateway;
use WCPOS\WooCommercePOS\MercadoPagoTerminal\Provider_Adapter;
use WCPOS\WooCommercePOS\MercadoPagoTerminal\Settings;
use WCPOS\WooCommercePOS\MercadoPagoTerminal\WebhookSignature;
use WCPOS\WooCommercePOSPro\Tests\Conformance\Conformance_Fixture;
use WCPOS\WooCommercePOSPro\Payments\Server\Server_Providers;
use WCPOS\WooCommercePOSPro\Payments\Server\Reader_Curation;
use WCPOS\WooCommercePOSPro\API\V2\Payments_Webhook_Controller;

final class Mercado_Pago_Conformance_Fixture implements Conformance_Fixture {
	private $registry_property;
	private $old_registry;
	private $old_gateways;
	private $old_options;
	private $old_currency;
	private $calls = array();
	private $aliases = array();
	private $actions = array();
	public $orders = array();
	public $current;
	public $raw_calls = array();
	public $response_override;
	private $scenario = 'create_ok';
	private $states = array( 'created' );
	private $webhook_read = false;
	private $lost = false;

	public function register_gateway( array $gateways ): array { return Gateway::register_gateway( $gateways ); }
	public function gateway_id(): string { return Settings::GATEWAY_ID; }
	public function install(): void {
		$this->old_options = get_option( 'woocommerce_' . $this->gateway_id() . '_settings', array() );
		$this->old_currency = get_option( 'woocommerce_currency' );
		update_option( 'woocommerce_currency', 'EUR' );
		update_option( 'woocommerce_' . $this->gateway_id() . '_settings', array( 'enabled' => 'yes', 'mode' => 'test', 'access_token' => 'TEST-conformance', 'webhook_secret' => 'conformance-secret' ) );
		$this->registry_property = new \ReflectionProperty( Server_Providers::class, 'instance' );
		$this->registry_property->setAccessible( true );
		$this->old_registry = $this->registry_property->getValue();
		$this->registry_property->setValue( null, null );
		wcpos_pro_register_server_provider( $this->gateway_id(), Provider_Adapter::class );
		$this->old_gateways = WC()->payment_gateways;
		add_filter( 'woocommerce_payment_gateways', array( $this, 'register_gateway' ) );
		WC()->payment_gateways = new \WC_Payment_Gateways();
		Reader_Curation::forget( $this->gateway_id() );
		delete_option( 'wcpos_pro_readers_lkg_' . $this->gateway_id() );
		add_filter( 'pre_http_request', array( $this, 'http' ), 10, 3 );
	}
	public function uninstall(): void {
		remove_filter( 'woocommerce_payment_gateways', array( $this, 'register_gateway' ) );
		remove_filter( 'pre_http_request', array( $this, 'http' ), 10 );
		Reader_Curation::forget( $this->gateway_id() );
		delete_option( 'wcpos_pro_readers_lkg_' . $this->gateway_id() );
		$this->registry_property->setValue( null, $this->old_registry );
		WC()->payment_gateways = $this->old_gateways;
		update_option( 'woocommerce_' . $this->gateway_id() . '_settings', $this->old_options );
		update_option( 'woocommerce_currency', $this->old_currency );
	}
	public function supports( string $capability ): bool {
		return in_array( $capability, array( 'cancel', 'cancel_unsupported', 'cancel_requested_then_completed', 'webhook', 'refund', 'partial_refund', 'expiry', 'test_live_isolation', 'legacy_adoption', 'historical_webview_refund' ), true );
	}
	public function script( string $scenario ): void {
		$scripts = array(
			'create_ok' => array( 'created' ), 'create_indeterminate' => array( 'created' ),
			'pending_then_completed' => array( 'created', 'processed' ), 'declined' => array( 'failed' ),
			'cancel_requested_then_cancelled' => array( 'canceled' ), 'cancel_requested_then_completed' => array( 'processed' ),
			'cancel_unsupported' => array( 'created', 'expired' ), 'expired' => array( 'expired' ),
			'webhook_replay' => array( 'created' ), 'webhook_out_of_order' => array( 'created' ),
			'amount_mismatch' => array( 'processed' ), 'currency_mismatch' => array( 'processed' ),
			'refund_ok' => array( 'processed' ), 'refund_pending' => array( 'processed' ), 'refund_failed' => array( 'processed' ),
			'test_live_isolation' => array( 'created' ),
		);
		if ( ! isset( $scripts[ $scenario ] ) ) { throw new \OutOfBoundsException( $scenario ); }
		$this->scenario = $scenario;
		$this->states = $scripts[ $scenario ];
		$this->lost = false;
	}
	public static function fixture( string $name ): array {
		return json_decode( file_get_contents( dirname( __DIR__, 2 ) . '/fixtures/' . $name . '.json' ), true );
	}
	public static function response( array $body, int $status = 200 ): array {
		return array( 'headers' => array(), 'body' => wp_json_encode( $body ), 'response' => array( 'code' => $status, 'message' => '' ), 'cookies' => array() );
	}
	public function http( $pre, array $args, string $url ) {
		if ( 'api.mercadopago.com' !== wp_parse_url( $url, PHP_URL_HOST ) ) { return $pre; }
		$this->raw_calls[] = array( 'url' => $url, 'args' => $args );
		if ( null !== $this->response_override ) {
			if ( $this->response_override instanceof \Throwable ) { throw $this->response_override; }
			return $this->response_override;
		}
		$path = wp_parse_url( $url, PHP_URL_PATH );
		$mode = 0 === strpos( $args['headers']['Authorization'], 'Bearer TEST-' ) ? 'test' : 'live';
		$body = isset( $args['body'] ) ? json_decode( $args['body'], true ) : null;
		if ( '/terminals/v1/list' === $path ) { return self::response( self::fixture( 'terminals-list' ) ); }
		if ( '/terminals/v1/setup' === $path ) { return self::response( array() ); }
		if ( '/v1/orders' === $path && 'POST' === $args['method'] ) {
			$key = $mode . ':' . $args['headers']['X-Idempotency-Key'];
			if ( ! isset( $this->actions[ $key ] ) ) {
				$ref = 'ORD' . ( count( $this->actions ) + 1 );
				$this->actions[ $key ] = $ref;
				$order = array_replace_recursive( self::fixture( 'order-created' ), $body );
				$order['id'] = $ref;
				$order['created_date'] = gmdate( 'c' );
				$order['transactions']['payments'][0]['id'] = 'PAY' . count( $this->actions );
				$this->orders[ $ref ] = array( 'data' => $order, 'mode' => $mode, 'states' => $this->states );
			}
			$this->current = $this->actions[ $key ];
			$this->record( 'create', $this->current, 'amount=' . $body['transactions']['payments'][0]['amount'] . ' currency=' . get_woocommerce_currency() . ' reader=' . $body['config']['point']['terminal_id'] . ' mode=' . $mode );
			if ( 'currency_mismatch' === $this->scenario ) { update_option( 'woocommerce_currency', 'USD' ); }
			if ( 'test_live_isolation' === $this->scenario ) {
				$options = get_option( 'woocommerce_' . $this->gateway_id() . '_settings' );
				$options['mode'] = 'live'; // MP's actual environment is selected by the token, not this label.
				update_option( 'woocommerce_' . $this->gateway_id() . '_settings', $options );
				WC()->payment_gateways->payment_gateways()[ $this->gateway_id() ]->settings['mode'] = 'live';
			}
			if ( 'create_indeterminate' === $this->scenario && ! $this->lost ) {
				$this->lost = true;
				return new \WP_Error( 'http_request_failed', 'Response lost after acceptance' );
			}
			return self::response( $this->orders[ $this->current ]['data'] );
		}
		if ( ! preg_match( '#^/v1/orders/([^/]+)(?:/(cancel|refund))?$#', $path, $match ) ) { throw new \LogicException( 'Unexpected request: ' . $path ); }
		$ref = $match[1];
		$op = $match[2] ?? ( $this->webhook_read ? 'webhook' : 'fetch' );
		$details = 'mode=' . $mode;
		if ( 'refund' === $op ) { $details .= ' amount=' . ( $body['amount'] ?? $this->orders[ $ref ]['data']['transactions']['payments'][0]['amount'] ) . ' currency=' . get_woocommerce_currency() . ' transaction_id=' . ( $body['transaction_id'] ?? 'full' ); }
		$this->record( $op, $ref, $details );
		if ( ! isset( $this->orders[ $ref ] ) || $mode !== $this->orders[ $ref ]['mode'] ) { return self::response( array( 'message' => 'Not found' ), 404 ); }
		$order = &$this->orders[ $ref ];
		if ( 'cancel' === $op ) {
			return 'cancel_unsupported' === $this->scenario ? self::response( self::fixture( 'error-400' ), 400 ) : self::response( array() );
		}
		if ( 'refund' === $op ) {
			$order['data']['transactions']['refunds'][] = array( 'id' => 'REF1', 'status' => array( 'refund_pending' => 'pending', 'refund_failed' => 'failed' )[ $this->scenario ] ?? 'processed' );
			return self::response( $order['data'] );
		}
		if ( ! $this->webhook_read ) {
			$order['data']['status'] = count( $order['states'] ) > 1 ? array_shift( $order['states'] ) : $order['states'][0];
		}
		$order['data']['status_detail'] = 'failed' === $order['data']['status'] ? 'card_declined' : $order['data']['status'];
		if ( 'amount_mismatch' === $this->scenario ) { $order['data']['transactions']['payments'][0]['paid_amount'] = '1.00'; }
		return self::response( $order['data'] );
	}
	public function webhook_request( string $event ): \WP_REST_Request {
		$tampered = 'tampered' === $event;
		$event = $tampered ? 'completed' : $event;
		$states = array( 'completed' => 'processed', 'failed' => 'failed', 'cancelled' => 'canceled', 'pending' => 'created' );
		if ( ! isset( $states[ $event ] ) ) { throw new \OutOfBoundsException( $event ); }
		$this->webhook_read = true;
		$this->orders[ $this->current ]['data']['status'] = $states[ $event ];
		if ( wcpos_pro_payment_id_for_action( 'mercadopago', $this->current ) ) {
			// Model an actual 0.x action: adoption cannot add metadata at Mercado Pago.
			$this->orders[ $this->current ]['data']['external_reference'] = 'wcpos-123-legacy';
		}
		$body = self::fixture( 'webhook-order-processed' );
		$body['data']['id'] = $this->current;
		$body['data']['status'] = $states[ $event ];
		$body['id'] = $this->current . '-' . $event;
		$request = new \WP_REST_Request( 'POST', Payments_Webhook_Controller::ROUTE );
		$request->set_query_params( array( 'provider' => 'mercadopago' ) );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( $body ) );
		$request->set_header( 'x-request-id', 'conformance-request' );
		$request->set_header( 'x-signature', 'ts=1,v1=' . hash_hmac( 'sha256', WebhookSignature::manifest( $this->current, 'conformance-request', '1' ), $tampered ? 'wrong-secret' : 'conformance-secret' ) );
		return $request;
	}
	private function record( string $op, string $ref, string $details ): void {
		if ( ! isset( $this->aliases[ $ref ] ) ) { $this->aliases[ $ref ] = 'action_' . ( count( $this->aliases ) + 1 ); }
		$this->calls[] = array( 'op' => $op, 'request' => 'action=' . $this->aliases[ $ref ] . ' ' . $details );
	}
	public function transcript(): array { return $this->calls; }
	public function reset_transcript(): void { $this->calls = array(); $this->aliases = array(); }
	public function transcript_dir(): ?string { return __DIR__ . '/transcripts'; }
}
