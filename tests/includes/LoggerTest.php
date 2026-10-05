<?php
use PHPUnit\Framework\TestCase;
use WCPOS\WooCommercePOS\MercadoPagoTerminal\Logger;

class LoggerTest extends TestCase {
	protected function setUp(): void {
		WP_Stub::reset();
		Logger::reset_request();
		Logger::configure( 'debug' );
		Logger::$logger = null;
		Logger::$log_level = null;
	}

	public function test_redact_masks_bearer_and_mercado_pago_tokens(): void {
		$this->assertSame( 'Bearer ***', Logger::redact( 'Bearer abc.def' ) );
		$this->assertSame( 'APP_USR-***', Logger::redact( 'APP_USR-1234567890123456-100512-abcdefabcdefabcdef-123456789' ) );
		$this->assertSame( 'TEST-***', Logger::redact( 'TEST-1234567890123456-100512-abcdefabcdefabcdef-123456789' ) );
	}

	public function test_log_truncates_messages_at_1000_characters(): void {
		foreach ( array( 1000, 1001 ) as $length ) {
			Logger::log( str_repeat( 'a', $length ) );
			$this->assertSame( '[' . Logger::request_id() . '] ' . str_repeat( 'a', 1000 ) . ( $length > 1000 ? '…' : '' ), end( WP_Stub::$logs )['message'] );
		}
	}

	public function test_log_redacts_context_and_maps_success_to_info(): void {
		Logger::log( 'Paid Bearer abc.def', array( 'access_token' => 'private', 'webhook_secret' => 'private', 'nested' => array( 'refreshToken' => 'private', 'note' => 'Bearer abc.def' ), 'terminal' => 'terminal-1' ), 'success' );
		$entries = array_values( array_filter( WP_Stub::$logs, function ( $entry ) { return false !== strpos( $entry['message'], 'Paid Bearer' ); } ) );
		$this->assertCount( 1, $entries );
		$entry = $entries[0];
		$this->assertSame( 'info', $entry['level'] );
		$this->assertSame( array( 'source' => 'mercadopago-terminal' ), $entry['context'] );
		list( $message, $context ) = explode( ' {', $entry['message'], 2 );
		$this->assertSame( '[' . Logger::request_id() . '] Paid Bearer ***', $message );
		$this->assertSame( array( 'access_token' => '***', 'webhook_secret' => '***', 'nested' => array( 'refreshToken' => '***', 'note' => 'Bearer ***' ), 'terminal' => 'terminal-1' ), json_decode( '{' . $context, true ) );
	}

	public function test_log_writes_nothing_when_filter_disables_logging(): void {
		add_filter( 'mptfwc_logging', function ( $enabled, $message ) {
			$this->assertTrue( $enabled );
			$this->assertSame( 'Hidden', $message );
			return false;
		} );
		Logger::log( 'Hidden' );
		$this->assertSame( array(), WP_Stub::$logs );
	}
}
