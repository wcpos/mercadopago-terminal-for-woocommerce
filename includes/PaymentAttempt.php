<?php
namespace WCPOS\WooCommercePOS\MercadoPagoTerminal;

class PaymentAttempt {
	public const META_CURRENT_ATTEMPT_ID = '_mptfwc_current_attempt_id';
	public const META_CURRENT_MP_ORDER_ID = '_mptfwc_current_mp_order_id';
	public const META_CURRENT_TERMINAL_ID = '_mptfwc_current_terminal_id';
	public const META_CURRENT_EXTERNAL_REFERENCE = '_mptfwc_current_external_reference';
	public const META_CURRENT_STATUS = '_mptfwc_current_status';
	public const META_CURRENT_CREATED_AT = '_mptfwc_current_created_at';
	public const META_ATTEMPTS = '_mptfwc_attempts';
	public const META_PENDING_CREATE = '_mptfwc_pending_create';
	public const META_MP_PAYMENT_ID = '_mptfwc_mp_payment_id';
	public const PENDING_REUSE_SECONDS = 3600;

	public static function current( $order ): ?array {
		$mp_order_id = $order->get_meta( self::META_CURRENT_MP_ORDER_ID );
		if ( ! $mp_order_id ) { return null; }
		return array(
			'attempt_id' => (string) $order->get_meta( self::META_CURRENT_ATTEMPT_ID ),
			'mp_order_id' => (string) $mp_order_id,
			'terminal_id' => (string) $order->get_meta( self::META_CURRENT_TERMINAL_ID ),
			'external_reference' => (string) $order->get_meta( self::META_CURRENT_EXTERNAL_REFERENCE ),
			'status' => (string) $order->get_meta( self::META_CURRENT_STATUS ),
			'created_at' => (string) $order->get_meta( self::META_CURRENT_CREATED_AT ),
		);
	}

	public static function prepare( $order, string $terminal_id, string $amount ): array {
		$pending = $order->get_meta( self::META_PENDING_CREATE );
		if ( is_array( $pending ) && ( $pending['terminal_id'] ?? '' ) === $terminal_id && ( $pending['amount'] ?? '' ) === $amount && time() - strtotime( $pending['created_at'] ?? '' ) < self::PENDING_REUSE_SECONDS ) {
			return $pending;
		}
		$attempt_id = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'mptfwc_', true );
		$pending = array(
			'attempt_id' => $attempt_id,
			'idempotency_key' => function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'mptfwc_', true ),
			'external_reference' => 'wcpos-' . $order->get_id() . '-' . substr( preg_replace( '/[^0-9a-f]/', '', strtolower( $attempt_id ) ), -12 ),
			'terminal_id' => $terminal_id,
			'amount' => $amount,
			'created_at' => gmdate( 'c' ),
		);
		$order->update_meta_data( self::META_PENDING_CREATE, $pending );
		$history = self::history( $order );
		$history[] = array(
			'attempt_id' => $pending['attempt_id'],
			'mp_order_id' => '',
			'external_reference' => $pending['external_reference'],
			'terminal_id' => $terminal_id,
			'amount' => $amount,
			'status' => 'creating',
			'created_at' => $pending['created_at'],
			'updated_at' => $pending['created_at'],
		);
		$order->update_meta_data( self::META_ATTEMPTS, $history );
		$order->save();
		return $pending;
	}

	public static function record_created( $order, array $pending, array $mp_order ): void {
		$status = self::status( $mp_order );
		$order->update_meta_data( self::META_CURRENT_ATTEMPT_ID, $pending['attempt_id'] );
		$order->update_meta_data( self::META_CURRENT_MP_ORDER_ID, $mp_order['id'] );
		$order->update_meta_data( self::META_CURRENT_TERMINAL_ID, $pending['terminal_id'] );
		$order->update_meta_data( self::META_CURRENT_EXTERNAL_REFERENCE, $pending['external_reference'] );
		$order->update_meta_data( self::META_CURRENT_STATUS, $status );
		$order->update_meta_data( self::META_CURRENT_CREATED_AT, $pending['created_at'] );
		$history = self::history( $order );
		foreach ( $history as &$attempt ) {
			if ( $attempt['attempt_id'] === $pending['attempt_id'] ) {
				$attempt['mp_order_id'] = $mp_order['id'];
				$attempt['status'] = $status;
				$attempt['updated_at'] = gmdate( 'c' );
			}
		}
		$order->update_meta_data( self::META_ATTEMPTS, $history );
		$order->delete_meta_data( self::META_PENDING_CREATE );
		$order->save();
	}

	public static function discard_pending( $order, array $pending ): void {
		$order->delete_meta_data( self::META_PENDING_CREATE );
		$history = self::history( $order );
		foreach ( $history as &$attempt ) {
			if ( $attempt['attempt_id'] === $pending['attempt_id'] ) {
				$attempt['status'] = 'rejected';
				$attempt['updated_at'] = gmdate( 'c' );
			}
		}
		$order->update_meta_data( self::META_ATTEMPTS, $history );
		$order->save();
	}

	public static function update_status( $order, array $mp_order ): void {
		$status = self::status( $mp_order );
		if ( (string) $order->get_meta( self::META_CURRENT_MP_ORDER_ID ) === $mp_order['id'] ) {
			$order->update_meta_data( self::META_CURRENT_STATUS, $status );
		}
		$history = self::history( $order );
		foreach ( $history as &$attempt ) {
			if ( $attempt['mp_order_id'] === $mp_order['id'] || ( '' === $attempt['mp_order_id'] && $attempt['external_reference'] === ( $mp_order['external_reference'] ?? '' ) ) ) {
				$attempt['mp_order_id'] = $mp_order['id'];
				$attempt['status'] = $status;
				$attempt['updated_at'] = gmdate( 'c' );
			}
		}
		$order->update_meta_data( self::META_ATTEMPTS, $history );
		$order->save();
	}

	public static function find( $order, array $mp_order ): ?array {
		$history = self::history( $order );
		foreach ( $history as $attempt ) {
			if ( $attempt['mp_order_id'] === $mp_order['id'] ) { return $attempt; }
		}
		$reference = (string) ( $mp_order['external_reference'] ?? '' );
		foreach ( $history as $attempt ) {
			if ( '' !== $reference && $attempt['external_reference'] === $reference ) { return $attempt; }
		}
		return null;
	}

	public static function history( $order ): array {
		$history = $order->get_meta( self::META_ATTEMPTS );
		return is_array( $history ) ? $history : array();
	}

	/**
	 * Make this gateway the order's payment method.
	 *
	 * A Mercado Pago Point order is created and completed over AJAX or the webhook,
	 * never through the WooCommerce pay form that normally stamps the chosen gateway
	 * onto the order. Without this the order keeps whatever method it had (none,
	 * or the POS default such as cash) and everything keyed on the order's
	 * payment method reads the wrong gateway: the WooCommerce POS per-gateway
	 * order status, refund routing, and the "Payment via" label.
	 *
	 * Called only once Mercado Pago confirms the payment, never when an attempt starts:
	 * an unfinished attempt must not leave Mercado Pago on an order that is then paid
	 * another way. Does not save; the caller saves the order right after.
	 */
	public static function claim_order_gateway( $order, string $title ): void {
		if ( Settings::GATEWAY_ID === (string) $order->get_payment_method() && '' !== (string) $order->get_payment_method_title() ) { return; }
		$order->set_payment_method( Settings::GATEWAY_ID );
		$order->set_payment_method_title( $title );
	}

	public static function order_id_from_external_reference( string $reference ): int {
		return preg_match( '/^wcpos-([0-9]+)-[0-9a-f]+$/D', $reference, $matches ) ? (int) $matches[1] : 0;
	}

	public static function status( array $mp_order ): string { return (string) ( $mp_order['status'] ?? 'unknown' ); }
	public static function is_paid_status( string $s ): bool { return 'processed' === $s; }
	public static function is_final_unpaid( string $s ): bool { return in_array( $s, array( 'canceled', 'failed', 'expired' ), true ); }
	public static function is_final( string $s ): bool { return self::is_paid_status( $s ) || self::is_final_unpaid( $s ) || 'refunded' === $s; }
	public static function is_non_final( string $s ): bool { return in_array( $s, array( 'created', 'at_terminal', 'action_required', 'creating', '' ), true ); }
}
