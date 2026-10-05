<?php
use PHPUnit\Framework\TestCase;
use WCPOS\WooCommercePOS\MercadoPagoTerminal\Logger;
use WCPOS\WooCommercePOS\MercadoPagoTerminal\PaymentAttempt;
use WCPOS\WooCommercePOS\MercadoPagoTerminal\Services\MercadoPagoApiException;
use WCPOS\WooCommercePOS\MercadoPagoTerminal\Settings;
use WCPOS\WooCommercePOS\MercadoPagoTerminal\WebhookHandler;
use WCPOS\WooCommercePOS\MercadoPagoTerminal\WebhookSignature;

class WebhookHandlerTest extends TestCase {
	private const SECRET = 'test-webhook-secret';
	private const DATA_ID = 'ORD01JYH1Z1YJN4HZ8J3Q0RB3YP6D';
	private const REQUEST_ID = 'bb56a2f1-6aae-46ac-982e-9dcd3581d08e';
	private $order;
	private $client;
	private $handler;
	private $body;
	private $signature;
	private $tokens;

	protected function setUp(): void {
		WP_Stub::reset();
		Logger::$logger = null;
		Logger::$log_level = null;
		WP_Stub::$options['woocommerce_' . Settings::GATEWAY_ID . '_settings'] = array( 'access_token' => 'TEST-token', 'webhook_secret' => self::SECRET );
		$GLOBALS['wpdb'] = new MPTFWC_Fake_Wpdb();
		$this->order = new MPTFWC_Test_Order( 123 );
		$GLOBALS['mptfwc_orders'] = array( 123 => $this->order );
		$GLOBALS['mptfwc_order_query_results'] = array();
		$pending = PaymentAttempt::prepare( $this->order, 'NEWLAND_N950__SBX0000001', '24.00' );
		$created = $this->fixture( 'order-created' );
		$created['external_reference'] = $pending['external_reference'];
		PaymentAttempt::record_created( $this->order, $pending, $created );
		$processed = $this->fixture( 'order-processed' );
		$processed['external_reference'] = $pending['external_reference'];
		$this->client = new FakeMercadoPagoClient();
		$this->client->returns['get_order'] = array( $processed );
		$this->tokens = array();
		$this->handler = new WebhookHandler( function ( string $token ) {
			$this->tokens[] = $token;
			return $this->client;
		} );
		$this->body = file_get_contents( dirname( __DIR__ ) . '/fixtures/webhook-order-processed.json' );
		$this->signature = $this->signature( self::DATA_ID );
	}

	private function fixture( string $name ): array {
		return json_decode( file_get_contents( dirname( __DIR__ ) . '/fixtures/' . $name . '.json' ), true );
	}

	private function signature( string $data_id ): string {
		return 'ts=1742505638683,v1=' . hash_hmac( 'sha256', WebhookSignature::manifest( $data_id, self::REQUEST_ID, '1742505638683' ), self::SECRET );
	}

	public function test_valid_signature_fetches_and_completes_order(): void {
		$this->assertSame( array( 'code' => 200, 'body' => 'OK' ), $this->handler->process( $this->body, $this->signature, self::REQUEST_ID, array() ) );
		$this->assertSame( array( 'TEST-token' ), $this->tokens );
		$this->assertSame( array( array( 'method' => 'get_order', 'args' => array( self::DATA_ID ) ) ), $this->client->calls );
		$this->assertTrue( $this->order->is_paid() );
		$this->assertSame( 1, $this->order->payment_complete_calls );
		$this->assertSame( self::DATA_ID, $this->order->get_transaction_id() );
		$this->assertStringContainsString( 'via webhook', implode( ' ', $this->order->notes ) );
		$this->assertStringContainsString( '"status":"paid"', end( WP_Stub::$logs )['message'] );
	}

	public function test_invalid_signature_rejects_without_client_or_order_changes(): void {
		$before = clone $this->order;
		$this->assertSame( array( 'code' => 401, 'body' => 'Invalid signature' ), $this->handler->process( $this->body, 'ts=1,v1=invalid', self::REQUEST_ID, array() ) );
		$this->assertSame( array(), $this->tokens );
		$this->assertSame( array(), $this->client->calls );
		$this->assertEquals( $before, $this->order );
		$this->assertSame( 'warning', end( WP_Stub::$logs )['level'] );
		$this->assertStringNotContainsString( self::SECRET, end( WP_Stub::$logs )['message'] );
		$this->assertStringNotContainsString( 'ts=1,v1=invalid', end( WP_Stub::$logs )['message'] );
	}

	public function test_no_secret_processes_and_logs_warning(): void {
		WP_Stub::$options['woocommerce_' . Settings::GATEWAY_ID . '_settings']['webhook_secret'] = '';
		$this->assertSame( array( 'code' => 200, 'body' => 'OK' ), $this->handler->process( $this->body, '', self::REQUEST_ID, array() ) );
		$this->assertTrue( $this->order->is_paid() );
		$warnings = array_values( array_filter( WP_Stub::$logs, function ( $entry ) { return 'warning' === $entry['level']; } ) );
		$this->assertCount( 1, $warnings );
		$this->assertStringContainsString( 'signature not verified', $warnings[0]['message'] );
	}

	public function test_payment_type_is_ignored_without_client_call(): void {
		$body = json_decode( $this->body, true );
		$body['type'] = 'payment';
		$this->assertSame( array( 'code' => 200, 'body' => 'Ignored' ), $this->handler->process( json_encode( $body ), $this->signature, self::REQUEST_ID, array() ) );
		$this->assertSame( array(), $this->tokens );
		$this->assertSame( array(), $this->client->calls );
		$this->assertFalse( $this->order->is_paid() );
	}

	public function test_query_data_id_is_used(): void {
		$this->assertSame( array( 'code' => 200, 'body' => 'OK' ), $this->handler->process( '{"type":"order"}', $this->signature( self::DATA_ID ), self::REQUEST_ID, array( 'data_id' => self::DATA_ID ) ) );
		$this->assertSame( array( array( 'method' => 'get_order', 'args' => array( self::DATA_ID ) ) ), $this->client->calls );
		$this->assertTrue( $this->order->is_paid() );
	}

	public function test_unknown_woocommerce_order_is_acknowledged(): void {
		$this->client->returns['get_order'][0]['external_reference'] = 'wcpos-999-a1b2c3d4';
		$GLOBALS['mptfwc_order_query_results'] = array();
		$this->assertSame( array( 'code' => 200, 'body' => 'OK' ), $this->handler->process( $this->body, $this->signature, self::REQUEST_ID, array() ) );
		$this->assertSame( 0, $this->order->payment_complete_calls );
		$this->assertFalse( $this->order->is_paid() );
	}

	public function test_api_exception_returns_error_for_retry(): void {
		$this->client->returns['get_order'] = array( new MercadoPagoApiException( 'API unavailable', 503 ) );
		$this->assertSame( array( 'code' => 500, 'body' => 'Error' ), $this->handler->process( $this->body, $this->signature, self::REQUEST_ID, array() ) );
		$this->assertFalse( $this->order->is_paid() );
		$this->assertSame( 'error', end( WP_Stub::$logs )['level'] );
		$this->assertStringContainsString( 'API unavailable', end( WP_Stub::$logs )['message'] );
	}

	public function test_body_status_cannot_complete_unpaid_fetched_order(): void {
		$this->client->returns['get_order'][0]['status'] = 'at_terminal';
		$this->assertSame( 'processed', json_decode( $this->body, true )['data']['status'] );
		$this->assertSame( array( 'code' => 200, 'body' => 'OK' ), $this->handler->process( $this->body, $this->signature, self::REQUEST_ID, array() ) );
		$this->assertSame( 0, $this->order->payment_complete_calls );
		$this->assertFalse( $this->order->is_paid() );
		$this->assertSame( 'at_terminal', PaymentAttempt::current( $this->order )['status'] );
	}

	public function test_missing_id_is_acknowledged_without_client_call(): void {
		$this->assertSame( array( 'code' => 200, 'body' => 'OK' ), $this->handler->process( '{}', $this->signature( '' ), self::REQUEST_ID, array() ) );
		$this->assertSame( array(), $this->client->calls );
		$this->assertSame( 'warning', end( WP_Stub::$logs )['level'] );
	}

	public function test_invalid_json_uses_query_id(): void {
		$this->assertSame( array( 'code' => 200, 'body' => 'OK' ), $this->handler->process( '{', $this->signature, self::REQUEST_ID, array( 'data_id' => self::DATA_ID ) ) );
		$this->assertTrue( $this->order->is_paid() );
	}

	public function test_handler_registers_both_ajax_hooks(): void {
		$this->assertSame( array( 'wp_ajax_mptfwc_webhook', 'wp_ajax_nopriv_mptfwc_webhook' ), array_column( WP_Stub::$actions, 'hook' ) );
		foreach ( WP_Stub::$actions as $action ) {
			$this->assertSame( array( $this->handler, 'handle' ), $action['callback'] );
		}
	}

	public function test_php_type_error_returns_clean_500(): void {
		$this->client->returns['get_order'] = array( new TypeError( 'Invalid response' ) );
		$this->assertSame( array( 'code' => 500, 'body' => 'Error' ), $this->handler->process( $this->body, $this->signature, self::REQUEST_ID, array() ) );
		$this->assertSame( 0, $this->order->payment_complete_calls );
		$this->assertSame( 'error', end( WP_Stub::$logs )['level'] );
	}
}
