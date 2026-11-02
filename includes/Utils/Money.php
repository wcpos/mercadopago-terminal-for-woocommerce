<?php
namespace WCPOS\WooCommercePOS\MercadoPagoTerminal\Utils;

final class Money {
	/**
	 * Formats an amount the way Mercado Pago's Orders API wants it: a string
	 * with "two decimals or none".
	 *
	 * Which of the two is not a free choice. A Chilean-peso store (CLP has no
	 * minor unit) had "1.00" and "100.00" refused with property_value /
	 * "'$.transactions.payments[0].amount' - does not match pattern" (live,
	 * 2026-10-15), while Mercado Pago's own examples for such markets use
	 * "120". So a store that prices in whole units (WooCommerce price
	 * decimals = 0) sends whole units; every other store sends two decimals.
	 *
	 * A fractional amount is never rounded away: in a whole-unit store it keeps
	 * its two decimals and Mercado Pago refuses it, which beats charging the
	 * customer a different figure from the order.
	 */
	public static function to_amount( $value ): string {
		$cents    = self::to_cents( $value );
		$decimals = 0 === self::decimals() && 0 === $cents % 100 ? 0 : 2;
		return number_format( $cents / 100, $decimals, '.', '' );
	}

	/** 0 when the store prices in whole units, otherwise 2. */
	public static function decimals(): int {
		return function_exists( 'wc_get_price_decimals' ) && 0 === (int) wc_get_price_decimals() ? 0 : 2;
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
