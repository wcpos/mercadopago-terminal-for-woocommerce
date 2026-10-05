<?php
use PHPUnit\Framework\TestCase;
use WCPOS\WooCommercePOS\MercadoPagoTerminal\AjaxHandler;
use WCPOS\WooCommercePOS\MercadoPagoTerminal\Gateway;
use WCPOS\WooCommercePOS\MercadoPagoTerminal\PaymentAttempt;
use WCPOS\WooCommercePOS\MercadoPagoTerminal\Settings;

class GatewayTest extends TestCase {
	private $order;

	protected function setUp(): void {
		WP_Stub::reset();
		$_GET = array();
		$this->order = new MPTFWC_Test_Order( 123 );
		$GLOBALS['mptfwc_orders'] = array( 123 => $this->order );
		$GLOBALS['wp'] = (object) array( 'query_vars' => array( 'order-pay' => 123 ) );
	}

	protected function tearDown(): void {
		$_GET = array();
		unset( $GLOBALS['mptfwc_orders'], $GLOBALS['wp'] );
		WP_Stub::reset();
	}

	private function render( Gateway $gateway, string $method ): string {
		ob_start();
		try {
			$gateway->$method();
			return ob_get_contents();
		} finally {
			ob_end_clean();
		}
	}

	public function test_supports_refunds(): void {
		$this->assertContains( 'refunds', ( new Gateway() )->supports );
	}

	public function test_admin_options_include_support_download_without_terminals(): void {
		$html = $this->render( new Gateway(), 'admin_options' );
		$this->assertStringContainsString( 'Download support bundle', $html );
		$this->assertStringContainsString( 'admin-post.php?action=mptfwc_support_bundle', $html );
		$this->assertStringContainsString( '_wpnonce=nonce-mptfwc_support_bundle', $html );
		$this->assertStringContainsString( 'source=mercadopago-terminal', $html );
		$this->assertStringContainsString( 'View logs', $html );
	}

	public function test_form_fields_without_a_settings_screen_use_a_text_terminal_field(): void {
		$gateway = new Gateway();
		$this->assertSame( array( 'enabled', 'title', 'description', 'mode', 'access_token', 'webhook_secret', 'default_terminal_id', 'lock_terminal', 'show_logs', 'log_level' ), array_keys( $gateway->form_fields ) );
		$this->assertSame( 'select', $gateway->form_fields['log_level']['type'] );
		$this->assertSame( 'debug', $gateway->form_fields['log_level']['default'] );
		$this->assertSame( array( 'off' => 'Off', 'errors' => 'Errors only', 'debug' => 'Debug (recommended while testing)' ), $gateway->form_fields['log_level']['options'] );
		$this->assertSame( 'text', $gateway->form_fields['default_terminal_id']['type'] );
		$this->assertSame( 'Mercado Pago Terminal', $gateway->form_fields['title']['default'] );
		$this->assertSame( 'Pay in person on a Mercado Pago Point terminal.', $gateway->form_fields['description']['default'] );
		$this->assertSame( 'password', $gateway->form_fields['access_token']['type'] );
		$this->assertSame( 'password', $gateway->form_fields['webhook_secret']['type'] );
		$this->assertSame( array( 'products', 'refunds' ), $gateway->supports );
		$this->assertSame( array(), WP_Stub::$http_requests );
	}

	public function test_webhook_secret_description_includes_url_and_event(): void {
		$description = ( new Gateway() )->form_fields['webhook_secret']['description'];
		$this->assertStringContainsString( 'https://shop.test/wp-admin/admin-ajax.php?action=mptfwc_webhook', $description );
		$this->assertStringContainsString( 'Your integrations → Webhooks', $description );
		$this->assertStringContainsString( 'Order (Mercado Pago)', $description );
	}

	public function test_payment_fields_include_order_token_and_resume_only_nonfinal_attempts(): void {
		WP_Stub::$checkout_pay_page = true;
		$this->order->update_meta_data( PaymentAttempt::META_CURRENT_MP_ORDER_ID, 'ORD1' );
		$gateway = new Gateway();
		foreach ( array( 'at_terminal' => '1', 'canceled' => '0' ) as $status => $resume ) {
			$this->order->update_meta_data( PaymentAttempt::META_CURRENT_STATUS, $status );
			$html = $this->render( $gateway, 'payment_fields' );
			$this->assertStringContainsString( 'id="mptfwc-payment-interface"', $html );
			$this->assertStringContainsString( 'data-order-id="123"', $html );
			$this->assertStringContainsString( 'data-order-token="' . AjaxHandler::order_token( 123 ) . '"', $html );
			$this->assertStringContainsString( 'data-resume="' . $resume . '"', $html );
			$this->assertStringContainsString( 'data-gateway-id="' . Settings::GATEWAY_ID . '"', $html );
			$this->assertStringContainsString( 'data-mptfwc-mode="' . ( '1' === $resume ? 'cancel' : 'start' ) . '"', $html );
			$this->assertSame( 1, substr_count( $html, 'mptfwc-primary-action' ) );
			$this->assertStringContainsString( 'role="status" aria-live="polite"', $html );
			$this->assertStringNotContainsString( 'mptfwc-qr', $html );
		}
	}

	public function test_locked_terminal_and_log_tools_are_rendered_from_settings(): void {
		WP_Stub::$checkout_pay_page = true;
		WP_Stub::$options[ 'woocommerce_' . Settings::GATEWAY_ID . '_settings' ] = array( 'default_terminal_id' => 'T1', 'lock_terminal' => 'yes', 'show_logs' => 'yes' );
		$html = $this->render( new Gateway(), 'payment_fields' );
		$this->assertStringContainsString( 'data-default-terminal-id="T1"', $html );
		$this->assertStringContainsString( 'data-lock-terminal="1"', $html );
		$this->assertStringContainsString( '<option value="T1" selected>T1</option>', $html );
		$this->assertStringNotContainsString( 'aria-busy="true"', $html );
		$this->assertStringContainsString( 'mptfwc-toggle-log', $html );
		$this->assertStringContainsString( 'mptfwc-copy-log', $html );
		$this->assertStringContainsString( 'mptfwc-clear-log', $html );
	}

	public function test_process_payment_returns_received_url_for_paid_order(): void {
		$this->order->paid = true;
		$this->assertSame( array( 'result' => 'success', 'redirect' => '/checkout/order-received/123/?key=key' ), ( new Gateway() )->process_payment( 123 ) );
		$this->assertSame( array(), WP_Stub::$http_requests );
		$this->assertSame( array(), WP_Stub::$notices );
	}

	public function test_process_payment_polls_unpaid_order_and_notices_when_still_at_terminal(): void {
		WP_Stub::$options[ 'woocommerce_' . Settings::GATEWAY_ID . '_settings' ] = array( 'access_token' => 'TEST-token' );
		$pending = PaymentAttempt::prepare( $this->order, 'T1', '24.00' );
		$remote = array( 'id' => 'ORD1', 'status' => 'created', 'external_reference' => $pending['external_reference'] );
		PaymentAttempt::record_created( $this->order, $pending, $remote );
		$remote['status'] = 'at_terminal';
		WP_Stub::$http_responses[] = array( 'response' => array( 'code' => 200 ), 'body' => json_encode( $remote ) );
		$this->assertSame( array( 'result' => 'failure' ), ( new Gateway() )->process_payment( 123 ) );
		$this->assertCount( 1, WP_Stub::$http_requests );
		$this->assertSame( 'https://api.mercadopago.com/v1/orders/ORD1', WP_Stub::$http_requests[0]['url'] );
		$this->assertSame( 'at_terminal', PaymentAttempt::current( $this->order )['status'] );
		$this->assertFalse( $this->order->is_paid() );
		$this->assertCount( 1, WP_Stub::$notices );
		$this->assertSame( 'notice', WP_Stub::$notices[0]['type'] );
		$this->assertStringContainsString( 'wait for Mercado Pago to confirm', WP_Stub::$notices[0]['message'] );
	}

	public function test_enqueue_scripts_localizes_the_checkout_contract(): void {
		$gateway = new Gateway();
		$gateway->enqueue_payment_scripts();
		$data = WP_Stub::$scripts['mptfwc-payment']['localized']['mptfwcPaymentData'];
		$this->assertSame( 360000, $data['pollTimeoutMs'] );
		$this->assertSame( 2000, $data['pollIntervalMs'] );
		$this->assertSame( 'https://shop.test/wp-admin/admin-ajax.php', $data['ajaxUrl'] );
		$keys = array( 'logsShown', 'logsHidden', 'copied', 'copyFailed', 'startAction', 'cancelAction', 'idle', 'sending', 'waiting', 'atTerminal', 'actionRequired', 'completing', 'selectTerminal', 'failed', 'canceled', 'expired', 'cancelOnTerminal', 'timedOut', 'contacting', 'requestFailed', 'noTerminals', 'selectTerminalOption', 'terminalsFailed', 'verificationFailed' );
		$this->assertEqualsCanonicalizing( $keys, array_keys( $data['i18n'] ) );
		$this->assertSame( 'Customer is paying on the terminal…', $data['i18n']['atTerminal'] );
		$this->assertSame( 'Confirm the payment on the terminal.', $data['i18n']['actionRequired'] );
		$this->assertSame( 'The payment expired on the terminal. You can try again.', $data['i18n']['expired'] );
		$this->assertSame( 'The payment is already on the terminal. Cancel it on the terminal, or wait for it to expire.', $data['i18n']['cancelOnTerminal'] );
		$this->assertSame( 'Contacting Mercado Pago…', $data['i18n']['contacting'] );
		$this->assertSame( 'Mercado Pago reported a payment that does not match this order. Check the order notes.', $data['i18n']['verificationFailed'] );
		$this->assertSame( MPTFWC_PLUGIN_URL . 'assets/js/payment.js', WP_Stub::$scripts['mptfwc-payment']['script']['src'] );
		$this->assertSame( MPTFWC_PLUGIN_URL . 'assets/css/payment.css', WP_Stub::$scripts['mptfwc-payment']['style']['src'] );
		$gateway->enqueue_admin_scripts();
		$this->assertSame( MPTFWC_PLUGIN_URL . 'assets/js/admin.js', WP_Stub::$scripts['mptfwc-admin']['script']['src'] );
		$this->assertSame( array( 'jquery' ), WP_Stub::$scripts['mptfwc-admin']['script']['deps'] );
		$this->assertSame( array( 'ajaxUrl' => $data['ajaxUrl'] ), WP_Stub::$scripts['mptfwc-admin']['localized']['mptfwcAdminData'] );
		add_filter( 'mptfwc_poll_interval_ms', function () { return 1000; } );
		add_filter( 'mptfwc_poll_timeout_ms', function () { return 420000; } );
		$gateway->enqueue_payment_scripts();
		$this->assertSame( 1000, WP_Stub::$scripts['mptfwc-payment']['localized']['mptfwcPaymentData']['pollIntervalMs'] );
		$this->assertSame( 420000, WP_Stub::$scripts['mptfwc-payment']['localized']['mptfwcPaymentData']['pollTimeoutMs'] );
	}

	public function test_settings_fetch_terminal_options_and_render_pdv_controls(): void {
		WP_Stub::$is_admin = true;
		$_GET['section'] = Settings::GATEWAY_ID;
		WP_Stub::$options[ 'woocommerce_' . Settings::GATEWAY_ID . '_settings' ] = array( 'access_token' => 'TEST-token', 'default_terminal_id' => 'NEWLAND_N950__SBX0000001' );
		$response = array( 'response' => array( 'code' => 200 ), 'body' => file_get_contents( __DIR__ . '/../fixtures/terminals-list.json' ) );
		WP_Stub::$http_responses = array( $response, $response );
		$gateway = new Gateway();
		$this->assertCount( 1, WP_Stub::$http_requests );
		$this->assertSame( 8, WP_Stub::$http_requests[0]['args']['timeout'] );
		$this->assertSame( 'select', $gateway->form_fields['default_terminal_id']['type'] );
		$this->assertSame( 'multiselect', $gateway->form_fields['enabled_terminals']['type'] );
		$this->assertSame( 'SUC0101POS (NEWLAND_N950__SBX0000001)', $gateway->form_fields['enabled_terminals']['options']['NEWLAND_N950__SBX0000001'] );
		$this->assertSame( $gateway->form_fields['enabled_terminals']['options'], WP_Stub::$transients['mptfwc_terminal_choices_test'] );
		$html = $this->render( $gateway, 'admin_options' );
		$this->assertStringContainsString( 'Mercado Pago Terminal diagnostics', $html );
		$this->assertStringContainsString( 'source=mercadopago-terminal', $html );
		$this->assertStringContainsString( 'source: mercadopago-terminal)', $html );
		$this->assertStringContainsString( 'MISSING — webhook signatures are not verified', $html );
		$this->assertStringContainsString( 'data-nonce="nonce-mptfwc_admin_actions"', $html );
		$this->assertStringContainsString( '<td>STANDALONE</td>', $html );
		$this->assertStringContainsString( '<button type="button" class="button mptfwc-set-pdv" data-terminal-id="NEWLAND_N950__N950NCB801293324">Switch to PDV</button>', $html );
		$this->assertSame( 1, substr_count( $html, 'mptfwc-set-pdv' ) );
		$this->assertStringNotContainsString( 'TEST-token', $html );
		new Gateway();
		$this->assertCount( 2, WP_Stub::$http_requests );
		WP_Stub::$transients['mptfwc_terminal_choices_live'] = array( 'T1' );
		$gateway->clear_terminal_cache();
		$this->assertSame( array(), WP_Stub::$transients );
	}

	public function test_settings_api_failure_omits_enabled_terminals_and_uses_text(): void {
		WP_Stub::$is_admin = true;
		$_GET['section'] = Settings::GATEWAY_ID;
		WP_Stub::$options[ 'woocommerce_' . Settings::GATEWAY_ID . '_settings' ] = array( 'access_token' => 'TEST-token' );
		WP_Stub::$http_responses[] = array( 'response' => array( 'code' => 503 ), 'body' => '{"message":"Unavailable"}' );
		$gateway = new Gateway();
		$this->assertSame( 'text', $gateway->form_fields['default_terminal_id']['type'] );
		$this->assertArrayNotHasKey( 'enabled_terminals', $gateway->form_fields );
		$this->assertCount( 1, WP_Stub::$http_requests );
	}

	public function test_bootstrap_registers_gateway_and_ajax_handlers(): void {
		WP_Stub::$doing_ajax = true;
		\WCPOS\WooCommercePOS\MercadoPagoTerminal\init();
		$this->assertSame( array( 'existing', Gateway::class ), apply_filters( 'woocommerce_payment_gateways', array( 'existing' ) ) );
		$this->assertContains( 'wp_ajax_nopriv_mptfwc_start_payment', array_column( WP_Stub::$actions, 'hook' ) );
		$this->assertContains( 'wp_ajax_mptfwc_webhook', array_column( WP_Stub::$actions, 'hook' ) );
		$this->assertContains( 'admin_post_mptfwc_support_bundle', array_column( WP_Stub::$actions, 'hook' ) );
		$this->assertNotContains( 'admin_post_nopriv_mptfwc_support_bundle', array_column( WP_Stub::$actions, 'hook' ) );
	}
}
