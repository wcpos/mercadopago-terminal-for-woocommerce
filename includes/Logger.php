<?php
namespace WCPOS\WooCommercePOS\MercadoPagoTerminal;

/**
 * Logger for the Mercado Pago Terminal integration.
 *
 * Follows the WooCommerce POS terminal-gateway logging convention shared by the
 * Stripe, SumUp, PayArc and Square terminal plugins: everything is written to
 * the WooCommerce status logs (WooCommerce → Status → Logs, source
 * "mercadopago-terminal"). Sensitive values are redacted, as the
 * PayArc/Square loggers also do, because this plugin logs Mercado Pago API payloads.
 *
 * NOTE: do not put any SQL queries in this class, eg: options table lookup.
 */
class Logger {
	public const WC_LOG_FILENAME = 'mercadopago-terminal';
	private static $active_level = 'debug';
	private static $request_id;
	private static $environment_logged = false;

	public static function configure( string $level ): void {
		self::$active_level = in_array( $level, array( 'off', 'errors', 'debug' ), true ) ? $level : 'debug';
	}

	public static function request_id(): string {
		if ( null === self::$request_id ) {
			try {
				self::$request_id = bin2hex( random_bytes( 4 ) );
			} catch ( \Throwable $e ) {
				self::$request_id = substr( md5( uniqid( '', true ) ), 0, 8 );
			}
		}
		return self::$request_id;
	}

	public static function reset_request(): void {
		self::$request_id = null;
		self::$environment_logged = false;
	}

	public static function timer(): float { return microtime( true ); }
	public static function elapsed_ms( float $start ): int { return (int) round( ( microtime( true ) - $start ) * 1000 ); }

	/** @var null|\WC_Logger */
	public static $logger;

	/** @var null|string */
	public static $log_level;

	public static function set_log_level( $level ): void {
		self::$log_level = $level;
	}

	/**
	 * Write a redacted message to the WooCommerce status logs.
	 *
	 * Argument order matches the PayArc terminal plugin's logger
	 * (message, context, level) so the family stays consistent.
	 *
	 * @param mixed  $message Message to log (non-strings are stringified).
	 * @param array  $context Extra context appended to the message, redacted.
	 * @param string $level   PSR-3 level; the internal "success" maps to "info".
	 */
	public static function log( $message, array $context = array(), string $level = '' ): void {
		try {
			if ( '' === $level ) {
				$level = self::$log_level ? self::$log_level : 'info';
			}
			if ( 'off' === self::$active_level || ( 'errors' === self::$active_level && ! in_array( $level, array( 'error', 'warning' ), true ) ) ) { return; }
			if ( function_exists( 'apply_filters' ) && ! apply_filters( 'mptfwc_logging', true, $message ) ) { return; }
			if ( ! self::$environment_logged && 'debug' === self::$active_level ) {
				self::$environment_logged = true;
				self::log( sprintf( 'Environment: plugin %s, WooCommerce %s, WordPress %s, PHP %s, WCPOS %s, log level %s', MPTFWC_VERSION, defined( 'WC_VERSION' ) ? WC_VERSION : 'n/a', $GLOBALS['wp_version'] ?? 'n/a', PHP_VERSION, defined( 'WCPOS_VERSION' ) ? WCPOS_VERSION : 'n/a', self::$active_level ), array(), 'info' );
			}
			if ( ! is_string( $message ) ) {
				$message = print_r( $message, true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r
			}
			$message = self::redact( (string) $message );
			$line = '[' . self::request_id() . '] ' . ( strlen( $message ) > 1000 ? substr( $message, 0, 1000 ) . '…' : $message );
			if ( ! empty( $context ) && function_exists( 'wp_json_encode' ) ) {
				$line .= ' ' . wp_json_encode( self::redact_context( $context ) );
			}
			if ( function_exists( 'wc_get_logger' ) ) {
				if ( empty( self::$logger ) ) { self::$logger = wc_get_logger(); }
				self::$logger->log( self::wc_level( $level ), $line, array( 'source' => self::WC_LOG_FILENAME ) );
				return;
			}
			if ( function_exists( 'error_log' ) ) {
				error_log( $line . ' [' . self::WC_LOG_FILENAME . '] [' . $level . ']' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
		} catch ( \Throwable $e ) {
			// A logging failure must never interrupt a payment.
		}
	}

	/** Convenience wrapper for error-level logging. */
	public static function log_api_error( string $message, array $context = array() ): void {
		self::log( $message, $context, 'error' );
	}

	public static function redact( string $value ): string {
		$value = preg_replace( '/Bearer\s+[^\s\'\"]+/i', 'Bearer ***', $value );
		$value = preg_replace( '/(APP_USR|TEST)-[0-9A-Za-z-]{20,}/', '$1-***', $value );
		$value = preg_replace( '/v1=[0-9a-f]+/i', 'v1=***', $value );
		return preg_replace_callback( '/(?<![0-9])(?<![0-9][ -])[0-9](?:[ -]?[0-9]){12,18}(?![ -]?[0-9])/', static function ( $match ) {
			$digits = str_replace( array( ' ', '-' ), '', $match[0] );
			$sum = 0;
			for ( $i = strlen( $digits ) - 1, $position = 0; $i >= 0; --$i, ++$position ) {
				$digit = (int) $digits[ $i ];
				if ( 1 === $position % 2 ) { $digit *= 2; }
				$sum += $digit > 9 ? $digit - 9 : $digit;
			}
			return 0 === $sum % 10 ? '****' . substr( $digits, -4 ) : $match[0];
		}, $value );
	}

	private static function redact_context( array $context ): array {
		foreach ( $context as $key => $value ) {
			if ( self::is_sensitive_key( (string) $key ) ) {
				$context[ $key ] = '***';
			} elseif ( is_array( $value ) ) {
				$context[ $key ] = self::redact_context( $value );
			} elseif ( is_string( $value ) ) {
				$value = self::redact( $value );
				$context[ $key ] = strlen( $value ) > 4000 ? substr( $value, 0, 4000 ) . '…' : $value;
			}
		}
		return $context;
	}

	private static function is_sensitive_key( string $key ): bool {
		if ( in_array( strtolower( $key ), array( 'x-idempotency-key', 'idempotency_key' ), true ) ) { return false; }
		foreach ( array( 'key', 'token', 'secret', 'authorization', 'password', 'bearer', 'signature', 'cvv', 'security_code', 'card_number' ) as $needle ) {
			if ( false !== stripos( $key, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	/** Map the internal "success" level onto a PSR-3 / WC_Logger level. */
	private static function wc_level( string $level ): string {
		$level = in_array( $level, array( 'debug', 'info', 'success', 'warning', 'error' ), true ) ? $level : 'info';
		return 'success' === $level ? 'info' : $level;
	}
}
