<?php
namespace WCPOS\WooCommercePOS\MercadoPagoTerminal;

use WCPOS\WooCommercePOS\MercadoPagoTerminal\Services\MercadoPagoApiException;
use WCPOS\WooCommercePOS\MercadoPagoTerminal\Services\MercadoPagoClient;
use WCPOS\WooCommercePOS\MercadoPagoTerminal\Utils\Money;

class RefundHandler {
	public const META_MP_REFUND_ID = '_mptfwc_mp_refund_id';
	/** Refund objects WooCommerce created in this request, keyed by order id. */
	private static $created = array();
	private $client;
	public function __construct( MercadoPagoClient $client ) { $this->client = $client; }

	/** `woocommerce_create_refund` callback. */
	public static function remember_refund( $refund, $args = array() ): void {
		// Only a refund that asks WooCommerce to reverse the payment reaches the gateway; a manual refund created earlier in the same request must not be mistaken for it.
		if ( empty( $args['refund_payment'] ) ) { return; }
		if ( is_object( $refund ) && method_exists( $refund, 'get_parent_id' ) ) { self::$created[ (int) $refund->get_parent_id() ] = $refund; }
	}

	public function process_refund( $order, $amount, string $reason = '' ) {
		try {
			$mp_order_id = $order->get_transaction_id();
			if ( '' === $mp_order_id || 0 !== strpos( $mp_order_id, 'ORD' ) ) {
				return new \WP_Error( 'mptfwc_refund_unavailable', __( 'This order has no Mercado Pago Point payment to refund.', 'mercadopago-terminal-for-woocommerce' ) );
			}
			$refund = self::$created[ (int) $order->get_id() ] ?? null;
			unset( self::$created[ (int) $order->get_id() ] );
			// The remembered record must still match this amount and not yet be linked to a Mercado Pago refund.
			if ( null !== $refund && ( wc_format_decimal( $refund->get_amount(), 2 ) !== wc_format_decimal( $amount, 2 ) || $refund->get_meta( self::META_MP_REFUND_ID ) ) ) { $refund = null; }
			if ( null === $refund ) {
				// WooCommerce returns refunds newest first; use the first unlinked amount match when no hook bound the record.
				foreach ( $order->get_refunds() as $candidate ) { if ( wc_format_decimal( $candidate->get_amount(), 2 ) === wc_format_decimal( $amount, 2 ) && ! $candidate->get_meta( self::META_MP_REFUND_ID ) ) { $refund = $candidate; break; } }
			}
			if ( null === $refund ) { return new \WP_Error( 'mptfwc_refund_not_found', __( 'No matching WooCommerce refund found.', 'mercadopago-terminal-for-woocommerce' ) ); }

			try {
				$cents = Money::to_cents( $amount );
			} catch ( \InvalidArgumentException $e ) {
				return new \WP_Error( 'mptfwc_refund_invalid_amount', __( 'Refund amount must be greater than zero.', 'mercadopago-terminal-for-woocommerce' ) );
			}
			if ( $cents <= 0 ) { return new \WP_Error( 'mptfwc_refund_invalid_amount', __( 'Refund amount must be greater than zero.', 'mercadopago-terminal-for-woocommerce' ) ); }
			$refunded = 0;
			foreach ( $order->get_refunds() as $candidate ) {
				if ( $candidate->get_meta( self::META_MP_REFUND_ID ) ) { $refunded += Money::to_cents( $candidate->get_amount() ); }
			}
			$total = Money::to_cents( $order->get_total() );
			if ( $refunded + $cents > $total ) { return new \WP_Error( 'mptfwc_refund_too_large', __( 'This refund would exceed the amount paid through Mercado Pago.', 'mercadopago-terminal-for-woocommerce' ) ); }
			$payload = null;
			if ( 0 !== $refunded || $cents !== $total ) {
				$payment_id = $order->get_meta( PaymentAttempt::META_MP_PAYMENT_ID );
				if ( ! $payment_id ) { return new \WP_Error( 'mptfwc_refund_unavailable', __( 'This order has no Mercado Pago payment id, so a partial refund cannot be sent. Refund the full amount, or refund on the Point terminal.', 'mercadopago-terminal-for-woocommerce' ) ); }
				$payload = array( 'amount' => Money::to_amount( $amount ), 'transaction_id' => $payment_id );
			}
			Logger::log( 'Mercado Pago refund requested', array( 'order_id' => $order->get_id(), 'mp_order_id' => $mp_order_id, 'refund_id' => $refund->get_id(), 'amount' => Money::to_amount( $amount ), 'full' => null === $payload, 'idempotency_key' => 'refund-' . $refund->get_id() ), 'info' );
			try {
				$response = $this->client->refund_order( $mp_order_id, 'refund-' . $refund->get_id(), $payload );
			} catch ( MercadoPagoApiException $e ) {
				Logger::log( 'Mercado Pago refund failed: ' . $e->getMessage(), array( 'http_status' => $e->http_status(), 'error_code' => $e->error_code() ), 'error' );
				return new \WP_Error( 'mptfwc_refund_failed', sprintf( __( 'Mercado Pago refused the refund: %s. Some card acquirers only allow refunds on the terminal; if so, refund it on the Point terminal and record the refund in WooCommerce without "Refund via Mercado Pago Terminal".', 'mercadopago-terminal-for-woocommerce' ), $e->getMessage() ) );
			}
			$refunds = $response['transactions']['refunds'] ?? array();
			$refund_id = end( $refunds )['id'] ?? '';
			$refund->update_meta_data( self::META_MP_REFUND_ID, $refund_id ?: 'unknown' );
			$refund->save();
			$note = sprintf( 'Mercado Pago Point refund of %s processed (refund %s, order status %s).', Money::to_amount( $amount ), $refund_id, $response['status'] ?? '' );
			if ( '' !== $reason ) { $note .= ' ' . $reason; }
			$order->add_order_note( $note );
			Logger::log( 'Mercado Pago refund succeeded', array( 'mp_refund_id' => $refund_id, 'status' => $response['status'] ?? '' ), 'success' );
			return true;
		} catch ( \Exception $e ) { Logger::log( 'Mercado Pago refund failed: ' . $e->getMessage(), array( 'http_status' => 0, 'error_code' => '' ), 'error' ); return new \WP_Error( 'mptfwc_refund_failed', $e->getMessage() ); }
	}
}
