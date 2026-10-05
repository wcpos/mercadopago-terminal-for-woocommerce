<?php
namespace WCPOS\WooCommercePOS\MercadoPagoTerminal\Services;

use WCPOS\WooCommercePOS\MercadoPagoTerminal\Logger;
use WCPOS\WooCommercePOS\MercadoPagoTerminal\Settings;

class TerminalService {
	private $client;

	public function __construct( MercadoPagoClient $client, Settings $settings ) {
		$this->client = $client;
	}

	public function list_terminals( int $timeout = 0 ): array {
		return self::normalize( $this->client->list_terminals( 50, 0, '', '', $timeout ) );
	}

	public function cached_terminals( int $ttl = 300, int $timeout = 8 ): array {
		$rows = get_transient( 'mptfwc_terminal_rows' );
		if ( is_array( $rows ) && $rows ) { return $rows; }
		try {
			$rows = $this->list_terminals( $timeout );
		} catch ( \Exception $e ) {
			$last_good = get_option( 'mptfwc_terminal_rows_last_good', array() );
			if ( $last_good ) { return $last_good; }
			throw $e;
		}
		if ( $rows ) {
			set_transient( 'mptfwc_terminal_rows', $rows, $ttl );
			update_option( 'mptfwc_terminal_rows_last_good', $rows, false );
		}
		return $rows;
	}

	public static function normalize( array $response ): array {
		$terminals = $response['data']['terminals'] ?? null;
		if ( ! is_array( $terminals ) ) { return array(); }
		$result = array();
		foreach ( $terminals as $terminal ) {
			if ( ! is_array( $terminal ) || empty( $terminal['id'] ) || ! is_scalar( $terminal['id'] ) ) { continue; }
			foreach ( array( 'external_pos_id', 'operating_mode', 'store_id', 'pos_id' ) as $field ) {
				if ( isset( $terminal[ $field ] ) && ! is_scalar( $terminal[ $field ] ) ) { continue 2; }
			}
			$id = (string) $terminal['id'];
			$result[] = array(
				'id' => $id,
				'label' => (string) ( ( $terminal['external_pos_id'] ?? '' ) ?: $id ),
				'operating_mode' => (string) ( $terminal['operating_mode'] ?? '' ),
				'store_id' => (string) ( $terminal['store_id'] ?? '' ),
				'pos_id' => (string) ( $terminal['pos_id'] ?? '' ),
			);
		}
		return $result;
	}

	public function find_terminal( string $terminal_id ): ?array {
		foreach ( $this->cached_terminals() as $terminal ) {
			if ( $terminal['id'] === $terminal_id ) { return $terminal; }
		}
		return null;
	}

	public function assert_can_receive_orders( string $terminal_id ): void {
		if ( '' === $terminal_id ) {
			throw new \RuntimeException( __( 'Select a terminal first.', 'mercadopago-terminal-for-woocommerce' ) );
		}
		try {
			$terminal = $this->find_terminal( $terminal_id );
		} catch ( \Throwable $e ) {
			Logger::log( 'Could not list Mercado Pago terminals: ' . $e->getMessage(), array( 'terminal_id' => $terminal_id ), 'warning' );
			return;
		}
		if ( null === $terminal ) {
			Logger::log( 'Selected Mercado Pago terminal was not listed.', array( 'terminal_id' => $terminal_id ), 'warning' );
			return;
		}
		if ( 'PDV' !== strtoupper( $terminal['operating_mode'] ) ) {
			throw new \RuntimeException( sprintf( __( 'Terminal %1$s is in %2$s mode. Switch it to PDV (integrated) mode in the gateway settings, then restart the terminal.', 'mercadopago-terminal-for-woocommerce' ), $terminal_id, $terminal['operating_mode'] ) );
		}
	}

	public function set_pdv_mode( string $terminal_id ): array {
		$response = $this->client->set_operating_mode( $terminal_id, 'PDV' );
		delete_transient( 'mptfwc_terminal_rows' );
		Logger::log( 'Mercado Pago terminal switched to PDV mode.', array( 'terminal_id' => $terminal_id ), 'success' );
		return $response;
	}
}
