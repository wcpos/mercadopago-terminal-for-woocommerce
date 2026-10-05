<?php
use PHPUnit\Framework\TestCase;
use WCPOS\WooCommercePOS\MercadoPagoTerminal\Gateway;
use WCPOS\WooCommercePOS\MercadoPagoTerminal\Settings;

class OptionKeysTest extends TestCase {
	protected function tearDown(): void {
		$_GET = array();
		WP_Stub::reset();
	}

	public function test_settings_keys_exist_as_gateway_fields(): void {
		WP_Stub::reset();
		WP_Stub::$is_admin = true;
		$_GET = array( 'section' => Settings::GATEWAY_ID );
		WP_Stub::$options[ 'woocommerce_' . Settings::GATEWAY_ID . '_settings' ] = array( 'access_token' => 'TEST-token' );
		WP_Stub::$transients['mptfwc_terminal_choices_test'] = array( 'T1' => 'Terminal' );
		$gateway = new Gateway();
		$keys = array();
		$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( dirname( __DIR__, 2 ) . '/includes', FilesystemIterator::SKIP_DOTS ) );
		foreach ( $files as $file ) {
			if ( 'php' !== $file->getExtension() ) { continue; }
			preg_match_all( '/->get\(\s*\x27([^\x27]+)\x27/', file_get_contents( $file->getPathname() ), $matches );
			$keys = array_merge( $keys, $matches[1] );
		}
		$this->assertNotEmpty( $keys );
		foreach ( array_unique( $keys ) as $key ) {
			$this->assertArrayHasKey( $key, $gateway->form_fields, 'Missing form field for Settings key: ' . $key );
		}
	}

	public function test_source_has_no_sibling_plugin_names_or_prefix_typos(): void {
		foreach ( array( 'includes', 'assets' ) as $directory ) {
			$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( dirname( __DIR__, 2 ) . '/' . $directory, FilesystemIterator::SKIP_DOTS ) );
			foreach ( $files as $file ) {
				$this->assertDoesNotMatchRegularExpression( '/mtfwc|Mollie|mollie/', file_get_contents( $file->getPathname() ), $file->getPathname() );
			}
		}
	}
}
