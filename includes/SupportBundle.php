<?php
namespace WCPOS\WooCommercePOS\MercadoPagoTerminal;

use WCPOS\WooCommercePOS\MercadoPagoTerminal\Services\MercadoPagoClient;
use WCPOS\WooCommercePOS\MercadoPagoTerminal\Services\TerminalService;

class SupportBundle {
	public const ACTION = 'mptfwc_support_bundle';
	private $settings;
	private $terminal_lister;

	public function __construct( ?Settings $settings = null, ?callable $terminal_lister = null ) {
		$this->settings = $settings ?? new Settings();
		$this->terminal_lister = $terminal_lister ?? static function ( Settings $settings ): array {
			return ( new TerminalService( new MercadoPagoClient( $settings->access_token() ), $settings ) )->list_terminals( 5 );
		};
	}

	public function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
	}

	public static function url(): string {
		return wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION ), self::ACTION );
	}

	public function handle(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! check_admin_referer( self::ACTION ) ) {
			wp_die( __( 'You are not allowed to download the support bundle.', 'mercadopago-terminal-for-woocommerce' ), '', array( 'response' => 403 ) );
		}
		Logger::log( 'Support bundle downloaded', array( 'user_id' => get_current_user_id() ), 'info' );
		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="mercadopago-terminal-support-' . gmdate( 'Ymd-His' ) . '.txt"' );
		echo $this->build();
		exit;
	}

	public static function mask( string $secret ): string {
		$length = strlen( $secret );
		if ( 0 === $length ) { return '(not set)'; }
		if ( $length < 12 ) { return '*** (' . $length . ' chars)'; }
		$prefix = explode( '-', $secret, 2 )[0];
		$prefix = in_array( $prefix, array( 'APP_USR', 'TEST' ), true ) ? $prefix : 'secret';
		return $prefix . '-…' . substr( $secret, -4 ) . ' (' . $length . ' chars)';
	}

	private static function tail_lines( string $file, int $max_bytes = 524288 ): array {
		$handle = @fopen( $file, 'rb' );
		if ( false === $handle ) { return array(); }
		$stat = fstat( $handle );
		if ( false === $stat || ( $stat['size'] > $max_bytes && ( 0 !== fseek( $handle, $stat['size'] - $max_bytes ) || false === fgets( $handle ) ) ) ) {
			fclose( $handle );
			return array();
		}
		$contents = stream_get_contents( $handle );
		$closed = fclose( $handle );
		if ( false === $contents || ! $closed || '' === $contents ) { return array(); }
		$lines = explode( "\n", $contents );
		if ( '' === end( $lines ) ) { array_pop( $lines ); }
		return array_map( static function ( $line ) { return rtrim( $line, "\r" ); }, $lines );
	}

	public function build(): string {
		global $wpdb;
		$lines = array( 'Generated at: ' . gmdate( 'c' ), '', '== Environment ==' );
		$secrets = array();
		try {
			$lines[] = 'Plugin: ' . MPTFWC_VERSION;
			$lines[] = 'WordPress: ' . ( $GLOBALS['wp_version'] ?? 'unknown' );
			$lines[] = 'WooCommerce: ' . ( defined( 'WC_VERSION' ) ? WC_VERSION : 'unknown' );
			$lines[] = 'PHP: ' . PHP_VERSION;
			$lines[] = 'WCPOS: ' . ( defined( 'WCPOS_VERSION' ) ? WCPOS_VERSION : 'unknown' );
			$lines[] = 'Site URL: ' . home_url();
			$lines[] = 'Timezone: ' . ( function_exists( 'wp_timezone_string' ) ? wp_timezone_string() : 'unknown' );
			$lines[] = 'HPOS enabled: ' . ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) ? ( \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ? 'yes' : 'no' ) : 'unknown' );
			foreach ( array( 'json', 'curl', 'openssl', 'mbstring' ) as $extension ) {
				$lines[] = $extension . ': ' . ( extension_loaded( $extension ) ? 'yes' : 'no' );
			}
			$lines[] = 'WP_DEBUG: ' . ( defined( 'WP_DEBUG' ) && WP_DEBUG ? 'yes' : 'no' );
			$lines[] = 'Memory limit: ' . ini_get( 'memory_limit' );
			$lines[] = 'WooCommerce log handler: ' . ( defined( 'WC_LOG_HANDLER' ) ? WC_LOG_HANDLER : 'default' );
		} catch ( \Throwable $e ) {
			$lines[] = 'error: ' . Logger::redact( $e->getMessage() );
		}

		$lines[] = "\n== Settings ==";
		try {
			$secrets[] = $this->settings->access_token();
			$secrets[] = $this->settings->webhook_secret();
			$options = get_option( 'woocommerce_' . Settings::GATEWAY_ID . '_settings', array() );
			ksort( $options );
			foreach ( $options as $key => $value ) {
				if ( in_array( $key, array( 'access_token', 'webhook_secret' ), true ) ) {
					$secrets[] = (string) $value;
					$value = self::mask( (string) $value );
				} elseif ( is_array( $value ) ) {
					$value = wp_json_encode( $value );
				}
				$lines[] = $key . ': ' . $value;
			}
			$lines[] = 'active: ' . ( $this->settings->active() ? 'yes' : 'no' );
			$lines[] = 'enabled_for_pos: ' . ( $this->settings->enabled_for_pos() ? 'yes' : 'no' );
			$lines[] = 'log_level: ' . $this->settings->log_level();
			$lines[] = 'webhook_url: ' . $this->settings->webhook_url();
		} catch ( \Throwable $e ) {
			$lines[] = 'error: ' . Logger::redact( $e->getMessage() );
		}

		$lines[] = "\n== Terminals ==";
		try {
			if ( '' === $this->settings->access_token() ) {
				$lines[] = '(skipped: no access token)';
			} else {
				foreach ( ( $this->terminal_lister )( $this->settings ) as $terminal ) {
					$lines[] = wp_json_encode( array( 'id' => $terminal['id'], 'label' => $terminal['label'], 'operating_mode' => $terminal['operating_mode'] ) );
				}
			}
		} catch ( \Throwable $e ) {
			$lines[] = 'error: ' . Logger::redact( $e->getMessage() );
		}

		$lines[] = "\n== Recent payment attempts ==";
		try {
			$orders = wc_get_orders( array( 'type' => 'shop_order', 'limit' => 10, 'orderby' => 'date', 'order' => 'DESC', 'meta_key' => PaymentAttempt::META_ATTEMPTS, 'meta_compare' => 'EXISTS' ) );
			foreach ( $orders as $order ) {
				$lines[] = sprintf( 'Order id: %s; status: %s; total: %s; currency: %s; payment method: %s; transaction id: %s', $order->get_id(), $order->get_status(), $order->get_total(), $order->get_currency(), $order->get_payment_method(), $order->get_transaction_id() );
				foreach ( PaymentAttempt::history( $order ) as $entry ) {
					$lines[] = wp_json_encode( $entry );
				}
			}
		} catch ( \Throwable $e ) {
			$lines[] = 'error: ' . Logger::redact( $e->getMessage() );
		}

		$lines[] = "\n== Recent log (source mercadopago-terminal) ==";
		$log_lines = array();
		try {
			$files = defined( 'WC_LOG_DIR' ) ? glob( WC_LOG_DIR . 'mercadopago-terminal-*.log' ) : array();
			if ( $files ) {
				usort( $files, static function ( $a, $b ) { return filemtime( $b ) <=> filemtime( $a ); } );
				foreach ( array_reverse( array_slice( $files, 0, 3 ) ) as $file ) {
					$log_lines = array_merge( $log_lines, self::tail_lines( $file ) );
				}
			}
			if ( ! $log_lines ) {
				$rows = $wpdb->get_results( "SELECT timestamp, level, message FROM {$wpdb->prefix}woocommerce_log WHERE source = 'mercadopago-terminal' ORDER BY log_id DESC LIMIT 500" );
				foreach ( array_reverse( $rows ?: array() ) as $row ) {
					$log_lines[] = $row->timestamp . ' ' . $row->level . ' ' . $row->message;
				}
			}
		} catch ( \Throwable $e ) {
			$lines[] = 'error: ' . Logger::redact( $e->getMessage() );
		}
		foreach ( array_slice( $log_lines, -500 ) as $line ) {
			$lines[] = Logger::redact( $line );
		}
		if ( ! $log_lines ) { $lines[] = '(no log lines found)'; }

		$bundle = str_replace( array_filter( $secrets, static function ( $secret ) { return '' !== $secret; } ), '***', implode( "\n", $lines ) );
		return implode( "\n", array_map( array( Logger::class, 'redact' ), explode( "\n", $bundle ) ) ) . "\n";
	}
}
