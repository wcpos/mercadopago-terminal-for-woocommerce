<?php
namespace WCPOS\WooCommercePOS\MercadoPagoTerminal;

use WCPOS\WooCommercePOS\Payments\Contract\Order_Lock;

class Legacy_Adoption {
	public const META_CURRENT_MP_ORDER_ID = '_mptfwc_current_mp_order_id';
	public const META_CURRENT_STATUS = '_mptfwc_current_status';
	public static function upgrade(): void {
		if ( version_compare( get_option( 'mptfwc_version', '0' ), '1.0.0', '>=' ) ) { return; }
		wp_clear_scheduled_hook( 'mptfwc_reconcile_stale_payments' );
		$count = 0;
		$offset = (int) get_option( 'mptfwc_adoption_offset', 0 );
		$orders = wc_get_orders( array( 'type' => 'shop_order', 'limit' => 25, 'offset' => $offset, 'orderby' => 'ID', 'order' => 'ASC', 'meta_key' => self::META_CURRENT_MP_ORDER_ID, 'meta_compare' => 'EXISTS' ) );
		foreach ( $orders as $order ) {
			$ref = (string) $order->get_meta( self::META_CURRENT_MP_ORDER_ID );
			if ( '' === $ref || ! in_array( $order->get_meta( self::META_CURRENT_STATUS ), array( 'created', 'at_terminal', 'action_required', 'creating', '' ), true ) || wcpos_pro_payment_id_for_action( 'mercadopago', $ref ) ) { continue; }
			$result = Order_Lock::instance()->with_lock( $order->get_id(), static function () use ( $order, $ref ) {
				if ( wcpos_pro_payment_id_for_action( 'mercadopago', $ref ) ) { return true; }
				$order = wc_get_order( $order->get_id() );
				return wcpos_pro_adopt_legacy_attempt( $order, Settings::GATEWAY_ID, $ref, (string) $order->get_total(), $order->get_currency() );
			} );
			if ( is_wp_error( $result ) ) {
				wc_get_logger()->error( 'Legacy Mercado Pago adoption failed for order ' . $order->get_id() . ': ' . $result->get_error_code(), array( 'source' => 'mercadopago-terminal' ) );
				continue;
			}
			++$count;
		}
		wc_get_logger()->info( 'Adopted legacy Mercado Pago attempts: ' . $count, array( 'source' => 'mercadopago-terminal' ) );
		update_option( 'mptfwc_adoption_offset', $offset + count( $orders ), false );
		if ( count( $orders ) < 25 ) {
			delete_option( 'mptfwc_adoption_offset' );
			update_option( 'mptfwc_version', '1.0.0', false );
		}
	}
}
