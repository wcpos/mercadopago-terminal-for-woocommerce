<?php
use PHPUnit\Framework\TestCase;
use WCPOS\WooCommercePOS\MercadoPagoTerminal\PaymentAttempt;
use WCPOS\WooCommercePOS\MercadoPagoTerminal\Settings;
use WCPOS\WooCommercePOS\MercadoPagoTerminal\SupportBundle;

class SupportBundleTest extends TestCase {
	private const TOKEN = 'APP_USR-1234567890123456-100512-abcdefabcdef-123456789';
	private const SECRET = 'whsec-super-secret-value-123';
	private static $created_log_dir = false;
	private $log_files = array();

	public static function setUpBeforeClass(): void {
		if ( ! defined( 'WC_LOG_DIR' ) ) {
			$dir = sys_get_temp_dir() . '/mptfwc-support-' . uniqid() . '/';
			mkdir( $dir );
			define( 'WC_LOG_DIR', $dir );
			self::$created_log_dir = true;
		}
	}

	public static function tearDownAfterClass(): void {
		if ( self::$created_log_dir ) { rmdir( WC_LOG_DIR ); }
	}

	protected function setUp(): void {
		WP_Stub::reset();
		$GLOBALS['wpdb'] = new MPTFWC_Fake_Wpdb();
		$GLOBALS['mptfwc_order_query_results'] = array();
	}

	protected function tearDown(): void {
		foreach ( $this->log_files as $file ) { unlink( $file ); }
		unset( $GLOBALS['wpdb'], $GLOBALS['mptfwc_order_query_results'] );
		WP_Stub::reset();
	}

	public function test_mask(): void {
		$this->assertSame( '(not set)', SupportBundle::mask( '' ) );
		foreach ( array(
			'APP_USR-1234567890123456-100512-abcdef-123456789' => 'APP_USR-…6789',
			'TEST-1234567890123456789' => 'TEST-…6789',
			'other-1234567890123456789' => 'secret-…6789',
			'abcdefghijkl' => 'secret-…ijkl',
			'short' => '***',
			'12345678901' => '***',
		) as $secret => $prefix ) {
			$secret = (string) $secret;
			$this->assertSame( $prefix . ' (' . strlen( $secret ) . ' chars)', SupportBundle::mask( $secret ) );
		}
	}

	public function test_build_has_all_sections_and_masks_settings_and_embedded_secrets(): void {
		WP_Stub::$options[ 'woocommerce_' . Settings::GATEWAY_ID . '_settings' ] = array(
			'webhook_secret' => self::SECRET,
			'title' => self::TOKEN . ' ' . self::SECRET . ' Bearer abc.def v1=' . str_repeat( 'a', 64 ) . ' 4111111111111111',
			'access_token' => self::TOKEN,
			'enabled_terminals' => array( 'T1', 'T2' ),
			'enabled' => 'yes',
		);
		$bundle = ( new SupportBundle( null, static function () { return array(); } ) )->build();
		foreach ( array( 'Environment', 'Settings', 'Terminals', 'Recent payment attempts', 'Recent log (source mercadopago-terminal)' ) as $title ) {
			$this->assertStringContainsString( "\n== " . $title . " ==\n", $bundle );
		}
		$this->assertStringContainsString( 'access_token: ' . SupportBundle::mask( self::TOKEN ), $bundle );
		$this->assertStringContainsString( 'webhook_secret: ' . SupportBundle::mask( self::SECRET ), $bundle );
		foreach ( array( self::TOKEN, self::SECRET, 'abc.def', str_repeat( 'a', 64 ), '4111111111111111' ) as $secret ) {
			$this->assertStringNotContainsString( $secret, $bundle );
		}
		$this->assertStringContainsString( "enabled: yes\nenabled_terminals: [\"T1\",\"T2\"]\ntitle:", $bundle );
		$this->assertStringContainsString( 'active: yes', $bundle );
		$this->assertStringContainsString( 'enabled_for_pos:', $bundle );
		$this->assertStringContainsString( 'log_level: debug', $bundle );
		$this->assertStringContainsString( 'webhook_url: https://shop.test/wp-admin/admin-ajax.php?action=mptfwc_webhook', $bundle );
		foreach ( array( 'Generated at:', 'Plugin:', 'WordPress:', 'WooCommerce:', 'PHP:', 'WCPOS:', 'Site URL: https://shop.test', 'Timezone: UTC', 'HPOS enabled:', 'json: yes', 'curl:', 'openssl:', 'mbstring:', 'WP_DEBUG:', 'Memory limit:', 'WooCommerce log handler:' ) as $field ) {
			$this->assertStringContainsString( $field, $bundle );
		}
		$this->assertStringContainsString( '(no log lines found)', $bundle );
	}

	public function test_terminal_rows_and_default_lister_timeout(): void {
		$settings = new Settings( array( 'access_token' => self::TOKEN ) );
		$row = array( 'id' => 'T1', 'label' => 'Counter', 'operating_mode' => 'PDV' );
		$lister = function ( Settings $received ) use ( $settings, $row ) {
			$this->assertSame( $settings, $received );
			return array( $row );
		};
		$this->assertStringContainsString( wp_json_encode( $row ), ( new SupportBundle( $settings, $lister ) )->build() );
		WP_Stub::$http_responses[] = array( 'response' => array( 'code' => 200 ), 'body' => file_get_contents( dirname( __DIR__ ) . '/fixtures/terminals-list.json' ) );
		$this->assertStringContainsString( 'NEWLAND_N950__SBX0000001', ( new SupportBundle( $settings ) )->build() );
		$this->assertCount( 1, WP_Stub::$http_requests );
		$this->assertSame( 5, WP_Stub::$http_requests[0]['args']['timeout'] );
	}

	public function test_terminal_failure_is_redacted_and_does_not_prevent_later_sections(): void {
		$settings = new Settings( array( 'access_token' => self::TOKEN, 'webhook_secret' => self::SECRET ) );
		$bundle = ( new SupportBundle( $settings, static function () {
			throw new RuntimeException( 'Cannot list ' . self::TOKEN . ' ' . self::SECRET );
		} ) )->build();
		$this->assertStringContainsString( 'error: Cannot list ', $bundle );
		$this->assertStringNotContainsString( self::TOKEN, $bundle );
		$this->assertStringNotContainsString( self::SECRET, $bundle );
		$this->assertStringContainsString( '== Recent payment attempts ==', $bundle );
		$this->assertStringContainsString( '== Recent log (source mercadopago-terminal) ==', $bundle );
	}

	public function test_empty_token_skips_terminal_lister(): void {
		$calls = 0;
		$bundle = ( new SupportBundle( new Settings( array() ), static function () use ( &$calls ) {
			++$calls;
			return array();
		} ) )->build();
		$this->assertSame( 0, $calls );
		$this->assertStringContainsString( '(skipped: no access token)', $bundle );
	}

	public function test_attempt_history_is_printed_with_order_summary(): void {
		$order = new MPTFWC_Test_Order( 321 );
		$order->payment_method = Settings::GATEWAY_ID;
		$order->transaction_id = 'MP123';
		$history = array(
			array( 'attempt_id' => 'A1', 'mp_order_id' => 'ORD1', 'status' => 'canceled' ),
			array( 'attempt_id' => 'A2', 'mp_order_id' => 'ORD2', 'status' => 'created' ),
		);
		$order->update_meta_data( PaymentAttempt::META_ATTEMPTS, $history );
		$GLOBALS['mptfwc_order_query_results'] = array( $order );
		$bundle = ( new SupportBundle() )->build();
		$this->assertStringContainsString( 'Order id: 321; status: pending; total: 24.00; currency: MXN; payment method: ' . Settings::GATEWAY_ID . '; transaction id: MP123', $bundle );
		$this->assertStringContainsString( wp_json_encode( $history[0] ) . "\n" . wp_json_encode( $history[1] ), $bundle );
	}

	public function test_file_logs_are_filtered_trimmed_chronological_and_redacted(): void {
		$old = WC_LOG_DIR . 'mercadopago-terminal-2026-10-04-old.log';
		$new = WC_LOG_DIR . 'mercadopago-terminal-2026-10-05-new.log';
		$other = WC_LOG_DIR . 'another-source-2026-10-05-other.log';
		$this->log_files = array( $old, $new, $other );
		$lines = array();
		for ( $i = 1; $i <= 600; ++$i ) { $lines[] = sprintf( 'log-line-%04d', $i ); }
		$lines[599] .= ' Bearer abc.def v1=' . str_repeat( 'a', 64 ) . ' 4111111111111111 ' . self::SECRET;
		file_put_contents( $old, implode( "\n", array_slice( $lines, 0, 300 ) ) . "\n" );
		file_put_contents( $new, implode( "\n", array_slice( $lines, 300 ) ) . "\n" );
		file_put_contents( $other, 'unrelated-log-line' );
		touch( $old, 100 );
		touch( $new, 200 );
		$bundle = ( new SupportBundle( new Settings( array( 'webhook_secret' => self::SECRET ) ) ) )->build();
		$log = explode( "== Recent log (source mercadopago-terminal) ==\n", $bundle, 2 )[1];
		$actual = explode( "\n", trim( $log ) );
		$this->assertCount( 500, $actual );
		$this->assertSame( array_slice( $lines, 100, 499 ), array_slice( $actual, 0, 499 ) );
		$this->assertSame( 'log-line-0600 Bearer *** v1=*** ****1111 ***', $actual[499] );
		$this->assertStringNotContainsString( 'unrelated-log-line', $bundle );
		$this->assertStringNotContainsString( self::SECRET, $bundle );
		$this->assertNull( $GLOBALS['wpdb']->log_query );
	}

	public function test_only_newest_three_log_files_are_used_in_mtime_order(): void {
		foreach ( array( 'd' => 1, 'c' => 2, 'b' => 3, 'a' => 4 ) as $name => $mtime ) {
			$file = WC_LOG_DIR . 'mercadopago-terminal-2026-10-05-' . $name . '.log';
			$this->log_files[] = $file;
			file_put_contents( $file, 'file-' . $name . "\n" );
			touch( $file, $mtime );
		}
		$bundle = ( new SupportBundle() )->build();
		$this->assertStringContainsString( "file-c\nfile-b\nfile-a\n", $bundle );
		$this->assertStringNotContainsString( 'file-d', $bundle );
	}

	public function test_database_logs_are_chronological_and_redacted_when_no_files_exist(): void {
		$GLOBALS['wpdb']->log_rows = array(
			(object) array( 'timestamp' => '2026-10-05 12:01:00', 'level' => 400, 'message' => 'Bearer abc.def 4111111111111111' ),
			(object) array( 'timestamp' => '2026-10-05 12:00:00', 'level' => 200, 'message' => 'Started' ),
		);
		$bundle = ( new SupportBundle() )->build();
		$this->assertStringContainsString( "2026-10-05 12:00:00 200 Started\n2026-10-05 12:01:00 400 Bearer *** ****1111", $bundle );
		$this->assertSame( "SELECT timestamp, level, message FROM wp_woocommerce_log WHERE source = 'mercadopago-terminal' ORDER BY log_id DESC LIMIT 500", $GLOBALS['wpdb']->log_query );
	}

	public function test_section_errors_do_not_escape_build(): void {
		$GLOBALS['mptfwc_order_query_results'] = array( new class extends MPTFWC_Test_Order {
			public function get_status() { throw new Error( 'Order failed Bearer abc.def' ); }
		} );
		$GLOBALS['wpdb'] = new class extends MPTFWC_Fake_Wpdb {
			public function get_results( $query ) { throw new Error( 'Log failed v1=' . str_repeat( 'b', 64 ) ); }
		};
		$bundle = ( new SupportBundle() )->build();
		$this->assertStringContainsString( 'error: Order failed Bearer ***', $bundle );
		$this->assertStringContainsString( 'error: Log failed v1=***', $bundle );
		$this->assertStringContainsString( '(no log lines found)', $bundle );
		$this->assertStringNotContainsString( 'abc.def', $bundle );
		$this->assertStringNotContainsString( str_repeat( 'b', 64 ), $bundle );
	}

	public function test_handle_refuses_missing_capability_and_invalid_nonce_without_output(): void {
		foreach ( array( array( false, true ), array( true, false ) ) as $permissions ) {
			WP_Stub::$caps['manage_woocommerce'] = $permissions[0];
			WP_Stub::$nonce_ok = $permissions[1];
			ob_start();
			try {
				( new SupportBundle() )->handle();
				$this->fail( 'Expected wp_die.' );
			} catch ( RuntimeException $e ) {
				$this->assertSame( 'You are not allowed to download the support bundle.', $e->getMessage() );
				$this->assertSame( '', ob_get_contents() );
			} finally {
				ob_end_clean();
			}
		}
	}
}
