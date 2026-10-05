<?php
class WP_Stub {
	public static $options = array();
	public static $actions = array();
	public static $filters = array();
	public static $logs = array();
	public static $boot_actions = array();

	public static function reset(): void {
		self::$options = array();
		self::$actions = array();
		self::$filters = array();
		self::$logs = array();
	}
}

if ( ! function_exists( 'plugin_dir_path' ) ) { function plugin_dir_path( $file ) { return dirname( $file ) . '/'; } }
if ( ! function_exists( 'plugin_dir_url' ) ) { function plugin_dir_url( $file ) { return 'https://shop.test/wp-content/plugins/' . basename( dirname( $file ) ) . '/'; } }
if ( ! function_exists( 'plugin_basename' ) ) { function plugin_basename( $file ) { return basename( dirname( $file ) ) . '/' . basename( $file ); } }
if ( ! function_exists( 'register_activation_hook' ) ) {
	function register_activation_hook( $file, $callback ) {
		add_action( 'activate_' . plugin_basename( $file ), $callback );
	}
}
if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $callback, $priority = 10 ) {
		WP_Stub::$actions[] = array( 'hook' => $hook, 'callback' => $callback, 'priority' => $priority );
		return true;
	}
}
if ( ! function_exists( 'do_action' ) ) {
	function do_action( $hook, ...$args ) {
		foreach ( WP_Stub::$actions as $action ) {
			if ( $hook === $action['hook'] ) {
				call_user_func_array( $action['callback'], $args );
			}
		}
	}
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $hook, $callback ) {
		WP_Stub::$filters[ $hook ] = $callback;
		return true;
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value, ...$args ) {
		return isset( WP_Stub::$filters[ $hook ] ) ? WP_Stub::$filters[ $hook ]( $value, ...$args ) : $value;
	}
}
if ( ! function_exists( 'get_option' ) ) { function get_option( $key, $default = false ) { return WP_Stub::$options[ $key ] ?? $default; } }
if ( ! function_exists( '__' ) ) { function __( $text, $domain = '' ) { return $text; } }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); } }
if ( ! function_exists( 'admin_url' ) ) { function admin_url( $path = '' ) { return 'https://shop.test/wp-admin/' . $path; } }
if ( ! function_exists( 'add_query_arg' ) ) {
	function add_query_arg( $args, $url ) {
		foreach ( $args as $key => $value ) {
			$url .= ( false === strpos( $url, '?' ) ? '?' : '&' ) . rawurlencode( (string) $key ) . '=' . rawurlencode( (string) $value );
		}
		return $url;
	}
}
if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $data ) { return json_encode( $data ); } }
if ( ! function_exists( 'load_plugin_textdomain' ) ) { function load_plugin_textdomain( $domain, $deprecated = false, $path = '' ) { return true; } }
if ( ! function_exists( 'deactivate_plugins' ) ) { function deactivate_plugins( $plugins ) {} }
if ( ! function_exists( 'wp_die' ) ) { function wp_die( $message ) { throw new RuntimeException( $message ); } }
if ( ! function_exists( 'wc_get_logger' ) ) {
	function wc_get_logger() {
		return new class {
			public function log( $level, $message, $context ) {
				WP_Stub::$logs[] = array( 'level' => $level, 'message' => $message, 'context' => $context );
			}
		};
	}
}
