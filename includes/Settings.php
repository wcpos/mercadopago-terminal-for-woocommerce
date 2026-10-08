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
	public function enabled_for_pos(): bool {
		$getter = function_exists( 'wcpos_get_settings' ) ? 'wcpos_get_settings' : 'woocommerce_pos_get_settings';
		if ( ! function_exists( $getter ) ) { return false; }
		$settings = $getter( 'payment_gateways' );
		return is_array( $settings ) && ! empty( $settings['gateways'][ self::GATEWAY_ID ]['enabled'] );
	}

	public function mode(): string { return 'live' === $this->get( 'mode', 'test' ) ? 'live' : 'test'; }
	public function webhook_url(): string { return add_query_arg( 'provider', 'mercadopago', rest_url( 'wcpos/v2/payments/webhook' ) ); }
}
