<?php
namespace WCPOS\WooCommercePOS\MercadoPagoTerminal;

use WCPOS\WooCommercePOS\MercadoPagoTerminal\Services\MercadoPagoClient;

class PaymentSweeper {
	public const CRON_HOOK = 'mptfwc_reconcile_stale_payments';
	public const SCHEDULE  = 'mptfwc_five_minutes';
	private $client_factory;

	public function __construct( ?callable $client_factory = null ) {
		$this->client_factory = $client_factory ?: function ( string $token ): MercadoPagoClient { return new MercadoPagoClient( $token, 8 ); };
		if ( ! function_exists( 'add_action' ) ) { return; }
		add_filter( 'cron_schedules', array( $this, 'add_schedule' ) );
		add_action( self::CRON_HOOK, array( $this, 'sweep' ) );
		add_action( 'init', array( $this, 'ensure_scheduled' ) );
	}

	public function add_schedule( $schedules ) {
		$schedules[ self::SCHEDULE ] = array( 'interval' => 5 * MINUTE_IN_SECONDS, 'display' => __( 'Every 5 minutes (Mercado Pago reconciliation)', 'mercadopago-terminal-for-woocommerce' ) );
		return $schedules;
	}

	public function ensure_scheduled(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, self::SCHEDULE, self::CRON_HOOK );
		}
	}

	public static function unschedule(): void {
		while ( $timestamp = wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}

	public static function stale_seconds(): int {
		return max( 30, (int) apply_filters( 'mptfwc_stale_payment_seconds', 120 ) );
	}

	public function sweep(): array {
		$result = array( 'scanned' => 0, 'reconciled' => 0, 'results' => array() );
		if ( '' === ( new Settings() )->access_token() ) {
			Logger::log( 'Sweep skipped: no access token', array(), 'debug' );
			return $result;
		}
		$args = array(
			'type' => 'shop_order', 'limit' => (int) apply_filters( 'mptfwc_sweep_batch', 25 ),
			'status' => array( 'pending', 'failed', 'on-hold' ), 'orderby' => 'date', 'order' => 'ASC',
			'meta_key' => PaymentAttempt::META_CURRENT_MP_ORDER_ID, 'meta_compare' => 'EXISTS',
		);
		$orders = array();
		foreach ( wc_get_orders( $args ) as $order ) { $orders[ $order->get_id() ] = $order; }
		$args['status'] = 'any';
		$args['order'] = 'DESC';
		$args['date_created'] = '>' . ( time() - DAY_IN_SECONDS );
		foreach ( wc_get_orders( $args ) as $order ) { $orders[ $order->get_id() ] = $order; }
		$result['scanned'] = count( $orders );
		foreach ( $orders as $id => $order ) {
			$status = $this->sweep_order( $order );
			if ( null === $status ) { continue; }
			$result['results'][ $id ] = $status;
			if ( 'error' !== $status ) { $result['reconciled']++; }
		}
		if ( $result['scanned'] > 0 ) { Logger::log( 'Reconciliation sweep finished', $result, 'info' ); }
		return $result;
	}

	public function sweep_order( $order ): ?string {
		$current = PaymentAttempt::current( $order );
		if ( ! $current || ! PaymentAttempt::is_non_final( $current['status'] ) || time() - strtotime( $current['created_at'] ) < self::stale_seconds() ) { return null; }
		try {
			$settings = new Settings();
			$mp_order = ( $this->client_factory )( $settings->access_token() )->get_order( $current['mp_order_id'] );
			$result = ( new PaymentReconciler( $settings ) )->reconcile( $order, $mp_order, 'sweep' );
			return $result['status'];
		} catch ( \Throwable $e ) {
			Logger::log( 'Reconciliation sweep failed: ' . $e->getMessage(), array( 'order_id' => $order->get_id(), 'mp_order_id' => $current['mp_order_id'] ), 'error' );
			return 'error';
		}
	}
}
