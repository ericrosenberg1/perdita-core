<?php
/**
 * Perdita Core smoke tests: the Stripe Checkout Session request.
 *
 * Loaded by tests/smoke.php. Calls create_stripe_session() with real order
 * totals and intercepts the outgoing request at pre_http_request, so the
 * assertions read the URL, headers and form body WordPress would actually
 * send, then answer in Stripe's documented response shapes.
 *
 * What this pins:
 *  - the amount Stripe charges (sum of unit_amount x quantity) equals the
 *    order total in minor units, shipping and tax included.
 *  - the secret key goes out as a Bearer token and never in the body.
 *  - the success URL carries Stripe's literal {CHECKOUT_SESSION_ID}
 *    placeholder, which the success page needs to hand out downloads.
 *  - a Stripe error, a transport failure, or a 200 without a url each
 *    become a WP_Error instead of a redirect to nowhere.
 *  - with no secret key nothing is sent at all.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

echo "\n--- sales checkout session request (tests/smoke-sales-checkout.php) ---\n";

require_once PERDITA_CORE_DIR . 'inc/modules/sales/class-perdita-sales.php';

$sco_orig_opt = get_option( Perdita_Sales::OPTION, null );
$sco_key      = 'sk_test_smoke_' . wp_generate_password( 16, false );
$sco_settings = Perdita_Sales::settings();

$sco_settings['secret_key'] = Perdita_Crypto::encrypt( $sco_key );
$sco_settings['currency']   = 'USD';
$sco_settings['tax_rate']   = 8.25;
update_option( Perdita_Sales::OPTION, $sco_settings );

$sco_sales   = new Perdita_Sales( perdita_core() );
$sco_session = new ReflectionMethod( 'Perdita_Sales', 'create_stripe_session' );
if ( PHP_VERSION_ID < 80100 ) {
	$sco_session->setAccessible( true );
}

$sco_digital = wp_insert_post( array( 'post_type' => Perdita_Sales::CPT_PRODUCT, 'post_title' => 'Smoke Ebook', 'post_status' => 'publish' ) );
update_post_meta( $sco_digital, '_perdita_sales_price', '19.99' );
update_post_meta( $sco_digital, '_perdita_sales_type', 'digital' );
$sco_physical = wp_insert_post( array( 'post_type' => Perdita_Sales::CPT_PRODUCT, 'post_title' => 'Smoke Mug', 'post_status' => 'publish' ) );
update_post_meta( $sco_physical, '_perdita_sales_price', '12.50' );
update_post_meta( $sco_physical, '_perdita_sales_type', 'physical' );
update_post_meta( $sco_physical, '_perdita_sales_shipping', '4.95' );

$sco_totals = $sco_sales->compute_totals( array( $sco_digital => 3, $sco_physical => 2 ) );
$sco_order  = 987654;

$sco_seen     = array();
$sco_response = null;
$sco_intercept = function ( $pre, $args, $url ) use ( &$sco_seen, &$sco_response ) {
	if ( 0 !== strpos( $url, 'https://api.stripe.com/' ) ) {
		return $pre;
	}
	$sco_seen[] = array( 'url' => $url, 'args' => $args );
	return $sco_response;
};
add_filter( 'pre_http_request', $sco_intercept, 10, 3 );

$sco_ok_body  = array(
	'id'     => 'cs_test_smoke123',
	'object' => 'checkout.session',
	'url'    => 'https://checkout.stripe.com/c/pay/cs_test_smoke123',
);
$sco_response = array(
	'headers'  => array( 'content-type' => 'application/json' ),
	'body'     => wp_json_encode( $sco_ok_body ),
	'response' => array( 'code' => 200, 'message' => 'OK' ),
	'cookies'  => array(),
	'filename' => null,
);
$sco_result = $sco_session->invoke( $sco_sales, $sco_order, $sco_totals, 'buyer@example.test' );

$ok( 1 === count( $sco_seen ), 'sales checkout: exactly one request goes to Stripe' );
$sco_req  = $sco_seen ? $sco_seen[0] : array( 'url' => '', 'args' => array() );
$sco_args = $sco_req['args'];
$sco_body = isset( $sco_args['body'] ) && is_array( $sco_args['body'] ) ? $sco_args['body'] : array();
$ok( 'https://api.stripe.com/v1/checkout/sessions' === $sco_req['url'], 'sales checkout: posts to /v1/checkout/sessions' );
$ok( isset( $sco_args['method'] ) && 'POST' === $sco_args['method'], 'sales checkout: the request is a POST' );
$ok( isset( $sco_args['headers']['Authorization'] ) && 'Bearer ' . $sco_key === $sco_args['headers']['Authorization'], 'sales checkout: the decrypted secret key goes out as a Bearer token' );
$ok( false === strpos( http_build_query( $sco_body ), rawurlencode( $sco_key ) ) && false === strpos( http_build_query( $sco_body ), $sco_key ), 'sales checkout: the secret key never appears in the form body' );
$ok( 'payment' === ( $sco_body['mode'] ?? '' ), 'sales checkout: mode is payment' );
$ok( (string) $sco_order === ( $sco_body['client_reference_id'] ?? '' ) && (string) $sco_order === ( $sco_body['metadata']['order_id'] ?? '' ), 'sales checkout: client_reference_id and metadata[order_id] carry the order id the webhook looks up' );
$ok( 'buyer@example.test' === ( $sco_body['customer_email'] ?? '' ), 'sales checkout: customer_email is the buyer\'s address' );
$ok( false !== strpos( (string) ( $sco_body['success_url'] ?? '' ), '&session_id={CHECKOUT_SESSION_ID}' ), 'sales checkout: success_url carries Stripe\'s literal {CHECKOUT_SESSION_ID} placeholder' );
$ok( false !== strpos( (string) ( $sco_body['success_url'] ?? '' ), 'order=' . $sco_order ), 'sales checkout: success_url names the order' );
$sco_ttl = (int) ( $sco_body['expires_at'] ?? 0 ) - time();
$ok( $sco_ttl >= 30 * MINUTE_IN_SECONDS - 5 && $sco_ttl <= DAY_IN_SECONDS, 'sales checkout: expires_at sits inside Stripe\'s 30 minute to 24 hour window' );

// Rebuild the line items exactly as Stripe will read them.
$sco_lines = array();
foreach ( $sco_body as $k => $v ) {
	if ( preg_match( '/^line_items\[(\d+)\]\[(.+)\]$/', (string) $k, $m ) ) {
		$sco_lines[ (int) $m[1] ][ $m[2] ] = $v;
	}
}
ksort( $sco_lines );
$sco_charged = 0;
$sco_names   = array();
$sco_curr_ok = true;
foreach ( $sco_lines as $line ) {
	$sco_charged += (int) $line['price_data][unit_amount'] * (int) $line['quantity'];
	$sco_names[]  = $line['price_data][product_data][name'];
	$sco_curr_ok  = $sco_curr_ok && 'usd' === $line['price_data][currency'];
}
$ok( 4 === count( $sco_lines ), 'sales checkout: one line per product plus a Shipping and a Tax line' );
$ok( array( 'Smoke Ebook', 'Smoke Mug', 'Shipping', 'Tax' ) === $sco_names, 'sales checkout: line names are the product titles, then Shipping, then Tax' );
$ok( $sco_curr_ok, 'sales checkout: every line is in the store currency, lowercased as Stripe expects' );
$ok( '1999' === ( $sco_lines[0]['price_data][unit_amount'] ?? '' ) && '3' === ( $sco_lines[0]['quantity'] ?? '' ), 'sales checkout: unit_amount is the server-side price in cents (19.99 becomes 1999) with the cart quantity' );
$ok( Perdita_Sales::to_minor_units( $sco_totals['total'] ) === $sco_charged, 'sales checkout: Stripe charges exactly the order total (' . $sco_charged . ' cents), shipping and tax included' );
$ok( is_array( $sco_result ) && 'cs_test_smoke123' === $sco_result['id'] && $sco_ok_body['url'] === $sco_result['url'], 'sales checkout: a 200 session returns its id and hosted url' );

// Stripe's documented error shape.
$sco_response = array(
	'headers'  => array( 'content-type' => 'application/json' ),
	'body'     => wp_json_encode( array( 'error' => array( 'type' => 'invalid_request_error', 'code' => 'api_key_expired', 'message' => 'Expired API Key provided: sk_test_***' ) ) ),
	'response' => array( 'code' => 401, 'message' => 'Unauthorized' ),
	'cookies'  => array(),
	'filename' => null,
);
$sco_result = $sco_session->invoke( $sco_sales, $sco_order, $sco_totals, 'buyer@example.test' );
$ok( is_wp_error( $sco_result ) && 'stripe_error' === $sco_result->get_error_code(), 'sales checkout: a Stripe 401 error body becomes a stripe_error, not a redirect' );
$ok( is_wp_error( $sco_result ) && false === strpos( $sco_result->get_error_message(), 'sk_test' ), 'sales checkout: the buyer-facing message never echoes Stripe\'s error text' );

$sco_response = array(
	'headers'  => array( 'content-type' => 'application/json' ),
	'body'     => wp_json_encode( array( 'id' => 'cs_test_nourl', 'object' => 'checkout.session', 'url' => null ) ),
	'response' => array( 'code' => 200, 'message' => 'OK' ),
	'cookies'  => array(),
	'filename' => null,
);
$sco_result = $sco_session->invoke( $sco_sales, $sco_order, $sco_totals, 'buyer@example.test' );
$ok( is_wp_error( $sco_result ), 'sales checkout: a 200 session with no hosted url is an error, not a redirect to an empty location' );

$sco_response = new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
$sco_result   = $sco_session->invoke( $sco_sales, $sco_order, $sco_totals, 'buyer@example.test' );
$ok( is_wp_error( $sco_result ) && 'stripe_unreachable' === $sco_result->get_error_code(), 'sales checkout: a transport failure becomes stripe_unreachable' );

// No key: nothing leaves the site.
$sco_settings['secret_key'] = '';
update_option( Perdita_Sales::OPTION, $sco_settings );
$sco_seen   = array();
$sco_result = $sco_session->invoke( $sco_sales, $sco_order, $sco_totals, 'buyer@example.test' );
$ok( is_wp_error( $sco_result ) && 'no_key' === $sco_result->get_error_code() && array() === $sco_seen, 'sales checkout: with no secret key the store says so and sends nothing to Stripe' );

remove_filter( 'pre_http_request', $sco_intercept, 10 );
wp_delete_post( $sco_digital, true );
wp_delete_post( $sco_physical, true );
if ( null === $sco_orig_opt ) {
	delete_option( Perdita_Sales::OPTION );
} else {
	update_option( Perdita_Sales::OPTION, $sco_orig_opt );
}
unset( $sco_orig_opt, $sco_key, $sco_settings, $sco_sales, $sco_session, $sco_digital, $sco_physical, $sco_totals, $sco_order, $sco_seen, $sco_response, $sco_intercept, $sco_ok_body, $sco_result, $sco_req, $sco_args, $sco_body, $sco_ttl, $sco_lines, $sco_charged, $sco_names, $sco_curr_ok );
