<?php
namespace WCPOS\WooCommercePOS\MercadoPagoTerminal\Tests\Includes;
use WCPOS\WooCommercePOS\MercadoPagoTerminal\Provider_Adapter;
use WCPOS\WooCommercePOS\MercadoPagoTerminal\Settings;
use WCPOS\WooCommercePOS\MercadoPagoTerminal\Tests\Conformance\Mercado_Pago_Conformance_Fixture as Transport;
use WCPOS\WooCommercePOS\Payments\Contract\Ledger;
require_once __DIR__ . '/Conformance/Mercado_Pago_Conformance_Fixture.php';
class Test_Provider_Adapter extends \WP_UnitTestCase {
	protected $transport;
	protected $adapter;
	public function setUp(): void { parent::setUp(); $this->transport = new Transport(); $this->transport->install(); $this->adapter = new Provider_Adapter(); }
	public function tearDown(): void { $this->transport->uninstall(); parent::tearDown(); }
	/** A row Pro would hand to a FIRST create: minted, never sent. */
	private function fresh_row(): array {
		$order = wc_create_order();
		$order->set_total( '24.00' ); $order->set_currency( 'EUR' ); $order->save();
		return array( $order, array( 'id' => wp_generate_uuid4(), 'order_id' => $order->get_id(), 'amount' => '24.00', 'currency' => 'EUR' ) );
	}
	private function create(): array {
		$order = wc_create_order();
		$order->set_total( '24.00' ); $order->set_currency( 'EUR' ); $order->save();
		$row = array( 'id' => wp_generate_uuid4(), 'order_id' => $order->get_id(), 'amount' => '24.00', 'currency' => 'EUR' );
		$action = $this->adapter->create_reader_action( $row, 'reader-1' );
		$this->assertNotWPError( $action );
		$row['provider_refs'] = array( 'action' => $action['ref'] );
		return array( $order, $row, $action );
	}
	public function test_create_payload_expiry_and_uuid_replay(): void {
		list( $order, $row, $action ) = $this->create();
		$again = $this->adapter->create_reader_action( $row, 'reader-1' );
		$this->assertSame( $action, $again );
		$call = $this->transport->raw_calls[0]['args'];
		$payload = json_decode( $call['body'], true );
		$this->assertSame( $row['id'], $call['headers']['X-Idempotency-Key'] );
		$this->assertSame( 'wcpos_' . $row['id'], $payload['external_reference'] );
		$this->assertSame( '24.00', $payload['transactions']['payments'][0]['amount'] );
		$this->assertSame( 'Order #' . $order->get_order_number(), $payload['description'] );
		$this->assertSame( array( 'terminal_id' => 'reader-1', 'print_on_terminal' => 'no_ticket' ), $payload['config']['point'] );
		$this->assertSame( 300, strtotime( $action['expires_at'] ) - strtotime( $this->transport->orders[ $action['ref'] ]['data']['created_date'] ) );
	}
	/** @dataProvider statuses */
	public function test_status_mapping( string $mp, string $expected ): void {
		$remote = Transport::fixture( 'order-processed' ); $remote['status'] = $mp;
		$this->transport->response_override = Transport::response( $remote );
		$result = $this->adapter->fetch( $remote['id'] );
		$this->assertSame( $expected, $result['status'] );
		if ( 'completed' === $expected ) {
			$this->assertSame( '24.00', $result['amount'] );
			$this->assertSame( 'EUR', $result['currency'] );
			$this->assertSame( $remote['id'], $result['provider_refs']['transaction_id'] );
			$this->assertSame( $remote['transactions']['payments'][0]['id'], $result['provider_refs']['payment_id'] );
			$this->assertSame( array( 'card_brand' => 'master', 'card_type' => 'credit_card' ), $result['receipt'] );
		}
		if ( 'failed' === $expected ) { $this->assertSame( $remote['status_detail'], $result['failure_reason'] ); }
	}
	public static function statuses(): array { return array( array( 'created', 'pending' ), array( 'at_terminal', 'pending' ), array( 'action_required', 'in_progress' ), array( 'processed', 'completed' ), array( 'refunded', 'completed' ), array( 'canceled', 'cancelled' ), array( 'expired', 'expired' ), array( 'failed', 'failed' ), array( 'unknown', 'pending' ) ); }
	public function test_order_amount_excludes_financing_and_missing_confirmation_is_null(): void {
		$remote = Transport::fixture( 'order-processed' );
		$remote['transactions']['payments'][0]['paid_amount'] = '1.00';
		$this->transport->response_override = Transport::response( $remote );
		$this->assertSame( '24.00', $this->adapter->fetch( 'ORD1' )['amount'] );
		unset( $remote['transactions']['payments'][0]['paid_amount'] );
		$this->transport->response_override = Transport::response( $remote );
		$this->assertSame( '24.00', $this->adapter->fetch( 'ORD1' )['amount'] );
		unset( $remote['transactions']['payments'] );
		$this->transport->response_override = Transport::response( $remote );
		$this->assertNull( $this->adapter->fetch( 'ORD1' )['amount'] );
	}
	public static function order_stores(): array { return array( array( false ), array( true ) ); }
	/** @dataProvider order_stores */
	public function test_404_grace_reads_persisted_expiry( bool $hpos ): void {
		update_option( 'woocommerce_custom_orders_table_enabled', $hpos ? 'yes' : 'no' );
		$this->assertSame( $hpos, \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() );
		list( $order, $row ) = $this->create();
		$row = wcpos_pro_adopt_legacy_attempt( $order, Settings::GATEWAY_ID, $row['provider_refs']['action'], '24.00', 'EUR' );
		$row['created_at_gmt'] = gmdate( 'c', time() - 120 );
		$row['expires_at'] = gmdate( 'c', time() + 300 );
		Ledger::instance()->save( $order, array( $row ), false );
		$this->transport->response_override = Transport::response( array(), 404 );
		$this->assertSame( 'pending', ( new Provider_Adapter() )->fetch( $row['provider_refs']['action'] )['status'] );
		$row['created_at_gmt'] = gmdate( 'c' );
		$row['expires_at'] = gmdate( 'c', time() - 1 );
		Ledger::instance()->save( $order, array( $row ), false );
		$this->assertSame( array( 'status' => 'failed', 'failure_reason' => 'expired' ), $this->adapter->fetch( $row['provider_refs']['action'] ) );
	}
	/** @dataProvider errors */
	public function test_error_classification( int $status, bool $indeterminate ): void {
		$this->transport->response_override = 0 === $status ? new \WP_Error( 'http_request_failed', 'timeout' ) : Transport::response( array( 'message' => 'API message' ), $status );
		$error = $this->adapter->fetch( 'ORD1' );
		$this->assertWPError( $error );
		$this->assertSame( $indeterminate, (bool) ( $error->get_error_data()['indeterminate'] ?? false ) );
		$this->assertSame( 502, $error->get_error_data()['status'] );
		if ( $status ) { $this->assertSame( 'API message', $error->get_error_message() ); }
	}
	public static function errors(): array { return array( array( 0, true ), array( 500, true ), array( 503, true ), array( 409, false ), array( 429, true ), array( 400, false ), array( 401, false ), array( 403, false ) ); }
	public function test_throwables_are_indeterminate_for_every_client_operation(): void {
		list( $order, $row ) = $this->create();
		$request = $this->transport->webhook_request( 'completed' );
		$this->transport->response_override = new \Error( 'Transport exploded' );
		$results = array( $this->adapter->list_readers(), $this->adapter->create_reader_action( $row, 'reader' ), $this->adapter->fetch( 'ORD1' ), $this->adapter->cancel( 'ORD1' ), $this->adapter->refund( $row, 1, '24.00' ), $this->adapter->verify_webhook( $request ) );
		foreach ( $results as $error ) { $this->assertWPError( $error ); $this->assertTrue( $error->get_error_data()['indeterminate'] ); }
	}
	public function test_reader_modes_errors_and_diagnostics(): void {
		$this->assertSame( array( 'online', 'offline' ), array_column( $this->adapter->list_readers(), 'status' ) );
		$gateway = new \WCPOS\WooCommercePOS\MercadoPagoTerminal\Gateway();
		$data = $this->adapter->diagnostics( $gateway );
		$this->assertSame( 2, $data['terminals'] ); $this->assertSame( 1, $data['terminals_pdv'] );
		$this->assertStringNotContainsString( 'TEST-conformance', wp_json_encode( $data ) );
		$this->transport->response_override = Transport::response( array(), 503 );
		$this->assertWPError( $this->adapter->list_readers() );
	}
	public function test_cancel_request_unsupported_and_idempotency(): void {
		list( $order, $row ) = $this->create();
		$this->assertSame( 'requested', $this->adapter->cancel( 'ORD1' ) );
		$this->assertSame( 'cancel-ORD1', end( $this->transport->raw_calls )['args']['headers']['X-Idempotency-Key'] );
		$this->transport->response_override = Transport::response( Transport::fixture( 'error-400' ), 400 );
		$this->assertSame( 'wcpos_capture_mode_unsupported', $this->adapter->cancel( 'ORD1' )->get_error_code() );
		$this->assertSame( 501, $this->adapter->capture( 'ORD1' )->get_error_data()['status'] );
		$this->assertSame( 501, $this->adapter->answer( 'ORD1', 'prompt', 'yes' )->get_error_data()['status'] );
	}
	/** @dataProvider refund_states */
	public function test_refund_full_partial_historical_and_result( string $state, string $expected ): void {
		list( $order, $row ) = $this->create();
		$this->transport->script( 'refund_ok' );
		$this->assertSame( 'succeeded', $this->adapter->refund( $row, 7, '24.00' )['status'] );
		$call = end( $this->transport->raw_calls )['args'];
		$this->assertEmpty( $call['body'] );
		$this->assertSame( 'refund-7', $call['headers']['X-Idempotency-Key'] );
		$row['provider_refs'] = array( 'transaction_id' => 'ORD1' );
		$this->adapter->refund( $row, 8, '5.00' );
		$this->assertSame( array( 'amount' => '5.00', 'transaction_id' => 'PAY1' ), json_decode( end( $this->transport->raw_calls )['args']['body'], true ) );
		$remote = Transport::fixture( 'order-refunded' );
		$remote['transactions']['refunds'][] = array( 'id' => 'REFnew', 'amount' => '5.00', 'status' => $state );
		$this->transport->response_override = Transport::response( $remote );
		$row['provider_refs']['payment_id'] = 'PAY1';
		$this->assertSame( array( 'status' => $expected, 'provider_ref' => 'REFnew' ), $this->adapter->refund( $row, 9, '5.00' ) );
	}
	public static function refund_states(): array { return array( array( 'processed', 'succeeded' ), array( 'pending', 'pending' ), array( 'in_process', 'pending' ), array( 'processing', 'pending' ), array( 'unknown', 'pending' ), array( 'failed', 'failed' ), array( 'rejected', 'failed' ), array( 'cancelled', 'failed' ), array( 'canceled', 'failed' ) ); }
	/** @dataProvider refund_amounts */
	public function test_refund_verifies_only_processed_amount( string $state, string $amount, string $expected ): void {
		list( $order, $row ) = $this->create();
		$remote = Transport::fixture( 'order-refunded' );
		$remote['transactions']['refunds'][] = array( 'id' => 'REFnew', 'amount' => $amount, 'status' => $state );
		$this->transport->response_override = Transport::response( $remote );
		$old_logger = wc_get_logger();
		$logger = $this->getMockBuilder( \WC_Logger::class )->setConstructorArgs( array( array(), 'debug' ) )->onlyMethods( array( 'error' ) )->getMock();
		if ( 'processed' === $state && '24.0' !== $amount ) { // A processed refund with different money is pending AND logged.
			$logger->expects( $this->once() )->method( 'error' )->with(
				$this->callback( static function ( $message ) use ( $amount ) { return false !== strpos( $message, 'requested 24.00' ) && false !== strpos( $message, 'refunded ' . $amount ); } ),
				$this->equalTo( array( 'source' => 'mercadopago-terminal' ) )
			);
		} else { $logger->expects( $this->never() )->method( 'error' ); }
		$logging = static function () use ( &$logger ) { return $logger; };
		add_filter( 'woocommerce_logging_class', $logging );
		try {
			$this->assertSame( array( 'status' => $expected, 'provider_ref' => 'REFnew' ), $this->adapter->refund( $row, 11, '24.00' ) );
		} finally {
			$logger = $old_logger; wc_get_logger(); remove_filter( 'woocommerce_logging_class', $logging );
		}
	}
	public static function refund_amounts(): array {
		return array(
			array( 'processed', '24.0', 'succeeded' ), array( 'processed', '5.00', 'pending' ), array( 'processed', '', 'pending' ),
			array( 'processing', '24.00', 'pending' ), array( 'processing', '5.00', 'pending' ),
			array( 'pending', '24.00', 'pending' ), array( 'pending', '5.00', 'pending' ),
		);
	}
	public function test_empty_action_falls_back_to_historical_transaction_reference(): void {
		list( $order, $row ) = $this->create();
		$row['provider_refs'] = array( 'action' => '', 'transaction_id' => 'ORD1' );
		$this->assertSame( 'succeeded', $this->adapter->refund( $row, 10, '24.00' )['status'] );
	}
	public function test_webhook_rejects_unsigned_and_empty_secret_before_fetch(): void {
		$this->create();
		$request = $this->transport->webhook_request( 'tampered' );
		$count = count( $this->transport->raw_calls );
		$this->assertSame( 401, $this->adapter->verify_webhook( $request )->get_error_data()['status'] );
		$request = $this->transport->webhook_request( 'completed' );
		$options = get_option( 'woocommerce_' . Settings::GATEWAY_ID . '_settings' );
		$options['webhook_secret'] = ''; update_option( 'woocommerce_' . Settings::GATEWAY_ID . '_settings', $options );
		$this->assertSame( 401, $this->adapter->verify_webhook( $request )->get_error_data()['status'] );
		$this->assertCount( $count, $this->transport->raw_calls );
	}
	public function test_webhook_reference_event_and_authoritative_mapping(): void {
		list( $order, $row ) = $this->create();
		foreach ( array( 'completed' => 'captured', 'failed' => 'failed', 'cancelled' => 'voided', 'pending' => 'pending' ) as $event => $state ) {
			$result = $this->adapter->verify_webhook( $this->transport->webhook_request( $event ) );
			$this->assertSame( $row['id'], $result['payment_id'] );
			$this->assertSame( $state, $result['patch']['status'] );
			$this->assertSame( array( 'completed' => 'ORD1:processed:created', 'failed' => 'ORD1:failed:created', 'cancelled' => 'ORD1:canceled:created', 'pending' => 'ORD1:created:created' )[ $event ], $result['patch']['event_id'] );
		}
		$adopted = wcpos_pro_adopt_legacy_attempt( $order, Settings::GATEWAY_ID, 'ORD1', '24.00', 'EUR' );
		$request = $this->transport->webhook_request( 'completed' );
		$body = $request->get_json_params(); unset( $body['id'] ); $body['data']['status'] = 'failed'; $request->set_body( wp_json_encode( $body ) );
		$result = $this->adapter->verify_webhook( $request );
		$this->assertSame( $adopted['id'], $result['payment_id'] );
		$this->assertSame( 'captured', $result['patch']['status'] );
		$this->assertSame( 'ORD1', $result['patch']['provider_refs']['transaction_id'] );
		$this->assertSame( 'ORD1:processed:created', $result['patch']['event_id'] );
	}
	public function test_webhook_event_id_tracks_observation_not_notification(): void {
		$this->create();
		$request = $this->transport->webhook_request( 'completed' );
		$first = $this->adapter->verify_webhook( $request );
		$this->assertSame( $first['patch']['event_id'], $this->adapter->verify_webhook( $request )['patch']['event_id'] );
		$body = $request->get_json_params(); $body['id'] = 'another-notification'; $request->set_body( wp_json_encode( $body ) );
		$this->assertSame( $first['patch']['event_id'], $this->adapter->verify_webhook( $request )['patch']['event_id'] );
		$this->transport->orders['ORD1']['data']['status'] = 'canceled';
		$changed = $this->adapter->verify_webhook( $request );
		$this->assertNotSame( $first['patch']['event_id'], $changed['patch']['event_id'] );
		$this->assertSame( 'voided', $changed['patch']['status'] );
		$this->transport->orders['ORD1']['data']['transactions']['payments'][0]['status'] = 'canceled';
		$this->assertSame( 'ORD1:canceled:canceled', $this->adapter->verify_webhook( $request )['patch']['event_id'] );
		unset( $this->transport->orders['ORD1']['data']['transactions']['payments'][0]['status'] );
		$this->transport->orders['ORD1']['data']['status_detail'] = 'canceled_by_user';
		$this->transport->response_override = Transport::response( $this->transport->orders['ORD1']['data'] );
		$this->assertSame( 'ORD1:canceled:canceled_by_user', $this->adapter->verify_webhook( $request )['patch']['event_id'] );
	}
	public function test_cancel_cannot_cancel_order_is_terminal_only(): void {
		$this->transport->response_override = Transport::response( array( 'errors' => array( array( 'code' => 'cannot_cancel_order' ) ) ), 409 );
		$error = $this->adapter->fetch( 'ORD1' );
		$this->assertSame( 409, $error->get_error_data()['http_status'] ?? null );
		$this->assertSame( 'cannot_cancel_order', $error->get_error_data()['error_code'] ?? null );
		$this->assertEmpty( $error->get_error_data()['indeterminate'] ?? false );
		$error = $this->adapter->cancel( 'ORD1' );
		$this->assertSame( 'wcpos_capture_mode_unsupported', $error->get_error_code() );
		$this->assertSame( 501, $error->get_error_data()['status'] );
		$this->assertStringContainsString( 'Cancel it on the terminal', $error->get_error_message() );
	}
	public function test_cancel_order_already_canceled_is_requested(): void {
		$this->transport->response_override = Transport::response( array( 'errors' => array( array( 'code' => 'order_already_canceled' ) ) ), 409 );
		$this->assertSame( 'requested', $this->adapter->cancel( 'ORD1' ) );
	}
	public function test_create_terminal_busy_is_determinate(): void {
		list( $order, $row ) = $this->fresh_row(); // A first attempt: nothing of ours can be on the terminal.
		$this->transport->response_override = Transport::response( array( 'errors' => array( array( 'code' => 'already_queued_order_for_terminal' ) ) ), 409 );
		$error = $this->adapter->create_reader_action( $row, 'reader-1' );
		$this->assertSame( 'mercadopago_terminal_busy', $error->get_error_code() );
		$this->assertSame( 409, $error->get_error_data()['status'] );
		$this->assertEmpty( $error->get_error_data()['indeterminate'] ?? false );
		$this->assertSame( 'The terminal is still busy with an earlier order. Finish or cancel it on the terminal, then try again.', $error->get_error_message() );
	}
	/** Reviewer pass 2: a busy terminal on a REPLAYED create may be our own unanswered order — never a final failure. */
	public function test_create_terminal_busy_on_replay_is_indeterminate(): void {
		list( $order, $row ) = $this->fresh_row();
		$this->transport->response_override = new \WP_Error( 'http_request_failed', 'timed out' );
		$first = $this->adapter->create_reader_action( $row, 'reader-1' );
		$this->assertTrue( $first->get_error_data()['indeterminate'] );
		$this->transport->response_override = Transport::response( array( 'errors' => array( array( 'code' => 'already_queued_order_for_terminal' ) ) ), 409 );
		$replay = $this->adapter->create_reader_action( $row, 'reader-1' );
		$this->assertSame( 'mercadopago_terminal_busy', $replay->get_error_code() );
		$this->assertTrue( $replay->get_error_data()['indeterminate'], 'A replay must keep the row pending so the webhook or sweeper can settle the order on the terminal' );
		// The stored row's events are the durable signal when the transient is gone (object cache flushed).
		delete_transient( 'mptfwc_sent_' . $row['id'] );
		$row['events'] = array( array( 't' => gmdate( 'c' ), 'level' => 'warning', 'message' => 'Provider did not answer' ) );
		$durable = $this->adapter->create_reader_action( $row, 'reader-1' );
		$this->assertTrue( $durable->get_error_data()['indeterminate'], 'The row\'s own events must mark it as sent before' );
		// A different row's first attempt is still a final refusal.
		list( $order2, $row2 ) = $this->fresh_row();
		$final = $this->adapter->create_reader_action( $row2, 'reader-1' );
		$this->assertEmpty( $final->get_error_data()['indeterminate'] ?? false );
	}
	public function test_resource_locked_423_is_indeterminate(): void {
		$this->transport->response_override = Transport::response( array( 'errors' => array( array( 'code' => 'resource_locked' ) ) ), 423 );
		$error = $this->adapter->cancel( 'ORD1' );
		$this->assertTrue( $error->get_error_data()['indeterminate'] );
		$this->assertSame( 423, $error->get_error_data()['http_status'] );
	}
	public function test_idempotency_conflict_is_indeterminate_with_details(): void {
		$this->transport->response_override = Transport::response( array( 'errors' => array( array( 'code' => 'idempotency_key_already_used' ) ) ), 409 );
		$error = $this->adapter->cancel( 'ORD1' );
		$this->assertTrue( $error->get_error_data()['indeterminate'] );
		$this->assertSame( 409, $error->get_error_data()['http_status'] ?? null );
		$this->assertSame( 'idempotency_key_already_used', $error->get_error_data()['error_code'] ?? null );
	}
	public static function query_ids(): array { return array( array( 'data.id' ), array( 'data_id' ), array( 'id' ) ); }
	/** @dataProvider query_ids */
	public function test_signed_query_id_is_accepted( string $key ): void {
		list( $order, $row ) = $this->create();
		$request = $this->transport->webhook_request( 'completed' );
		$body = $request->get_json_params(); unset( $body['data']['id'] );
		$request->set_body( wp_json_encode( $body ) );
		$request->set_query_params( array( 'provider' => 'mercadopago', $key => 'ORD1' ) );
		$result = $this->adapter->verify_webhook( $request );
		$this->assertNotWPError( $result );
		$this->assertSame( $row['id'], $result['payment_id'] );
		$request->set_query_params( array( 'provider' => 'mercadopago', $key => 'tampered' ) );
		$this->assertSame( 401, $this->adapter->verify_webhook( $request )->get_error_data()['status'] );
	}
	public static function ignored_notifications(): array { return array( array( 'unrelated' ), array( 'legacy-unmapped' ), array( 'invalid-uuid' ), array( 'type' ), array( 'topic' ) ); }
	/** @dataProvider ignored_notifications */
	public function test_unrelated_signed_webhook_dispatches_200( string $kind ): void {
		$this->create();
		$request = $this->transport->webhook_request( 'completed' );
		if ( in_array( $kind, array( 'type', 'topic' ), true ) ) {
			$body = $request->get_json_params(); unset( $body['type'] ); $body[ $kind ] = 'payment';
			$request->set_body( wp_json_encode( $body ) );
		} else {
			$this->transport->orders['ORD1']['data']['external_reference'] = array( 'unrelated' => 'other-store', 'legacy-unmapped' => 'wcpos-123-abcd', 'invalid-uuid' => 'wcpos_not-a-uuid' )[ $kind ];
		}
		global $wp_rest_server;
		$old_server = $wp_rest_server; $wp_rest_server = null;
		try {
			$server = rest_get_server();
			( new \WCPOS\WooCommercePOSPro\API\V2\Payments_Webhook_Controller() )->register_routes();
			$count = count( $this->transport->raw_calls );
			$response = $server->dispatch( $request );
			$this->assertSame( 200, $response->get_status() );
			$this->assertSame( 'mercadopago_ignored', $response->get_data()['code'] ?? null );
			if ( in_array( $kind, array( 'type', 'topic' ), true ) ) { $this->assertCount( $count, $this->transport->raw_calls ); }
		} finally { $wp_rest_server = $old_server; }
	}
	public function test_completed_currency_uses_provider_then_store_fallback(): void {
		update_option( 'woocommerce_currency', 'USD' );
		$remote = Transport::fixture( 'order-processed' );
		$remote['currency'] = 'ARS';
		$this->transport->response_override = Transport::response( $remote );
		$this->assertSame( 'ARS', $this->adapter->fetch( 'ORD1' )['currency'] );
		unset( $remote['currency'] );
		$this->transport->response_override = Transport::response( $remote );
		$this->assertSame( 'USD', $this->adapter->fetch( 'ORD1' )['currency'] );
	}
	public function test_ars_order_cannot_settle_usd_payment(): void {
		update_option( 'woocommerce_currency', 'USD' );
		list( $order, $row ) = $this->create();
		$order->set_currency( 'USD' ); $order->save();
		$row = wcpos_pro_adopt_legacy_attempt( $order, Settings::GATEWAY_ID, 'ORD1', '24.00', 'USD' );
		$request = $this->transport->webhook_request( 'completed' );
		$this->transport->orders['ORD1']['data']['currency'] = 'ARS';
		$verified = $this->adapter->verify_webhook( $request );
		$result = wcpos_settle_payment( $verified['payment_id'], $verified['patch'] );
		$this->assertWPError( $result );
		$this->assertSame( 'wcpos_amount_mismatch', $result->get_error_code() );
		$this->assertSame( 'failed', Ledger::instance()->find( wc_get_order( $order->get_id() ), $row['id'] )['status'] );
	}
}
