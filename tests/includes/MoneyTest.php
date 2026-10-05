<?php
use PHPUnit\Framework\TestCase;
use WCPOS\WooCommercePOS\MercadoPagoTerminal\Utils\Money;

class MoneyTest extends TestCase {
	/** @dataProvider amounts */
	public function test_to_amount( $value, string $expected ): void {
		$this->assertSame( $expected, Money::to_amount( $value ) );
	}

	public function amounts(): array {
		return array(
			array( 24, '24.00' ),
			array( '1234.5', '1234.50' ),
			array( 0.105, '0.11' ),
			array( 1.005, '1.01' ),
			array( '19.99', '19.99' ),
			array( '0', '0.00' ),
		);
	}

	public function test_to_cents_and_equals(): void {
		$this->assertSame( 1999, Money::to_cents( '19.99' ) );
		$this->assertSame( 11, Money::to_cents( 0.105 ) );
		$this->assertSame( 101, Money::to_cents( 1.005 ) );
		$this->assertTrue( Money::equals( '24', 24.00 ) );
	}

	/** @dataProvider invalidAmounts */
	public function test_invalid_amounts_throw( $value ): void {
		$this->expectException( InvalidArgumentException::class );
		Money::to_amount( $value );
	}

	public function invalidAmounts(): array {
		return array( array( 'abc' ), array( -1 ), array( NAN ), array( INF ) );
	}
}
