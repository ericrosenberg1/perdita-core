<?php
/**
 * Perdita Core smoke tests: the Stripe webhook and the success page.
 *
 * Loaded by tests/smoke.php. Drives handle_webhook() with signed events and
 * render_success() with real query strings, then removes everything it made.
 *
 * Regressions pinned here:
 *  - the success page handed out download links to anyone who guessed an
 *    order id (ids are sequential); it now requires the order's own Stripe
 *    session id, which only the buyer's browser carries back from Stripe.
 *  - checkout.session.completed with payment_status 'unpaid' (ACH, SEPA)
 *    marked the order paid before any money arrived.
 *  - stock reserved at checkout never came back when the buyer walked away,
 *    so anyone could empty a product by starting checkouts.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

echo "\n--- sales webhook and success page (tests/smoke-sales-webhook.php) ---\n";

require_once PERDITA_CORE_DIR . 'inc/modules/sales/class-perdita-sales.php';

$swh_orig_opt = get_option( Perdita_Sales::OPTION, null );
$swh_secret   = 'whsec_smoke_' . wp_generate_password( 12, false );
$swh_settings = Perdita_Sales::settings();

$swh_settings['webhook_secret'] = Perdita_Crypto::encrypt( $swh_secret );
update_option( Perdita_Sales::OPTION, $swh_settings );

$swh_sales = new Perdita_Sales( perdita_core() );
$swh_mail  = function () {
	return true; // Short-circuit wp_mail(): the receipt is not what is under test.
};
add_filter( 'pre_wp_mail', $swh_mail );

$swh_event = function ( $type, array $obj ) use ( $swh_sales, $swh_secret ) {
	$payload = wp_json_encode(
		array(
			'id'   => 'evt_smoke_' . wp_generate_password( 8, false ),
			'type' => $type,
			'data' => array( 'object' => $obj ),
		)
	);
	$t   = time();
	$req = new WP_REST_Request( 'POST', '/perdita/v1/sales/webhook' );
	$req->set_body( $payload );
	$req->set_header( 'stripe_signature', 't=' . $t . ',v1=' . hash_hmac( 'sha256', $t . '.' . $payload, $swh_secret ) );
	return $swh_sales->handle_webhook( $req );
};

$swh_product = wp_insert_post(
	array(
		'post_type'   => Perdita_Sales::CPT_PRODUCT,
		'post_title'  => 'Smoke Webhook Product',
		'post_status' => 'publish',
	)
);
update_post_meta( $swh_product, '_perdita_sales_type', 'physical' );
update_post_meta( $swh_product, '_perdita_sales_stock', 1 ); // 3 in stock, 2 already reserved by the order below.

$swh_order = function ( $session, array $extra = array() ) use ( $swh_product ) {
	$id = wp_insert_post(
		array(
			'post_type'   => Perdita_Sales::CPT_ORDER,
			'post_status' => 'publish',
			'post_title'  => 'Smoke Order',
		)
	);
	update_post_meta( $id, '_perdita_sales_status', 'pending' );
	update_post_meta(
		$id,
		'_perdita_sales_items',
		array(
			array(
				'id'         => $swh_product,
				'title'      => 'Smoke Webhook Product',
				'type'       => 'physical',
				'qty'        => 2,
				'unit_price' => 10.0,
			),
		)
	);
	update_post_meta( $id, '_perdita_sales_email', 'buyer@example.com' );
	update_post_meta( $id, '_perdita_sales_session_id', $session );
	foreach ( $extra as $k => $v ) {
		update_post_meta( $id, $k, $v );
	}
	return $id;
};
$swh_stock = function () use ( $swh_product ) {
	wp_cache_delete( $swh_product, 'post_meta' );
	return (int) get_post_meta( $swh_product, '_perdita_sales_stock', true );
};
$swh_status = function ( $id ) {
	wp_cache_delete( $id, 'post_meta' );
	return (string) get_post_meta( $id, '_perdita_sales_status', true );
};

// 1. Delayed payment: 'completed' while unpaid leaves the order pending.
$swh_a = $swh_order( 'cs_smoke_a', array( '_perdita_sales_stock_reserved' => '1' ) );
$swh_r = $swh_event(
	'checkout.session.completed',
	array(
		'id'                  => 'cs_smoke_a',
		'client_reference_id' => (string) $swh_a,
		'payment_status'      => 'unpaid',
	)
);
$ok( 200 === $swh_r->get_status() && 'pending' === $swh_status( $swh_a ), 'sales webhook: a completed session that is still unpaid does not mark the order paid' );
$swh_event(
	'checkout.session.async_payment_succeeded',
	array(
		'id'                  => 'cs_smoke_a',
		'client_reference_id' => (string) $swh_a,
		'payment_status'      => 'paid',
	)
);
$ok( 'paid' === $swh_status( $swh_a ), 'sales webhook: async_payment_succeeded marks the order paid' );

// 2. An expired session returns the reserved stock, exactly once.
$swh_b = $swh_order( 'cs_smoke_b', array( '_perdita_sales_stock_reserved' => '1' ) );
$swh_event( 'checkout.session.expired', array( 'id' => 'cs_smoke_b', 'client_reference_id' => (string) $swh_b ) );
$ok( 'expired' === $swh_status( $swh_b ) && 3 === $swh_stock(), 'sales webhook: an expired checkout returns its reserved stock and closes the order' );
$swh_event( 'checkout.session.expired', array( 'id' => 'cs_smoke_b', 'client_reference_id' => (string) $swh_b ) );
$ok( 3 === $swh_stock(), 'sales webhook: a replayed expired event does not return the stock twice' );

// 3. An expired event naming a different session touches nothing.
$swh_c = $swh_order( 'cs_smoke_c', array( '_perdita_sales_stock_reserved' => '1' ) );
$swh_event( 'checkout.session.expired', array( 'id' => 'cs_smoke_other', 'client_reference_id' => (string) $swh_c ) );
$ok( 'pending' === $swh_status( $swh_c ) && 3 === $swh_stock(), 'sales webhook: an expired event for another session leaves the order and stock alone' );

// 4. A paid order is never reopened.
$swh_event( 'checkout.session.expired', array( 'id' => 'cs_smoke_a', 'client_reference_id' => (string) $swh_a ) );
$ok( 'paid' === $swh_status( $swh_a ) && 3 === $swh_stock(), 'sales webhook: an expired event cannot unpay a paid order or free its stock' );

// 5. A Sales Pro order never reserved stock, so none is returned for it.
$swh_d = $swh_order( 'cs_smoke_d', array( '_perdita_sales_pro_user_id' => 0 ) );
$swh_event( 'checkout.session.async_payment_failed', array( 'id' => 'cs_smoke_d', 'client_reference_id' => (string) $swh_d ) );
$ok( 'failed' === $swh_status( $swh_d ) && 3 === $swh_stock(), 'sales webhook: a failed Sales Pro order closes without inventing stock' );

// 6. The success page needs the order's own session id.
$swh_success = new ReflectionMethod( 'Perdita_Sales', 'render_success' );
if ( PHP_VERSION_ID < 80100 ) {
	$swh_success->setAccessible( true );
}
$swh_get  = $_GET;
$_GET     = array( 'order' => (string) $swh_a );
$swh_html = $swh_success->invoke( $swh_sales );
$ok( false !== strpos( $swh_html, 'could not find that order' ), 'sales success page: an order id alone reveals nothing' );
$_GET     = array(
	'order'      => (string) $swh_a,
	'session_id' => 'cs_smoke_wrong',
);
$swh_html = $swh_success->invoke( $swh_sales );
$ok( false !== strpos( $swh_html, 'could not find that order' ), 'sales success page: a wrong session id reveals nothing' );
$_GET     = array(
	'order'      => (string) $swh_a,
	'session_id' => 'cs_smoke_a',
);
$swh_html = $swh_success->invoke( $swh_sales );
$ok( false !== strpos( $swh_html, 'order is confirmed' ), 'sales success page: the buyer\'s own session id shows the confirmation' );
$_GET = $swh_get;

// Cleanup.
remove_filter( 'pre_wp_mail', $swh_mail );
foreach ( array( $swh_a, $swh_b, $swh_c, $swh_d, $swh_product ) as $swh_id ) {
	wp_delete_post( $swh_id, true );
}
if ( null === $swh_orig_opt ) {
	delete_option( Perdita_Sales::OPTION );
} else {
	update_option( Perdita_Sales::OPTION, $swh_orig_opt );
}
