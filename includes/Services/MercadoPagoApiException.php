<?php
namespace WCPOS\WooCommercePOS\MercadoPagoTerminal\Services;

class MercadoPagoApiException extends \RuntimeException {
	private $http_status;
	private $error_code;
	private $body;

	public function __construct( string $message, int $http_status = 0, string $error_code = '', array $body = array() ) {
		parent::__construct( $message );
		$this->http_status = $http_status;
		$this->error_code = $error_code;
		$this->body       = $body;
	}

	public function http_status(): int { return $this->http_status; }
	public function error_code(): string { return $this->error_code; }
	public function body(): array { return $this->body; }
}
