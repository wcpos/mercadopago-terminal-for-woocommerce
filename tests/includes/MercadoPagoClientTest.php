<?php
use PHPUnit\Framework\TestCase;
use WCPOS\WooCommercePOS\MercadoPagoTerminal\Logger;
use WCPOS\WooCommercePOS\MercadoPagoTerminal\Services\MercadoPagoApiException;
use WCPOS\WooCommercePOS\MercadoPagoTerminal\Services\MercadoPagoClient;

class MercadoPagoClientTest extends TestCase {
	private const ORDER_ID = 'ORD01JYH1Z1YJN4HZ8J3Q0RB3YP6D';
	private const PAYMENT_ID = 'PAY01JYH1Z1YJN4HZ8J3Q0RB3YP6E';

	protected function setUp(): void {
		WP_Stub::reset();
		Logger::$logger = null;
		Logger::$log_level = null;
	}

	private function fixture( string $name ): string {
		return file_get_contents( dirname( __DIR__ ) . '/fixtures/' . $name . '.json' );
	}

	private function queue_response( int $status, string $body ): void {
		WP_Stub::$http_responses[] = array( 'response' => array( 'code' => $status ), 'body' => $body );
	}

	public function test_create_order_sends_payload_and_idempotency_header(): void {
		$payload = array( 'type' => 'point', 'total_amount' => '24.00' );
		$this->queue_response( 201, $this->fixture( 'order-created' ) );
		$this->assertSame( json_decode( $this->fixture( 'order-created' ), true ), ( new MercadoPagoClient( 'TEST-token' ) )->create_order( $payload, 'key-1' ) );
		$this->assertCount( 1, WP_Stub::$http_requests );
		$request = WP_Stub::$http_requests[0];
		$this->assertSame( MercadoPagoClient::BASE_URL . '/v1/orders', $request['url'] );
		$this->assertSame( 'POST', $request['args']['method'] );
		$this->assertSame( 30, $request['args']['timeout'] );
		$this->assertSame( array( 'Authorization' => 'Bearer TEST-token', 'Content-Type' => 'application/json', 'X-Idempotency-Key' => 'key-1' ), $request['args']['headers'] );
		$this->assertSame( $payload, json_decode( $request['args']['body'], true ) );
	}

	public function test_create_order_rejects_empty_idempotency_key_without_request(): void {
		$this->expectException( InvalidArgumentException::class );
		try {
			( new MercadoPagoClient( 'TEST-token' ) )->create_order( array(), '  ' );
		} finally {
			$this->assertSame( array(), WP_Stub::$http_requests );
		}
	}

	public function test_get_order_sends_get_without_body_or_idempotency(): void {
		$this->queue_response( 200, $this->fixture( 'order-processed' ) );
		$this->assertSame( json_decode( $this->fixture( 'order-processed' ), true ), ( new MercadoPagoClient( 'TEST-token' ) )->get_order( self::ORDER_ID ) );
		$request = WP_Stub::$http_requests[0];
		$this->assertSame( MercadoPagoClient::BASE_URL . '/v1/orders/' . self::ORDER_ID, $request['url'] );
		$this->assertSame( 'GET', $request['args']['method'] );
		$this->assertArrayNotHasKey( 'body', $request['args'] );
		$this->assertArrayNotHasKey( 'X-Idempotency-Key', $request['args']['headers'] );
	}

	public function test_cancel_order_sends_post_without_body(): void {
		$this->queue_response( 200, $this->fixture( 'order-canceled' ) );
		$this->assertSame( json_decode( $this->fixture( 'order-canceled' ), true ), ( new MercadoPagoClient( 'TEST-token' ) )->cancel_order( self::ORDER_ID, 'cancel-1' ) );
		$request = WP_Stub::$http_requests[0];
		$this->assertSame( MercadoPagoClient::BASE_URL . '/v1/orders/' . self::ORDER_ID . '/cancel', $request['url'] );
		$this->assertSame( 'POST', $request['args']['method'] );
		$this->assertSame( 'cancel-1', $request['args']['headers']['X-Idempotency-Key'] );
		$this->assertArrayNotHasKey( 'body', $request['args'] );
	}

	public function test_refund_order_sends_full_and_partial_refunds(): void {
		$this->queue_response( 200, $this->fixture( 'order-refunded' ) );
		$this->queue_response( 200, $this->fixture( 'order-refunded' ) );
		$client = new MercadoPagoClient( 'TEST-token' );
		$this->assertSame( json_decode( $this->fixture( 'order-refunded' ), true ), $client->refund_order( self::ORDER_ID, 'refund-1' ) );
		$payload = array( 'amount' => '5.00', 'transaction_id' => self::PAYMENT_ID );
		$this->assertSame( json_decode( $this->fixture( 'order-refunded' ), true ), $client->refund_order( self::ORDER_ID, 'refund-2', $payload ) );
		$this->assertCount( 2, WP_Stub::$http_requests );
		foreach ( WP_Stub::$http_requests as $index => $request ) {
			$this->assertSame( MercadoPagoClient::BASE_URL . '/v1/orders/' . self::ORDER_ID . '/refund', $request['url'] );
			$this->assertSame( 'POST', $request['args']['method'] );
			$this->assertSame( 'refund-' . ( $index + 1 ), $request['args']['headers']['X-Idempotency-Key'] );
		}
		$this->assertArrayNotHasKey( 'body', WP_Stub::$http_requests[0]['args'] );
		$this->assertSame( $payload, json_decode( WP_Stub::$http_requests[1]['args']['body'], true ) );
	}

	public function test_list_terminals_sends_query_and_returns_data(): void {
		$this->queue_response( 200, $this->fixture( 'terminals-list' ) );
		$this->assertSame( json_decode( $this->fixture( 'terminals-list' ), true ), ( new MercadoPagoClient( 'TEST-token' ) )->list_terminals( 50, 0, 'S1' ) );
		$request = WP_Stub::$http_requests[0];
		$this->assertSame( MercadoPagoClient::BASE_URL . '/terminals/v1/list?limit=50&offset=0&store_id=S1', $request['url'] );
		$this->assertSame( 'GET', $request['args']['method'] );
		$this->assertArrayNotHasKey( 'body', $request['args'] );
	}

	public function test_set_operating_mode_sends_patch(): void {
		$this->queue_response( 200, '{}' );
		$this->assertSame( array(), ( new MercadoPagoClient( 'TEST-token' ) )->set_operating_mode( 'NEWLAND_N950__SBX0000001', 'PDV' ) );
		$request = WP_Stub::$http_requests[0];
		$this->assertSame( MercadoPagoClient::BASE_URL . '/terminals/v1/setup', $request['url'] );
		$this->assertSame( 'PATCH', $request['args']['method'] );
		$this->assertSame( array( 'terminals' => array( array( 'id' => 'NEWLAND_N950__SBX0000001', 'operating_mode' => 'PDV' ) ) ), json_decode( $request['args']['body'], true ) );
	}

	/** @dataProvider invalidModes */
	public function test_set_operating_mode_rejects_invalid_value( string $mode ): void {
		$this->expectException( InvalidArgumentException::class );
		try {
			( new MercadoPagoClient( 'TEST-token' ) )->set_operating_mode( 'terminal', $mode );
		} finally {
			$this->assertSame( array(), WP_Stub::$http_requests );
		}
	}

	public function invalidModes(): array { return array( 'wrong case' => array( 'pdv' ), 'unknown' => array( 'OTHER' ) ); }

	public function test_simulate_event_accepts_empty_204(): void {
		$this->queue_response( 204, '' );
		$this->assertSame( array(), ( new MercadoPagoClient( 'TEST-token' ) )->simulate_event( self::ORDER_ID, 'processed' ) );
		$request = WP_Stub::$http_requests[0];
		$this->assertSame( MercadoPagoClient::BASE_URL . '/v1/orders/' . self::ORDER_ID . '/events', $request['url'] );
		$this->assertSame( 'POST', $request['args']['method'] );
		$this->assertSame( array( 'status' => 'processed' ), json_decode( $request['args']['body'], true ) );
	}

	public function test_api_error_has_status_code_message_and_body(): void {
		$this->queue_response( 400, $this->fixture( 'error-400' ) );
		try {
			( new MercadoPagoClient( 'TEST-token' ) )->cancel_order( self::ORDER_ID, 'cancel-1' );
			$this->fail( 'Expected an API exception.' );
		} catch ( MercadoPagoApiException $error ) {
			$this->assertSame( 400, $error->http_status() );
			$this->assertSame( 'invalid_status_transition', $error->error_code() );
			$this->assertSame( 'The order cannot be canceled in its current status.', $error->getMessage() );
			$this->assertSame( json_decode( $this->fixture( 'error-400' ), true ), $error->body() );
		}
	}

	public function test_transport_error_throws_with_zero_status(): void {
		WP_Stub::$http_responses[] = new WP_Error( 'network', 'Connection failed' );
		$this->expectException( MercadoPagoApiException::class );
		$this->expectExceptionMessage( 'Mercado Pago API request failed.' );
		try {
			( new MercadoPagoClient( 'TEST-token' ) )->get_order( self::ORDER_ID );
		} catch ( MercadoPagoApiException $error ) {
			$this->assertSame( 0, $error->http_status() );
			throw $error;
		}
	}

	public function test_invalid_json_success_throws(): void {
		$this->queue_response( 200, 'not json' );
		$this->expectException( MercadoPagoApiException::class );
		$this->expectExceptionMessage( 'Mercado Pago API returned an invalid response.' );
		( new MercadoPagoClient( 'TEST-token' ) )->get_order( self::ORDER_ID );
	}

	public function test_missing_access_token_throws_without_request(): void {
		$client = new MercadoPagoClient( '  ' );
		$this->assertFalse( $client->has_access_token() );
		$this->expectException( MercadoPagoApiException::class );
		$this->expectExceptionMessage( 'Mercado Pago access token is missing.' );
		try {
			$client->get_order( self::ORDER_ID );
		} finally {
			$this->assertSame( array(), WP_Stub::$http_requests );
		}
	}

	public function test_failing_request_never_logs_access_token(): void {
		WP_Stub::$http_responses[] = new WP_Error( 'network', 'Connection failed' );
		try {
			( new MercadoPagoClient( 'TEST-token' ) )->get_order( self::ORDER_ID );
		} catch ( MercadoPagoApiException $error ) {
			$this->assertNotEmpty( WP_Stub::$logs );
			foreach ( WP_Stub::$logs as $line ) {
				$this->assertStringNotContainsString( 'TEST-token', $line['message'] );
				$this->assertStringNotContainsString( 'TEST-token', json_encode( $line['context'] ) );
			}
			return;
		}
		$this->fail( 'Expected an API exception.' );
	}
}
