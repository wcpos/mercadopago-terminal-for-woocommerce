<?php
/**
 * Plugin Name: Mercado Pago Terminal for WooCommerce
 * Description: Adds Mercado Pago Point Smart terminal support to WooCommerce for in-person payments.
 * Version:     0.0.1
 * Author:      kilbot
 * Author URI:  https://kilbot.com/
 * Update URI:  https://github.com/wcpos/mercadopago-terminal-for-woocommerce
 * License:     GPL v3 or later
 * Text Domain: mercadopago-terminal-for-woocommerce
 * Requires at least: 5.2
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 */

namespace WCPOS\WooCommercePOS\MercadoPagoTerminal;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MPTFWC_VERSION', '0.0.1' );
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

function mptfwc_activate(): void {
	if ( PHP_VERSION_ID >= MPTFWC_MINIMUM_PHP_VERSION_ID ) {
		return;
	}
	deactivate_plugins( plugin_basename( __FILE__ ) );
	wp_die( esc_html( sprintf( __( 'Mercado Pago Terminal for WooCommerce requires PHP %1$s or newer. Your server is running PHP %2$s.', 'mercadopago-terminal-for-woocommerce' ), MPTFWC_MINIMUM_PHP_VERSION, PHP_VERSION ) ) );
}
register_activation_hook( __FILE__, __NAMESPACE__ . '\\mptfwc_activate' );

function mptfwc_deactivate(): void { PaymentSweeper::unschedule(); }
register_deactivation_hook( __FILE__, __NAMESPACE__ . '\\mptfwc_deactivate' );

function load_textdomain(): void {
	load_plugin_textdomain( 'mercadopago-terminal-for-woocommerce', false, dirname( plugin_basename( MPTFWC_PLUGIN_FILE ) ) . '/languages' );
}
add_action( 'init', __NAMESPACE__ . '\\load_textdomain' );

function init(): void {
	Logger::configure( ( new Settings() )->log_level() );
	add_filter( 'woocommerce_payment_gateways', array( Gateway::class, 'register_gateway' ) );
	add_action( 'woocommerce_create_refund', array( RefundHandler::class, 'remember_refund' ), 10, 2 );
	new AjaxHandler();
	new WebhookHandler();
	new PaymentSweeper();
	( new SupportBundle() )->register();
	do_action( 'mptfwc_init' );
}
add_action( 'plugins_loaded', __NAMESPACE__ . '\\init', 11 );
