<?php
/**
 * Plugin Name: Mercado Pago Terminal for WooCommerce
 * Description: Adds Mercado Pago Point Smart terminal support to WooCommerce for in-person payments. Requires WooCommerce POS Pro 2.0.
 * Version:     1.0.0
 * Author:      kilbot
 * Author URI:  https://kilbot.com/
 * Update URI:  https://github.com/wcpos/mercadopago-terminal-for-woocommerce
 * License:     GPL v3 or later
 * Text Domain: mercadopago-terminal-for-woocommerce
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 */

namespace WCPOS\WooCommercePOS\MercadoPagoTerminal;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MPTFWC_VERSION', '1.0.0' );
define( 'MPTFWC_PLUGIN_FILE', __FILE__ );
define( 'MPTFWC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MPTFWC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'MPTFWC_MINIMUM_PHP_VERSION', '7.4' );
define( 'MPTFWC_MINIMUM_PHP_VERSION_ID', 70400 );

if ( file_exists( MPTFWC_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
	require_once MPTFWC_PLUGIN_DIR . 'vendor/autoload.php';
}

spl_autoload_register(
	function ( $class ): void {
		$prefix = __NAMESPACE__ . '\\';
		$len    = strlen( $prefix );
		if ( 0 !== strncmp( $prefix, $class, $len ) ) {
			return;
		}
		$file = MPTFWC_PLUGIN_DIR . 'includes/' . str_replace( '\\', '/', substr( $class, $len ) ) . '.php';
		if ( file_exists( $file ) ) {
			require $file;
		}
	}
);

function init(): void {
	if ( ! function_exists( 'wcpos_pro_requires' ) || ! wcpos_pro_requires( '2.0.0', __FILE__ ) ) {
		add_action( 'admin_notices', static function () {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Mercado Pago Terminal requires WooCommerce POS Pro 2.0 or newer.', 'mercadopago-terminal-for-woocommerce' ) . '</p></div>';
		} );
		return;
	}
	add_filter( 'woocommerce_payment_gateways', array( Gateway::class, 'register_gateway' ) );
	wcpos_pro_register_server_provider( Settings::GATEWAY_ID, Provider_Adapter::class );
	add_action( 'init', array( Legacy_Adoption::class, 'upgrade' ), 20 );
	if ( 'mptfwc_set_pdv' === ( $_REQUEST['action'] ?? '' ) ) {
		add_action( 'admin_post_mptfwc_set_pdv', array( Gateway::class, 'set_pdv' ) );
	}
}
// Pro defines wcpos_pro_requires() and the provider registration API from its own
// plugins_loaded hook at priority 20; the gate must run after that.
add_action( 'plugins_loaded', __NAMESPACE__ . '\\init', 30 );
