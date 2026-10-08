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
		$this->assertSame( 'wcpos:' . $row['id'], $payload['external_reference'] );
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
	public function test_paid_amount_overrides_amount_and_missing_confirmation_is_null(): void {
		$remote = Transport::fixture( 'order-processed' );
		$remote['transactions']['payments'][0]['paid_amount'] = '1.00';
		$this->transport->response_override = Transport::response( $remote );
		$this->assertSame( '1.00', $this->adapter->fetch( 'ORD1' )['amount'] );
		unset( $remote['transactions']['payments'][0]['paid_amount'] );
		$this->transport->response_override = Transport::response( $remote );
		$this->assertSame( '24.00', $this->adapter->fetch( 'ORD1' )['amount'] );
		unset( $remote['transactions']['payments'] );
		$this->transport->response_override = Transport::response( $remote );
		$this->assertNull( $this->adapter->fetch( 'ORD1' )['amount'] );
	}
	public static function order_stores(): array { return array( array( false ), array( true ) ); }
	/** @dataProvider order_stores */
	public function test_404_grace_reads_persisted_row_age( bool $hpos ): void {
		update_option( 'woocommerce_custom_orders_table_enabled', $hpos ? 'yes' : 'no' );
		$this->assertSame( $hpos, \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() );
		list( $order, $row ) = $this->create();
		$row = wcpos_pro_adopt_legacy_attempt( $order, Settings::GATEWAY_ID, $row['provider_refs']['action'], '24.00', 'EUR' );
		$this->transport->response_override = Transport::response( array(), 404 );
		$this->assertSame( 'pending', ( new Provider_Adapter() )->fetch( $row['provider_refs']['action'] )['status'] );
		$row['created_at_gmt'] = gmdate( 'c', time() - 61 );
		Ledger::instance()->save( $order, array( $row ), false );
		$this->assertSame( array( 'status' => 'failed', 'failure_reason' => 'provider_error' ), $this->adapter->fetch( $row['provider_refs']['action'] ) );
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
	public static function errors(): array { return array( array( 0, true ), array( 500, true ), array( 503, true ), array( 409, true ), array( 429, true ), array( 400, false ), array( 401, false ), array( 403, false ) ); }
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
		$remote['transactions']['refunds'][] = array( 'id' => 'REFnew', 'status' => $state );
		$this->transport->response_override = Transport::response( $remote );
		$row['provider_refs']['payment_id'] = 'PAY1';
		$this->assertSame( array( 'status' => $expected, 'provider_ref' => 'REFnew' ), $this->adapter->refund( $row, 9, '5.00' ) );
	}
	public static function refund_states(): array { return array( array( 'processed', 'succeeded' ), array( 'pending', 'pending' ), array( 'in_process', 'pending' ), array( 'failed', 'failed' ) ); }
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
			$this->assertSame( 'ORD1-' . $event, $result['patch']['event_id'] );
		}
		$adopted = wcpos_pro_adopt_legacy_attempt( $order, Settings::GATEWAY_ID, 'ORD1', '24.00', 'EUR' );
		$request = $this->transport->webhook_request( 'completed' );
		$body = $request->get_json_params(); unset( $body['id'] ); $body['data']['status'] = 'failed'; $request->set_body( wp_json_encode( $body ) );
		$result = $this->adapter->verify_webhook( $request );
		$this->assertSame( $adopted['id'], $result['payment_id'] );
		$this->assertSame( 'captured', $result['patch']['status'] );
		$this->assertSame( 'ORD1', $result['patch']['provider_refs']['transaction_id'] );
		$this->assertSame( 'conformance-request', $result['patch']['event_id'] );
	}
}
