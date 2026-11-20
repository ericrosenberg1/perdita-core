<?php
/**
 * Firewall engine benchmark. No WordPress loads.
 *
 *   php tests/bench-firewall.php [iterations]
 *
 * Runs Perdita_Firewall::run() 10,000 times (by default) on each case with
 * every rule category on in block mode, the way an untrusted visitor meets
 * it, and prints the median and the 95th percentile in microseconds.
 * The budget: a plain GET under 50 microseconds median, a 20-field POST
 * under 300.
 *
 * @package Perdita_Core
 */

if ( 'cli' !== PHP_SAPI ) {
	exit;
}
define( 'ABSPATH', __DIR__ . '/' );
require dirname( __DIR__ ) . '/inc/modules/security/firewall/class-perdita-firewall.php';

$iterations = isset( $argv[1] ) ? max( 100, (int) $argv[1] ) : 10000;

$cf = array( '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13', '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22', '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32' );

$config = array(
	'enabled'        => true,
	'mode'           => 'block',
	'rules'          => array_fill_keys( array( 'sqli', 'xss', 'traversal', 'rfi', 'php_upload', 'bad_bots', 'probes', 'xmlrpc' ), true ),
	'exclusions'     => array(),
	'allowlist'      => Perdita_Firewall::parse_ranges( array( '198.51.100.0/24' ) ),
	'blocklist'      => array(),
	'key'            => str_repeat( 'ab', 32 ),
	'cf_ranges'      => Perdita_Firewall::parse_ranges( $cf ),
	'host'           => 'example.org',
	'log_dir'        => '',
	'disable_xmlrpc' => true,
);

$ua     = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_6) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';
$server = array(
	'REQUEST_URI'     => '/shop/?utm_source=news&color=blue&page=2',
	'REQUEST_METHOD'  => 'GET',
	'REMOTE_ADDR'     => '203.0.113.50',
	'SERVER_ADDR'     => '10.0.0.5',
	'HTTP_USER_AGENT' => $ua,
);
$cookie = array(
	'wordpress_test_cookie'      => 'WP Cookie check',
	'woocommerce_items_in_cart'  => '1',
	'_ga'                        => 'GA1.1.123456789.1731900000',
);

$post = array(
	'billing_first_name' => "D'Arcy",
	'billing_last_name'  => "O'Brien",
	'billing_company'    => 'Union Select & Sons',
	'billing_country'    => 'US',
	'billing_address_1'  => '1 Union Select Rd',
	'billing_address_2'  => 'Apt 4',
	'billing_city'       => 'Springfield',
	'billing_state'      => 'IL',
	'billing_postcode'   => '62701',
	'billing_phone'      => '(217) 555-0100',
	'billing_email'      => 'darcy+shop@example.com',
	'shipping_method'    => 'flat_rate:1',
	'payment_method'     => 'stripe',
	'order_comments'     => "Leave it by the door -- thanks! Don't ring the bell, the dog barks.",
	'terms'              => 'on',
	'terms-field'        => '1',
	'woocommerce-process-checkout-nonce' => '9f8e7d6c5b',
	'_wp_http_referer'   => '/?wc-ajax=update_order_review',
	'createaccount'      => '0',
	'ship_to_different_address' => '0',
);

$blocks = '';
$i      = 0;
while ( strlen( $blocks ) < 30000 ) {
	++$i;
	$blocks .= "<!-- wp:heading {\"level\":2} -->\n<h2 class=\"wp-block-heading\">Section $i: how to select a union rep</h2>\n<!-- /wp:heading -->\n"
		. "<!-- wp:paragraph -->\n<p>It's O'Brien's <strong>guide</strong>, with <a href=\"https://example.org/guide/$i?ref=a&amp;b=2\">a link</a> and <em>notes</em> from the team. We met at 1 Union Select Rd and talked about the drop table at the fair.</p>\n<!-- /wp:paragraph -->\n"
		. "<!-- wp:image {\"id\":$i,\"sizeSlug\":\"large\"} -->\n<figure class=\"wp-block-image size-large\"><img src=\"https://example.org/wp-content/uploads/img-$i.jpg\" alt=\"Photo $i\" class=\"wp-image-$i\"/></figure>\n<!-- /wp:image -->\n";
}
$json = json_encode(
	array(
		'id'      => 42,
		'title'   => 'A long post',
		'content' => $blocks,
		'excerpt' => '',
		'status'  => 'publish',
		'meta'    => array( 'footnotes' => '' ),
	)
);

// Large requests: every field gets its first 64 KB and last 8 KB inspected,
// with no request-wide cap. The filler is block editor markup, the most
// needle-dense content a real site sends.
$big_form = function ( $bytes ) use ( $blocks ) {
	$field = substr( str_repeat( $blocks, 3 ), 0, 65536 );
	$out   = array();
	for ( $n = 0; $n * 65536 < $bytes; $n++ ) {
		$out[ 'field_' . $n ] = $field;
	}
	return $out;
};
$big_json = json_encode(
	array(
		'id'      => 42,
		'title'   => 'A very long post',
		'content' => substr( str_repeat( $blocks, (int) ceil( 4194304 / strlen( $blocks ) ) ), 0, 4194304 - 200 ),
		'status'  => 'publish',
	)
);
$post_srv = array_merge( $server, array( 'REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/wp-admin/admin-ajax.php' ) );

$cases = array(
	'plain GET, 3 query args'          => array( $server, array( 'utm_source' => 'news', 'color' => 'blue', 'page' => '2' ), array(), null ),
	'POST, 20 fields (WooCommerce)'    => array( array_merge( $server, array( 'REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/?wc-ajax=checkout' ) ), array( 'wc-ajax' => 'checkout' ), $post, null ),
	'POST, ' . round( strlen( $json ) / 1024 ) . ' KB Gutenberg JSON' => array( array_merge( $server, array( 'REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/wp-json/wp/v2/posts/42?_locale=user', 'CONTENT_TYPE' => 'application/json' ) ), array( '_locale' => 'user' ), array(), $json ),
	'POST, 1 MB form (16 x 64 KB)'     => array( $post_srv, array(), $big_form( 1048576 ), null, 200 ),
	'POST, 1 MB form (one field)'      => array( $post_srv, array(), array( 'content' => substr( str_repeat( $blocks, 40 ), 0, 1048576 ) ), null, 200 ),
	'POST, 8 MB form, anonymous (anomaly-003)' => array( $post_srv, array(), $big_form( 8388608 ), null, 100, 'block' ),
	'POST, 4 MB JSON, anonymous (anomaly-003)' => array( array_merge( $post_srv, array( 'REQUEST_URI' => '/wp-json/wp/v2/posts/42', 'CONTENT_TYPE' => 'application/json' ) ), array(), array(), $big_json, 30, 'block' ),
	'POST, padded repro (blocks)'      => array( $post_srv, array(), array( 'a' => str_repeat( 'x ', 150000 ), 'b' => "1' OR '1'='1" ), null, 2000, 'block' ),
);

printf( "Perdita firewall engine, PHP %s, %d iterations per case\n", PHP_VERSION, $iterations );
foreach ( $cases as $label => $case ) {
	list( $srv, $get, $body_post, $body ) = $case;
	$runs   = isset( $case[4] ) ? min( $iterations, $case[4] ) : $iterations;
	$expect = isset( $case[5] ) ? $case[5] : 'allow';
	$v = Perdita_Firewall::run( $config, $srv, $get, $body_post, $cookie, array(), $body );
	if ( $expect !== $v['action'] ) {
		printf( "  %s: unexpected %s %s on %s\n", $label, $v['action'], $v['rule'], $v['field'] );
	}
	for ( $w = 0; $w < min( 200, $runs ); $w++ ) {
		Perdita_Firewall::run( $config, $srv, $get, $body_post, $cookie, array(), $body );
	}
	$times = array();
	for ( $n = 0; $n < $runs; $n++ ) {
		$t = hrtime( true );
		Perdita_Firewall::run( $config, $srv, $get, $body_post, $cookie, array(), $body );
		$times[] = hrtime( true ) - $t;
	}
	sort( $times );
	$median = $times[ (int) floor( count( $times ) / 2 ) ] / 1000;
	$p95    = $times[ (int) floor( count( $times ) * 0.95 ) ] / 1000;
	printf( "  %-42s median %8.2f us   p95 %8.2f us\n", $label, $median, $p95 );
}
