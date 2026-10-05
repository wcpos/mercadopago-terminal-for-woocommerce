<?php
use PHPUnit\Framework\TestCase;
use WCPOS\WooCommercePOS\MercadoPagoTerminal\Logger;
use WCPOS\WooCommercePOS\MercadoPagoTerminal\PaymentAttempt;
use WCPOS\WooCommercePOS\MercadoPagoTerminal\RefundHandler;
use WCPOS\WooCommercePOS\MercadoPagoTerminal\Services\MercadoPagoApiException;

class RefundHandlerTest extends TestCase {
	private const ORDER_ID = 'ORD01JYH1Z1YJN4HZ8J3Q0RB3YP6D';
	private const PAYMENT_ID = 'PAY01JYH1Z1YJN4HZ8J3Q0RB3YP6E';
	private const REFUND_ID = 'REF01JYH20000000000000000000';
	private $order;
	private $refund;
	private $client;
	private $handler;
	private $response;

	protected function setUp(): void {
		WP_Stub::reset();
		Logger::$logger = null;
		Logger::$log_level = null;
		$this->order = new MPTFWC_Test_Order( 123 );
		$this->order->total = '24.00';
		$this->order->set_transaction_id( self::ORDER_ID );
		$this->order->update_meta_data( PaymentAttempt::META_MP_PAYMENT_ID, self::PAYMENT_ID );
		$this->refund = new MPTFWC_Test_Refund( 501, '24.00' );
		$this->order->refunds = array( $this->refund );
		$this->response = json_decode( file_get_contents( dirname( __DIR__ ) . '/fixtures/order-refunded.json' ), true );
		$this->client = new FakeMercadoPagoClient();
		$this->client->returns['refund_order'] = array( $this->response );
		$this->handler = new RefundHandler( $this->client );
	}

	public function test_full_refund_uses_remembered_record_and_no_body(): void {
		$newest = new MPTFWC_Test_Refund( 502, '24.00' );
		$this->order->refunds = array( $newest, $this->refund );
		RefundHandler::remember_refund( $this->refund, array( 'refund_payment' => true ) );
		$this->assertTrue( $this->handler->process_refund( $this->order, '24.00', 'Customer request.' ) );
		$this->assertSame( array( array( 'method' => 'refund_order', 'args' => array( self::ORDER_ID, 'refund-501', null ) ) ), $this->client->calls );
		$this->assertSame( self::REFUND_ID, $this->refund->get_meta( RefundHandler::META_MP_REFUND_ID ) );
		$this->assertSame( 1, $this->refund->save_calls );
		$this->assertSame( '', $newest->get_meta( RefundHandler::META_MP_REFUND_ID ) );
		$this->assertSame( array( 'Mercado Pago Point refund of 24.00 processed (refund ' . self::REFUND_ID . ', order status refunded). Customer request.' ), $this->order->notes );
	}

	public function test_partial_refund_sends_amount_and_payment_id(): void {
		$this->refund->amount = '5.00';
		$this->response['status'] = 'processed';
		$this->response['status_detail'] = 'partially_refunded';
		$this->response['transactions']['refunds'][0]['amount'] = '5.00';
		$this->client->returns['refund_order'] = array( $this->response );
		$this->assertTrue( $this->handler->process_refund( $this->order, '5.00' ) );
		$this->assertSame( array( array( 'method' => 'refund_order', 'args' => array( self::ORDER_ID, 'refund-501', array( 'amount' => '5.00', 'transaction_id' => self::PAYMENT_ID ) ) ) ), $this->client->calls );
		$this->assertSame( array( 'Mercado Pago Point refund of 5.00 processed (refund ' . self::REFUND_ID . ', order status processed).' ), $this->order->notes );
	}

	/** @dataProvider remaining_amount_provider */
	public function test_remaining_amount_after_linked_refund( string $amount, bool $allowed ): void {
		$previous = new MPTFWC_Test_Refund( 500, '5.00' );
		$previous->update_meta_data( RefundHandler::META_MP_REFUND_ID, 'REF-previous' );
		$this->refund->amount = $amount;
		$this->order->refunds = array( $this->refund, $previous );
		$result = $this->handler->process_refund( $this->order, $amount );
		if ( $allowed ) {
			$this->assertTrue( $result );
			$this->assertSame( array( array( 'method' => 'refund_order', 'args' => array( self::ORDER_ID, 'refund-501', array( 'amount' => '19.00', 'transaction_id' => self::PAYMENT_ID ) ) ) ), $this->client->calls );
		} else {
			$this->assertInstanceOf( WP_Error::class, $result );
			$this->assertSame( 'mptfwc_refund_too_large', $result->get_error_code() );
			$this->assertSame( 'This refund would exceed the amount paid through Mercado Pago.', $result->get_error_message() );
			$this->assertSame( array(), $this->client->calls );
			$this->assertSame( '', $this->refund->get_meta( RefundHandler::META_MP_REFUND_ID ) );
		}
	}

	public static function remaining_amount_provider(): array {
		return array( 'remaining balance' => array( '19.00', true ), 'over balance' => array( '19.01', false ) );
	}

	public function test_retry_of_same_record_reuses_idempotency_key(): void {
		$this->client->returns['refund_order'] = array( new MercadoPagoApiException( 'Timed out', 504 ), $this->response );
		RefundHandler::remember_refund( $this->refund, array( 'refund_payment' => true ) );
		$this->assertInstanceOf( WP_Error::class, $this->handler->process_refund( $this->order, '24.00' ) );
		$this->assertSame( '', $this->refund->get_meta( RefundHandler::META_MP_REFUND_ID ) );
		$this->assertTrue( $this->handler->process_refund( $this->order, '24.00' ) );
		$this->assertCount( 2, $this->client->calls );
		$this->assertSame( array( self::ORDER_ID, 'refund-501', null ), $this->client->calls[0]['args'] );
		$this->assertSame( $this->client->calls[0], $this->client->calls[1] );
		$this->assertSame( 1, $this->refund->save_calls );
		$this->assertCount( 1, $this->order->notes );
	}

	public function test_api_refusal_explains_terminal_refund_and_does_not_link_record(): void {
		$this->client->returns['refund_order'] = array( new MercadoPagoApiException( 'Refund must be made at the terminal', 400 ) );
		$result = $this->handler->process_refund( $this->order, '24.00' );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'mptfwc_refund_failed', $result->get_error_code() );
		$this->assertStringContainsString( 'Refund must be made at the terminal', $result->get_error_message() );
		$this->assertStringContainsString( 'on the Point terminal', $result->get_error_message() );
		$this->assertSame( '', $this->refund->get_meta( RefundHandler::META_MP_REFUND_ID ) );
		$this->assertSame( 0, $this->refund->save_calls );
		$this->assertSame( array(), $this->order->notes );
		$this->assertCount( 1, WP_Stub::$logs );
		$this->assertSame( 'error', WP_Stub::$logs[0]['level'] );
	}

	/** @dataProvider unavailable_transaction_provider */
	public function test_order_without_point_transaction_is_unavailable( string $transaction_id ): void {
		$this->order->set_transaction_id( $transaction_id );
		$result = $this->handler->process_refund( $this->order, '24.00' );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'mptfwc_refund_unavailable', $result->get_error_code() );
		$this->assertSame( 'This order has no Mercado Pago Point payment to refund.', $result->get_error_message() );
		$this->assertSame( array(), $this->client->calls );
	}

	public static function unavailable_transaction_provider(): array {
		return array( 'empty' => array( '' ), 'payment id' => array( self::PAYMENT_ID ) );
	}

	public function test_manual_refund_is_not_remembered_and_falls_back_to_newest_amount_match(): void {
		$manual = new MPTFWC_Test_Refund( 499, '24.00' );
		$linked = new MPTFWC_Test_Refund( 503, '5.00' );
		$linked->update_meta_data( RefundHandler::META_MP_REFUND_ID, 'REF-previous' );
		$unmatched = new MPTFWC_Test_Refund( 502, '6.00' );
		$this->refund->amount = '5.00';
		$manual->amount = '5.00';
		$this->order->refunds = array( $linked, $unmatched, $this->refund, $manual );
		RefundHandler::remember_refund( $manual );
		$this->assertTrue( $this->handler->process_refund( $this->order, '5.00' ) );
		$this->assertSame( 'refund-501', $this->client->calls[0]['args'][1] );
		$this->assertSame( '', $manual->get_meta( RefundHandler::META_MP_REFUND_ID ) );
	}

	public function test_missing_refund_record_is_reported_without_api_call(): void {
		$this->order->refunds = array();
		$result = $this->handler->process_refund( $this->order, '24.00' );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'mptfwc_refund_not_found', $result->get_error_code() );
		$this->assertSame( 'No matching WooCommerce refund found.', $result->get_error_message() );
		$this->assertSame( array(), $this->client->calls );
	}

	/** @dataProvider invalid_amount_provider */
	public function test_invalid_amount_is_refused_locally( $amount ): void {
		$this->refund->amount = $amount;
		$result = $this->handler->process_refund( $this->order, $amount );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'mptfwc_refund_invalid_amount', $result->get_error_code() );
		$this->assertSame( 'Refund amount must be greater than zero.', $result->get_error_message() );
		$this->assertSame( array(), $this->client->calls );
	}

	public static function invalid_amount_provider(): array {
		return array( array( '0.00' ), array( '-1.00' ), array( 'not-numeric' ), array( null ) );
	}

	public function test_partial_refund_without_payment_id_is_refused_locally(): void {
		$this->refund->amount = '5.00';
		$this->order->delete_meta_data( PaymentAttempt::META_MP_PAYMENT_ID );
		$result = $this->handler->process_refund( $this->order, '5.00' );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'mptfwc_refund_unavailable', $result->get_error_code() );
		$this->assertSame( 'This order has no Mercado Pago payment id, so a partial refund cannot be sent. Refund the full amount, or refund on the Point terminal.', $result->get_error_message() );
		$this->assertSame( array(), $this->client->calls );
	}

	public function test_last_refund_id_is_stored(): void {
		$this->response['transactions']['refunds'][] = array( 'id' => 'REF-last' );
		$this->client->returns['refund_order'] = array( $this->response );
		$this->assertTrue( $this->handler->process_refund( $this->order, '24.00' ) );
		$this->assertSame( 'REF-last', $this->refund->get_meta( RefundHandler::META_MP_REFUND_ID ) );
	}

	public function test_missing_refund_id_is_linked_as_unknown(): void {
		$this->client->returns['refund_order'] = array( array() );
		$this->assertTrue( $this->handler->process_refund( $this->order, '24.00' ) );
		$this->assertSame( 'unknown', $this->refund->get_meta( RefundHandler::META_MP_REFUND_ID ) );
	}

	public function test_other_exception_is_logged_and_returned_as_error(): void {
		$this->client->returns['refund_order'] = array( new RuntimeException( 'Unexpected failure' ) );
		$result = $this->handler->process_refund( $this->order, '24.00' );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'mptfwc_refund_failed', $result->get_error_code() );
		$this->assertSame( 'Unexpected failure', $result->get_error_message() );
		$this->assertSame( '', $this->refund->get_meta( RefundHandler::META_MP_REFUND_ID ) );
		$this->assertCount( 1, WP_Stub::$logs );
	}
}
