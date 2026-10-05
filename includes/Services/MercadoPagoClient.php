<?php
namespace WCPOS\WooCommercePOS\MercadoPagoTerminal\Services;

use WCPOS\WooCommercePOS\MercadoPagoTerminal\Logger;

class MercadoPagoClient {
	public const BASE_URL = 'https://api.mercadopago.com';
	private $access_token;
	private $default_timeout;

	public function __construct( string $access_token, int $default_timeout = 0 ) {
		$this->access_token    = $access_token;
		$this->default_timeout = $default_timeout;
	}

	public function has_access_token(): bool { return '' !== trim( $this->access_token ); }

	public function list_terminals( int $limit = 50, int $offset = 0, string $store_id = '', string $pos_id = '', int $timeout = 0 ): array {
		$path = '/terminals/v1/list?limit=' . $limit . '&offset=' . $offset;
		if ( '' !== $store_id ) { $path .= '&store_id=' . rawurlencode( $store_id ); }
		if ( '' !== $pos_id ) { $path .= '&pos_id=' . rawurlencode( $pos_id ); }
		return $this->request( 'GET', $path, null, '', $timeout );
	}

	public function set_operating_mode( string $terminal_id, string $operating_mode ): array {
		if ( ! in_array( $operating_mode, array( 'PDV', 'STANDALONE' ), true ) ) {
			throw new \InvalidArgumentException( 'Invalid terminal operating mode.' );
		}
		return $this->request( 'PATCH', '/terminals/v1/setup', array( 'terminals' => array( array( 'id' => $terminal_id, 'operating_mode' => $operating_mode ) ) ) );
	}

	public function create_order( array $payload, string $idempotency_key ): array {
		if ( '' === trim( $idempotency_key ) ) { throw new \InvalidArgumentException( 'An idempotency key is required.' ); }
		return $this->request( 'POST', '/v1/orders', $payload, $idempotency_key );
	}

	public function get_order( string $order_id ): array {
		return $this->request( 'GET', '/v1/orders/' . rawurlencode( $order_id ) );
	}

	public function cancel_order( string $order_id, string $idempotency_key ): array {
		if ( '' === trim( $idempotency_key ) ) { throw new \InvalidArgumentException( 'An idempotency key is required.' ); }
		return $this->request( 'POST', '/v1/orders/' . rawurlencode( $order_id ) . '/cancel', null, $idempotency_key );
	}

	public function refund_order( string $order_id, string $idempotency_key, ?array $payload = null ): array {
		if ( '' === trim( $idempotency_key ) ) { throw new \InvalidArgumentException( 'An idempotency key is required.' ); }
		return $this->request( 'POST', '/v1/orders/' . rawurlencode( $order_id ) . '/refund', $payload, $idempotency_key );
	}

	public function simulate_event( string $order_id, string $status ): array {
		return $this->request( 'POST', '/v1/orders/' . rawurlencode( $order_id ) . '/events', array( 'status' => $status ) );
	}

	private function request( string $method, string $path, ?array $body = null, string $idempotency_key = '', int $timeout = 0 ): array {
		if ( ! $this->has_access_token() ) {
			Logger::log_api_error( 'Mercado Pago access token is missing.', array( 'method' => $method, 'path' => $path ) );
			throw new MercadoPagoApiException( 'Mercado Pago access token is missing.' );
		}
		$args = array(
			'method'  => $method,
			'timeout' => $timeout > 0 ? $timeout : ( $this->default_timeout > 0 ? $this->default_timeout : 30 ),
			'headers' => array( 'Authorization' => 'Bearer ' . $this->access_token, 'Content-Type' => 'application/json' ),
		);
		if ( '' !== $idempotency_key ) { $args['headers']['X-Idempotency-Key'] = $idempotency_key; }
		if ( null !== $body ) { $args['body'] = wp_json_encode( $body ); }
		Logger::log( 'Mercado Pago API request: ' . $method . ' ' . $path, array( 'method' => $method, 'url' => self::BASE_URL . $path, 'headers' => $args['headers'], 'body' => $body, 'timeout' => $args['timeout'] ), 'debug' );
		$start = Logger::timer();
		$response = wp_remote_request( self::BASE_URL . $path, $args );
		$duration = Logger::elapsed_ms( $start );
		if ( is_wp_error( $response ) ) {
			Logger::log_api_error( 'Mercado Pago API transport error: ' . $response->get_error_message(), array( 'method' => $method, 'path' => $path, 'duration_ms' => $duration, 'error_code' => $response->get_error_code() ) );
			throw new MercadoPagoApiException( 'Mercado Pago API request failed.', 0 );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$raw    = (string) wp_remote_retrieve_body( $response );
		$data   = '' === $raw ? array() : json_decode( $raw, true );
		$headers = function_exists( 'wp_remote_retrieve_headers' ) ? wp_remote_retrieve_headers( $response ) : array();
		$context = array( 'method' => $method, 'path' => $path, 'status' => $status, 'duration_ms' => $duration, 'response_headers' => $headers instanceof \Traversable ? iterator_to_array( $headers ) : (array) $headers, 'body' => '' !== $raw && is_array( $data ) ? $data : $raw );
		Logger::log( sprintf( 'Mercado Pago API response: %s %s HTTP %d in %d ms', $method, $path, $status, $duration ), $context, 'debug' );
		if ( $status < 200 || $status >= 300 ) {
			$data = is_array( $data ) ? $data : array();
			$message = $data['errors'][0]['message'] ?? $data['message'] ?? $data['error'] ?? 'Mercado Pago API error.';
			$code    = $data['errors'][0]['code'] ?? $data['error'] ?? '';
			Logger::log_api_error( sprintf( 'Mercado Pago API error (%s %s, HTTP %d): %s', $method, $path, $status, $message ), $context + array( 'error_code' => $code ) );
			throw new MercadoPagoApiException( $message, $status, $code, $data );
		}
		if ( ! is_array( $data ) ) {
			Logger::log_api_error( 'Mercado Pago API returned an invalid response.', $context + array( 'error_code' => '' ) );
			throw new MercadoPagoApiException( 'Mercado Pago API returned an invalid response.', $status );
		}
		return $data;
	}
}
