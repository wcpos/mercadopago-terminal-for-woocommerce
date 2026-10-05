<?php
use PHPUnit\Framework\TestCase;
use WCPOS\WooCommercePOS\MercadoPagoTerminal\Logger;
use WCPOS\WooCommercePOS\MercadoPagoTerminal\Services\MercadoPagoApiException;
use WCPOS\WooCommercePOS\MercadoPagoTerminal\Services\TerminalService;
use WCPOS\WooCommercePOS\MercadoPagoTerminal\Settings;

class TerminalServiceTest extends TestCase {
	private const TERMINAL_ID = 'NEWLAND_N950__SBX0000001';
	private $client;
	private $service;
	private $fixture;

	protected function setUp(): void {
		WP_Stub::reset();
		Logger::$logger = null;
		Logger::$log_level = null;
		$GLOBALS['wpdb'] = new MPTFWC_Fake_Wpdb();
		$GLOBALS['mptfwc_orders'] = array( 123 => new MPTFWC_Test_Order( 123 ) );
		$this->client = new FakeMercadoPagoClient();
		$this->service = new TerminalService( $this->client, new Settings( array() ) );
		$this->fixture = json_decode( file_get_contents( dirname( __DIR__ ) . '/fixtures/terminals-list.json' ), true );
	}

	public function test_normalize_fixture(): void {
		$this->assertSame( array(
			array( 'id' => self::TERMINAL_ID, 'label' => 'SUC0101POS', 'operating_mode' => 'PDV', 'store_id' => '12354567', 'pos_id' => '23545678' ),
			array( 'id' => 'NEWLAND_N950__N950NCB801293324', 'label' => 'SUC0101POS2', 'operating_mode' => 'STANDALONE', 'store_id' => '12354567', 'pos_id' => '23545679' ),
		), TerminalService::normalize( $this->fixture ) );
	}

	public function test_normalize_skips_malformed_input(): void {
		foreach ( array(
			array(),
			array( 'data' => null ),
			array( 'data' => 'bad' ),
			array( 'data' => array( 'terminals' => 'bad' ) ),
			array( 'data' => array( 'terminals' => array( null, 'bad', array(), array( 'id' => '' ), array( 'id' => array( 'bad' ) ), array( 'id' => 'bad', 'operating_mode' => array() ) ) ) ),
		) as $response ) {
			$this->assertSame( array(), TerminalService::normalize( $response ) );
		}
	}

	public function test_normalize_keeps_valid_rows_and_uses_id_as_missing_label(): void {
		$response = array( 'data' => array( 'terminals' => array( null, array( 'id' => 'VALID', 'external_pos_id' => '' ), array( 'id' => '' ) ) ) );
		$this->assertSame( array( array( 'id' => 'VALID', 'label' => 'VALID', 'operating_mode' => '', 'store_id' => '', 'pos_id' => '' ) ), TerminalService::normalize( $response ) );
	}

	public function test_list_requests_one_page_with_timeout(): void {
		$this->client->returns['list_terminals'] = array( $this->fixture );
		$this->assertSame( TerminalService::normalize( $this->fixture ), $this->service->list_terminals( 7 ) );
		$this->assertSame( array( array( 'method' => 'list_terminals', 'args' => array( 50, 0, '', '', 7 ) ) ), $this->client->calls );
	}

	public function test_find_terminal_returns_normalized_row_or_null(): void {
		$this->client->returns['list_terminals'] = array( $this->fixture );
		$this->assertSame( TerminalService::normalize( $this->fixture )[0], $this->service->find_terminal( self::TERMINAL_ID ) );
		$this->assertNull( $this->service->find_terminal( 'UNLISTED' ) );
		$this->assertCount( 1, $this->client->calls );
	}

	public function test_pdv_mode_passes_case_insensitively(): void {
		$lowercase = $this->fixture;
		$lowercase['data']['terminals'][0]['operating_mode'] = 'pdv';
		$this->client->returns['list_terminals'] = array( $this->fixture, $lowercase );
		$this->service->assert_can_receive_orders( self::TERMINAL_ID );
		delete_transient( 'mptfwc_terminal_rows' );
		$this->service->assert_can_receive_orders( self::TERMINAL_ID );
		$this->assertSame( array(), WP_Stub::$logs );
		$this->assertCount( 2, $this->client->calls );
	}

	public function test_standalone_mode_message_names_terminal_and_mode(): void {
		$this->client->returns['list_terminals'] = array( $this->fixture );
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Terminal NEWLAND_N950__N950NCB801293324 is in STANDALONE mode. Switch it to PDV (integrated) mode in the gateway settings, then restart the terminal.' );
		$this->service->assert_can_receive_orders( 'NEWLAND_N950__N950NCB801293324' );
	}

	public function test_unlisted_terminal_passes_with_warning(): void {
		$this->client->returns['list_terminals'] = array( $this->fixture );
		$this->service->assert_can_receive_orders( 'UNLISTED' );
		$this->assertSame( 'warning', end( WP_Stub::$logs )['level'] );
		$this->assertStringContainsString( 'UNLISTED', end( WP_Stub::$logs )['message'] );
	}

	public function test_list_failure_passes_with_warning(): void {
		$this->client->returns['list_terminals'] = array( new MercadoPagoApiException( 'List failed.', 500 ) );
		$this->service->assert_can_receive_orders( self::TERMINAL_ID );
		$this->assertSame( 'warning', end( WP_Stub::$logs )['level'] );
		$this->assertStringContainsString( 'List failed.', end( WP_Stub::$logs )['message'] );
		$this->assertCount( 1, $this->client->calls );
	}

	public function test_empty_terminal_throws_without_api_call(): void {
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Select a terminal first.' );
		try {
			$this->service->assert_can_receive_orders( '' );
		} finally {
			$this->assertSame( array(), $this->client->calls );
		}
	}

	public function test_set_pdv_mode_calls_client_logs_and_returns_response(): void {
		WP_Stub::$transients['mptfwc_terminal_rows'] = array( 'cached' );
		$response = array( 'terminals' => array( array( 'id' => self::TERMINAL_ID, 'operating_mode' => 'PDV' ) ) );
		$this->client->returns['set_operating_mode'] = array( $response );
		$this->assertSame( $response, $this->service->set_pdv_mode( self::TERMINAL_ID ) );
		$this->assertFalse( get_transient( 'mptfwc_terminal_rows' ) );
		$this->assertSame( array( array( 'method' => 'set_operating_mode', 'args' => array( self::TERMINAL_ID, 'PDV' ) ) ), $this->client->calls );
		$this->assertCount( 1, array_filter( WP_Stub::$logs, function ( $entry ) { return false !== strpos( $entry['message'], self::TERMINAL_ID ); } ) );
		$this->assertStringContainsString( self::TERMINAL_ID, end( WP_Stub::$logs )['message'] );
	}

	public function test_cached_terminals_reuses_rows_for_start_and_refreshes_after_expiry(): void {
		$this->client->returns['list_terminals'] = array( $this->fixture, $this->fixture );
		$rows = $this->service->cached_terminals( 90, 6 );
		$this->assertSame( TerminalService::normalize( $this->fixture ), $rows );
		$this->assertSame( $rows, get_option( 'mptfwc_terminal_rows_last_good' ) );
		$this->assertSame( 90, WP_Stub::$transient_expirations['mptfwc_terminal_rows'] );
		$this->assertSame( $rows, $this->service->cached_terminals() );
		$this->service->assert_can_receive_orders( self::TERMINAL_ID );
		$this->assertSame( array( array( 'method' => 'list_terminals', 'args' => array( 50, 0, '', '', 6 ) ) ), $this->client->calls );
		delete_transient( 'mptfwc_terminal_rows' );
		$this->assertSame( $rows, $this->service->cached_terminals() );
		$this->assertCount( 2, $this->client->calls );
		$this->assertSame( 300, WP_Stub::$transient_expirations['mptfwc_terminal_rows'] );
		$this->assertSame( array( 50, 0, '', '', 8 ), $this->client->calls[1]['args'] );
	}

	public function test_cache_failure_falls_back_to_last_good_rows(): void {
		$this->client->returns['list_terminals'] = array( $this->fixture, new MercadoPagoApiException( 'Unavailable', 503 ) );
		$rows = $this->service->cached_terminals();
		delete_transient( 'mptfwc_terminal_rows' );
		$this->assertSame( $rows, $this->service->cached_terminals() );
		$this->assertCount( 2, $this->client->calls );
		$this->assertFalse( get_transient( 'mptfwc_terminal_rows' ) );
	}

	public function test_cache_failure_without_last_good_rethrows(): void {
		$this->client->returns['list_terminals'] = array( new MercadoPagoApiException( 'Unavailable', 503 ) );
		$this->expectException( MercadoPagoApiException::class );
		$this->expectExceptionMessage( 'Unavailable' );
		$this->service->cached_terminals();
	}

	public function test_empty_lists_are_not_cached_and_live_lists_bypass_cache(): void {
		$this->client->returns['list_terminals'] = array( array(), $this->fixture, array() );
		$this->assertSame( array(), $this->service->cached_terminals() );
		$this->assertFalse( get_transient( 'mptfwc_terminal_rows' ) );
		$this->assertFalse( get_option( 'mptfwc_terminal_rows_last_good' ) );
		$this->assertNotEmpty( $this->service->cached_terminals() );
		$this->assertSame( array(), $this->service->list_terminals() );
		$this->assertCount( 3, $this->client->calls );
	}

	public function test_fake_client_rejects_unexpected_calls(): void {
		$this->expectException( LogicException::class );
		$this->expectExceptionMessage( 'unexpected call: get_order' );
		$this->client->get_order( 'UNEXPECTED' );
	}
}
