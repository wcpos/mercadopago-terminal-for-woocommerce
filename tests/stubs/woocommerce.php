<?php
if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
	class WC_Payment_Gateway {
		public string $id = '';
		public string $method_title = '';
		public string $method_description = '';
		public $title = null;
		public $description = null;
		public array $supports = array();
		public array $form_fields = array();
		protected array $settings = array();
		public function init_settings(): void { $this->settings = (array) get_option( 'woocommerce_' . $this->id . '_settings', array() ); }
		public function get_option( $key, $default = '' ) { return $this->settings[ $key ] ?? $default; }
		public function process_admin_options() { return true; }
		public function admin_options(): void {}
	}
}

class MPTFWC_Test_Order {
	public int $id;
	public array $meta = array();
	public array $notes = array();
	public bool $paid = false;
	public string $transaction_id = '';
	public int $payment_complete_calls = 0;
	public string $total = '24.00';
	public string $currency = 'MXN';
	public string $payment_method = '';
	public string $payment_method_title = '';
	public int $save_calls = 0;

	public function __construct( $id = 123 ) { $this->id = $id; }
	public function get_id() { return $this->id; }
	public function get_order_key() { return 'key'; }
	public function get_checkout_order_received_url() { return '/checkout/order-received/' . $this->id . '/?key=' . $this->get_order_key(); }
	public function get_meta( $key, $single = true ) { return $this->meta[ $key ] ?? ( $single ? '' : array() ); }
	public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
	public function delete_meta_data( $key ) { unset( $this->meta[ $key ] ); }
	public function add_order_note( $note ) { $this->notes[] = $note; }
	public function save() { $this->save_calls++; }
	public function is_paid() { return $this->paid; }
	public function get_transaction_id() { return $this->transaction_id; }
	public function set_transaction_id( $id ) { $this->transaction_id = $id; }
	public function get_total() { return $this->total; }
	public function get_currency() { return $this->currency; }
	public function get_payment_method() { return $this->payment_method; }
	public function get_payment_method_title() { return $this->payment_method_title; }
	public function set_payment_method( $method ) { $this->payment_method = $method; }
	public function set_payment_method_title( $title ) { $this->payment_method_title = $title; }
	public function payment_complete( $id = '' ) {
		$this->payment_complete_calls++;
		$this->paid = true;
		if ( $id ) { $this->transaction_id = $id; }
	}
}

if ( ! function_exists( 'wc_get_order' ) ) { function wc_get_order( $id ) { return $GLOBALS['mptfwc_orders'][ $id ] ?? null; } }
if ( ! function_exists( 'wc_get_orders' ) ) { function wc_get_orders( $args ) { return $GLOBALS['mptfwc_order_query_results'] ?? array(); } }

/** The options-table queries PaymentLock runs, including atomic INSERT IGNORE. */
class MPTFWC_Fake_Wpdb {
	public $options = 'wp_options';
	public $rows = array();
	/** @var callable|null Runs once before the next INSERT to stage a competing request. */
	public $before_insert;

	public function prepare( $query, ...$args ) {
		return json_encode( array( $query, $args ) );
	}

	public function query( $prepared ) {
		list( $sql, $args ) = json_decode( $prepared, true );
		$sql = preg_replace( '/\s+/', ' ', trim( $sql ) );
		if ( "INSERT IGNORE INTO {$this->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')" === $sql ) {
			if ( $this->before_insert ) {
				$callback = $this->before_insert;
				$this->before_insert = null;
				$callback();
			}
			if ( array_key_exists( $args[0], $this->rows ) ) { return 0; }
			$this->rows[ $args[0] ] = $args[1];
			return 1;
		}
		if ( "DELETE FROM {$this->options} WHERE option_name = %s AND option_value = %s" === $sql ) {
			if ( isset( $this->rows[ $args[0] ] ) && $this->rows[ $args[0] ] === $args[1] ) {
				unset( $this->rows[ $args[0] ] );
				return 1;
			}
			return 0;
		}
		throw new RuntimeException( 'fake wpdb: unsupported query: ' . $sql );
	}

	public function get_var( $prepared ) {
		list( $sql, $args ) = json_decode( $prepared, true );
		$sql = preg_replace( '/\s+/', ' ', trim( $sql ) );
		if ( "SELECT option_value FROM {$this->options} WHERE option_name = %s" === $sql ) {
			return $this->rows[ $args[0] ] ?? null;
		}
		throw new RuntimeException( 'fake wpdb: unsupported query: ' . $sql );
	}
}
