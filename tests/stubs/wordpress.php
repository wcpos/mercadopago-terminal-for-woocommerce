<?php
class WP_Stub {
	public static $options = array();
	public static $actions = array();
	public static $filters = array();
	public static $logs = array();
	public static $logger;
	public static $boot_actions = array();
	public static $http_requests = array();
	public static $http_responses = array();
	public static $uuid_counter = 0;
	public static $status = null;
	public static $doing_ajax = false;
	public static $caps = array();
	public static $nonce_ok = false;
	public static $json = null;
	public static $transients = array();
	public static $transient_expirations = array();
	public static $is_admin = false;
	public static $scripts = array();
	public static $checkout_pay_page = false;
	public static $notices = array();
	public static $cron = array();
	public static $logged_in = false;

	public static function reset(): void {
		self::$options = array();
		self::$actions = array();
		self::$filters = array();
		self::$logs = array();
		self::$logger = null;
		self::$http_requests = array();
		self::$http_responses = array();
		self::$uuid_counter = 0;
		self::$status = null;
		self::$doing_ajax = false;
		self::$caps = array();
		self::$nonce_ok = false;
		self::$json = null;
		self::$transients = array();
		self::$transient_expirations = array();
		self::$is_admin = false;
		self::$scripts = array();
		self::$checkout_pay_page = false;
		self::$notices = array();
		self::$cron = array();
		self::$logged_in = false;
		unset( $GLOBALS['mptfwc_wc_get_orders_args'], $GLOBALS['mptfwc_wc_get_orders_callback'] );
	}
}

if ( ! defined( 'MINUTE_IN_SECONDS' ) ) { define( 'MINUTE_IN_SECONDS', 60 ); }
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }
if ( ! function_exists( 'is_user_logged_in' ) ) { function is_user_logged_in() { return WP_Stub::$logged_in; } }
if ( ! function_exists( 'wp_next_scheduled' ) ) { function wp_next_scheduled( $hook ) { return empty( WP_Stub::$cron[ $hook ] ) ? false : min( array_keys( WP_Stub::$cron[ $hook ] ) ); } }
if ( ! function_exists( 'wp_schedule_event' ) ) { function wp_schedule_event( $timestamp, $schedule, $hook ) { WP_Stub::$cron[ $hook ][ $timestamp ] = $schedule; return true; } }
if ( ! function_exists( 'wp_unschedule_event' ) ) { function wp_unschedule_event( $timestamp, $hook ) { unset( WP_Stub::$cron[ $hook ][ $timestamp ] ); return true; } }
if ( ! function_exists( 'register_deactivation_hook' ) ) { function register_deactivation_hook( $file, $callback ) { add_action( 'deactivate_' . plugin_basename( $file ), $callback ); } }
// Simulate exit without being caught by the handlers' service-exception catches.
if ( ! class_exists( 'WP_Stub_Json_Exit' ) ) { class WP_Stub_Json_Exit extends Error {} }
if ( ! function_exists( 'wp_doing_ajax' ) ) { function wp_doing_ajax() { return WP_Stub::$doing_ajax; } }
if ( ! function_exists( 'current_user_can' ) ) { function current_user_can( $cap, ...$args ) { return ! empty( WP_Stub::$caps[ $cap ] ); } }
if ( ! function_exists( 'check_ajax_referer' ) ) { function check_ajax_referer( $action, $query_arg = false, $stop = true ) { return WP_Stub::$nonce_ok; } }
if ( ! function_exists( 'check_admin_referer' ) ) { function check_admin_referer( $action = -1, $query_arg = '_wpnonce' ) { return WP_Stub::$nonce_ok; } }
if ( ! function_exists( 'wp_nonce_url' ) ) { function wp_nonce_url( $url, $action = -1 ) { return esc_html( add_query_arg( array( '_wpnonce' => wp_create_nonce( $action ) ), $url ) ); } }
if ( ! function_exists( 'nocache_headers' ) ) { function nocache_headers() {} }
if ( ! function_exists( 'home_url' ) ) { function home_url( $path = '' ) { return get_home_url( null, $path ); } }
if ( ! function_exists( 'wp_timezone_string' ) ) { function wp_timezone_string() { return 'UTC'; } }
if ( ! function_exists( 'get_current_user_id' ) ) { function get_current_user_id() { return 1; } }
if ( ! function_exists( 'wp_send_json_success' ) ) {
	function wp_send_json_success( $data = null, $status_code = null ) {
		WP_Stub::$json = array( 'success' => true, 'data' => $data, 'code' => $status_code ?? 200 );
		throw new WP_Stub_Json_Exit();
	}
}
if ( ! function_exists( 'wp_send_json_error' ) ) {
	function wp_send_json_error( $data = null, $status_code = null ) {
		WP_Stub::$json = array( 'success' => false, 'data' => $data, 'code' => $status_code ?? 200 );
		throw new WP_Stub_Json_Exit();
	}
}
if ( ! function_exists( 'absint' ) ) { function absint( $value ) { return abs( (int) $value ); } }
if ( ! function_exists( 'wp_unslash' ) ) { function wp_unslash( $value ) { return stripslashes( $value ); } }
if ( ! function_exists( 'wp_salt' ) ) { function wp_salt( $scheme = 'auth' ) { return 'test-salt-' . $scheme; } }
if ( ! function_exists( 'wp_hash' ) ) { function wp_hash( $data, $scheme = 'auth' ) { return hash_hmac( 'md5', $data, wp_salt( $scheme ) ); } }
if ( ! function_exists( 'get_home_url' ) ) { function get_home_url( $blog_id = null, $path = '' ) { return 'https://shop.test' . $path; } }
if ( ! function_exists( 'get_transient' ) ) { function get_transient( $key ) { return WP_Stub::$transients[ $key ] ?? false; } }
if ( ! function_exists( 'set_transient' ) ) { function set_transient( $key, $value, $expiration = 0 ) { WP_Stub::$transients[ $key ] = $value; WP_Stub::$transient_expirations[ $key ] = $expiration; return true; } }
if ( ! function_exists( 'delete_transient' ) ) { function delete_transient( $key ) { unset( WP_Stub::$transients[ $key ] ); return true; } }
if ( ! function_exists( 'is_admin' ) ) { function is_admin() { return WP_Stub::$is_admin; } }
if ( ! function_exists( 'esc_attr' ) ) { function esc_attr( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); } }
if ( ! function_exists( 'esc_attr__' ) ) { function esc_attr__( $text, $domain = '' ) { return esc_attr( __( $text, $domain ) ); } }
if ( ! function_exists( 'esc_html__' ) ) { function esc_html__( $text, $domain = '' ) { return esc_html( __( $text, $domain ) ); } }
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $url ) { return htmlspecialchars( $url, ENT_QUOTES, 'UTF-8' ); } }
if ( ! function_exists( 'wp_kses_post' ) ) { function wp_kses_post( $data ) { return $data; } }
if ( ! function_exists( 'wp_create_nonce' ) ) { function wp_create_nonce( $action = -1 ) { return 'nonce-' . $action; } }
if ( ! function_exists( 'wp_enqueue_script' ) ) {
	function wp_enqueue_script( $handle, $src = '', $deps = array(), $ver = false, $in_footer = false ) {
		WP_Stub::$scripts[ $handle ]['script'] = compact( 'src', 'deps', 'ver', 'in_footer' );
	}
}
if ( ! function_exists( 'wp_enqueue_style' ) ) {
	function wp_enqueue_style( $handle, $src = '', $deps = array(), $ver = false ) {
		WP_Stub::$scripts[ $handle ]['style'] = compact( 'src', 'deps', 'ver' );
	}
}
if ( ! function_exists( 'wp_localize_script' ) ) { function wp_localize_script( $handle, $object_name, $data ) { WP_Stub::$scripts[ $handle ]['localized'][ $object_name ] = $data; return true; } }
if ( ! function_exists( 'is_checkout_pay_page' ) ) { function is_checkout_pay_page() { return WP_Stub::$checkout_pay_page; } }
if ( ! function_exists( 'wc_add_notice' ) ) { function wc_add_notice( $message, $type = 'success' ) { WP_Stub::$notices[] = compact( 'message', 'type' ); } }

if ( ! function_exists( 'wp_generate_uuid4' ) ) { function wp_generate_uuid4() { return sprintf( '00000000-0000-4000-8000-%012d', ++WP_Stub::$uuid_counter ); } }
if ( ! function_exists( 'sanitize_key' ) ) { function sanitize_key( $key ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) ); } }
if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( $value ) { return trim( strip_tags( $value ) ); } }
if ( ! function_exists( 'status_header' ) ) { function status_header( $code ) { WP_Stub::$status = $code; } }

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
if ( ! function_exists( 'update_option' ) ) { function update_option( $key, $value, $autoload = null ) { $changed = ! array_key_exists( $key, WP_Stub::$options ) || WP_Stub::$options[ $key ] !== $value; WP_Stub::$options[ $key ] = $value; return $changed; } }
if ( ! function_exists( 'delete_option' ) ) { function delete_option( $key ) { unset( WP_Stub::$options[ $key ] ); return true; } }
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
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $code;
		private $message;
		public function __construct( $code = '', $message = '' ) { $this->code = $code; $this->message = $message; }
		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->message; }
	}
}
if ( ! function_exists( 'wc_format_decimal' ) ) { function wc_format_decimal( $number, $dp = false ) { return number_format( (float) $number, false === $dp ? 2 : $dp, '.', '' ); } }
if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $value ) { return $value instanceof WP_Error; } }
if ( ! function_exists( 'wp_remote_request' ) ) {
	function wp_remote_request( $url, $args ) {
		WP_Stub::$http_requests[] = array( 'url' => $url, 'args' => $args );
		return array_shift( WP_Stub::$http_responses );
	}
}
if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) { function wp_remote_retrieve_response_code( $response ) { return $response['response']['code']; } }
if ( ! function_exists( 'wp_remote_retrieve_body' ) ) { function wp_remote_retrieve_body( $response ) { return $response['body']; } }
if ( ! function_exists( 'wp_remote_retrieve_headers' ) ) { function wp_remote_retrieve_headers( $response ) { return $response['headers'] ?? array(); } }
if ( ! function_exists( 'load_plugin_textdomain' ) ) { function load_plugin_textdomain( $domain, $deprecated = false, $path = '' ) { return true; } }
if ( ! function_exists( 'deactivate_plugins' ) ) { function deactivate_plugins( $plugins ) {} }
if ( ! function_exists( 'wp_die' ) ) { function wp_die( $message ) { throw new RuntimeException( $message ); } }
if ( ! function_exists( 'wc_get_logger' ) ) {
	function wc_get_logger() {
		if ( null !== WP_Stub::$logger ) { return WP_Stub::$logger; }
		return new class {
			public function log( $level, $message, $context ) {
				WP_Stub::$logs[] = array( 'level' => $level, 'message' => $message, 'context' => $context );
			}
		};
	}
}
