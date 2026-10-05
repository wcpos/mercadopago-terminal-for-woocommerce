<?php
use PHPUnit\Framework\TestCase;

class BootstrapTest extends TestCase {
	protected function setUp(): void {
		WP_Stub::reset();
	}

	public function test_constants_define_version_and_minimum_php(): void {
		$this->assertTrue( defined( 'MPTFWC_VERSION' ) );
		$this->assertSame( '0.0.1', MPTFWC_VERSION );
		$this->assertTrue( defined( 'MPTFWC_MINIMUM_PHP_VERSION_ID' ) );
		$this->assertSame( 70400, MPTFWC_MINIMUM_PHP_VERSION_ID );
	}

	public function test_bootstrap_registers_init_and_plugins_loaded_hooks(): void {
		$this->assertContains( array( 'hook' => 'init', 'callback' => 'WCPOS\\WooCommercePOS\\MercadoPagoTerminal\\load_textdomain', 'priority' => 10 ), WP_Stub::$boot_actions );
		$this->assertContains( array( 'hook' => 'plugins_loaded', 'callback' => 'WCPOS\\WooCommercePOS\\MercadoPagoTerminal\\init', 'priority' => 11 ), WP_Stub::$boot_actions );
	}

	public function test_init_dispatches_plugin_action(): void {
		$called = false;
		add_action( 'mptfwc_init', function () use ( &$called ) { $called = true; } );
		\WCPOS\WooCommercePOS\MercadoPagoTerminal\init();
		$this->assertTrue( $called );
	}
}
