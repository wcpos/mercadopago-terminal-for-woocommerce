<?php
use PHPUnit\Framework\TestCase;
use WCPOS\WooCommercePOS\MercadoPagoTerminal\AjaxHandler;
use WCPOS\WooCommercePOS\MercadoPagoTerminal\Gateway;
use WCPOS\WooCommercePOS\MercadoPagoTerminal\PaymentAttempt;
use WCPOS\WooCommercePOS\MercadoPagoTerminal\Settings;

if ( ! function_exists( 'woocommerce_pos_request' ) ) {
	function woocommerce_pos_request( ?bool $value = null ): bool {
		static $is_pos = false;
		if ( null !== $value ) {
			$is_pos = $value;
		}
		return $is_pos;
	}
}

class GatewayTest extends TestCase {
	private $order;

	protected function setUp(): void {
		WP_Stub::reset();
		$_GET = array();
		$_POST = array();
		$this->order = new MPTFWC_Test_Order( 123 );
		$GLOBALS['mptfwc_orders'] = array( 123 => $this->order );
		$GLOBALS['wp'] = (object) array( 'query_vars' => array( 'order-pay' => 123 ) );
	}

	protected function tearDown(): void {
		$_GET = array();
		$_POST = array();
		woocommerce_pos_request( false );
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
		$this->assertSame( 'mptfwc_secret', $gateway->form_fields['access_token']['type'] );
		$this->assertSame( 'mptfwc_secret', $gateway->form_fields['webhook_secret']['type'] );
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
		WP_Stub::$checkout_pay_page = true;
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
		WP_Stub::$transients['mptfwc_terminal_rows'] = array( 'T1' );
		$gateway->clear_terminal_cache();
		$this->assertSame( array( 'mptfwc_api_check_test' ), array_keys( WP_Stub::$transients ) );
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

	public function test_secret_fields_render_blank_and_validate_without_losing_saved_values(): void {
		$secrets = array( 'access_token' => 'APP_USR-1234567890123456-100512-abcdefabcdef-123456789', 'webhook_secret' => 'whsec-super-secret-value-123' );
		WP_Stub::$options[ 'woocommerce_' . Settings::GATEWAY_ID . '_settings' ] = $secrets;
		$gateway = new Gateway();
		$html = $this->render( $gateway, 'admin_options' );
		foreach ( $secrets as $key => $saved ) {
			$this->assertStringNotContainsString( $saved, $html );
			$this->assertStringNotContainsString( $saved, json_encode( WP_Stub::$logs ) );
			$this->assertSame( $saved, $gateway->validate_mptfwc_secret_field( $key, " \t\n" ) );
			$this->assertSame( 'new-secret', $gateway->validate_mptfwc_secret_field( $key, ' new-secret ' ) );
			$this->assertStringContainsString( 'name="' . $gateway->get_field_key( $key ) . '"', $html );
		}
		$this->assertSame( 2, substr_count( $html, 'type="password" autocomplete="new-password"' ) );
		$this->assertSame( 2, substr_count( $html, 'value=""' ) );
		$this->assertStringContainsString( 'Saved (ends in 6789). Leave blank to keep.', $html );
		$this->assertStringContainsString( 'Your integrations → Webhooks', $html );
		$this->assertSame( array(), WP_Stub::$http_requests );
		WP_Stub::$options = array();
		$gateway = new Gateway();
		$this->assertStringContainsString( 'placeholder="Paste here"', $gateway->generate_mptfwc_secret_html( 'access_token', array( 'title' => 'Token', 'placeholder' => 'Paste here' ) ) );
	}

	public function test_credential_changes_reset_health_and_caches_but_blank_unchanged_save_keeps_them(): void {
		foreach ( array( 'access_token', 'webhook_secret', 'mode' ) as $key ) {
			$saved = array( 'access_token' => 'TEST-token', 'webhook_secret' => 'secret', 'mode' => 'test' );
			WP_Stub::$options[ 'woocommerce_' . Settings::GATEWAY_ID . '_settings' ] = $saved;
			WP_Stub::$options['mptfwc_last_verified_webhook'] = 123;
			$caches = array_fill_keys( array( 'mptfwc_api_check_test', 'mptfwc_api_check_live', 'mptfwc_terminal_choices_test', 'mptfwc_terminal_choices_live', 'mptfwc_terminal_rows' ), array( 'cached' ) );
			WP_Stub::$transients = $caches;
			$gateway = new Gateway();
			$_POST = array( $gateway->get_field_key( 'access_token' ) => ' ', $gateway->get_field_key( 'webhook_secret' ) => '', $gateway->get_field_key( 'mode' ) => 'test' );
			$this->assertFalse( $gateway->process_admin_options() );
			$this->assertSame( $saved, WP_Stub::$options[ 'woocommerce_' . Settings::GATEWAY_ID . '_settings' ] );
			$this->assertSame( 123, get_option( 'mptfwc_last_verified_webhook' ) );
			$this->assertSame( $caches, WP_Stub::$transients );
			$_POST[ $gateway->get_field_key( $key ) ] = 'mode' === $key ? 'live' : 'replacement';
			$this->assertTrue( $gateway->process_admin_options() );
			$this->assertFalse( get_option( 'mptfwc_last_verified_webhook' ) );
			$this->assertSame( array(), WP_Stub::$transients );
		}
	}

	public function test_api_check_success_is_cached_and_warns_about_mixed_credentials(): void {
		WP_Stub::$is_admin = true;
		$_GET['section'] = Settings::GATEWAY_ID;
		WP_Stub::$options[ 'woocommerce_' . Settings::GATEWAY_ID . '_settings' ] = array( 'access_token' => 'TEST-token', 'mode' => 'live' );
		WP_Stub::$transients['mptfwc_terminal_choices_live'] = array();
		WP_Stub::$http_responses[] = array( 'response' => array( 'code' => 200 ), 'body' => file_get_contents( __DIR__ . '/../fixtures/terminals-list.json' ) );
		$gateway = new Gateway();
		$html = $this->render( $gateway, 'admin_options' );
		$this->assertStringContainsString( 'OK: 2 terminal(s), 1 in PDV mode', $html );
		$this->assertStringContainsString( 'notice notice-warning inline', $html );
		$this->assertStringContainsString( 'Live mode is selected but the access token is a test token (TEST-…). Payments will go to the sandbox.', $html );
		$this->assertSame( $html, $this->render( new Gateway(), 'admin_options' ) );
		$this->assertCount( 1, WP_Stub::$http_requests );
		$this->assertSame( 8, WP_Stub::$http_requests[0]['args']['timeout'] );
		$this->assertSame( 60, WP_Stub::$transient_expirations['mptfwc_api_check_live'] );
	}

	public function test_api_check_errors_are_plain_escaped_redacted_and_cached(): void {
		WP_Stub::$is_admin = true;
		$_GET['section'] = Settings::GATEWAY_ID;
		$token = 'APP_USR-1234567890123456-100512-abcdefabcdef-123456789';
		WP_Stub::$options[ 'woocommerce_' . Settings::GATEWAY_ID . '_settings' ] = array( 'access_token' => $token );
		$messages = array(
			401 => 'Access token rejected by Mercado Pago (HTTP 401): &lt;denied&gt; ***. Copy a fresh access token from Your integrations → Credentials.',
			403 => 'Access token is not allowed to use Point (HTTP 403): &lt;denied&gt; ***.',
			503 => 'Could not reach Mercado Pago: &lt;denied&gt; ***',
			0 => 'Could not reach Mercado Pago: Mercado Pago API request failed.',
		);
		foreach ( $messages as $status => $message ) {
			WP_Stub::$transients = array( 'mptfwc_terminal_choices_test' => array() );
			WP_Stub::$http_requests = array();
			WP_Stub::$http_responses[] = $status ? array( 'response' => array( 'code' => $status ), 'body' => json_encode( array( 'message' => '<denied> ' . $token ) ) ) : new WP_Error( 'network', 'Network unavailable' );
			$gateway = new Gateway();
			$html = $this->render( $gateway, 'admin_options' );
			$this->assertStringContainsString( $message, $html );
			$this->assertStringNotContainsString( $token, $html );
			$this->assertStringNotContainsString( $token, json_encode( WP_Stub::$logs ) );
			$this->assertSame( $html, $this->render( $gateway, 'admin_options' ) );
			$this->assertCount( 1, WP_Stub::$http_requests );
			$this->assertSame( 60, WP_Stub::$transient_expirations['mptfwc_api_check_test'] );
		}
	}

	public function test_api_check_does_not_request_outside_the_settings_screen_or_without_token(): void {
		$gateway = new Gateway();
		$this->assertStringContainsString( 'Not checked: no access token', $this->render( $gateway, 'admin_options' ) );
		WP_Stub::$options[ 'woocommerce_' . Settings::GATEWAY_ID . '_settings' ] = array( 'access_token' => 'TEST-token' );
		foreach ( array( array( false, false, Settings::GATEWAY_ID ), array( true, true, Settings::GATEWAY_ID ), array( true, false, 'other' ) ) as $context ) {
			list( WP_Stub::$is_admin, WP_Stub::$doing_ajax, $_GET['section'] ) = $context;
			$this->render( new Gateway(), 'admin_options' );
		}
		$this->assertSame( array(), WP_Stub::$http_requests );
	}

	public function test_empty_terminals_help_and_webhook_health(): void {
		WP_Stub::$is_admin = true;
		$_GET['section'] = Settings::GATEWAY_ID;
		WP_Stub::$options[ 'woocommerce_' . Settings::GATEWAY_ID . '_settings' ] = array( 'access_token' => 'TEST-token', 'webhook_secret' => 'secret' );
		WP_Stub::$transients['mptfwc_terminal_choices_test'] = array();
		WP_Stub::$http_responses[] = array( 'response' => array( 'code' => 200 ), 'body' => '{"data":{"terminals":[]}}' );
		$gateway = new Gateway();
		$html = $this->render( $gateway, 'admin_options' );
		$this->assertStringContainsString( 'OK: 0 terminal(s), 0 in PDV mode', $html );
		$this->assertStringContainsString( 'No Point terminals are linked to this Mercado Pago account yet. On the terminal, log in with this account; it then appears here. Switch it to PDV mode and restart it before taking payments.', $html );
		$this->assertStringContainsString( 'Last verified webhook</th><td><code>never', $html );
		$this->assertStringContainsString( 'Mercado Pago has not sent a verified notification yet. Check the webhook URL and secret.', $html );
		$this->assertStringNotContainsString( 'notice notice-warning inline', $html );
		WP_Stub::$options['mptfwc_last_verified_webhook'] = 1700000000;
		$html = $this->render( $gateway, 'admin_options' );
		$this->assertStringContainsString( '2023-11-14 22:13:20 UTC', $html );
		$this->assertStringNotContainsString( 'has not sent a verified notification', $html );
	}

	public function test_storefront_redirects_unpaid_order_to_order_pay(): void {
		$GLOBALS['wp']->query_vars = array();
		$this->assertSame( array( 'result' => 'success', 'redirect' => '/checkout/order-pay/123/?key=key' ), ( new Gateway() )->process_payment( 123 ) );
		$this->assertSame( array(), WP_Stub::$notices );
		$this->assertSame( array(), WP_Stub::$http_requests );
		$this->assertFalse( $this->order->is_paid() );
	}

	public function test_pos_unpaid_order_polls_and_notices(): void {
		$GLOBALS['wp']->query_vars = array();
		woocommerce_pos_request( true );
		WP_Stub::$options[ 'woocommerce_' . Settings::GATEWAY_ID . '_settings' ] = array( 'access_token' => 'TEST-token' );
		$pending = PaymentAttempt::prepare( $this->order, 'T1', '24.00' );
		$remote = array( 'id' => 'ORD1', 'status' => 'created', 'external_reference' => $pending['external_reference'] );
		PaymentAttempt::record_created( $this->order, $pending, $remote );
		$remote['status'] = 'at_terminal';
		WP_Stub::$http_responses[] = array( 'response' => array( 'code' => 200 ), 'body' => json_encode( $remote ) );
		$this->assertSame( array( 'result' => 'failure' ), ( new Gateway() )->process_payment( 123 ) );
		$this->assertCount( 1, WP_Stub::$http_requests );
		$this->assertCount( 1, WP_Stub::$notices );
		$this->assertSame( 'notice', WP_Stub::$notices[0]['type'] );
		$this->assertFalse( $this->order->is_paid() );
	}

	public function test_order_pay_post_unpaid_order_polls_and_notices(): void {
		$GLOBALS['wp']->query_vars = array();
		$_POST['woocommerce_pay'] = '1';
		WP_Stub::$options[ 'woocommerce_' . Settings::GATEWAY_ID . '_settings' ] = array( 'access_token' => 'TEST-token' );
		$pending = PaymentAttempt::prepare( $this->order, 'T1', '24.00' );
		$remote = array( 'id' => 'ORD1', 'status' => 'created', 'external_reference' => $pending['external_reference'] );
		PaymentAttempt::record_created( $this->order, $pending, $remote );
		$remote['status'] = 'at_terminal';
		WP_Stub::$http_responses[] = array( 'response' => array( 'code' => 200 ), 'body' => json_encode( $remote ) );
		$this->assertSame( array( 'result' => 'failure' ), ( new Gateway() )->process_payment( 123 ) );
		$this->assertCount( 1, WP_Stub::$http_requests );
		$this->assertCount( 1, WP_Stub::$notices );
		$this->assertSame( 'notice', WP_Stub::$notices[0]['type'] );
		$this->assertFalse( $this->order->is_paid() );
	}

	public function test_order_pay_query_var_unpaid_order_polls_and_notices(): void {
		WP_Stub::$options[ 'woocommerce_' . Settings::GATEWAY_ID . '_settings' ] = array( 'access_token' => 'TEST-token' );
		$pending = PaymentAttempt::prepare( $this->order, 'T1', '24.00' );
		$remote = array( 'id' => 'ORD1', 'status' => 'created', 'external_reference' => $pending['external_reference'] );
		PaymentAttempt::record_created( $this->order, $pending, $remote );
		$remote['status'] = 'at_terminal';
		WP_Stub::$http_responses[] = array( 'response' => array( 'code' => 200 ), 'body' => json_encode( $remote ) );
		$this->assertSame( array( 'result' => 'failure' ), ( new Gateway() )->process_payment( 123 ) );
		$this->assertCount( 1, WP_Stub::$http_requests );
		$this->assertCount( 1, WP_Stub::$notices );
		$this->assertSame( 'notice', WP_Stub::$notices[0]['type'] );
		$this->assertFalse( $this->order->is_paid() );
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
