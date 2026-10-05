<?php
class FakeMercadoPagoClient extends \WCPOS\WooCommercePOS\MercadoPagoTerminal\Services\MercadoPagoClient {
	public $calls = array();
	public $returns = array();

	public function __construct() { parent::__construct( 'TEST-fake' ); }

	public function create_order( array $payload, string $idempotency_key ): array {
		return $this->respond( __FUNCTION__, func_get_args() );
	}

	public function get_order( string $order_id ): array {
		return $this->respond( __FUNCTION__, func_get_args() );
	}

	public function cancel_order( string $order_id, string $idempotency_key ): array {
		return $this->respond( __FUNCTION__, func_get_args() );
	}

	public function list_terminals( int $limit = 50, int $offset = 0, string $store_id = '', string $pos_id = '', int $timeout = 0 ): array {
		return $this->respond( __FUNCTION__, func_get_args() );
	}

	public function set_operating_mode( string $terminal_id, string $operating_mode ): array {
		return $this->respond( __FUNCTION__, func_get_args() );
	}

	private function respond( string $method, array $args ): array {
		$this->calls[] = array( 'method' => $method, 'args' => $args );
		if ( empty( $this->returns[ $method ] ) ) { throw new \LogicException( 'unexpected call: ' . $method ); }
		$result = array_shift( $this->returns[ $method ] );
		if ( $result instanceof \Throwable ) { throw $result; }
		return $result;
	}
}
