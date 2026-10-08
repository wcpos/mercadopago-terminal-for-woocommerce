<?php
namespace WCPOS\WooCommercePOS\MercadoPagoTerminal\Services;

class TerminalService {
	private $client;
	public function __construct( MercadoPagoClient $client ) { $this->client = $client; }
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

	public function set_pdv_mode( string $terminal_id ): array {
		return $this->client->set_operating_mode( $terminal_id, 'PDV' );
	}
}
