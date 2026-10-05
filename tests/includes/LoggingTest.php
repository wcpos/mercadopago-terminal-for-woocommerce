<?php
use PHPUnit\Framework\TestCase;
use WCPOS\WooCommercePOS\MercadoPagoTerminal\Logger;
use WCPOS\WooCommercePOS\MercadoPagoTerminal\PaymentAttempt;
use WCPOS\WooCommercePOS\MercadoPagoTerminal\PaymentReconciler;
use WCPOS\WooCommercePOS\MercadoPagoTerminal\RefundHandler;
use WCPOS\WooCommercePOS\MercadoPagoTerminal\Services\MercadoPagoApiException;
use WCPOS\WooCommercePOS\MercadoPagoTerminal\Services\MercadoPagoClient;
use WCPOS\WooCommercePOS\MercadoPagoTerminal\Services\PointPaymentService;
use WCPOS\WooCommercePOS\MercadoPagoTerminal\Settings;
use WCPOS\WooCommercePOS\MercadoPagoTerminal\WebhookHandler;
use WCPOS\WooCommercePOS\MercadoPagoTerminal\WebhookSignature;

class MPTFWC_Logging_Test_Order extends MPTFWC_Test_Order {
	public function get_order_number(): string {
		return (string) $this->id;
	}
}

class LoggingTest extends TestCase {
	protected function setUp(): void {
		WP_Stub::reset();
		Logger::reset_request();
		Logger::$logger = null;
		Logger::$log_level = null;
		Logger::configure( 'debug' );
	}

	protected function tearDown(): void {
		WP_Stub::reset();
		Logger::reset_request();
		Logger::$logger = null;
		Logger::configure( 'debug' );
	}

	private function entry( string $message ): array {
		$entries = array_values( array_filter( WP_Stub::$logs, function ( $entry ) use ( $message ) { return false !== strpos( $entry['message'], '] ' . $message ); } ) );
		$this->assertCount( 1, $entries, $message );
		return $entries[0];
	}

	private function context( string $message ): array {
		$line = $this->entry( $message )['message'];
		return json_decode( substr( $line, strpos( $line, ' {' ) + 1 ), true );
	}

	/** @dataProvider levels */
	public function test_level_filtering( string $level, array $expected ): void {
		Logger::configure( $level );
		foreach ( array( 'debug', 'info', 'success', 'warning', 'error' ) as $severity ) { Logger::log( $severity, array(), $severity ); }
		$this->assertSame( $expected, array_column( WP_Stub::$logs, 'level' ) );
	}

	public function levels(): array {
		return array(
			array( 'off', array() ),
			array( 'errors', array( 'warning', 'error' ) ),
			array( 'debug', array( 'info', 'debug', 'info', 'info', 'warning', 'error' ) ),
			array( 'invalid', array( 'info', 'debug', 'info', 'info', 'warning', 'error' ) ),
		);
	}

	public function test_environment_is_first_once_and_request_id_is_stable(): void {
		Logger::log( 'First' );
		Logger::log( 'Second' );
		$id = Logger::request_id();
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{8}$/', $id );
		$this->assertCount( 3, WP_Stub::$logs );
		$this->assertSame( '[' . $id . '] ' . sprintf( 'Environment: plugin %s, WooCommerce %s, WordPress %s, PHP %s, WCPOS %s, log level debug', MPTFWC_VERSION, defined( 'WC_VERSION' ) ? WC_VERSION : 'n/a', $GLOBALS['wp_version'] ?? 'n/a', PHP_VERSION, defined( 'WCPOS_VERSION' ) ? WCPOS_VERSION : 'n/a' ), WP_Stub::$logs[0]['message'] );
		foreach ( WP_Stub::$logs as $entry ) { $this->assertStringStartsWith( '[' . $id . '] ', $entry['message'] ); }
		Logger::reset_request();
		Logger::log( 'Next request' );
		$this->assertStringContainsString( '] Environment:', WP_Stub::$logs[3]['message'] );
		$this->assertStringStartsWith( '[' . Logger::request_id() . '] ', WP_Stub::$logs[4]['message'] );
	}

	/** @dataProvider redactions */
	public function test_redaction_in_message_and_nested_context( string $input, string $expected ): void {
		Logger::log( $input, array( 'nested' => array( 'value' => $input ) ) );
		$line = end( WP_Stub::$logs )['message'];
		$this->assertSame( '[' . Logger::request_id() . '] ' . $expected . ' ' . json_encode( array( 'nested' => array( 'value' => $expected ) ) ), $line );
	}

	public function redactions(): array {
		return array(
			array( 'Bearer abc.def+/= rest', 'Bearer *** rest' ),
			array( '"Bearer abc.def"', '"Bearer ***"' ),
			array( 'APP_USR-1234567890abcdefghij', 'APP_USR-***' ),
			array( 'TEST-1234567890abcdefghij', 'TEST-***' ),
			array( 'ts=1742505638683,v1=' . str_repeat( 'a', 64 ), 'ts=1742505638683,v1=***' ),
			array( '4111111111111111', '****1111' ),
			array( '4111 1111 1111 1111', '****1111' ),
			array( '5500-0000-0000-0004', '****0004' ),
			array( '4222222222222', '****2222' ),
			array( '4000000000000000006', '****0006' ),
			array( '4111111111111112', '4111111111111112' ),
			array( '11111111111111111111', '11111111111111111111' ),
			array( 'ORD01JYH1Z1YJN4HZ8J3Q0RB3YP6D', 'ORD01JYH1Z1YJN4HZ8J3Q0RB3YP6D' ),
		);
	}

	public function test_sensitive_keys_and_idempotency_exceptions(): void {
		$values = array_fill_keys( array( 'access_token', 'webhook_secret', 'Authorization', 'x-signature', 'card_number', 'security_code', 'CVV', 'Password', 'api_key', 'bearer' ), 'private-value' );
		$visible = array( 'X-Idempotency-Key' => 'retry-1', 'idempotency_key' => 'retry-2' );
		Logger::log( 'Keys', array( 'nested' => $values + $visible ) );
		$this->assertSame( array( 'nested' => array_fill_keys( array_keys( $values ), '***' ) + $visible ), $this->context( 'Keys' ) );
		$this->assertStringNotContainsString( 'private-value', json_encode( WP_Stub::$logs ) );
	}

	public function test_context_strings_have_their_own_truncation_limit(): void {
		Logger::log( 'Limits', array( 'nested' => array( str_repeat( 'a', 1001 ), str_repeat( 'b', 4000 ), str_repeat( 'c', 4001 ) ) ) );
		$this->assertSame( array( 'nested' => array( str_repeat( 'a', 1001 ), str_repeat( 'b', 4000 ), str_repeat( 'c', 4000 ) . '…' ) ), $this->context( 'Limits' ) );
	}

	public function test_api_request_and_response_are_timed_and_redacted(): void {
		$token = 'TEST-token-1234567890abcdefghij';
		$body = array( 'id' => 'ORD1', 'access_token' => $token, 'note' => '4111111111111111' );
		WP_Stub::$http_responses[] = array( 'response' => array( 'code' => 201 ), 'headers' => array( 'x-request-id' => 'req-1' ), 'body' => json_encode( $body ) );
		$this->assertSame( $body, ( new MercadoPagoClient( $token ) )->create_order( array( 'description' => '4111 1111 1111 1111' ), 'retry-1' ) );
		$request = $this->context( 'Mercado Pago API request: POST /v1/orders' );
		$this->assertSame( 'POST', $request['method'] );
		$this->assertSame( MercadoPagoClient::BASE_URL . '/v1/orders', $request['url'] );
		$this->assertSame( array( 'Authorization' => '***', 'Content-Type' => 'application/json', 'X-Idempotency-Key' => 'retry-1' ), $request['headers'] );
		$this->assertSame( array( 'description' => '****1111' ), $request['body'] );
		$this->assertSame( 30, $request['timeout'] );
		$response = $this->context( 'Mercado Pago API response: POST /v1/orders HTTP 201 in ' );
		$this->assertSame( 201, $response['status'] );
		$this->assertIsInt( $response['duration_ms'] );
		$this->assertGreaterThanOrEqual( 0, $response['duration_ms'] );
		$this->assertSame( 'req-1', $response['response_headers']['x-request-id'] );
		$this->assertSame( array( 'id' => 'ORD1', 'access_token' => '***', 'note' => '****1111' ), $response['body'] );
		foreach ( WP_Stub::$logs as $entry ) {
			$this->assertStringNotContainsString( $token, json_encode( $entry ) );
			$this->assertStringNotContainsString( '4111111111111111', json_encode( $entry ) );
		}
	}

	/** @dataProvider api_failures */
	public function test_api_failures_are_redacted_and_timed( int $status, bool $json ): void {
		$private = 'Bearer private-value v1=' . str_repeat( 'a', 64 ) . ' 4111111111111111';
		WP_Stub::$http_responses[] = 0 === $status ? new WP_Error( 'transport', $private ) : array( 'response' => array( 'code' => $status ), 'headers' => new ArrayIterator( array( 'x-request-id' => 'failed-1' ) ), 'body' => $json ? json_encode( array( 'message' => $private, 'error' => 'bad_request' ) ) : $private );
		try {
			( new MercadoPagoClient( 'TEST-token-1234567890abcdefghij' ) )->get_order( 'ORD1' );
			$this->fail( 'Expected an API exception.' );
		} catch ( MercadoPagoApiException $e ) {
			$this->assertSame( $status, $e->http_status() );
		}
		$error = end( WP_Stub::$logs );
		$this->assertSame( 'error', $error['level'] );
		$context = json_decode( substr( $error['message'], strpos( $error['message'], ' {' ) + 1 ), true );
		$this->assertIsInt( $context['duration_ms'] );
		$this->assertSame( 0 === $status ? 'transport' : ( $json ? 'bad_request' : '' ), $context['error_code'] );
		if ( $status ) {
			$this->assertSame( $status, $context['status'] );
			$this->assertSame( 'failed-1', $context['response_headers']['x-request-id'] );
			$this->assertSame( $json ? array( 'message' => 'Bearer *** v1=*** ****1111', 'error' => 'bad_request' ) : 'Bearer *** v1=*** ****1111', $context['body'] );
		}
		foreach ( array( 'private-value', str_repeat( 'a', 64 ), '4111111111111111', 'TEST-token-1234567890abcdefghij' ) as $secret ) { $this->assertStringNotContainsString( $secret, json_encode( WP_Stub::$logs ) ); }
	}

	public function api_failures(): array { return array( array( 0, false ), array( 400, true ), array( 502, false ), array( 200, false ) ); }

	/** @dataProvider signatures */
	public function test_webhook_signature_results_never_expose_secrets( string $mode, string $message, string $level ): void {
		$secret = 'private-webhook-secret';
		$hex = hash_hmac( 'sha256', WebhookSignature::manifest( 'ORD1', 'req-1', '1742505638683' ), $secret );
		WP_Stub::$options[ 'woocommerce_' . Settings::GATEWAY_ID . '_settings' ] = array( 'webhook_secret' => 'missing' === $mode ? '' : $secret );
		$signature = 'ts=1742505638683,v1=' . ( 'invalid' === $mode ? str_repeat( 'a', 64 ) : $hex );
		( new WebhookHandler() )->process( json_encode( array( 'type' => 'payment', 'data' => array( 'id' => 'ORD1' ), 'webhook_secret' => $secret ) ), $signature, 'req-1', array( 'note' => '4111111111111111' ) );
		$received = $this->context( 'Mercado Pago webhook received' );
		$this->assertSame( '***', $received['body']['webhook_secret'] );
		$this->assertSame( array( 'note' => '****1111' ), $received['query'] );
		$this->assertSame( 'req-1', $received['x_request_id'] );
		$this->assertSame( '1742505638683', $received['sig_ts'] );
		$this->assertTrue( $received['sig_present'] );
		$this->assertSame( 'ORD1', $received['data_id'] );
		$this->assertSame( $level, $this->entry( 'Mercado Pago webhook signature ' . $message )['level'] );
		$this->assertCount( 1, array_filter( WP_Stub::$logs, function ( $entry ) { return false !== strpos( $entry['message'], '] Mercado Pago webhook signature ' ); } ) );
		if ( 'invalid' !== $mode ) { $this->assertIsInt( $this->context( 'Mercado Pago webhook ignored type payment' )['duration_ms'] ); }
		foreach ( array( $hex, str_repeat( 'a', 64 ), $secret, '4111111111111111' ) as $value ) { $this->assertStringNotContainsString( $value, json_encode( WP_Stub::$logs ) ); }
	}

	public function signatures(): array {
		return array( array( 'valid', 'valid', 'debug' ), array( 'invalid', 'invalid', 'warning' ), array( 'missing', 'not verified: no webhook secret configured', 'warning' ) );
	}

	public function test_state_transitions_log_before_and_after_only_when_changed(): void {
		$order = new MPTFWC_Test_Order( 123 );
		$pending = PaymentAttempt::prepare( $order, 'T1', '24.00' );
		PaymentAttempt::record_created( $order, $pending, array( 'id' => 'ORD1', 'status' => 'created' ) );
		$processed = array( 'id' => 'ORD1', 'status' => 'processed', 'status_detail' => 'accredited' );
		PaymentAttempt::update_status( $order, $processed );
		PaymentAttempt::update_status( $order, $processed );
		$this->assertCount( 4, WP_Stub::$logs );
		$this->assertStringContainsString( '] Payment attempt prepared ', WP_Stub::$logs[1]['message'] );
		$this->assertStringContainsString( '] Mercado Pago order created ', WP_Stub::$logs[2]['message'] );
		$this->assertSame( array( 'order_id' => 123, 'mp_order_id' => 'ORD1', 'from' => 'created', 'to' => 'processed', 'status_detail' => 'accredited' ), $this->context( 'Payment status changed' ) );
		$this->assertSame( $pending['idempotency_key'], $this->context( 'Payment attempt prepared' )['idempotency_key'] );
	}

	public function test_logger_failure_cannot_escape_to_the_caller(): void {
		WP_Stub::$logger = new class {
			public function log( $level, $message, $context ): void { throw new RuntimeException( 'Logging unavailable' ); }
		};
		Logger::log( 'Payment continues' );
		$this->assertSame( array(), WP_Stub::$logs );
		Logger::$logger = null;
		WP_Stub::$logger = null;
		add_filter( 'mptfwc_logging', function () { throw new Error( 'Filter failed' ); } );
		Logger::log( 'Payment still continues' );
		$this->assertSame( array(), WP_Stub::$logs );
	}

	public function test_pending_reuse_and_discard_are_logged(): void {
		$order = new MPTFWC_Test_Order( 123 );
		$pending = PaymentAttempt::prepare( $order, 'T1', '24.00' );
		PaymentAttempt::prepare( $order, 'T1', '24.00' );
		$context = $this->context( 'Payment attempt reused after an earlier failed create' );
		foreach ( array( 'attempt_id', 'external_reference', 'idempotency_key', 'terminal_id', 'amount' ) as $key ) { $this->assertSame( $pending[ $key ], $context[ $key ] ); }
		PaymentAttempt::discard_pending( $order, $pending );
		$this->assertSame( 'warning', $this->entry( 'Payment attempt rejected by Mercado Pago; key discarded' )['level'] );
		$this->assertSame( array( 'order_id' => 123, 'attempt_id' => $pending['attempt_id'] ), $this->context( 'Payment attempt rejected by Mercado Pago; key discarded' ) );
	}

	public function test_reconciliation_results_are_logged(): void {
		$GLOBALS['wpdb'] = new MPTFWC_Fake_Wpdb();
		$order = new MPTFWC_Test_Order( 123 );
		$GLOBALS['mptfwc_orders'] = array( 123 => $order );
		$pending = PaymentAttempt::prepare( $order, 'T1', '24.00' );
		$remote = array( 'id' => 'ORD1', 'external_reference' => $pending['external_reference'], 'status' => 'processed', 'transactions' => array( 'payments' => array( array( 'id' => 'PAY1', 'amount' => '24.00' ) ) ) );
		$reconciler = new PaymentReconciler();
		$reconciler->reconcile( $order, $remote, 'poll' );
		$this->assertSame( array( 'order_id' => 123, 'mp_order_id' => 'ORD1', 'payment_id' => 'PAY1', 'source' => 'poll' ), $this->context( 'Mercado Pago payment completed' ) );
		$this->assertSame( 'info', $this->entry( 'Mercado Pago payment completed' )['level'] );
		$reconciler->reconcile( $order, $remote, 'webhook' );
		$this->assertSame( 'debug', $this->entry( 'Mercado Pago payment already completed' )['level'] );
		$order->set_transaction_id( 'OTHER' );
		$reconciler->reconcile( $order, $remote, 'poll' );
		$this->assertSame( 'warning', $this->entry( 'Mercado Pago payment conflict:' )['level'] );
		$remote['transactions']['payments'][0]['amount'] = '23.00';
		$reconciler->reconcile( $order, $remote, 'poll' );
		$this->assertSame( 'warning', $this->entry( 'Mercado Pago payment verification failed' )['level'] );
		$this->assertSame( array( 'amount mismatch' ), $this->context( 'Mercado Pago payment verification failed' )['errors'] );
		unset( $GLOBALS['mptfwc_orders'], $GLOBALS['wpdb'] );
	}

	public function test_service_start_poll_cancel_results_have_timings(): void {
		$GLOBALS['wpdb'] = new MPTFWC_Fake_Wpdb();
		$order = new MPTFWC_Logging_Test_Order( 123 );
		$client = new FakeMercadoPagoClient();
		$client->returns['list_terminals'] = array( array( 'data' => array( 'terminals' => array( array( 'id' => 'T1', 'operating_mode' => 'PDV' ) ) ) ) );
		$client->returns['create_order'] = array( array( 'id' => 'ORD1', 'status' => 'created' ) );
		$service = new PointPaymentService( $client, new Settings() );
		$service->start_payment_for_order( $order, 'T1' );
		$remote = array( 'id' => 'ORD1', 'status' => 'created', 'external_reference' => PaymentAttempt::current( $order )['external_reference'] );
		$canceled = array_merge( $remote, array( 'status' => 'canceled' ) );
		$client->returns['get_order'] = array( $remote, $canceled );
		$service->poll_order( $order );
		$service->cancel_order_payment( $order );
		foreach ( array( 'start' => 'created', 'poll' => 'created', 'cancel' => 'canceled' ) as $operation => $status ) {
			$message = 'Mercado Pago payment ' . $operation . ' result';
			$context = $this->context( $message );
			$this->assertSame( 123, $context['order_id'] );
			$this->assertSame( 'ORD1', $context['mp_order_id'] );
			$this->assertSame( $status, $context['status'] );
			$this->assertIsInt( $context['duration_ms'] );
			$this->assertSame( 'poll' === $operation ? 'debug' : 'info', $this->entry( $message )['level'] );
		}
		unset( $GLOBALS['wpdb'] );
	}

	/** @dataProvider refund_results */
	public function test_refund_results_and_redacted_failures_are_logged( bool $fail ): void {
		$order = new MPTFWC_Test_Order( 123 );
		$order->set_transaction_id( 'ORD1' );
		$order->refunds = array( new MPTFWC_Test_Refund( 501, '24.00' ) );
		$client = new FakeMercadoPagoClient();
		$client->returns['refund_order'] = array( $fail ? new MercadoPagoApiException( 'Bearer private-value 4111111111111111', 400, 'bad_request' ) : array( 'status' => 'refunded', 'transactions' => array( 'refunds' => array( array( 'id' => 'REF1' ) ) ) ) );
		( new RefundHandler( $client ) )->process_refund( $order, '24.00' );
		$this->assertSame( array( 'order_id' => 123, 'mp_order_id' => 'ORD1', 'refund_id' => 501, 'amount' => '24.00', 'full' => true, 'idempotency_key' => 'refund-501' ), $this->context( 'Mercado Pago refund requested' ) );
		if ( $fail ) {
			$this->assertSame( 'error', $this->entry( 'Mercado Pago refund failed:' )['level'] );
			$this->assertSame( array( 'http_status' => 400, 'error_code' => 'bad_request' ), $this->context( 'Mercado Pago refund failed:' ) );
		} else {
			$this->assertSame( 'info', $this->entry( 'Mercado Pago refund succeeded' )['level'] );
			$this->assertSame( array( 'mp_refund_id' => 'REF1', 'status' => 'refunded' ), $this->context( 'Mercado Pago refund succeeded' ) );
		}
		foreach ( array( 'private-value', '4111111111111111' ) as $secret ) { $this->assertStringNotContainsString( $secret, json_encode( WP_Stub::$logs ) ); }
	}

	public function refund_results(): array { return array( array( false ), array( true ) ); }

	public function test_bootstrap_pushes_configured_level_before_plugin_init(): void {
		WP_Stub::$options[ 'woocommerce_' . Settings::GATEWAY_ID . '_settings' ] = array( 'log_level' => 'off' );
		add_action( 'mptfwc_init', function () { Logger::log( 'Hidden at init' ); } );
		\WCPOS\WooCommercePOS\MercadoPagoTerminal\init();
		$this->assertSame( array(), WP_Stub::$logs );
	}
}
