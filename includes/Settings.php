<?php
namespace WCPOS\WooCommercePOS\MercadoPagoTerminal;

class Settings {
	public const GATEWAY_ID = 'mercadopago_terminal_for_woocommerce';

	private $options;

	public function __construct( ?array $options = null ) {
		$this->options = $options;
	}

	public function get( string $key, $default = '' ) {
		$options = $this->options;
		if ( null === $options ) {
			$options = get_option( 'woocommerce_' . self::GATEWAY_ID . '_settings', array() );
		}
		return $options[ $key ] ?? $default;
	}

	public function access_token(): string { return trim( (string) $this->get( 'access_token', '' ) ); }
	public function webhook_secret(): string { return trim( (string) $this->get( 'webhook_secret', '' ) ); }

	/** Whether the merchant has the gateway switched on in WooCommerce → Payments (online store only). */
	public function enabled(): bool { return 'yes' === $this->get( 'enabled', 'no' ); }

	/**
	 * Whether WooCommerce POS has this gateway switched on under
	 * POS → Settings → Checkout. WooCommerce POS forces a gateway enabled from its
	 * own settings and ignores the WooCommerce → Payments switch, so merchants
	 * routinely leave that switch off for a POS-only terminal.
	 */
	public function enabled_for_pos(): bool {
		// wcpos_get_settings() is the maintained helper; woocommerce_pos_get_settings() is its deprecated alias.
		$getter = function_exists( 'wcpos_get_settings' ) ? 'wcpos_get_settings' : 'woocommerce_pos_get_settings';
		if ( ! function_exists( $getter ) ) { return false; }
		$settings = $getter( 'payment_gateways' );
		return is_array( $settings ) && ! empty( $settings['gateways'][ self::GATEWAY_ID ]['enabled'] );
	}

	/** Whether the gateway is switched on anywhere: online store or WooCommerce POS. */
	public function active(): bool { return $this->enabled() || $this->enabled_for_pos(); }

	/** Gateway title as configured in WooCommerce → Payments; the customer-facing "Payment via" label. */
	public function title(): string {
		$title = trim( (string) $this->get( 'title', '' ) );
		if ( '' !== $title ) { return $title; }
		return function_exists( '__' ) ? __( 'Mercado Pago Terminal', 'mercadopago-terminal-for-woocommerce' ) : 'Mercado Pago Terminal';
	}
	public function mode(): string { return 'live' === $this->get( 'mode', 'test' ) ? 'live' : 'test'; }
	public function default_terminal_id(): string { return trim( (string) $this->get( 'default_terminal_id', '' ) ); }

	/** Whether the checkout log tools (Show logs / Copy / Clear) are shown. */
	public function show_logs(): bool { return 'yes' === $this->get( 'show_logs', 'no' ); }

	/**
	 * Terminals the merchant allows at checkout. Empty means all active
	 * terminals are allowed (no restriction configured). When a restriction is
	 * configured, the default terminal is always included — the merchant chose
	 * it explicitly, and excluding it would brick checkout when the terminal
	 * selection is locked to the default.
	 */
	public function enabled_terminal_ids(): array {
		$value = $this->get( 'enabled_terminals', array() );
		if ( is_string( $value ) ) {
			$value = '' === $value ? array() : array( $value );
		}
		if ( ! is_array( $value ) ) { return array(); }
		$ids = array_values( array_filter( array_map( 'strval', $value ) ) );
		$default = $this->default_terminal_id();
		if ( $ids && '' !== $default && ! in_array( $default, $ids, true ) ) {
			$ids[] = $default;
		}
		return $ids;
	}

	/**
	 * When locked, cashiers cannot pick a terminal at checkout — the default
	 * terminal is always used. Only effective when a default is configured.
	 */
	public function lock_terminal(): bool {
		return 'yes' === $this->get( 'lock_terminal', 'no' ) && '' !== $this->default_terminal_id();
	}

	public function webhook_url(): string {
		return add_query_arg( array( 'action' => 'mptfwc_webhook' ), admin_url( 'admin-ajax.php' ) );
	}
}
