<?php
/**
 * Prueba del validador sin WordPress: php tests/validator-test.php
 *
 * @package TaxIDCheckout
 */

define( 'ABSPATH', __DIR__ . '/' );

function apply_filters( $hook, $value ) {
	return $value;
}

function __( $text, $domain = null ) {
	return $text;
}

require dirname( __DIR__ ) . '/includes/class-tax-id-checkout-validator.php';

$cases = array(
	array( 'BR', '529.982.247-25', true ),
	array( 'BR', '529.982.247-26', false ),
	array( 'BR', '111.111.111-11', false ),
	array( 'BR', '11.222.333/0001-81', true ),
	array( 'BR', '11.222.333/0001-82', false ),
	array( 'ES', '12345678Z', true ),
	array( 'ES', '12345678A', false ),
	array( 'CL', '12.345.678-5', true ),
	array( 'CL', '12.345.678-4', false ),
);

$failed = 0;
foreach ( $cases as $case ) {
	list( $country, $value, $expected ) = $case;
	$result = Tax_ID_Checkout_Validator::check( $country, $value );
	$ok     = (bool) $result['valid'] === $expected;
	echo ( $ok ? 'ok    ' : 'FALLA ' ), $country, ' ', $value, PHP_EOL;
	$failed += $ok ? 0 : 1;
}

echo count( $cases ) - $failed, ' de ', count( $cases ), PHP_EOL;
exit( $failed ? 1 : 0 );
