<?php
namespace WCPOS\WooCommercePOS\MercadoPagoTerminal;

use WCPOS\WooCommercePOS\MercadoPagoTerminal\Utils\Money;

class PaymentReconciler {
	// Covers payment_complete() (status, stock, emails); a dead request's claim
	// can be taken over after this interval.
	private const COMPLETE_LOCK_TTL = 120;

	private $settings;
	public function __construct( ?Settings $settings = null ) { $this->settings = $settings ?: new Settings(); }

	public function reconcile( $order, array $mp_order, string $source ): array {
		if ( 'processed' !== PaymentAttempt::status( $mp_order ) ) {
			return $this->apply( $order, $mp_order, $source );
		}
		$order_id = (int) $order->get_id();
		if ( ! PaymentLock::acquire( $order_id, 'complete_payment', self::COMPLETE_LOCK_TTL ) ) {
			Logger::log( 'Mercado Pago Terminal payment completion already in progress for this order.', array( 'order_id' => $order_id, 'mp_order_id' => $mp_order['id'], 'source' => $source ), 'info' );
			// Another request is completing the order; report paid only if verified.
			$verification = $this->verify( $order, $mp_order );
			if ( $verification['valid'] ) {
				return array( 'status' => 'paid', 'completing' => true );
			}
			Logger::log( 'Mercado Pago payment verification failed', array( 'order_id' => $order_id, 'mp_order_id' => $mp_order['id'], 'source' => $source, 'errors' => $verification['errors'] ), 'warning' );
			return array( 'status' => 'pending', 'retry_allowed' => false );
		}
		try {
			// Reload under the claim in case another request already completed it.
			$fresh = self::reload_order( $order );
			return $this->apply( $fresh, $mp_order, $source );
		} finally {
			PaymentLock::release( $order_id, 'complete_payment' );
		}
	}

	/**
	 * Callers that keep working on an order after reconcile() use this to reload it.
	 * Clears the post cache, the HPOS order cache, the HPOS datastore cache and the HPOS meta cache, then force-reads meta.
	 * The meta cache is cleared directly because the datastore skips it when the row-cache delete fails (#25).
	 */
	public static function reload_order( $order ) {
		$id = $order->get_id();
		if ( function_exists( 'clean_post_cache' ) ) { clean_post_cache( $id ); }
		if ( function_exists( 'wc_get_container' ) && class_exists( \Automattic\WooCommerce\Caches\OrderCache::class ) ) {
			wc_get_container()->get( \Automattic\WooCommerce\Caches\OrderCache::class )->remove( $id );
		}
		if ( function_exists( 'wc_get_container' ) && class_exists( \Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore::class ) ) {
			$data_store = wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore::class );
			if ( method_exists( $data_store, 'clear_cached_data' ) ) {
				$data_store->clear_cached_data( array( $id ) );
			}
		}
		if ( function_exists( 'wc_get_container' ) && class_exists( \Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStoreMeta::class ) ) {
			$meta_store = wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStoreMeta::class );
			if ( method_exists( $meta_store, 'clear_cached_data' ) ) {
				$meta_store->clear_cached_data( array( $id ) );
			}
		}
		$fresh = function_exists( 'wc_get_order' ) ? wc_get_order( $id ) : false;
		if ( is_object( $fresh ) && method_exists( $fresh, 'read_meta_data' ) ) {
			$fresh->read_meta_data( true );
		}
		return is_object( $fresh ) ? $fresh : $order;
	}

	private function verify( $order, array $mp_order ): array {
		$errors = array();
		if ( PaymentAttempt::order_id_from_external_reference( $mp_order['external_reference'] ?? '' ) !== (int) $order->get_id() ) { $errors[] = 'external reference does not match this order'; }
		$attempt = PaymentAttempt::find( $order, $mp_order );
		if ( null === $attempt ) { $errors[] = 'order is not known for this order'; }
		if ( isset( $mp_order['type'] ) && 'point' !== $mp_order['type'] ) { $errors[] = 'order type is not point'; }
		if ( isset( $mp_order['transactions']['payments'][0]['amount'] ) && ! Money::equals( $mp_order['transactions']['payments'][0]['amount'], $order->get_total() ) ) { $errors[] = 'amount mismatch'; }
		if ( 'processed' === PaymentAttempt::status( $mp_order ) ) {
			if ( ! isset( $mp_order['transactions']['payments'][0]['amount'] ) || '' === $mp_order['transactions']['payments'][0]['amount'] ) { $errors[] = 'payment amount missing'; }
			if ( ! isset( $mp_order['transactions']['payments'][0]['id'] ) || '' === $mp_order['transactions']['payments'][0]['id'] ) { $errors[] = 'payment id missing'; }
		}
		if ( isset( $mp_order['config']['point']['terminal_id'] ) && ! empty( $attempt['terminal_id'] ) && $mp_order['config']['point']['terminal_id'] !== $attempt['terminal_id'] ) { $errors[] = 'terminal mismatch'; }
		return array( 'valid' => empty( $errors ), 'errors' => $errors );
	}

	private function apply( $order, array $mp_order, string $source ): array {
		$previous_status = PaymentAttempt::find( $order, $mp_order )['status'] ?? '';
		PaymentAttempt::update_status( $order, $mp_order );
		$status = PaymentAttempt::status( $mp_order );
		$verification = $this->verify( $order, $mp_order );
		if ( ! $verification['valid'] ) {
			Logger::log( 'Mercado Pago payment verification failed', array( 'order_id' => $order->get_id(), 'mp_order_id' => $mp_order['id'], 'source' => $source, 'errors' => $verification['errors'] ), 'warning' );
			$order->add_order_note( sprintf( 'Mercado Pago Point verification failed via %s: %s', $source, implode( '; ', $verification['errors'] ) ) );
			$order->save();
			return array( 'status' => 'verification_failed', 'payment_status' => $status, 'errors' => $verification['errors'] );
		}
		if ( 'processed' === $status ) {
			return $this->complete_paid_order( $order, $mp_order, $source );
		}
		if ( PaymentAttempt::is_final_unpaid( $status ) ) {
			$status_detail = (string) ( $mp_order['status_detail'] ?? '' );
			if ( $previous_status !== $status ) { $order->add_order_note( sprintf( 'Mercado Pago Point order %s via %s (%s).', $status, $source, $status_detail ) ); }
			$order->save();
			return array( 'status' => $status, 'status_detail' => $status_detail, 'retry_allowed' => true );
		}
		if ( 'refunded' === $status ) {
			if ( $previous_status !== $status ) { $order->add_order_note( sprintf( 'Mercado Pago Point order refunded via %s.', $source ) ); }
			$order->save();
			return array( 'status' => 'refunded', 'retry_allowed' => false );
		}
		return array( 'status' => in_array( $status, array( 'created', 'at_terminal', 'action_required' ), true ) ? $status : 'unknown', 'retry_allowed' => false );
	}

	private function complete_paid_order( $order, array $mp_order, string $source ): array {
		$mp_order_id = $mp_order['id'];
		if ( $order->is_paid() ) {
			if ( $order->get_transaction_id() === $mp_order_id ) {
				Logger::log( 'Mercado Pago payment already completed', array( 'order_id' => $order->get_id(), 'mp_order_id' => $mp_order_id, 'source' => $source ), 'debug' );
				return array( 'status' => 'paid', 'idempotent' => true );
			}
			Logger::log( 'Mercado Pago payment conflict: order already paid by another transaction', array( 'order_id' => $order->get_id(), 'mp_order_id' => $mp_order_id, 'source' => $source ), 'warning' );
			$order->add_order_note( 'Mercado Pago Point order processed but the WooCommerce order was already paid by another transaction.' );
			$order->save();
			return array( 'status' => 'conflict' );
		}
		PaymentAttempt::claim_order_gateway( $order, $this->settings->title() );
		$payment_id = $mp_order['transactions']['payments'][0]['id'];
		$order->update_meta_data( PaymentAttempt::META_MP_PAYMENT_ID, $payment_id );
		$order->set_transaction_id( $mp_order_id );
		$order->payment_complete( $mp_order_id );
		$order->add_order_note( sprintf( 'Mercado Pago Point payment completed via %s (order %s, payment %s).', $source, $mp_order_id, $payment_id ) );
		$order->save();
		Logger::log( 'Mercado Pago payment completed', array( 'order_id' => $order->get_id(), 'mp_order_id' => $mp_order_id, 'payment_id' => $payment_id, 'source' => $source ), 'success' );
		return array( 'status' => 'paid' );
	}
}
