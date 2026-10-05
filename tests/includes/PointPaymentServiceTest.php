<?php
use PHPUnit\Framework\TestCase;
use WCPOS\WooCommercePOS\MercadoPagoTerminal\Logger;
use WCPOS\WooCommercePOS\MercadoPagoTerminal\PaymentAttempt;
use WCPOS\WooCommercePOS\MercadoPagoTerminal\Services\MercadoPagoApiException;
use WCPOS\WooCommercePOS\MercadoPagoTerminal\Services\PointPaymentService;
use WCPOS\WooCommercePOS\MercadoPagoTerminal\Settings;

class PointPaymentServiceTest extends TestCase {
	private const TERMINAL_ID = 'NEWLAND_N950__SBX0000001';
	private $order;
	private $client;
	private $service;

	protected function setUp(): void {
		WP_Stub::reset();
		Logger::$logger = null;
		Logger::$log_level = null;
		$GLOBALS['wpdb'] = new MPTFWC_Fake_Wpdb();
		$this->order = new class( 123 ) extends MPTFWC_Test_Order {
			public function get_order_number() { return (string) $this->get_id(); }
		};
		$GLOBALS['mptfwc_orders'] = array( 123 => $this->order );
		$this->client = new FakeMercadoPagoClient();
		$this->service = new PointPaymentService( $this->client, new Settings( array( 'default_terminal_id' => self::TERMINAL_ID ) ) );
	}

	private function fixture( string $name, array $pending = array() ): array {
		$data = json_decode( file_get_contents( dirname( __DIR__ ) . '/fixtures/' . $name . '.json' ), true );
		if ( $pending ) { $data['external_reference'] = $pending['external_reference']; }
		return $data;
	}

	private function current_attempt(): array {
		$pending = PaymentAttempt::prepare( $this->order, self::TERMINAL_ID, '24.00' );
		PaymentAttempt::record_created( $this->order, $pending, $this->fixture( 'order-created', $pending ) );
		return $pending;
	}

	public function test_start_uses_default_terminal_and_persisted_payload_and_key(): void {
		$pending = PaymentAttempt::prepare( $this->order, self::TERMINAL_ID, '24.00' );
		$remote = $this->fixture( 'order-created', $pending );
		$this->client->returns['list_terminals'] = array( $this->fixture( 'terminals-list' ) );
		$this->client->returns['create_order'] = array( $remote );
		$result = $this->service->start_payment_for_order( $this->order );
		$this->assertSame( array( 'status' => 'created', 'mp_order_id' => $remote['id'], 'terminal_id' => self::TERMINAL_ID ), $result );
		$payload = PointPaymentService::build_payload( $this->order, $pending );
		$this->assertSame( array(
			'type' => 'point',
			'external_reference' => $pending['external_reference'],
			'expiration_time' => 'PT5M',
			'description' => 'Order #123',
			'transactions' => array( 'payments' => array( array( 'amount' => '24.00' ) ) ),
			'config' => array( 'point' => array( 'terminal_id' => self::TERMINAL_ID, 'print_on_terminal' => 'no_ticket' ) ),
		), $payload );
		$this->assertSame( array(
			array( 'method' => 'list_terminals', 'args' => array( 50, 0, '', '', 0 ) ),
			array( 'method' => 'create_order', 'args' => array( $payload, $pending['idempotency_key'] ) ),
		), $this->client->calls );
		$this->assertSame( $remote['id'], PaymentAttempt::current( $this->order )['mp_order_id'] );
		$this->assertSame( '', $this->order->get_meta( PaymentAttempt::META_PENDING_CREATE ) );
	}

	public function test_failed_create_rethrows_and_retry_reuses_persisted_key_and_reference(): void {
		$error = new MercadoPagoApiException( 'timeout', 0 );
		$this->client->returns['list_terminals'] = array( $this->fixture( 'terminals-list' ), $this->fixture( 'terminals-list' ) );
		$this->client->returns['create_order'] = array( $error );
		try {
			$this->service->start_payment_for_order( $this->order );
			$this->fail( 'The create exception must be rethrown.' );
		} catch ( MercadoPagoApiException $caught ) {
			$this->assertSame( $error, $caught );
		}
		$pending = $this->order->get_meta( PaymentAttempt::META_PENDING_CREATE );
		$this->assertIsArray( $pending );
		$this->assertSame( 1, $this->order->save_calls );
		$this->assertNull( PaymentAttempt::current( $this->order ) );
		$this->assertSame( 'error', WP_Stub::$logs[0]['level'] );
		$this->client->returns['create_order'] = array( $this->fixture( 'order-created', $pending ) );
		$this->assertSame( 'created', $this->service->start_payment_for_order( $this->order )['status'] );
		$this->assertSame( array( 'list_terminals', 'create_order', 'list_terminals', 'create_order' ), array_column( $this->client->calls, 'method' ) );
		$first = $this->client->calls[1]['args'];
		$second = $this->client->calls[3]['args'];
		$this->assertSame( $pending['idempotency_key'], $first[1] );
		$this->assertSame( $first[1], $second[1] );
		$this->assertSame( $pending['external_reference'], $first[0]['external_reference'] );
		$this->assertSame( $first[0]['external_reference'], $second[0]['external_reference'] );
		$this->assertCount( 1, PaymentAttempt::history( $this->order ) );
	}

	/** @dataProvider create_rejection_status_provider */
	public function test_rejected_create_discards_only_definitive_4xx( int $http_status, bool $discard ): void {
		$error = new MercadoPagoApiException( 'Create failed.', $http_status );
		$this->client->returns['list_terminals'] = array( $this->fixture( 'terminals-list' ), $this->fixture( 'terminals-list' ) );
		$this->client->returns['create_order'] = array( $error );
		try {
			$this->service->start_payment_for_order( $this->order );
			$this->fail( 'The create exception must be rethrown.' );
		} catch ( MercadoPagoApiException $caught ) {
			$this->assertSame( $error, $caught );
		}
		$first = $this->client->calls[1]['args'];
		$history = PaymentAttempt::history( $this->order );
		$this->assertSame( $discard ? 'rejected' : 'creating', $history[0]['status'] );
		$this->assertSame( $discard ? 2 : 1, $this->order->save_calls );
		if ( $discard ) {
			$this->assertArrayNotHasKey( PaymentAttempt::META_PENDING_CREATE, $this->order->meta );
		} else {
			$this->assertSame( $history[0]['attempt_id'], $this->order->get_meta( PaymentAttempt::META_PENDING_CREATE )['attempt_id'] );
		}
		add_filter( 'mptfwc_order_payload', function ( $payload, $order ) {
			$this->client->returns['create_order'] = array( $this->fixture( 'order-created', $order->get_meta( PaymentAttempt::META_PENDING_CREATE ) ) );
			return $payload;
		} );
		$this->assertSame( 'created', $this->service->start_payment_for_order( $this->order )['status'] );
		$second = $this->client->calls[3]['args'];
		$this->assertSame( ! $discard, $first[1] === $second[1] );
		$this->assertSame( ! $discard, $first[0]['external_reference'] === $second[0]['external_reference'] );
		$this->assertCount( $discard ? 2 : 1, PaymentAttempt::history( $this->order ) );
		$this->assertSame( $discard ? 'rejected' : 'created', PaymentAttempt::history( $this->order )[0]['status'] );
	}

	public static function create_rejection_status_provider(): array {
		return array(
			'bad request' => array( 400, true ),
			'unprocessable entity' => array( 422, true ),
			'transport error' => array( 0, false ),
			'server error' => array( 500, false ),
			'conflict' => array( 409, false ),
			'rate limited' => array( 429, false ),
		);
	}

	public function test_standalone_terminal_prevents_create(): void {
		$this->client->returns['list_terminals'] = array( $this->fixture( 'terminals-list' ) );
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'STANDALONE' );
		try {
			$this->service->start_payment_for_order( $this->order, 'NEWLAND_N950__N950NCB801293324' );
		} finally {
			$this->assertSame( array( 'list_terminals' ), array_column( $this->client->calls, 'method' ) );
			$this->assertSame( '', $this->order->get_meta( PaymentAttempt::META_PENDING_CREATE ) );
		}
	}

	public function test_unlisted_terminal_still_creates_payment(): void {
		$pending = PaymentAttempt::prepare( $this->order, 'UNLISTED', '24.00' );
		$remote = $this->fixture( 'order-created', $pending );
		$remote['config']['point']['terminal_id'] = 'UNLISTED';
		$this->client->returns['list_terminals'] = array( $this->fixture( 'terminals-list' ) );
		$this->client->returns['create_order'] = array( $remote );
		$result = $this->service->start_payment_for_order( $this->order, 'UNLISTED' );
		$this->assertSame( 'created', $result['status'] );
		$this->assertSame( 'UNLISTED', $result['terminal_id'] );
		$this->assertSame( 'UNLISTED', $this->client->calls[1]['args'][0]['config']['point']['terminal_id'] );
	}

	public function test_empty_terminal_without_default_makes_no_api_call(): void {
		$service = new PointPaymentService( $this->client, new Settings( array() ) );
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Select a terminal first.' );
		try {
			$service->start_payment_for_order( $this->order );
		} finally {
			$this->assertSame( array(), $this->client->calls );
		}
	}

	public function test_already_paid_makes_no_api_call(): void {
		$this->order->paid = true;
		$this->assertSame( array( 'status' => 'already_paid' ), $this->service->start_payment_for_order( $this->order ) );
		$this->assertSame( array(), $this->client->calls );
	}

	public function test_current_attempt_at_terminal_is_reused(): void {
		$pending = $this->current_attempt();
		$remote = $this->fixture( 'order-created', $pending );
		$remote['status'] = 'at_terminal';
		$this->client->returns['get_order'] = array( $remote );
		$this->assertSame( array( 'status' => 'at_terminal', 'retry_allowed' => false, 'reused' => true, 'mp_order_id' => $remote['id'] ), $this->service->start_payment_for_order( $this->order ) );
		$this->assertSame( array( array( 'method' => 'get_order', 'args' => array( $remote['id'] ) ) ), $this->client->calls );
	}

	public function test_canceled_attempt_can_create_with_new_key(): void {
		$pending = $this->current_attempt();
		$this->client->returns['get_order'] = array( $this->fixture( 'order-canceled', $pending ) );
		$this->client->returns['list_terminals'] = array( $this->fixture( 'terminals-list' ) );
		add_filter( 'mptfwc_order_payload', function ( $payload, $order ) {
			$new_pending = $order->get_meta( PaymentAttempt::META_PENDING_CREATE );
			$remote = $this->fixture( 'order-created', $new_pending );
			$remote['id'] = 'ORD-SECOND';
			$this->client->returns['create_order'] = array( $remote );
			return $payload;
		} );
		$this->assertSame( 'created', $this->service->start_payment_for_order( $this->order )['status'] );
		$this->assertSame( array( 'get_order', 'list_terminals', 'create_order' ), array_column( $this->client->calls, 'method' ) );
		$create = $this->client->calls[2]['args'];
		$this->assertNotSame( $pending['idempotency_key'], $create[1] );
		$this->assertNotSame( $pending['external_reference'], $create[0]['external_reference'] );
		$this->assertSame( 'ORD-SECOND', PaymentAttempt::current( $this->order )['mp_order_id'] );
		$this->assertCount( 2, PaymentAttempt::history( $this->order ) );
	}

	public function test_verification_failure_does_not_create_after_remote_cancellation(): void {
		$pending = $this->current_attempt();
		$remote = $this->fixture( 'order-canceled', $pending );
		$remote['transactions']['payments'][0]['amount'] = '25.00';
		$this->client->returns['get_order'] = array( $remote );
		$result = $this->service->start_payment_for_order( $this->order );
		$this->assertSame( 'verification_failed', $result['status'] );
		$this->assertTrue( $result['reused'] );
		$this->assertSame( array( 'get_order' ), array_column( $this->client->calls, 'method' ) );
	}

	public function test_payload_honours_expiration_and_payload_filters(): void {
		$pending = PaymentAttempt::prepare( $this->order, self::TERMINAL_ID, '24.00' );
		add_filter( 'mptfwc_order_expiration_time', function ( $expiration, $order ) {
			$this->assertSame( 'PT5M', $expiration );
			$this->assertSame( $this->order, $order );
			return 'PT10M';
		} );
		$this->assertSame( 'PT10M', PointPaymentService::build_payload( $this->order, $pending )['expiration_time'] );
		add_filter( 'mptfwc_order_payload', function ( $payload, $order ) {
			$this->assertSame( $this->order, $order );
			$payload['description'] = 'Custom order description';
			return $payload;
		} );
		$this->assertSame( 'Custom order description', PointPaymentService::build_payload( $this->order, $pending )['description'] );
	}

	public function test_poll_without_attempt_is_idle(): void {
		$this->assertSame( array( 'status' => 'idle' ), $this->service->poll_order( $this->order ) );
		$this->assertSame( array(), $this->client->calls );
	}

	public function test_poll_processed_completes_order(): void {
		$pending = $this->current_attempt();
		$this->client->returns['get_order'] = array( $this->fixture( 'order-processed', $pending ) );
		$this->assertSame( array( 'status' => 'paid' ), $this->service->poll_order( $this->order ) );
		$this->assertTrue( $this->order->is_paid() );
		$this->assertSame( 1, $this->order->payment_complete_calls );
		$this->assertStringContainsString( 'via poll', $this->order->notes[0] );
	}

	public function test_poll_action_required_sets_message(): void {
		$pending = $this->current_attempt();
		$remote = $this->fixture( 'order-created', $pending );
		$remote['status'] = 'action_required';
		$this->client->returns['get_order'] = array( $remote );
		$this->order->update_meta_data( PaymentAttempt::META_CURRENT_CREATED_AT, gmdate( 'c', time() - 120 ) );
		$this->assertSame( array( 'status' => 'action_required', 'retry_allowed' => false, 'message' => 'Confirm the payment on the terminal.' ), $this->service->poll_order( $this->order ) );
	}

	public function test_poll_waiting_message_depends_on_attempt_age(): void {
		$pending = $this->current_attempt();
		$remote = $this->fixture( 'order-created', $pending );
		$this->client->returns['get_order'] = array( $remote, $remote );
		$this->assertArrayNotHasKey( 'message', $this->service->poll_order( $this->order ) );
		$this->order->update_meta_data( PaymentAttempt::META_CURRENT_CREATED_AT, gmdate( 'c', time() - 120 ) );
		$this->assertSame( 'Still waiting for the terminal. Check the terminal, or cancel and retry.', $this->service->poll_order( $this->order )['message'] );
		$remote['status'] = 'at_terminal';
		$this->client->returns['get_order'] = array( $remote );
		$this->assertSame( 'Still waiting for the terminal. Check the terminal, or cancel and retry.', $this->service->poll_order( $this->order )['message'] );
	}

	public function test_poll_stored_final_status_makes_no_api_call(): void {
		$this->current_attempt();
		$this->order->update_meta_data( PaymentAttempt::META_CURRENT_STATUS, 'canceled' );
		$this->assertSame( array( 'status' => 'canceled' ), $this->service->poll_order( $this->order ) );
		$this->assertSame( array(), $this->client->calls );
	}

	public function test_cancel_created_reconciles_confirmed_cancellation(): void {
		$pending = $this->current_attempt();
		$created = $this->fixture( 'order-created', $pending );
		$this->client->returns['get_order'] = array( $created, $this->fixture( 'order-canceled', $pending ) );
		$this->client->returns['cancel_order'] = array( array() );
		$this->assertSame( array( 'status' => 'canceled', 'status_detail' => 'canceled', 'retry_allowed' => true ), $this->service->cancel_order_payment( $this->order ) );
		$this->assertSame( array(
			array( 'method' => 'get_order', 'args' => array( $created['id'] ) ),
			array( 'method' => 'cancel_order', 'args' => array( $created['id'], 'cancel-' . $pending['attempt_id'] ) ),
			array( 'method' => 'get_order', 'args' => array( $created['id'] ) ),
		), $this->client->calls );
		$this->assertSame( 'canceled', PaymentAttempt::current( $this->order )['status'] );
		$this->assertStringContainsString( 'via cancel', $this->order->notes[0] );
	}

	public function test_cancel_race_keeps_meta_and_notes_unchanged(): void {
		$pending = $this->current_attempt();
		$created = $this->fixture( 'order-created', $pending );
		$at_terminal = $created;
		$at_terminal['status'] = 'at_terminal';
		$this->client->returns['get_order'] = array( $created, $at_terminal );
		$this->client->returns['cancel_order'] = array( new MercadoPagoApiException( 'Terminal picked up the order.', 400 ) );
		$meta = $this->order->meta;
		$notes = $this->order->notes;
		$saves = $this->order->save_calls;
		$this->assertSame( array(
			'status' => 'cancel_on_terminal',
			'retry_allowed' => false,
			'message' => 'The payment is already on the terminal. Cancel it on the terminal, or wait for it to expire.',
		), $this->service->cancel_order_payment( $this->order ) );
		$this->assertSame( array( 'get_order', 'cancel_order', 'get_order' ), array_column( $this->client->calls, 'method' ) );
		$this->assertSame( $meta, $this->order->meta );
		$this->assertSame( $notes, $this->order->notes );
		$this->assertSame( $saves, $this->order->save_calls );
	}

	public function test_cancel_at_terminal_never_calls_cancel_api_or_changes_order(): void {
		$pending = $this->current_attempt();
		$remote = $this->fixture( 'order-created', $pending );
		$remote['status'] = 'at_terminal';
		$this->client->returns['get_order'] = array( $remote );
		$meta = $this->order->meta;
		$notes = $this->order->notes;
		$saves = $this->order->save_calls;
		$result = $this->service->cancel_order_payment( $this->order );
		$this->assertSame( 'cancel_on_terminal', $result['status'] );
		$this->assertFalse( $result['retry_allowed'] );
		$this->assertSame( array( 'get_order' ), array_column( $this->client->calls, 'method' ) );
		$this->assertSame( $meta, $this->order->meta );
		$this->assertSame( $notes, $this->order->notes );
		$this->assertSame( $saves, $this->order->save_calls );
	}

	public function test_cancel_final_remote_reconciles_without_cancel_api(): void {
		$pending = $this->current_attempt();
		$this->client->returns['get_order'] = array( $this->fixture( 'order-processed', $pending ) );
		$this->assertSame( array( 'status' => 'paid' ), $this->service->cancel_order_payment( $this->order ) );
		$this->assertTrue( $this->order->is_paid() );
		$this->assertSame( array( 'get_order' ), array_column( $this->client->calls, 'method' ) );
	}

	public function test_cancel_without_attempt_is_idle(): void {
		$this->assertSame( array( 'status' => 'idle' ), $this->service->cancel_order_payment( $this->order ) );
		$this->assertSame( array(), $this->client->calls );
	}
}
