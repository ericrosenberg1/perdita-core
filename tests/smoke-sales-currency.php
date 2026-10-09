<?php
/**
 * Perdita Core smoke tests: Stripe amounts in the store's currency.
 *
 * Loaded by tests/smoke.php. Until 2026-10-09 to_minor_units() multiplied
 * every amount by 100, so a store set to JPY, KRW or any other zero-decimal
 * currency sent Stripe 100 times the price and the buyer was charged that.
 * This pins the conversion table and then reads a real JPY Checkout Session
 * request off pre_http_request.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

echo "\n--- sales currency minor units (tests/smoke-sales-currency.php) ---\n";

require_once PERDITA_CORE_DIR . 'inc/modules/sales/class-perdita-sales.php';

$ok( 1999 === Perdita_Sales::to_minor_units( 19.99, 'USD' ), 'currency: 19.99 USD is 1999' );
$ok( 1999 === Perdita_Sales::to_minor_units( 19.99, 'eur' ), 'currency: the code is case-insensitive (eur)' );
$ok( 1500 === Perdita_Sales::to_minor_units( 1500, 'JPY' ), 'currency: 1500 JPY is 1500, not 150000' );
$ok( 25000 === Perdita_Sales::to_minor_units( 25000, 'KRW' ), 'currency: 25000 KRW is 25000' );
$ok( 50000 === Perdita_Sales::to_minor_units( 500, 'ISK' ), 'currency: 500 ISK is 50000 (Stripe keeps ISK two-decimal with 00)' );
$ok( 50000 === Perdita_Sales::to_minor_units( 499.6, 'ISK' ), 'currency: a fractional ISK amount rounds to a whole krona first' );
$ok( 12340 === Perdita_Sales::to_minor_units( 12.34, 'KWD' ), 'currency: 12.34 KWD is 12340, thousandths ending in 0' );
$ok( 1500.0 === Perdita_Sales::from_minor_units( 1500, 'JPY' ), 'currency: from_minor_units(1500, JPY) is 1500' );
$ok( 19.99 === Perdita_Sales::from_minor_units( 1999, 'USD' ), 'currency: from_minor_units(1999, USD) is 19.99' );
$ok( 12.34 === Perdita_Sales::from_minor_units( 12340, 'KWD' ), 'currency: from_minor_units(12340, KWD) is 12.34' );
$ok( 0 === Perdita_Sales::currency_decimals( 'JPY' ) && 2 === Perdita_Sales::currency_decimals( 'USD' ), 'currency: JPY has no decimals, USD has two' );

// A JPY store, end to end through the request builder.
$scu_orig_opt = get_option( Perdita_Sales::OPTION, null );
$scu_settings = Perdita_Sales::settings();

$scu_settings['secret_key'] = Perdita_Crypto::encrypt( 'sk_test_smoke_' . wp_generate_password( 16, false ) );
$scu_settings['currency']   = 'JPY';
$scu_settings['tax_rate']   = 8.25;
update_option( Perdita_Sales::OPTION, $scu_settings );

$scu_sales   = new Perdita_Sales( perdita_core() );
$scu_session = new ReflectionMethod( 'Perdita_Sales', 'create_stripe_session' );
if ( PHP_VERSION_ID < 80100 ) {
	$scu_session->setAccessible( true );
}
$scu_product = wp_insert_post( array( 'post_type' => Perdita_Sales::CPT_PRODUCT, 'post_title' => 'Smoke Tea', 'post_status' => 'publish' ) );
update_post_meta( $scu_product, '_perdita_sales_price', '1500' );
update_post_meta( $scu_product, '_perdita_sales_type', 'physical' );
update_post_meta( $scu_product, '_perdita_sales_shipping', '700' );
$scu_totals = $scu_sales->compute_totals( array( $scu_product => 3 ) );

$ok( 4500.0 === (float) $scu_totals['subtotal'] && 2100.0 === (float) $scu_totals['shipping'], 'currency: JPY subtotal 4500 and shipping 2100' );
$ok( 545.0 === (float) $scu_totals['tax'], 'currency: JPY tax rounds to a whole yen (8.25% of 6600 is 544.5, stored as 545)' );

$scu_body      = array();
$scu_intercept = function ( $pre, $args, $url ) use ( &$scu_body ) {
	if ( 0 !== strpos( $url, 'https://api.stripe.com/' ) ) {
		return $pre;
	}
	$scu_body = $args['body'];
	return array(
		'headers'  => array( 'content-type' => 'application/json' ),
		'body'     => wp_json_encode( array( 'id' => 'cs_test_jpy', 'object' => 'checkout.session', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_jpy' ) ),
		'response' => array( 'code' => 200, 'message' => 'OK' ),
		'cookies'  => array(),
		'filename' => null,
	);
};
add_filter( 'pre_http_request', $scu_intercept, 10, 3 );
$scu_session->invoke( $scu_sales, 4242, $scu_totals, 'buyer@example.test' );
remove_filter( 'pre_http_request', $scu_intercept, 10 );

$scu_charged = 0;
for ( $i = 0; isset( $scu_body[ "line_items[$i][quantity]" ] ); $i++ ) {
	$scu_charged += (int) $scu_body[ "line_items[$i][price_data][unit_amount]" ] * (int) $scu_body[ "line_items[$i][quantity]" ];
}
$ok( 'jpy' === ( $scu_body['line_items[0][price_data][currency]'] ?? '' ), 'currency: the session is in jpy' );
$ok( '1500' === ( $scu_body['line_items[0][price_data][unit_amount]'] ?? '' ), 'currency: a 1500 JPY item goes to Stripe as unit_amount 1500' );
$ok( 7145 === $scu_charged && 7145.0 === (float) $scu_totals['total'], 'currency: Stripe charges 7145 JPY, the same as the stored order total' );

wp_delete_post( $scu_product, true );
if ( null === $scu_orig_opt ) {
	delete_option( Perdita_Sales::OPTION );
} else {
	update_option( Perdita_Sales::OPTION, $scu_orig_opt );
}
unset( $scu_orig_opt, $scu_settings, $scu_sales, $scu_session, $scu_product, $scu_totals, $scu_body, $scu_intercept, $scu_charged, $i );
