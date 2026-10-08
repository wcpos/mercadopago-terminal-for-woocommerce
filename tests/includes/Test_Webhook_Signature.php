<?php
namespace WCPOS\WooCommercePOS\MercadoPagoTerminal\Tests\Includes;
use WP_UnitTestCase;
use WCPOS\WooCommercePOS\MercadoPagoTerminal\WebhookSignature;

class Test_Webhook_Signature extends WP_UnitTestCase {
	private const SECRET = 'test-webhook-secret';
	private const DATA_ID = 'ORD01JYH1Z1YJN4HZ8J3Q0RB3YP6D';
	private const REQUEST_ID = 'bb56a2f1-6aae-46ac-982e-9dcd3581d08e';
	private const HASH = '39bcbed7e05ab23ae2f86fcde921c6b79e8020f1332965ce277c6c3995b47c81';

	public function test_known_vector(): void {
		$this->assertTrue( WebhookSignature::verify( 'ts=1742505638683,v1=' . self::HASH, self::REQUEST_ID, self::DATA_ID, self::SECRET ) );
	}

	public function test_header_spaces_and_uppercase_hex(): void {
		$this->assertTrue( WebhookSignature::verify( 'ts=1742505638683, v1=' . self::HASH, self::REQUEST_ID, self::DATA_ID, self::SECRET ) );
		$this->assertTrue( WebhookSignature::verify( 'ts=1742505638683,v1=' . strtoupper( self::HASH ), self::REQUEST_ID, self::DATA_ID, self::SECRET ) );
	}

	/** @dataProvider invalid_signature_provider */
	public function test_invalid_signatures( string $header, string $request_id, string $data_id, string $secret ): void {
		$this->assertFalse( WebhookSignature::verify( $header, $request_id, $data_id, $secret ) );
	}

	public static function invalid_signature_provider(): array {
		$header = 'ts=1742505638683,v1=' . self::HASH;
		return array(
			'wrong secret' => array( $header, self::REQUEST_ID, self::DATA_ID, 'wrong-secret' ),
			'wrong data id' => array( $header, self::REQUEST_ID, 'ORDwrong', self::SECRET ),
			'wrong request id' => array( $header, 'wrong-request', self::DATA_ID, self::SECRET ),
			'changed timestamp' => array( 'ts=1742505638684,v1=' . self::HASH, self::REQUEST_ID, self::DATA_ID, self::SECRET ),
			'missing v1' => array( 'ts=1742505638683', self::REQUEST_ID, self::DATA_ID, self::SECRET ),
			'empty v1' => array( 'ts=1742505638683,v1=', self::REQUEST_ID, self::DATA_ID, self::SECRET ),
			'missing timestamp' => array( 'v1=' . self::HASH, self::REQUEST_ID, self::DATA_ID, self::SECRET ),
			'empty timestamp' => array( 'ts=,v1=' . self::HASH, self::REQUEST_ID, self::DATA_ID, self::SECRET ),
			'empty header' => array( '', self::REQUEST_ID, self::DATA_ID, self::SECRET ),
			'empty secret' => array( $header, self::REQUEST_ID, self::DATA_ID, '' ),
		);
	}

	public function test_manifest_omits_missing_parts_and_only_lowercases_alphanumeric_ids(): void {
		$this->assertSame( 'id:ord01jyh1z1yjn4hz8j3q0rb3yp6d;ts:1;', WebhookSignature::manifest( self::DATA_ID, '', '1' ) );
		$this->assertSame( 'id:abc-123;ts:1;', WebhookSignature::manifest( 'abc-123', '', '1' ) );
		$this->assertSame( 'id:ABC-123;ts:1;', WebhookSignature::manifest( 'ABC-123', '', '1' ) );
		$this->assertSame( 'request-id:request;', WebhookSignature::manifest( '', 'request', '' ) );
		$this->assertSame( '', WebhookSignature::manifest( '', '', '' ) );
	}

	public function test_parse_header_keeps_unknown_keys_and_skips_malformed_parts(): void {
		$this->assertSame( array( 'ts' => '1', 'v1' => 'abc', 'extra' => 'a=b' ), WebhookSignature::parse_header( ' ts=1, v1=abc ,extra=a=b,malformed,=no-key, ' ) );
	}
}
