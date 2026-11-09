<?php
namespace WCPOS\WooCommercePOS\MercadoPagoTerminal\Tests\Conformance;
use WCPOS\WooCommercePOSPro\Tests\Conformance\Conformance_Fixture;
use WCPOS\WooCommercePOSPro\Tests\Conformance\Provider_Conformance_Test_Case;
require_once __DIR__ . '/Mercado_Pago_Conformance_Fixture.php';
class Test_Mercado_Pago_Provider_Conformance extends Provider_Conformance_Test_Case {
	protected function fixture(): Conformance_Fixture { return new Mercado_Pago_Conformance_Fixture(); }
}
