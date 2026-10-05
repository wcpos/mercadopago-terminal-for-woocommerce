<?php
use PHPUnit\Framework\TestCase;
use WCPOS\WooCommercePOS\MercadoPagoTerminal\Logger;
use WCPOS\WooCommercePOS\MercadoPagoTerminal\PaymentAttempt;
use WCPOS\WooCommercePOS\MercadoPagoTerminal\PaymentReconciler;
use WCPOS\WooCommercePOS\MercadoPagoTerminal\Settings;

class PaymentReconcilerTest extends TestCase {
	private const ORDER_ID = 'ORD01JYH1Z1YJN4HZ8J3Q0RB3YP6D';
	private const PAYMENT_ID = 'PAY01JYH1Z1YJN4HZ8J3Q0RB3YP6E';
	private const LOCK_KEY = 'mptfwc_lock_order_123_complete_payment';
	private $order;
	private $pending;
	private $reconciler;

	protected function setUp(): void {
		WP_Stub::reset();
		Logger::$logger = null;
		Logger::$log_level = null;
		$this->order = new MPTFWC_Test_Order( 123 );
		$GLOBALS['mptfwc_orders'] = array( 123 => $this->order );
		$GLOBALS['wpdb'] = new MPTFWC_Fake_Wpdb();
		$this->pending = PaymentAttempt::prepare( $this->order, 'NEWLAND_N950__SBX0000001', '24.00' );
		PaymentAttempt::record_created( $this->order, $this->pending, $this->fixture( 'order-created' ) );
		$this->reconciler = new PaymentReconciler( new Settings( array( 'title' => 'Point terminal' ) ) );
	}

	private function fixture( string $name ): array {
		$mp_order = json_decode( file_get_contents( dirname( __DIR__ ) . '/fixtures/' . $name . '.json' ), true );
		$mp_order['external_reference'] = $this->pending['external_reference'];
		return $mp_order;
	}

	public function test_processed_order_completes_with_order_transaction_and_payment_meta(): void {
		$this->order->set_payment_method( 'cash' );
		$this->assertSame( array( 'status' => 'paid' ), $this->reconciler->reconcile( $this->order, $this->fixture( 'order-processed' ), 'poll' ) );
		$this->assertTrue( $this->order->is_paid() );
		$this->assertSame( 1, $this->order->payment_complete_calls );
		$this->assertSame( self::ORDER_ID, $this->order->get_transaction_id() );
		$this->assertSame( self::PAYMENT_ID, $this->order->get_meta( PaymentAttempt::META_MP_PAYMENT_ID ) );
		$this->assertSame( Settings::GATEWAY_ID, $this->order->get_payment_method() );
		$this->assertSame( 'Point terminal', $this->order->get_payment_method_title() );
		$this->assertSame( array( 'Mercado Pago Point payment completed via poll (order ' . self::ORDER_ID . ', payment ' . self::PAYMENT_ID . ').' ), $this->order->notes );
		$this->assertSame( 'processed', PaymentAttempt::current( $this->order )['status'] );
		$this->assertSame( 'processed', PaymentAttempt::history( $this->order )[0]['status'] );
		$this->assertArrayNotHasKey( self::LOCK_KEY, $GLOBALS['wpdb']->rows );
	}

	public function test_processing_same_order_twice_completes_exactly_once_without_another_note(): void {
		$mp_order = $this->fixture( 'order-processed' );
		$this->reconciler->reconcile( $this->order, $mp_order, 'poll' );
		$notes = $this->order->notes;
		$this->assertSame( array( 'status' => 'paid', 'idempotent' => true ), $this->reconciler->reconcile( $this->order, $mp_order, 'webhook' ) );
		$this->assertSame( 1, $this->order->payment_complete_calls );
		$this->assertSame( $notes, $this->order->notes );
	}

	/** @dataProvider invalid_order_provider */
	public function test_invalid_order_never_completes( array $changes, string $error ): void {
		$mp_order = array_replace_recursive( $this->fixture( 'order-processed' ), $changes );
		$this->assertSame( array( 'status' => 'verification_failed', 'payment_status' => 'processed', 'errors' => array( $error ) ), $this->reconciler->reconcile( $this->order, $mp_order, 'webhook' ) );
		$this->assertSame( 0, $this->order->payment_complete_calls );
		$this->assertFalse( $this->order->is_paid() );
		$this->assertSame( '', $this->order->get_transaction_id() );
		$this->assertSame( '', $this->order->get_payment_method() );
		$this->assertSame( array( 'Mercado Pago Point verification failed via webhook: ' . $error ), $this->order->notes );
		$this->assertArrayNotHasKey( self::LOCK_KEY, $GLOBALS['wpdb']->rows );
	}

	public static function invalid_order_provider(): array {
		return array(
			'amount' => array( array( 'transactions' => array( 'payments' => array( array( 'amount' => '25.00' ) ) ) ), 'amount mismatch' ),
			'other order reference' => array( array( 'external_reference' => 'wcpos-999-a1b2c3d4' ), 'external reference does not match this order' ),
			'unknown order and reference' => array( array( 'id' => 'ORD-UNKNOWN', 'external_reference' => 'wcpos-123-deadbeef' ), 'order is not known for this order' ),
			'terminal' => array( array( 'config' => array( 'point' => array( 'terminal_id' => 'OTHER-TERMINAL' ) ) ), 'terminal mismatch' ),
			'type' => array( array( 'type' => 'online' ), 'order type is not point' ),
		);
	}

	/** @dataProvider missing_payment_field_provider */
	public function test_processed_order_without_amount_or_payment_id_never_completes( string $field, string $error ): void {
		$mp_order = $this->fixture( 'order-processed' );
		unset( $mp_order['transactions']['payments'][0][ $field ] );
		$this->assertSame( array( 'status' => 'verification_failed', 'payment_status' => 'processed', 'errors' => array( $error ) ), $this->reconciler->reconcile( $this->order, $mp_order, 'webhook' ) );
		$this->assertSame( 0, $this->order->payment_complete_calls );
		$this->assertFalse( $this->order->is_paid() );
	}

	public static function missing_payment_field_provider(): array {
		return array(
			'amount' => array( 'amount', 'payment amount missing' ),
			'id' => array( 'id', 'payment id missing' ),
		);
	}

	public function test_canceled_order_records_note_and_allows_retry(): void {
		$this->assertSame( array( 'status' => 'canceled', 'status_detail' => 'canceled', 'retry_allowed' => true ), $this->reconciler->reconcile( $this->order, $this->fixture( 'order-canceled' ), 'cancel' ) );
		$this->assertSame( array( 'Mercado Pago Point order canceled via cancel (canceled).' ), $this->order->notes );
		$this->assertFalse( $this->order->is_paid() );
		$this->assertSame( 0, $this->order->payment_complete_calls );
		$this->assertSame( 4, $this->order->save_calls );
	}

	/** @dataProvider non_final_provider */
	public function test_non_final_or_unknown_status_disallows_retry_without_note( string $status, string $expected ): void {
		$mp_order = $this->fixture( 'order-created' );
		$mp_order['status'] = $status;
		$this->assertSame( array( 'status' => $expected, 'retry_allowed' => false ), $this->reconciler->reconcile( $this->order, $mp_order, 'poll' ) );
		$this->assertSame( array(), $this->order->notes );
		$this->assertSame( 0, $this->order->payment_complete_calls );
	}

	public static function non_final_provider(): array {
		return array( array( 'created', 'created' ), array( 'at_terminal', 'at_terminal' ), array( 'action_required', 'action_required' ), array( 'unrecognized', 'unknown' ) );
	}

	public function test_held_lock_reports_verified_order_as_completing(): void {
		$claim = json_encode( array( 'token' => 'other-request', 'expires_at' => time() + 120 ) );
		$GLOBALS['wpdb']->rows[ self::LOCK_KEY ] = $claim;
		$this->assertSame( array( 'status' => 'paid', 'completing' => true ), $this->reconciler->reconcile( $this->order, $this->fixture( 'order-processed' ), 'poll' ) );
		$this->assertSame( 0, $this->order->payment_complete_calls );
		$this->assertSame( $claim, $GLOBALS['wpdb']->rows[ self::LOCK_KEY ] );
		$this->assertSame( array(), $this->order->notes );
	}

	public function test_held_lock_never_reports_invalid_order_as_paid(): void {
		$GLOBALS['wpdb']->rows[ self::LOCK_KEY ] = json_encode( array( 'token' => 'other-request', 'expires_at' => time() + 120 ) );
		$mp_order = $this->fixture( 'order-processed' );
		$mp_order['transactions']['payments'][0]['amount'] = '25.00';
		$this->assertSame( array( 'status' => 'pending', 'retry_allowed' => false ), $this->reconciler->reconcile( $this->order, $mp_order, 'poll' ) );
		$this->assertSame( 0, $this->order->payment_complete_calls );
	}

	public function test_order_already_paid_by_another_transaction_is_a_conflict(): void {
		$this->order->paid = true;
		$this->order->set_transaction_id( 'ORD-OTHER' );
		$this->assertSame( array( 'status' => 'conflict' ), $this->reconciler->reconcile( $this->order, $this->fixture( 'order-processed' ), 'webhook' ) );
		$this->assertSame( 0, $this->order->payment_complete_calls );
		$this->assertSame( 'ORD-OTHER', $this->order->get_transaction_id() );
		$this->assertSame( array( 'Mercado Pago Point order processed but the WooCommerce order was already paid by another transaction.' ), $this->order->notes );
	}

	public function test_lost_create_response_can_complete_from_prepared_reference(): void {
		$this->order = new MPTFWC_Test_Order( 123 );
		$GLOBALS['mptfwc_orders'][ 123 ] = $this->order;
		$this->pending = PaymentAttempt::prepare( $this->order, 'NEWLAND_N950__SBX0000001', '24.00' );
		$this->assertSame( '', PaymentAttempt::history( $this->order )[0]['mp_order_id'] );
		$this->assertSame( array( 'status' => 'paid' ), $this->reconciler->reconcile( $this->order, $this->fixture( 'order-processed' ), 'webhook' ) );
		$this->assertSame( 1, $this->order->payment_complete_calls );
		$this->assertSame( self::ORDER_ID, PaymentAttempt::history( $this->order )[0]['mp_order_id'] );
		$this->assertSame( 'processed', PaymentAttempt::history( $this->order )[0]['status'] );
	}

	public function test_stale_order_is_reloaded_before_completion(): void {
		$stale = clone $this->order;
		$mp_order = $this->fixture( 'order-processed' );
		$this->reconciler->reconcile( $this->order, $mp_order, 'webhook' );
		$this->assertSame( $this->order, PaymentReconciler::reload_order( $stale ) );
		$this->assertSame( array( 'status' => 'paid', 'idempotent' => true ), $this->reconciler->reconcile( $stale, $mp_order, 'poll' ) );
		$this->assertSame( 1, $this->order->payment_complete_calls );
		$this->assertSame( 0, $stale->payment_complete_calls );
	}

	public function test_refunded_order_records_note_without_completing_again(): void {
		$this->order->paid = true;
		$this->order->set_transaction_id( self::ORDER_ID );
		$this->assertSame( array( 'status' => 'refunded', 'retry_allowed' => false ), $this->reconciler->reconcile( $this->order, $this->fixture( 'order-refunded' ), 'webhook' ) );
		$this->assertSame( array( 'Mercado Pago Point order refunded via webhook.' ), $this->order->notes );
		$this->assertSame( 0, $this->order->payment_complete_calls );
		$this->assertTrue( $this->order->is_paid() );
	}
}
