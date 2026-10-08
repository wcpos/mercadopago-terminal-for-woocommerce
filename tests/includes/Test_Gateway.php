<?php
namespace WCPOS\WooCommercePOS\MercadoPagoTerminal\Tests\Includes;
use WCPOS\WooCommercePOS\MercadoPagoTerminal\Gateway;
use WCPOS\WooCommercePOS\MercadoPagoTerminal\Settings;
use WCPOS\WooCommercePOS\MercadoPagoTerminal\Tests\Conformance\Mercado_Pago_Conformance_Fixture as Transport;
require_once __DIR__ . '/Conformance/Mercado_Pago_Conformance_Fixture.php';
class Test_Gateway extends \WP_UnitTestCase {
	private $transport;
	public function setUp(): void { parent::setUp(); $this->transport = new Transport(); $this->transport->install(); }
	public function tearDown(): void { $this->transport->uninstall(); parent::tearDown(); }
	public function test_fields_availability_and_masked_secrets(): void {
		$gateway = new Gateway();
		$this->assertSame( array( 'enabled', 'title', 'description', 'mode', 'access_token', 'webhook_secret' ), array_keys( $gateway->form_fields ) );
		$this->assertStringContainsString( 'wcpos/v2/payments/webhook', rawurldecode( $gateway->form_fields['webhook_secret']['description'] ) );
		$this->assertTrue( $gateway->is_available() );
		$gateway->enabled = 'no'; $this->assertFalse( $gateway->is_available() ); $gateway->enabled = 'yes';
		$this->assertStringNotContainsString( 'TEST-conformance', $gateway->generate_mptfwc_secret_html( 'access_token', $gateway->form_fields['access_token'] ) );
		$options = get_option( 'woocommerce_' . Settings::GATEWAY_ID . '_settings' ); $options['access_token'] = ''; update_option( 'woocommerce_' . Settings::GATEWAY_ID . '_settings', $options );
		$this->assertFalse( $gateway->is_available() );
	}
	public function test_order_pay_uses_pro_panel_only_for_eligible_user(): void {
		global $wp;
		$gateway = new Gateway(); $order = wc_create_order(); $order->set_total( '24.00' ); $order->save();
		$enable = static function ( $settings ) { $settings['gateways'][Settings::GATEWAY_ID]['enabled'] = true; return $settings; };
		add_filter( 'woocommerce_pos_payment_gateways_settings', $enable );
		add_filter( 'woocommerce_is_checkout', '__return_true' );
		$wp->query_vars['order-pay'] = $order->get_id();
		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		get_user_by( 'id', $user )->add_cap( 'access_woocommerce_pos' ); wp_set_current_user( $user );
		ob_start(); $gateway->payment_fields(); $html = ob_get_clean();
		$this->assertStringContainsString( 'wcpos-pro-order-pay', $html );
		wp_set_current_user( 0 ); ob_start(); $gateway->payment_fields(); $html = ob_get_clean();
		$this->assertStringNotContainsString( 'wcpos-pro-order-pay', $html );
		remove_filter( 'woocommerce_is_checkout', '__return_true' );
		remove_filter( 'woocommerce_pos_payment_gateways_settings', $enable ); unset( $wp->query_vars['order-pay'] );
	}
	public function test_admin_pdv_link_and_no_private_support_bundle(): void {
		ob_start(); ( new Gateway() )->admin_options(); $html = ob_get_clean();
		$this->assertStringContainsString( 'mptfwc_set_pdv', $html ); $this->assertStringContainsString( '_wpnonce', $html );
		$this->assertStringContainsString( 'Switch to PDV', $html ); $this->assertStringNotContainsString( 'mptfwc_support', $html );
	}
	public function test_pdv_requires_capability(): void {
		wp_set_current_user( 0 );
		$this->expectException( \WPDieException::class );
		Gateway::set_pdv();
	}
	public function test_webhook_url_with_plain_permalinks(): void {
		update_option( 'permalink_structure', '' );
		$url = ( new Settings() )->webhook_url();
		$this->assertSame( 1, substr_count( $url, '?' ) );
		$this->assertStringContainsString( 'provider=mercadopago', $url );
	}
}
