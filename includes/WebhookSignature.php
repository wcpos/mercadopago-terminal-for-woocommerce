<?php
namespace WCPOS\WooCommercePOS\MercadoPagoTerminal;

final class WebhookSignature {
	public static function parse_header( string $header ): array {
		$values = array();
		foreach ( explode( ',', $header ) as $part ) {
			$pair = explode( '=', trim( $part ), 2 );
			if ( 2 !== count( $pair ) || '' === trim( $pair[0] ) ) { continue; }
			$values[ trim( $pair[0] ) ] = trim( $pair[1] );
		}
		return $values;
	}

	public static function manifest( string $data_id, string $request_id, string $ts ): string {
		if ( ctype_alnum( $data_id ) ) { $data_id = strtolower( $data_id ); }
		$manifest = '';
		if ( '' !== $data_id ) { $manifest .= 'id:' . $data_id . ';'; }
		if ( '' !== $request_id ) { $manifest .= 'request-id:' . $request_id . ';'; }
		if ( '' !== $ts ) { $manifest .= 'ts:' . $ts . ';'; }
		return $manifest;
	}

	public static function verify( string $header, string $request_id, string $data_id, string $secret ): bool {
		$values = self::parse_header( $header );
		$ts = $values['ts'] ?? '';
		$v1 = $values['v1'] ?? '';
		if ( '' === $secret || '' === $ts || '' === $v1 ) { return false; }
		// No timestamp tolerance: a replayed valid notification only re-fetches the order.
		return hash_equals( hash_hmac( 'sha256', self::manifest( $data_id, $request_id, $ts ), $secret ), strtolower( $v1 ) );
	}
}
