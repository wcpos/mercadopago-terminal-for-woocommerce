<?php
use PHPUnit\Framework\TestCase;
use WCPOS\WooCommercePOS\MercadoPagoTerminal\PaymentLock;

class PaymentLockTest extends TestCase {
	private const KEY = 'mptfwc_lock_order_123_complete_payment';

	protected function setUp(): void {
		WP_Stub::reset();
		$GLOBALS['mptfwc_orders'] = array();
		$GLOBALS['wpdb'] = new MPTFWC_Fake_Wpdb();
	}

	protected function tearDown(): void {
		PaymentLock::release( 123, 'complete_payment' );
	}

	public function test_acquire_and_release(): void {
		$this->assertTrue( PaymentLock::acquire( 123, 'complete_payment' ) );
		$lock = json_decode( $GLOBALS['wpdb']->rows[ self::KEY ], true );
		$this->assertNotEmpty( $lock['token'] );
		$this->assertGreaterThanOrEqual( time(), $lock['expires_at'] );
		PaymentLock::release( 123, 'complete_payment' );
		$this->assertArrayNotHasKey( self::KEY, $GLOBALS['wpdb']->rows );
		$this->assertTrue( PaymentLock::acquire( 123, 'complete_payment' ) );
	}

	public function test_second_acquire_fails_while_held(): void {
		$this->assertTrue( PaymentLock::acquire( 123, 'complete_payment' ) );
		$value = $GLOBALS['wpdb']->rows[ self::KEY ];
		$this->assertFalse( PaymentLock::acquire( 123, 'complete_payment' ) );
		$this->assertSame( $value, $GLOBALS['wpdb']->rows[ self::KEY ] );
	}

	public function test_expired_lock_can_be_taken_over(): void {
		$GLOBALS['wpdb']->rows[ self::KEY ] = json_encode( array( 'token' => 'expired', 'expires_at' => time() - 1 ) );
		$this->assertTrue( PaymentLock::acquire( 123, 'complete_payment' ) );
		$lock = json_decode( $GLOBALS['wpdb']->rows[ self::KEY ], true );
		$this->assertNotSame( 'expired', $lock['token'] );
		$this->assertGreaterThanOrEqual( time(), $lock['expires_at'] );
	}

	public function test_release_does_not_delete_replacement_claim(): void {
		$this->assertTrue( PaymentLock::acquire( 123, 'complete_payment' ) );
		$replacement = json_encode( array( 'token' => 'another-request', 'expires_at' => time() + 120 ) );
		$GLOBALS['wpdb']->rows[ self::KEY ] = $replacement;
		PaymentLock::release( 123, 'complete_payment' );
		$this->assertSame( $replacement, $GLOBALS['wpdb']->rows[ self::KEY ] );
	}

	public function test_with_lock_returns_result_and_releases(): void {
		$this->assertSame( 'result', PaymentLock::with_lock( 123, 'complete_payment', function () { return 'result'; } ) );
		$this->assertArrayNotHasKey( self::KEY, $GLOBALS['wpdb']->rows );
	}

	public function test_with_lock_releases_after_exception(): void {
		try {
			PaymentLock::with_lock( 123, 'complete_payment', function () { throw new RuntimeException( 'callback failed' ); } );
			$this->fail( 'Expected the callback exception.' );
		} catch ( RuntimeException $error ) {
			$this->assertSame( 'callback failed', $error->getMessage() );
		}
		$this->assertArrayNotHasKey( self::KEY, $GLOBALS['wpdb']->rows );
	}

	public function test_with_lock_throws_while_held(): void {
		$this->assertTrue( PaymentLock::acquire( 123, 'complete_payment' ) );
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Another Mercado Pago Terminal operation is already running for this order.' );
		PaymentLock::with_lock( 123, 'complete_payment', function () { $this->fail( 'A competing callback must not run.' ); } );
	}

	public function test_competing_insert_wins_claim(): void {
		$competitor = json_encode( array( 'token' => 'competitor', 'expires_at' => time() + 120 ) );
		$GLOBALS['wpdb']->before_insert = function () use ( $competitor ) {
			$GLOBALS['wpdb']->rows[ self::KEY ] = $competitor;
		};
		$this->assertFalse( PaymentLock::acquire( 123, 'complete_payment' ) );
		PaymentLock::release( 123, 'complete_payment' );
		$this->assertSame( $competitor, $GLOBALS['wpdb']->rows[ self::KEY ] );
	}
}
