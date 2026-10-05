<?php
use PHPUnit\Framework\TestCase;
use WCPOS\WooCommercePOS\MercadoPagoTerminal\AjaxHandler;
use WCPOS\WooCommercePOS\MercadoPagoTerminal\Services\PointPaymentService;
use WCPOS\WooCommercePOS\MercadoPagoTerminal\Services\TerminalService;
use WCPOS\WooCommercePOS\MercadoPagoTerminal\Settings;

class AjaxHandlerTest extends TestCase {
	private $payments;
	private $terminals;
	private $handler;
	private $factory_calls;
	private $order;

	protected function setUp(): void {
		WP_Stub::reset();
		$_POST = array();
		$this->order = new MPTFWC_Test_Order( 123 );
		$GLOBALS['mptfwc_orders'] = array( 123 => $this->order );
		$this->payments = $this->createMock( PointPaymentService::class );
		$this->terminals = $this->createMock( TerminalService::class );
		$this->factory_calls = 0;
		$this->handler = new AjaxHandler( function ( Settings $settings ): array {
			$this->factory_calls++;
			return array( 'payments' => $this->payments, 'terminals' => $this->terminals );
		} );
	}

	protected function tearDown(): void {
		$_POST = array();
		unset( $GLOBALS['mptfwc_orders'] );
		WP_Stub::reset();
	}

	private function request( string $action ): array {
		WP_Stub::$json = null;
		try {
			$this->handler->{ 'mptfwc_' . $action }();
		} catch ( WP_Stub_Json_Exit $e ) {
			return WP_Stub::$json;
		}
		$this->fail( 'The AJAX handler did not send JSON.' );
	}

	private function authorize(): void {
		$_POST = array( 'order_id' => 123, 'order_token' => AjaxHandler::order_token( 123 ) );
	}

	public function test_order_actions_refuse_missing_id_and_wrong_token_before_services(): void {
		foreach ( array( 'start_payment', 'poll_payment', 'cancel_payment', 'list_terminals' ) as $action ) {
			$_POST = array();
			$result = $this->request( $action );
			$this->assertFalse( $result['success'] );
			$this->assertSame( 'list_terminals' === $action ? 403 : 400, $result['code'] );
			$_POST = array( 'order_id' => 123, 'order_token' => AjaxHandler::order_token( 124 ) );
			$result = $this->request( $action );
			$this->assertSame( array( 'success' => false, 'data' => 'Unauthorized request.', 'code' => 403 ), $result );
		}
		$this->assertSame( 0, $this->factory_calls );
		$this->assertSame( array(), $this->order->meta );
		$this->assertSame( 0, $this->order->save_calls );
	}

	public function test_start_and_list_refuse_a_disabled_gateway(): void {
		$this->authorize();
		foreach ( array( 'start_payment', 'list_terminals' ) as $action ) {
			$this->assertSame( array( 'success' => false, 'data' => 'Mercado Pago Terminal is disabled.', 'code' => 403 ), $this->request( $action ) );
		}
		$this->assertSame( 0, $this->factory_calls );
	}

	public function test_start_uses_the_locked_default_terminal(): void {
		$this->authorize();
		WP_Stub::$options[ 'woocommerce_' . Settings::GATEWAY_ID . '_settings' ] = array( 'enabled' => 'yes', 'lock_terminal' => 'yes', 'default_terminal_id' => 'T1' );
		$_POST['terminal_id'] = 'T2';
		$this->payments->expects( $this->once() )->method( 'start_payment_for_order' )->with( $this->order, 'T1' )->willReturn( array( 'status' => 'created' ) );
		$this->assertSame( array( 'success' => true, 'data' => array( 'status' => 'created' ), 'code' => 200 ), $this->request( 'start_payment' ) );
	}

	public function test_start_uses_the_submitted_terminal_when_unlocked(): void {
		$this->authorize();
		WP_Stub::$options[ 'woocommerce_' . Settings::GATEWAY_ID . '_settings' ] = array( 'enabled' => 'yes', 'default_terminal_id' => 'T1' );
		$_POST['terminal_id'] = 'T2';
		$this->payments->expects( $this->once() )->method( 'start_payment_for_order' )->with( $this->order, 'T2' )->willReturn( array( 'status' => 'created' ) );
		$this->assertTrue( $this->request( 'start_payment' )['success'] );
	}

	public function test_poll_adds_paid_redirects_even_when_gateway_is_disabled(): void {
		$this->authorize();
		$this->payments->expects( $this->exactly( 3 ) )->method( 'poll_order' )->with( $this->order )->willReturnOnConsecutiveCalls( array( 'status' => 'paid' ), array( 'status' => 'already_paid' ), array( 'status' => 'conflict' ) );
		foreach ( array( 'paid', 'already_paid', 'conflict' ) as $status ) {
			$result = $this->request( 'poll_payment' );
			$this->assertTrue( $result['success'] );
			$this->assertSame( $status, $result['data']['status'] );
			$this->assertSame( '/checkout/order-received/123/?key=key', $result['data']['redirect_url'] );
		}
	}

	public function test_cancel_on_terminal_passes_through_without_redirect_when_disabled(): void {
		$this->authorize();
		$data = array( 'status' => 'cancel_on_terminal', 'message' => 'Cancel it on the terminal.', 'retry_allowed' => false );
		$this->payments->expects( $this->once() )->method( 'cancel_order_payment' )->with( $this->order )->willReturn( $data );
		$this->assertSame( array( 'success' => true, 'data' => $data, 'code' => 200 ), $this->request( 'cancel_payment' ) );
	}

	public function test_order_capabilities_allow_access_without_a_token(): void {
		$_POST = array( 'order_id' => 123 );
		$this->payments->expects( $this->exactly( 2 ) )->method( 'poll_order' )->with( $this->order )->willReturn( array( 'status' => 'idle' ) );
		foreach ( array( 'manage_woocommerce', 'edit_shop_order' ) as $cap ) {
			WP_Stub::$caps = array( $cap => true );
			$this->assertTrue( $this->request( 'poll_payment' )['success'] );
		}
	}

	public function test_missing_order_is_a_404(): void {
		$this->authorize();
		$GLOBALS['mptfwc_orders'] = array();
		$this->assertSame( array( 'success' => false, 'data' => 'Invalid order.', 'code' => 404 ), $this->request( 'poll_payment' ) );
		$this->assertSame( 0, $this->factory_calls );
	}

	public function test_list_filters_enabled_terminals_and_labels_standalone_mode(): void {
		$this->authorize();
		WP_Stub::$options[ 'woocommerce_' . Settings::GATEWAY_ID . '_settings' ] = array( 'enabled' => 'yes', 'enabled_terminals' => array( 'T1' ), 'default_terminal_id' => 'T1', 'lock_terminal' => 'yes' );
		$rows = array(
			array( 'id' => 'T1', 'label' => 'Front', 'operating_mode' => 'STANDALONE', 'store_id' => 'S1', 'pos_id' => 'P1' ),
			array( 'id' => 'T2', 'label' => 'Back', 'operating_mode' => 'PDV', 'store_id' => 'S1', 'pos_id' => 'P2' ),
		);
		$this->terminals->expects( $this->once() )->method( 'list_terminals' )->willReturn( $rows );
		$result = $this->request( 'list_terminals' );
		$rows[0]['label'] .= ' — standalone mode, switch to PDV in settings';
		$this->assertSame( array( 'success' => true, 'data' => array( 'terminals' => array( $rows[0] ), 'default_terminal_id' => 'T1', 'lock_terminal' => true ), 'code' => 200 ), $result );
		$this->assertCount( 2, AjaxHandler::selectable_terminals( $rows ) );
		$this->assertSame( $rows[1], AjaxHandler::selectable_terminals( $rows )[1] );
	}

	public function test_set_pdv_refuses_missing_capability_bad_nonce_and_missing_id(): void {
		$_POST = array( 'terminal_id' => 'T1', 'nonce' => 'submitted' );
		WP_Stub::$nonce_ok = true;
		$this->assertSame( array( 'success' => false, 'data' => 'Security check failed', 'code' => 403 ), $this->request( 'set_pdv_mode' ) );
		WP_Stub::$caps = array( 'manage_woocommerce' => true );
		WP_Stub::$nonce_ok = false;
		$this->assertSame( array( 'success' => false, 'data' => 'Security check failed', 'code' => 403 ), $this->request( 'set_pdv_mode' ) );
		WP_Stub::$nonce_ok = true;
		unset( $_POST['terminal_id'] );
		$this->assertSame( array( 'success' => false, 'data' => 'Terminal ID is required.', 'code' => 400 ), $this->request( 'set_pdv_mode' ) );
		$this->assertSame( 0, $this->factory_calls );
	}

	public function test_set_pdv_changes_mode_clears_both_caches_and_requests_restart(): void {
		WP_Stub::$caps = array( 'manage_woocommerce' => true );
		WP_Stub::$nonce_ok = true;
		WP_Stub::$transients = array( 'mptfwc_terminal_choices_test' => array( 'T1' ), 'mptfwc_terminal_choices_live' => array( 'T1' ) );
		$_POST = array( 'terminal_id' => 'T1', 'nonce' => 'submitted' );
		$this->terminals->expects( $this->once() )->method( 'set_pdv_mode' )->with( 'T1' )->willReturn( array() );
		$result = $this->request( 'set_pdv_mode' );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 200, $result['code'] );
		$this->assertSame( array( 'terminal_id' => 'T1', 'operating_mode' => 'PDV', 'message' => 'Switched to PDV mode. Restart the terminal to apply it.' ), $result['data'] );
		$this->assertSame( array(), WP_Stub::$transients );
	}

	public function test_service_exceptions_return_500_and_the_message(): void {
		$this->authorize();
		WP_Stub::$options[ 'woocommerce_' . Settings::GATEWAY_ID . '_settings' ] = array( 'enabled' => 'yes' );
		WP_Stub::$caps = array( 'manage_woocommerce' => true );
		WP_Stub::$nonce_ok = true;
		$_POST['terminal_id'] = 'T1';
		$this->payments->method( 'start_payment_for_order' )->willThrowException( new RuntimeException( 'Service unavailable' ) );
		$this->terminals->method( 'list_terminals' )->willThrowException( new RuntimeException( 'Service unavailable' ) );
		$this->terminals->method( 'set_pdv_mode' )->willThrowException( new RuntimeException( 'Service unavailable' ) );
		foreach ( array( 'start_payment', 'list_terminals', 'set_pdv_mode' ) as $action ) {
			$this->assertSame( array( 'success' => false, 'data' => 'Service unavailable', 'code' => 500 ), $this->request( $action ) );
		}
	}

	public function test_only_ajax_requests_register_the_expected_hooks(): void {
		$this->assertSame( array(), WP_Stub::$actions );
		WP_Stub::$doing_ajax = true;
		new AjaxHandler();
		$expected = array();
		foreach ( array( 'start_payment', 'poll_payment', 'cancel_payment', 'list_terminals' ) as $action ) {
			$expected[] = 'wp_ajax_mptfwc_' . $action;
			$expected[] = 'wp_ajax_nopriv_mptfwc_' . $action;
		}
		$expected[] = 'wp_ajax_mptfwc_set_pdv_mode';
		$this->assertSame( $expected, array_column( WP_Stub::$actions, 'hook' ) );
		foreach ( WP_Stub::$actions as $action ) {
			$this->assertTrue( is_callable( $action['callback'] ) );
		}
	}

	public function test_wrong_token_logs_reason_and_context_without_the_token(): void {
		\WCPOS\WooCommercePOS\MercadoPagoTerminal\Logger::$logger = null;
		\WCPOS\WooCommercePOS\MercadoPagoTerminal\Logger::configure( 'debug' );
		$_POST = array( 'order_id' => 123, 'order_token' => 'wrong-secret-token' );
		$this->assertSame( 403, $this->request( 'poll_payment' )['code'] );
		$log = end( WP_Stub::$logs );
		$this->assertSame( 'warning', $log['level'] );
		$this->assertStringContainsString( 'AJAX request rejected', $log['message'] );
		foreach ( array( '"action":"mptfwc_poll_payment"', '"reason":"unauthorized"', '"order_id":123', '"credential_submitted":true', '"logged_in":false', '"can_manage":false' ) as $context ) {
			$this->assertStringContainsString( $context, $log['message'] );
		}
		$this->assertStringNotContainsString( $_POST['order_token'], json_encode( WP_Stub::$logs ) );
	}

	public function test_php_errors_return_json_500_for_each_service_handler(): void {
		$this->authorize();
		WP_Stub::$options[ 'woocommerce_' . Settings::GATEWAY_ID . '_settings' ] = array( 'enabled' => 'yes' );
		WP_Stub::$caps = array( 'manage_woocommerce' => true );
		WP_Stub::$nonce_ok = true;
		$_POST['terminal_id'] = 'T1';
		$this->payments->method( 'start_payment_for_order' )->willThrowException( new Error( 'Service error' ) );
		$this->terminals->method( 'list_terminals' )->willThrowException( new Error( 'Service error' ) );
		$this->terminals->method( 'set_pdv_mode' )->willThrowException( new Error( 'Service error' ) );
		foreach ( array( 'start_payment', 'list_terminals', 'set_pdv_mode' ) as $action ) {
			$this->assertSame( array( 'success' => false, 'data' => 'Service error', 'code' => 500 ), $this->request( $action ) );
		}
	}
}
