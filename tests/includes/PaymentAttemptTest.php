<?php
use PHPUnit\Framework\TestCase;
use WCPOS\WooCommercePOS\MercadoPagoTerminal\PaymentAttempt;
use WCPOS\WooCommercePOS\MercadoPagoTerminal\Settings;

class PaymentAttemptTest extends TestCase {
	private $order;

	protected function setUp(): void {
		WP_Stub::reset();
		$this->order = new MPTFWC_Test_Order( 123 );
		$GLOBALS['mptfwc_orders'] = array( 123 => $this->order );
		$GLOBALS['wpdb'] = new MPTFWC_Fake_Wpdb();
	}

	public function test_prepare_saves_pending_request_and_creating_history(): void {
		$this->assertNull( PaymentAttempt::current( $this->order ) );
		$this->assertSame( array(), PaymentAttempt::history( $this->order ) );
		$pending = PaymentAttempt::prepare( $this->order, 'NEWLAND_N950__SBX0000001', '24.00' );
		$this->assertSame( $pending, $this->order->get_meta( PaymentAttempt::META_PENDING_CREATE ) );
		$this->assertNotEmpty( $pending['attempt_id'] );
		$this->assertNotEmpty( $pending['idempotency_key'] );
		$this->assertMatchesRegularExpression( '/^wcpos-123-[0-9a-f]{12}$/', $pending['external_reference'] );
		$this->assertLessThanOrEqual( 64, strlen( $pending['external_reference'] ) );
		$this->assertSame( gmdate( 'c', strtotime( $pending['created_at'] ) ), $pending['created_at'] );
		$this->assertSame( array( array(
			'attempt_id' => $pending['attempt_id'],
			'mp_order_id' => '',
			'external_reference' => $pending['external_reference'],
			'terminal_id' => 'NEWLAND_N950__SBX0000001',
			'amount' => '24.00',
			'status' => 'creating',
			'created_at' => $pending['created_at'],
			'updated_at' => $pending['created_at'],
		) ), PaymentAttempt::history( $this->order ) );
		$this->assertSame( 1, $this->order->save_calls );
		$this->assertSame( '', $this->order->get_payment_method() );
	}

	public function test_prepare_reuses_recent_pending_request_without_another_save(): void {
		$pending = PaymentAttempt::prepare( $this->order, 'terminal-1', '24.00' );
		$this->assertSame( $pending, PaymentAttempt::prepare( $this->order, 'terminal-1', '24.00' ) );
		$this->assertCount( 1, PaymentAttempt::history( $this->order ) );
		$this->assertSame( 1, $this->order->save_calls );
	}

	public function test_discard_pending_marks_history_rejected_and_next_prepare_gets_new_key(): void {
		PaymentAttempt::prepare( $this->order, 'terminal-older', '24.00' );
		$pending = PaymentAttempt::prepare( $this->order, 'terminal-1', '24.00' );
		$history = PaymentAttempt::history( $this->order );
		$older = $history[0];
		$history[1]['updated_at'] = '2000-01-01T00:00:00+00:00';
		$this->order->update_meta_data( PaymentAttempt::META_ATTEMPTS, $history );
		PaymentAttempt::discard_pending( $this->order, $pending );
		$this->assertArrayNotHasKey( PaymentAttempt::META_PENDING_CREATE, $this->order->meta );
		$this->assertSame( 3, $this->order->save_calls );
		$history = PaymentAttempt::history( $this->order );
		$this->assertSame( $older, $history[0] );
		$this->assertSame( $pending['attempt_id'], $history[1]['attempt_id'] );
		$this->assertSame( 'rejected', $history[1]['status'] );
		$this->assertGreaterThan( strtotime( '2000-01-01T00:00:00+00:00' ), strtotime( $history[1]['updated_at'] ) );
		$next = PaymentAttempt::prepare( $this->order, 'terminal-1', '24.00' );
		$this->assertNotSame( $pending['idempotency_key'], $next['idempotency_key'] );
		$this->assertNotSame( $pending['external_reference'], $next['external_reference'] );
		$this->assertSame( 'creating', PaymentAttempt::history( $this->order )[2]['status'] );
	}

	/** @dataProvider changed_request_provider */
	public function test_prepare_creates_new_key_when_request_changes_or_expires( string $terminal, string $amount, bool $expired ): void {
		$pending = PaymentAttempt::prepare( $this->order, 'terminal-1', '24.00' );
		if ( $expired ) {
			$pending['created_at'] = gmdate( 'c', time() - 3601 );
			$this->order->update_meta_data( PaymentAttempt::META_PENDING_CREATE, $pending );
		}
		$next = PaymentAttempt::prepare( $this->order, $terminal, $amount );
		$this->assertNotSame( $pending['idempotency_key'], $next['idempotency_key'] );
		$this->assertNotSame( $pending['external_reference'], $next['external_reference'] );
		$this->assertSame( $next, $this->order->get_meta( PaymentAttempt::META_PENDING_CREATE ) );
		$this->assertCount( 2, PaymentAttempt::history( $this->order ) );
		$this->assertSame( 2, $this->order->save_calls );
	}

	public static function changed_request_provider(): array {
		return array(
			'amount' => array( 'terminal-1', '25.00', false ),
			'terminal' => array( 'terminal-2', '24.00', false ),
			'expired' => array( 'terminal-1', '24.00', true ),
		);
	}

	public function test_record_created_sets_current_meta_and_updates_only_matching_history(): void {
		$older = PaymentAttempt::prepare( $this->order, 'terminal-older', '24.00' );
		$pending = PaymentAttempt::prepare( $this->order, 'NEWLAND_N950__SBX0000001', '24.00' );
		$mp_order = json_decode( file_get_contents( dirname( __DIR__ ) . '/fixtures/order-created.json' ), true );
		$mp_order['external_reference'] = $pending['external_reference'];
		PaymentAttempt::record_created( $this->order, $pending, $mp_order );
		$this->assertSame( array(
			'attempt_id' => $pending['attempt_id'],
			'mp_order_id' => $mp_order['id'],
			'terminal_id' => $pending['terminal_id'],
			'external_reference' => $pending['external_reference'],
			'status' => 'created',
			'created_at' => $pending['created_at'],
		), PaymentAttempt::current( $this->order ) );
		$history = PaymentAttempt::history( $this->order );
		$this->assertSame( $older['attempt_id'], $history[0]['attempt_id'] );
		$this->assertSame( '', $history[0]['mp_order_id'] );
		$this->assertSame( $mp_order['id'], $history[1]['mp_order_id'] );
		$this->assertSame( 'created', $history[1]['status'] );
		$this->assertNotEmpty( $history[1]['updated_at'] );
		$this->assertArrayNotHasKey( PaymentAttempt::META_PENDING_CREATE, $this->order->meta );
		$this->assertSame( 3, $this->order->save_calls );
		$mp_order['status'] = 'at_terminal';
		PaymentAttempt::update_status( $this->order, $mp_order );
		$this->assertSame( 'at_terminal', PaymentAttempt::current( $this->order )['status'] );
		$this->assertSame( 'at_terminal', PaymentAttempt::history( $this->order )[1]['status'] );
		$this->assertSame( 'creating', PaymentAttempt::history( $this->order )[0]['status'] );
	}

	public function test_update_status_recovers_lost_create_response_without_replacing_current_pointer(): void {
		PaymentAttempt::prepare( $this->order, 'NEWLAND_N950__SBX0000001', '24.00' );
		$mp_order = json_decode( file_get_contents( dirname( __DIR__ ) . '/fixtures/order-processed.json' ), true );
		$history = PaymentAttempt::history( $this->order );
		$history[0]['external_reference'] = $mp_order['external_reference'];
		$this->order->update_meta_data( PaymentAttempt::META_ATTEMPTS, $history );
		$this->order->update_meta_data( PaymentAttempt::META_CURRENT_MP_ORDER_ID, 'ORD-OTHER' );
		$this->order->update_meta_data( PaymentAttempt::META_CURRENT_STATUS, 'created' );
		$this->assertSame( $history[0], PaymentAttempt::find( $this->order, $mp_order ) );
		PaymentAttempt::update_status( $this->order, $mp_order );
		$recovered = PaymentAttempt::history( $this->order )[0];
		$this->assertSame( $mp_order['id'], $recovered['mp_order_id'] );
		$this->assertSame( 'processed', $recovered['status'] );
		$this->assertSame( $recovered, PaymentAttempt::find( $this->order, $mp_order ) );
		$this->assertSame( 'created', PaymentAttempt::current( $this->order )['status'] );
		$this->assertSame( 2, $this->order->save_calls );
	}

	/** @dataProvider reference_provider */
	public function test_order_id_from_external_reference( string $reference, int $expected ): void {
		$this->assertSame( $expected, PaymentAttempt::order_id_from_external_reference( $reference ) );
	}

	public static function reference_provider(): array {
		return array( array( 'wcpos-123-a1b2c3d4', 123 ), array( 'wcpos-abc-1', 0 ), array( 'other-123-ab', 0 ), array( '', 0 ) );
	}

	/** @dataProvider status_provider */
	public function test_status_helpers( string $status, bool $paid, bool $unpaid, bool $final, bool $non_final ): void {
		$this->assertSame( $status, PaymentAttempt::status( array( 'status' => $status ) ) );
		$this->assertSame( $paid, PaymentAttempt::is_paid_status( $status ) );
		$this->assertSame( $unpaid, PaymentAttempt::is_final_unpaid( $status ) );
		$this->assertSame( $final, PaymentAttempt::is_final( $status ) );
		$this->assertSame( $non_final, PaymentAttempt::is_non_final( $status ) );
	}

	public static function status_provider(): array {
		return array(
			array( 'created', false, false, false, true ),
			array( 'at_terminal', false, false, false, true ),
			array( 'action_required', false, false, false, true ),
			array( 'processed', true, false, true, false ),
			array( 'canceled', false, true, true, false ),
			array( 'failed', false, true, true, false ),
			array( 'expired', false, true, true, false ),
			array( 'refunded', false, false, true, false ),
			array( 'creating', false, false, false, true ),
			array( '', false, false, false, true ),
			array( 'unknown', false, false, false, false ),
		);
	}

	public function test_missing_status_is_unknown(): void {
		$this->assertSame( 'unknown', PaymentAttempt::status( array() ) );
	}

	public function test_claim_gateway_sets_method_and_title_and_preserves_existing_gateway_title(): void {
		$this->order->set_payment_method( 'cash' );
		PaymentAttempt::claim_order_gateway( $this->order, 'Point terminal' );
		$this->assertSame( Settings::GATEWAY_ID, $this->order->get_payment_method() );
		$this->assertSame( 'Point terminal', $this->order->get_payment_method_title() );
		PaymentAttempt::claim_order_gateway( $this->order, 'Changed title' );
		$this->assertSame( 'Point terminal', $this->order->get_payment_method_title() );
		$this->assertSame( 0, $this->order->save_calls );
	}
}
