<?php
use PHPUnit\Framework\TestCase;
use WCPOS\WooCommercePOS\MercadoPagoTerminal\Logger;
use WCPOS\WooCommercePOS\MercadoPagoTerminal\PaymentAttempt;
use WCPOS\WooCommercePOS\MercadoPagoTerminal\PaymentSweeper;
use WCPOS\WooCommercePOS\MercadoPagoTerminal\Settings;

class PaymentSweeperTest extends TestCase {
	private $client;
	private $sweeper;

	protected function setUp(): void {
		WP_Stub::reset();
		Logger::$logger = null;
		Logger::configure( 'debug' );
		WP_Stub::$options[ 'woocommerce_' . Settings::GATEWAY_ID . '_settings' ] = array( 'access_token' => 'TEST-sweep' );
		$GLOBALS['wpdb'] = new MPTFWC_Fake_Wpdb();
		$GLOBALS['mptfwc_orders'] = array();
		$GLOBALS['mptfwc_order_query_results'] = array();
		$this->client = new FakeMercadoPagoClient();
		$this->sweeper = new PaymentSweeper( function ( string $token ) {
			$this->assertSame( 'TEST-sweep', $token );
			return $this->client;
		} );
	}

	protected function tearDown(): void {
		$this->assertNotContains( 'cancel_order', array_column( $this->client->calls, 'method' ) );
		unset( $GLOBALS['mptfwc_orders'], $GLOBALS['mptfwc_order_query_results'], $GLOBALS['wpdb'] );
		WP_Stub::reset();
	}

	private function attempt( int $id = 123, int $age = 300, string $status = 'at_terminal' ): MPTFWC_Test_Order {
		$order = new MPTFWC_Test_Order( $id );
		$pending = PaymentAttempt::prepare( $order, 'NEWLAND_N950__SBX0000001', '24.00' );
		$pending['created_at'] = gmdate( 'c', time() - $age );
		$remote = json_decode( file_get_contents( dirname( __DIR__ ) . '/fixtures/order-processed.json' ), true );
		$remote['id'] .= '-' . $id;
		$remote['external_reference'] = $pending['external_reference'];
		PaymentAttempt::record_created( $order, $pending, array_replace( $remote, array( 'status' => $status ) ) );
		$this->client->returns['get_order'][] = $remote;
		$GLOBALS['mptfwc_orders'][ $id ] = $order;
		return $order;
	}

	public function test_sweep_recovers_payment_without_webhook_or_browser_and_deduplicates_queries(): void {
		$order = $this->attempt();
		$GLOBALS['mptfwc_order_query_results'] = array( $order );
		$before = time();
		$this->assertSame( array( 'scanned' => 1, 'reconciled' => 1, 'results' => array( 123 => 'paid' ) ), $this->sweeper->sweep() );
		$this->assertSame( 1, $order->payment_complete_calls );
		$this->assertStringContainsString( 'via sweep', implode( ' ', $order->notes ) );
		$this->assertSame( array( 'get_order' ), array_column( $this->client->calls, 'method' ) );
		$queries = $GLOBALS['mptfwc_wc_get_orders_args'];
		$this->assertCount( 2, $queries );
		foreach ( $queries as $args ) {
			$this->assertSame( 'shop_order', $args['type'] );
			$this->assertSame( PaymentAttempt::META_CURRENT_MP_ORDER_ID, $args['meta_key'] );
			$this->assertSame( 'EXISTS', $args['meta_compare'] );
			$this->assertArrayNotHasKey( 'meta_query', $args );
			$this->assertSame( 25, $args['limit'] );
			$this->assertSame( 'date', $args['orderby'] );
		}
		$this->assertSame( array( 'pending', 'failed', 'on-hold' ), $queries[0]['status'] );
		$this->assertSame( 'ASC', $queries[0]['order'] );
		$this->assertArrayNotHasKey( 'date_created', $queries[0] );
		$this->assertSame( 'any', $queries[1]['status'] );
		$this->assertSame( 'DESC', $queries[1]['order'] );
		$this->assertSame( '>', substr( $queries[1]['date_created'], 0, 1 ) );
		$this->assertGreaterThanOrEqual( $before - DAY_IN_SECONDS, (int) substr( $queries[1]['date_created'], 1 ) );
		$this->assertLessThanOrEqual( time() - DAY_IN_SECONDS, (int) substr( $queries[1]['date_created'], 1 ) );
		$this->assertStringContainsString( 'Reconciliation sweep finished', end( WP_Stub::$logs )['message'] );
		$this->assertStringContainsString( '"results":{"123":"paid"}', end( WP_Stub::$logs )['message'] );
	}

	public function test_young_final_and_missing_attempts_are_skipped(): void {
		$this->assertNull( $this->sweeper->sweep_order( $this->attempt( 123, 30 ) ) );
		foreach ( array( 'processed', 'canceled', 'failed', 'expired', 'refunded' ) as $status ) {
			$this->assertNull( $this->sweeper->sweep_order( $this->attempt( 123, 300, $status ) ) );
		}
		$this->assertNull( $this->sweeper->sweep_order( new MPTFWC_Test_Order() ) );
		$this->assertSame( array(), $this->client->calls );
	}

	public function test_recent_cash_paid_order_is_found_and_reports_conflict_without_completion(): void {
		$order = $this->attempt();
		$order->status = 'completed';
		$order->paid = true;
		$order->set_payment_method( 'cash' );
		$order->set_transaction_id( 'cash-1' );
		$GLOBALS['mptfwc_wc_get_orders_callback'] = function ( $args ) use ( $order ) { return 'any' === $args['status'] ? array( $order ) : array(); };
		$this->assertSame( array( 'scanned' => 1, 'reconciled' => 1, 'results' => array( 123 => 'conflict' ) ), $this->sweeper->sweep() );
		$this->assertSame( 0, $order->payment_complete_calls );
		$this->assertSame( 'cash-1', $order->get_transaction_id() );
		$this->assertSame( 'cash', $order->get_payment_method() );
		$this->assertStringContainsString( 'already paid by another transaction', implode( ' ', $order->notes ) );
	}

	public function test_error_is_logged_and_next_order_is_reconciled(): void {
		$GLOBALS['mptfwc_order_query_results'] = array( $this->attempt(), $this->attempt( 124 ) );
		$this->client->returns['get_order'][0] = new TypeError( 'Failed fetch' );
		$this->assertSame( array( 'scanned' => 2, 'reconciled' => 1, 'results' => array( 123 => 'error', 124 => 'paid' ) ), $this->sweeper->sweep() );
		$this->assertCount( 2, $this->client->calls );
		$errors = array_values( array_filter( WP_Stub::$logs, function ( $log ) { return 'error' === $log['level']; } ) );
		$this->assertCount( 1, $errors );
		$this->assertStringContainsString( 'Failed fetch', $errors[0]['message'] );
		$this->assertStringContainsString( '"order_id":123', $errors[0]['message'] );
		$this->assertStringContainsString( '"mp_order_id":"' . PaymentAttempt::current( $GLOBALS['mptfwc_orders'][123] )['mp_order_id'] . '"', $errors[0]['message'] );
	}

	public function test_no_token_skips_queries_and_api(): void {
		WP_Stub::$options = array();
		$this->assertSame( array( 'scanned' => 0, 'reconciled' => 0, 'results' => array() ), $this->sweeper->sweep() );
		$this->assertEmpty( $GLOBALS['mptfwc_wc_get_orders_args'] ?? array() );
		$this->assertSame( array(), $this->client->calls );
		$this->assertSame( 'debug', end( WP_Stub::$logs )['level'] );
		$this->assertStringContainsString( 'Sweep skipped: no access token', end( WP_Stub::$logs )['message'] );
	}

	public function test_schedule_registration_is_idempotent_and_can_be_cleared(): void {
		$this->assertSame( 300, apply_filters( 'cron_schedules', array() )[ PaymentSweeper::SCHEDULE ]['interval'] );
		$this->assertContains( array( 'hook' => PaymentSweeper::CRON_HOOK, 'callback' => array( $this->sweeper, 'sweep' ), 'priority' => 10 ), WP_Stub::$actions );
		do_action( 'init' );
		$scheduled = WP_Stub::$cron;
		$this->assertCount( 1, $scheduled[ PaymentSweeper::CRON_HOOK ] );
		$this->assertSame( PaymentSweeper::SCHEDULE, reset( $scheduled[ PaymentSweeper::CRON_HOOK ] ) );
		$this->sweeper->ensure_scheduled();
		$this->assertSame( $scheduled, WP_Stub::$cron );
		PaymentSweeper::unschedule();
		$this->assertFalse( wp_next_scheduled( PaymentSweeper::CRON_HOOK ) );
		$this->assertSame( 120, PaymentSweeper::stale_seconds() );
		add_filter( 'mptfwc_stale_payment_seconds', function () { return 1; } );
		$this->assertSame( 30, PaymentSweeper::stale_seconds() );
	}
}
