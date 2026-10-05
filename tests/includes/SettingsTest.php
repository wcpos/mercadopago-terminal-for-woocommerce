<?php
use PHPUnit\Framework\TestCase;
use WCPOS\WooCommercePOS\MercadoPagoTerminal\Settings;

if ( ! function_exists( 'wcpos_get_settings' ) ) {
	function wcpos_get_settings( $key ) {
		return SettingsTest::$pos_settings[ $key ] ?? array();
	}
}

class SettingsTest extends TestCase {
	public static $pos_settings = array();

	protected function setUp(): void {
		WP_Stub::reset();
		self::$pos_settings = array();
	}

	public function test_get_uses_supplied_options_and_default(): void {
		$settings = new Settings( array( 'title' => 'Counter' ) );
		$this->assertSame( 'Counter', $settings->get( 'title' ) );
		$this->assertSame( '', $settings->get( 'missing' ) );
		$this->assertSame( 'fallback', $settings->get( 'missing', 'fallback' ) );
	}

	public function test_get_reads_woocommerce_option_without_supplied_options(): void {
		WP_Stub::$options['woocommerce_mercadopago_terminal_for_woocommerce_settings'] = array( 'title' => 'Stored' );
		$this->assertSame( 'Stored', ( new Settings() )->get( 'title' ) );
	}

	public function test_access_token_is_trimmed(): void {
		$this->assertSame( 'TEST-token', ( new Settings( array( 'access_token' => ' TEST-token ' ) ) )->access_token() );
		$this->assertSame( '', ( new Settings( array() ) )->access_token() );
	}

	public function test_webhook_secret_is_trimmed(): void {
		$this->assertSame( 'secret', ( new Settings( array( 'webhook_secret' => ' secret ' ) ) )->webhook_secret() );
		$this->assertSame( '', ( new Settings( array() ) )->webhook_secret() );
	}

	public function test_mode_defaults_to_test(): void {
		$this->assertSame( 'test', ( new Settings( array() ) )->mode() );
		$this->assertSame( 'test', ( new Settings( array( 'mode' => 'invalid' ) ) )->mode() );
		$this->assertSame( 'test', ( new Settings( array( 'mode' => 'test' ) ) )->mode() );
		$this->assertSame( 'live', ( new Settings( array( 'mode' => 'live' ) ) )->mode() );
	}

	public function test_default_terminal_id_is_trimmed(): void {
		$this->assertSame( 'terminal-1', ( new Settings( array( 'default_terminal_id' => ' terminal-1 ' ) ) )->default_terminal_id() );
		$this->assertSame( '', ( new Settings( array() ) )->default_terminal_id() );
	}

	public function test_enabled_requires_yes(): void {
		$this->assertFalse( ( new Settings( array() ) )->enabled() );
		$this->assertFalse( ( new Settings( array( 'enabled' => 'no' ) ) )->enabled() );
		$this->assertTrue( ( new Settings( array( 'enabled' => 'yes' ) ) )->enabled() );
	}

	public function test_enabled_for_pos_reads_payment_gateways(): void {
		$settings = new Settings( array() );
		$this->assertFalse( $settings->enabled_for_pos() );
		self::$pos_settings['payment_gateways'] = array( 'gateways' => array( Settings::GATEWAY_ID => array( 'enabled' => true ) ) );
		$this->assertTrue( $settings->enabled_for_pos() );
		self::$pos_settings['payment_gateways']['gateways'][ Settings::GATEWAY_ID ]['enabled'] = false;
		$this->assertFalse( $settings->enabled_for_pos() );
	}

	public function test_active_when_enabled_online_or_in_pos(): void {
		$settings = new Settings( array( 'enabled' => 'no' ) );
		$this->assertFalse( $settings->active() );
		$this->assertTrue( ( new Settings( array( 'enabled' => 'yes' ) ) )->active() );
		self::$pos_settings['payment_gateways'] = array( 'gateways' => array( Settings::GATEWAY_ID => array( 'enabled' => true ) ) );
		$this->assertTrue( $settings->active() );
	}

	public function test_title_uses_trimmed_value_or_default(): void {
		$this->assertSame( 'Counter', ( new Settings( array( 'title' => ' Counter ' ) ) )->title() );
		$this->assertSame( 'Mercado Pago Terminal', ( new Settings( array( 'title' => ' ' ) ) )->title() );
		$this->assertSame( 'Mercado Pago Terminal', ( new Settings( array() ) )->title() );
	}

	public function test_show_logs_requires_yes(): void {
		$this->assertFalse( ( new Settings( array() ) )->show_logs() );
		$this->assertFalse( ( new Settings( array( 'show_logs' => 'no' ) ) )->show_logs() );
		$this->assertTrue( ( new Settings( array( 'show_logs' => 'yes' ) ) )->show_logs() );
	}

	public function test_enabled_terminal_ids_includes_default_only_when_restricted(): void {
		$options = array( 'default_terminal_id' => 'terminal-1' );
		$this->assertSame( array(), ( new Settings( array() ) )->enabled_terminal_ids() );
		$this->assertSame( array(), ( new Settings( $options ) )->enabled_terminal_ids() );
		$options['enabled_terminals'] = array( 'terminal-2', '' );
		$this->assertSame( array( 'terminal-2', 'terminal-1' ), ( new Settings( $options ) )->enabled_terminal_ids() );
		$options['enabled_terminals'] = array( 'terminal-1' );
		$this->assertSame( array( 'terminal-1' ), ( new Settings( $options ) )->enabled_terminal_ids() );
		$options['enabled_terminals'] = 'terminal-2';
		$this->assertSame( array( 'terminal-2', 'terminal-1' ), ( new Settings( $options ) )->enabled_terminal_ids() );
	}

	public function test_lock_terminal_requires_default_terminal(): void {
		$this->assertFalse( ( new Settings( array() ) )->lock_terminal() );
		$this->assertFalse( ( new Settings( array( 'lock_terminal' => 'yes' ) ) )->lock_terminal() );
		$this->assertFalse( ( new Settings( array( 'default_terminal_id' => 'terminal-1' ) ) )->lock_terminal() );
		$this->assertTrue( ( new Settings( array( 'lock_terminal' => 'yes', 'default_terminal_id' => 'terminal-1' ) ) )->lock_terminal() );
	}

	public function test_webhook_url_uses_admin_ajax_action(): void {
		$this->assertSame( 'https://shop.test/wp-admin/admin-ajax.php?action=mptfwc_webhook', ( new Settings( array() ) )->webhook_url() );
	}
}
