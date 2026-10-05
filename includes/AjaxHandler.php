<?php
namespace WCPOS\WooCommercePOS\MercadoPagoTerminal;

use WCPOS\WooCommercePOS\MercadoPagoTerminal\Services\MercadoPagoClient;
use WCPOS\WooCommercePOS\MercadoPagoTerminal\Services\PointPaymentService;
use WCPOS\WooCommercePOS\MercadoPagoTerminal\Services\TerminalService;

class AjaxHandler {
	private $service_factory;

	public function __construct( ?callable $service_factory = null ) {
		$this->service_factory = $service_factory ?: function ( Settings $settings ): array {
			$client = new MercadoPagoClient( $settings->access_token() );
			return array( 'payments' => new PointPaymentService( $client, $settings ), 'terminals' => new TerminalService( $client, $settings ) );
		};
		if ( ! function_exists( 'add_action' ) || ! wp_doing_ajax() ) { return; }
		foreach ( array( 'mptfwc_start_payment', 'mptfwc_poll_payment', 'mptfwc_cancel_payment', 'mptfwc_list_terminals' ) as $action ) {
			add_action( 'wp_ajax_' . $action, array( $this, $action ) );
			add_action( 'wp_ajax_nopriv_' . $action, array( $this, $action ) );
		}
		add_action( 'wp_ajax_mptfwc_set_pdv_mode', array( $this, 'mptfwc_set_pdv_mode' ) );
	}

	public function mptfwc_start_payment(): void {
		$this->with_order( 'start_payment', function ( $order ) {
			$terminal_id = sanitize_text_field( wp_unslash( $_POST['terminal_id'] ?? '' ) );
			$settings    = $this->settings();
			if ( $settings->lock_terminal() ) {
				// Terminal selection is locked: always use the configured default,
				// regardless of what the client submitted.
				$terminal_id = $settings->default_terminal_id();
			}
			return $this->payment_service()->start_payment_for_order( $order, $terminal_id );
		} );
	}
	public function mptfwc_poll_payment(): void { $this->with_order( 'poll_payment', function ( $order ) { return $this->payment_service()->poll_order( $order ); } ); }
	public function mptfwc_cancel_payment(): void { $this->with_order( 'cancel_payment', function ( $order ) { return $this->payment_service()->cancel_order_payment( $order ); } ); }

	public function mptfwc_list_terminals(): void {
		$order_id = absint( $_POST['order_id'] ?? 0 );
		if ( ! $order_id || ! $this->can_access_order( $order_id ) ) {
			Logger::log( 'AJAX request rejected', array( 'action' => 'mptfwc_list_terminals', 'reason' => ( $order_id ? 'unauthorized' : 'missing_order_id' ), 'order_id' => $order_id, 'credential_submitted' => isset( $_POST['order_token'] ), 'logged_in' => is_user_logged_in(), 'can_manage' => current_user_can( 'manage_woocommerce' ) ), 'warning' );
			wp_send_json_error( __( 'Unauthorized request.', 'mercadopago-terminal-for-woocommerce' ), 403 );
		}
		$this->require_gateway_enabled( 'mptfwc_list_terminals' );
		$error = null;
		try {
			$settings = $this->settings();
			$default  = $settings->default_terminal_id();
			$items    = self::selectable_terminals( $this->terminal_service()->list_terminals(), $settings->enabled_terminal_ids() );
			Logger::log( 'Mercado Pago Terminal list retrieved.', array( 'order_id' => $order_id, 'count' => count( $items ) ), 'info' );
			$result = array( 'terminals' => $items, 'default_terminal_id' => $default, 'lock_terminal' => $settings->lock_terminal() );
		} catch ( \Throwable $e ) {
			Logger::log( 'Mercado Pago Terminal list failed: ' . $e->getMessage(), array( 'order_id' => $order_id ), 'error' );
			$error = $e;
		}
		if ( null !== $error ) { wp_send_json_error( $error->getMessage(), 500 ); }
		wp_send_json_success( $result );
	}

	/** Keep the configured terminals and explain which ones need PDV mode. */
	public static function selectable_terminals( array $terminals, array $enabled_ids = array() ): array {
		$items = array();
		foreach ( $terminals as $terminal ) {
			if ( $enabled_ids && ! in_array( (string) ( $terminal['id'] ?? '' ), $enabled_ids, true ) ) { continue; }
			if ( 'PDV' !== $terminal['operating_mode'] ) {
				$terminal['label'] .= ' — ' . __( 'standalone mode, switch to PDV in settings', 'mercadopago-terminal-for-woocommerce' );
			}
			$items[] = $terminal;
		}
		return $items;
	}

	/**
	 * Thank-you URL for a paid order. Inside the WooCommerce POS the standard
	 * order-received page is not what the POS watches for, so POS requests
	 * (detected via the X-WCPOS header the frontend sends) get the
	 * /wcpos-checkout/order-received/ variant instead — the same URL the
	 * Stripe/SumUp terminal gateways redirect to.
	 */
	public static function order_return_url( $order ): string {
		if ( function_exists( 'woocommerce_pos_request' ) && woocommerce_pos_request() ) {
			return add_query_arg(
				array( 'key' => $order->get_order_key() ),
				get_home_url( null, '/wcpos-checkout/order-received/' . $order->get_id() )
			);
		}
		return (string) $order->get_checkout_order_received_url();
	}

	public static function with_paid_redirect( array $result, $order ): array {
		// The completed order may be a fresh copy, not $order; the panel needs
		// the thank-you URL for every paid answer.
		if ( $order->is_paid() || in_array( $result['status'] ?? '', array( 'paid', 'already_paid', 'conflict' ), true ) ) {
			$result['redirect_url'] = self::order_return_url( $order );
		}
		return $result;
	}

	public function mptfwc_set_pdv_mode(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! check_ajax_referer( 'mptfwc_admin_actions', 'nonce', false ) ) {
			Logger::log( 'AJAX request rejected', array( 'action' => 'mptfwc_set_pdv_mode', 'reason' => 'security_check_failed', 'order_id' => absint( $_POST['order_id'] ?? 0 ), 'credential_submitted' => isset( $_POST['order_token'] ), 'logged_in' => is_user_logged_in(), 'can_manage' => current_user_can( 'manage_woocommerce' ) ), 'warning' );
			wp_send_json_error( __( 'Security check failed', 'mercadopago-terminal-for-woocommerce' ), 403 );
		}
		$terminal_id = sanitize_text_field( wp_unslash( $_POST['terminal_id'] ?? '' ) );
		if ( '' === $terminal_id ) {
			Logger::log( 'AJAX request rejected', array( 'action' => 'mptfwc_set_pdv_mode', 'reason' => 'missing_terminal_id', 'order_id' => absint( $_POST['order_id'] ?? 0 ), 'credential_submitted' => isset( $_POST['order_token'] ), 'logged_in' => is_user_logged_in(), 'can_manage' => current_user_can( 'manage_woocommerce' ) ), 'warning' );
			wp_send_json_error( __( 'Terminal ID is required.', 'mercadopago-terminal-for-woocommerce' ), 400 );
		}
		$error = null;
		try {
			$this->terminal_service()->set_pdv_mode( $terminal_id );
			delete_transient( 'mptfwc_terminal_choices_test' );
			delete_transient( 'mptfwc_terminal_choices_live' );
			delete_transient( 'mptfwc_terminal_rows' );
			$result = array( 'terminal_id' => $terminal_id, 'operating_mode' => 'PDV', 'message' => __( 'Switched to PDV mode. Restart the terminal to apply it.', 'mercadopago-terminal-for-woocommerce' ) );
		} catch ( \Throwable $e ) {
			Logger::log( 'Terminal PDV switch failed: ' . $e->getMessage(), array(), 'error' );
			$error = $e;
		}
		if ( null !== $error ) { wp_send_json_error( $error->getMessage(), 500 ); }
		wp_send_json_success( $result );
	}

	private function with_order( string $operation, callable $callback ): void {
		$order_id = absint( $_POST['order_id'] ?? 0 );
		if ( ! $order_id ) {
			Logger::log( 'AJAX request rejected', array( 'action' => 'mptfwc_' . $operation, 'reason' => 'missing_order_id', 'order_id' => $order_id, 'credential_submitted' => isset( $_POST['order_token'] ), 'logged_in' => is_user_logged_in(), 'can_manage' => current_user_can( 'manage_woocommerce' ) ), 'warning' );
			wp_send_json_error( __( 'Order ID is required.', 'mercadopago-terminal-for-woocommerce' ), 400 );
		}
		if ( ! $this->can_access_order( $order_id ) ) {
			Logger::log( 'AJAX request rejected', array( 'action' => 'mptfwc_' . $operation, 'reason' => 'unauthorized', 'order_id' => $order_id, 'credential_submitted' => isset( $_POST['order_token'] ), 'logged_in' => is_user_logged_in(), 'can_manage' => current_user_can( 'manage_woocommerce' ) ), 'warning' );
			wp_send_json_error( __( 'Unauthorized request.', 'mercadopago-terminal-for-woocommerce' ), 403 );
		}
		Logger::log( 'Mercado Pago Terminal AJAX request received.', array( 'operation' => $operation, 'order_id' => $order_id ), 'info' );
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			Logger::log( 'Mercado Pago Terminal AJAX request used invalid order.', array( 'operation' => $operation, 'order_id' => $order_id ), 'error' );
			Logger::log( 'AJAX request rejected', array( 'action' => 'mptfwc_' . $operation, 'reason' => 'invalid_order', 'order_id' => $order_id, 'credential_submitted' => isset( $_POST['order_token'] ), 'logged_in' => is_user_logged_in(), 'can_manage' => current_user_can( 'manage_woocommerce' ) ), 'warning' );
			wp_send_json_error( __( 'Invalid order.', 'mercadopago-terminal-for-woocommerce' ), 404 );
		}
		if ( 'start_payment' === $operation ) { $this->require_gateway_enabled( 'mptfwc_' . $operation ); }
		$error = null;
		try {
			$result = $callback( $order );
			if ( is_array( $result ) ) {
				// The order is already reconciled and paid, so re-submitting the
				// order-pay form would hit WooCommerce's "already paid" guard.
				// Hand the frontend the thank-you URL to navigate to directly.
				$result = self::with_paid_redirect( $result, $order );
			}
			Logger::log( 'Mercado Pago Terminal AJAX request completed.', array( 'operation' => $operation, 'order_id' => $order_id, 'status' => is_array( $result ) ? ( $result['status'] ?? '' ) : '' ), 'success' );
		} catch ( \Throwable $e ) {
			Logger::log( 'Mercado Pago Terminal AJAX failed: ' . $e->getMessage(), array( 'operation' => $operation ), 'error' );
			$error = $e;
		}
		if ( null !== $error ) { wp_send_json_error( $error->getMessage(), 500 ); }
		wp_send_json_success( $result );
	}

	private function can_access_order( int $order_id ): bool {
		if ( current_user_can( 'manage_woocommerce' ) || current_user_can( 'edit_shop_order', $order_id ) ) { return true; }
		$token = sanitize_text_field( wp_unslash( $_POST['order_token'] ?? '' ) );
		return $token && hash_equals( self::order_token( $order_id ), $token );
	}

	/**
	 * A gateway switched off everywhere must stay off: the checkout actions
	 * stay registered while the plugin is active, so starting a payment and
	 * listing terminals check the switches themselves. There are two: the
	 * WooCommerce → Payments checkbox (online store) and POS → Settings →
	 * Checkout (WooCommerce POS), and either one counts — POS merchants
	 * routinely leave the WooCommerce one off (the 0.5.1 guard only looked at
	 * that one and locked every such POS out of taking payments).
	 * Poll and cancel are deliberately not gated (nor is the webhook): a
	 * payment already in flight must still settle, and the cashier must keep
	 * the ability to cancel it, even if the gateway was switched off meanwhile.
	 */
	private function require_gateway_enabled( string $action ): void {
		if ( ! $this->settings()->active() ) {
			Logger::log( 'AJAX request rejected', array( 'action' => $action, 'reason' => 'gateway_disabled', 'order_id' => absint( $_POST['order_id'] ?? 0 ), 'credential_submitted' => isset( $_POST['order_token'] ), 'logged_in' => is_user_logged_in(), 'can_manage' => current_user_can( 'manage_woocommerce' ) ), 'warning' );
			wp_send_json_error( __( 'Mercado Pago Terminal is disabled.', 'mercadopago-terminal-for-woocommerce' ), 403 );
		}
	}

	public static function order_token( int $order_id ): string { return substr( wp_hash( 'mptfwc_order_' . $order_id . wp_salt( 'nonce' ) ), 0, 16 ); }
	private function settings(): Settings { return new Settings(); }
	private function terminal_service(): TerminalService { return ( $this->service_factory )( $this->settings() )['terminals']; }
	private function payment_service(): PointPaymentService { return ( $this->service_factory )( $this->settings() )['payments']; }
}
