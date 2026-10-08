<?php
namespace WCPOS\WooCommercePOS\MercadoPagoTerminal\Tests\Includes;
use WCPOS\WooCommercePOS\MercadoPagoTerminal\Legacy_Adoption;
use WCPOS\WooCommercePOS\MercadoPagoTerminal\Settings;
use WCPOS\WooCommercePOS\Payments\Contract\Ledger;
use WCPOS\WooCommercePOS\MercadoPagoTerminal\Tests\Conformance\Mercado_Pago_Conformance_Fixture as Transport;
require_once __DIR__ . '/Conformance/Mercado_Pago_Conformance_Fixture.php';
class Test_Legacy_Adoption extends \WP_UnitTestCase {
	public static function order_stores(): array { return array( array( false ), array( true ) ); }
	/** @dataProvider order_stores */
	public function test_only_nonfinal_attempts_adopted_once_and_cron_cleared( bool $hpos ): void {
		update_option( 'woocommerce_custom_orders_table_enabled', $hpos ? 'yes' : 'no' );
		$this->assertSame( $hpos, \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() );
		$transport = new Transport(); $transport->install();
		try {
			$orders = array();
			foreach ( array( 'created', 'processed', '' ) as $index => $status ) {
				$order = wc_create_order(); $order->set_total( '24.00' ); $order->set_currency( 'EUR' );
				$order->update_meta_data( Legacy_Adoption::META_CURRENT_MP_ORDER_ID, 'ORDlegacy' . $index );
				$order->update_meta_data( Legacy_Adoption::META_CURRENT_STATUS, $status ); $order->save(); $orders[] = $order;
			}
			update_option( 'mptfwc_version', '0.1.0' );
			wp_schedule_single_event( time() + 60, 'mptfwc_reconcile_stale_payments' );
			Legacy_Adoption::upgrade();
			$this->assertSame( '1.0.0', get_option( 'mptfwc_version' ) );
			$this->assertFalse( wp_next_scheduled( 'mptfwc_reconcile_stale_payments' ) );
			$first = array();
			foreach ( $orders as $index => $order ) {
				$fresh = wc_get_order( $order->get_id() ); $rows = Ledger::instance()->read( $fresh ); $first[] = $rows;
				$this->assertCount( 1 === $index ? 0 : 1, $rows );
				$this->assertSame( 'ORDlegacy' . $index, $fresh->get_meta( Legacy_Adoption::META_CURRENT_MP_ORDER_ID ) );
				if ( $rows ) {
					$this->assertSame( 'pending', $rows[0]['status'] ); $this->assertSame( '24.00', $rows[0]['amount'] );
					$this->assertSame( 'EUR', $rows[0]['currency'] ); $this->assertSame( 'webview', $rows[0]['source'] );
				}
			}
			Legacy_Adoption::upgrade();
			// Even a repeated upgrade after a version-option loss must not duplicate the charge.
			delete_option( 'mptfwc_version' ); Legacy_Adoption::upgrade();
			foreach ( $orders as $index => $order ) { $this->assertSame( $first[ $index ], Ledger::instance()->read( wc_get_order( $order->get_id() ) ) ); }
			$this->assertSame( array(), $transport->transcript() );
		} finally { $transport->uninstall(); }
	}
}
