<?php
namespace WCPOS\WooCommercePOS\MercadoPagoTerminal\Utils;

final class Money {
	public static function to_amount( $value ): string {
		return number_format( self::to_cents( $value ) / 100, 2, '.', '' );
	}

	public static function to_cents( $value ): int {
		if ( ! is_numeric( $value ) || ! is_finite( (float) $value ) || (float) $value < 0 ) {
			throw new \InvalidArgumentException( 'Invalid monetary amount.' );
		}
		return (int) round( (float) $value * 100 + 1e-8, 0, PHP_ROUND_HALF_UP );
	}

	public static function equals( $a, $b ): bool {
		return self::to_cents( $a ) === self::to_cents( $b );
	}
}
