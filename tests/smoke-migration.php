<?php
/**
 * Moving a live site onto Perdita: the AIOSEO settings import, the AIOSEO tag
 * translator, archive canonicals, manual excerpts, literal titles, the stale
 * llms.txt cleanup, the Cloudflare-aware client IP and the page cache's
 * early-serve drop-in.
 *
 * Every case here came from moving hsatracker.org off All in One SEO, WP
 * Super Cache, Akismet and Wordfence on 2026-10-21.
 *
 * tests/smoke.php requires every tests/smoke-*.php in the same scope, so $ok()
 * and the counters are the ones it defines. Everything here restores what it
 * touched.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

// --- AIOSEO tags to Perdita tokens ---
require_once PERDITA_CORE_DIR . 'inc/class-perdita-seo-variables.php';
$ok( '%title% %sep% %sitename%' === Perdita_SEO_Variables::from_aioseo( '#post_title #separator_sa #site_title' ), 'migration: AIOSEO\'s default title format translates to Perdita tokens' );
$ok( '%title%' === Perdita_SEO_Variables::from_aioseo( '#post_title' ), 'migration: a bare #post_title translates, so that page keeps its title without the site name' );
$ok( 'Top #1 tips' === Perdita_SEO_Variables::from_aioseo( 'Top #1 tips' ), 'migration: a "#" before a digit is plain text and stays' );
$ok( null === Perdita_SEO_Variables::from_aioseo( '#author_bio' ), 'migration: a tag with no Perdita twin is refused, never printed' );
$ok( '%excerpt%' === Perdita_SEO_Variables::from_aioseo( '#post_excerpt_only' ), 'migration: the longest tag name wins (#post_excerpt_only is not #post_excerpt plus "_only")' );

// --- AIOSEO site-wide settings import ---
$mig_store      = perdita_core()->seo;
$mig_seo_option = get_option( Perdita_SEO_Store::OPTION );
$mig_aio        = get_option( 'aioseo_options' );
$mig_aio_dyn    = get_option( 'aioseo_options_dynamic' );
$mig_front      = get_option( 'show_on_front' );
update_option(
	'aioseo_options',
	wp_json_encode(
		array(
			'searchAppearance' => array(
				'global'   => array(
					'separator'       => '&#45;',
					'siteTitle'       => '#site_title #separator_sa #tagline',
					'metaDescription' => '#tagline',
					'schema'          => array(
						'siteRepresents'   => 'organization',
						'organizationName' => '#site_title',
						'organizationLogo' => '',
					),
				),
				'archives' => array(
					'author' => array( 'show' => false ),
					'date'   => array(
						'show'     => true,
						'advanced' => array( 'robotsMeta' => array( 'default' => true, 'noindex' => false ) ),
					),
				),
				'advanced' => array( 'globalRobotsMeta' => array( 'noindexPaginated' => true ) ),
			),
			'social'           => array(
				'twitter' => array( 'general' => array( 'defaultCardType' => 'summary' ) ),
			),
			'webmasterTools'   => array( 'google' => 'smoke-google-token' ),
			'rssContent'       => array( 'after' => '&lt;p&gt;The post #post_link first appeared on #site_link.&lt;/p&gt;' ),
		)
	)
);
update_option(
	'aioseo_options_dynamic',
	wp_json_encode(
		array(
			'searchAppearance' => array(
				'postTypes'  => array(
					'post'           => array( 'title' => '#post_title #separator_sa #site_title', 'metaDescription' => '#post_excerpt' ),
					'page'           => array( 'title' => '#post_title #separator_sa #site_title', 'metaDescription' => '#author_bio' ),
					'no-such-type-x' => array( 'title' => 'ignored' ),
				),
				'taxonomies' => array(
					'category' => array(
						'title'           => '#taxonomy_title #separator_sa #site_title',
						'metaDescription' => '#taxonomy_description',
						'advanced'        => array( 'robotsMeta' => array( 'default' => false, 'noindex' => true ) ),
					),
				),
			),
		)
	)
);
update_option( 'show_on_front', 'posts' );
$mig_store->reset();
$mig_n   = $mig_store->import_aioseo();
$mig_doc = $mig_store->all();
$ok( $mig_n > 10, 'migration: the AIOSEO import reports the settings it carried (' . $mig_n . ')' );
$ok( '-' === $mig_doc['separator'], 'migration: AIOSEO\'s "&#45;" separator imports as a plain hyphen' );
$ok( '%sitename% %sep% %tagline%' === $mig_doc['home_title'] && '%tagline%' === $mig_doc['home_description'], 'migration: AIOSEO\'s Home Page title and description import as templates, not skipped for their tags' );
$ok( '' === (string) $mig_doc['default_description'], 'migration: AIOSEO\'s home description no longer becomes every page\'s default description' );
$ok( '%title% %sep% %sitename%' === ( $mig_doc['post_types']['post']['title'] ?? '' ) && '%excerpt%' === ( $mig_doc['post_types']['post']['description'] ?? '' ), 'migration: the post type title and description templates import' );
$ok( ! isset( $mig_doc['post_types']['page']['description'] ) && isset( $mig_doc['post_types']['page']['title'] ), 'migration: a template with an untranslatable tag is left at Perdita\'s default, its sibling still imports' );
$ok( ! isset( $mig_doc['post_types']['no-such-type-x'] ), 'migration: a post type this site doesn\'t register is not imported' );
$ok( '%term_title% %sep% %sitename%' === ( $mig_doc['taxonomies']['category']['title'] ?? '' ) && ! empty( $mig_doc['taxonomies']['category']['noindex'] ), 'migration: taxonomy templates and an explicit taxonomy noindex import' );
$ok( true === $mig_doc['noindex']['archive_author'] && false === $mig_doc['noindex']['archive_date'], 'migration: hidden author archives import as noindex, a default date archive stays indexed' );
$ok( true === $mig_doc['noindex_paginated'], 'migration: AIOSEO\'s paginated noindex imports' );
$ok( 'smoke-google-token' === $mig_doc['verify_google'], 'migration: the Google verification code imports, so Search Console stays verified' );
$ok( 'summary' === $mig_doc['twitter_card'] && 'Organization' === $mig_doc['schema_type'], 'migration: the X card type and who the site represents import' );
$ok( '' === (string) $mig_doc['org_name'], 'migration: an organization name of #site_title is left empty, which already means the site title' );
$ok( '<p>The post %%POSTLINK%% first appeared on %%BLOGLINK%%.</p>' === $mig_doc['rss_after'], 'migration: the RSS footer imports with Perdita\'s feed tokens' );
update_option( 'show_on_front', 'page' );
$mig_store->reset();
$mig_store->import_aioseo();
$ok( '' === (string) $mig_store->get( 'home_title' ), 'migration: with a static front page the AIOSEO home title is not imported, that page\'s own SEO applies' );

// Restore everything the import touched.
$mig_store->reset();
if ( false !== $mig_seo_option ) {
	update_option( Perdita_SEO_Store::OPTION, $mig_seo_option );
}
$mig_restore = function ( $name, $value ) {
	if ( false === $value ) {
		delete_option( $name );
	} else {
		update_option( $name, $value );
	}
};
$mig_restore( 'aioseo_options', $mig_aio );
$mig_restore( 'aioseo_options_dynamic', $mig_aio_dyn );
update_option( 'show_on_front', $mig_front );
$mig_store->reset();
if ( false !== $mig_seo_option ) {
	update_option( Perdita_SEO_Store::OPTION, $mig_seo_option );
}
$ok( get_option( Perdita_SEO_Store::OPTION ) == $mig_seo_option, 'migration: the SEO option is restored after the import tests' ); // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- array compare.

// --- image URLs saved under another domain ---
$ok( 0 === Perdita_SEO_Store::attachment_id_for_url( 'https://elsewhere.example/no/uploads-here.png' ), 'migration: a URL outside any uploads folder resolves to nothing' );

// --- archive canonicals ---
require_once PERDITA_CORE_DIR . 'inc/class-perdita-seo.php';
$ok( home_url( '/category/news/' ) === Perdita_SEO::strip_foreign_query_args( home_url( '/category/news/?utm_source=newsletter&cb=123' ) ), 'migration: tracking and cache-buster arguments never reach an archive canonical' );
$ok( false !== strpos( Perdita_SEO::strip_foreign_query_args( home_url( '/?cat=3&utm_medium=x' ) ), 'cat=3' ) && false === strpos( Perdita_SEO::strip_foreign_query_args( home_url( '/?cat=3&utm_medium=x' ) ), 'utm_medium' ), 'migration: a public query var (cat under plain permalinks) stays in the canonical' );

// --- manual excerpts are not cut at 155 ---
$mig_long   = str_repeat( 'Hand written summary words here. ', 6 );
$mig_post   = wp_insert_post( array( 'post_title' => 'Smoke migration excerpt', 'post_status' => 'publish', 'post_excerpt' => trim( $mig_long ), 'post_content' => str_repeat( 'Body text that goes on. ', 40 ) ) );
$mig_post2  = wp_insert_post( array( 'post_title' => 'Smoke migration body', 'post_status' => 'publish', 'post_content' => str_repeat( 'Body text that goes on. ', 40 ) ) );
$mig_ex     = Perdita_SEO_Variables::excerpt( array( 'type' => 'singular', 'post' => get_post( $mig_post ) ) );
$mig_ex2    = Perdita_SEO_Variables::excerpt( array( 'type' => 'singular', 'post' => get_post( $mig_post2 ) ) );
$ok( mb_strlen( $mig_ex ) > 155 && mb_strlen( $mig_ex ) <= Perdita_SEO_Variables::MANUAL_EXCERPT_LENGTH, 'migration: a hand-written excerpt prints whole (' . mb_strlen( $mig_ex ) . ' chars)' );
$ok( mb_strlen( $mig_ex2 ) <= Perdita_SEO_Variables::EXCERPT_LENGTH, 'migration: an excerpt cut from the body still stops at 155' );
wp_delete_post( $mig_post, true );
wp_delete_post( $mig_post2, true );

// --- literal titles ---
if ( ! Perdita_SEO::other_seo_active() && perdita_core()->seo->get( 'enabled', true ) ) {
	$ok( false === has_filter( 'document_title', 'wptexturize' ), 'migration: Perdita prints the <title> as written, no wptexturize turning " - " into an en dash' );
}

// --- a stale llms.txt from AIOSEO ---
require_once PERDITA_CORE_DIR . 'inc/class-perdita-seo-llms.php';
$mig_llms = ABSPATH . 'llms-full.txt';
if ( ! file_exists( $mig_llms ) && wp_is_writable( ABSPATH ) ) {
	file_put_contents( $mig_llms, "# Site\n\n> Generated by All in One SEO v5.0.3, this is an llms.txt file\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	$mig_removed = Perdita_SEO_Llms::remove_foreign_static_files( true );
	$ok( in_array( 'llms-full.txt', $mig_removed, true ) && ! file_exists( $mig_llms ), 'migration: an llms file AIOSEO generated is removed once AIOSEO is off' );
	file_put_contents( $mig_llms, "# Written by hand\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	Perdita_SEO_Llms::remove_foreign_static_files( true );
	$ok( file_exists( $mig_llms ), 'migration: an llms file nobody generated is never touched' );
	wp_delete_file( $mig_llms );
} else {
	echo "SKIP  migration: llms-full.txt already exists in the site root, leaving it alone\n";
}

// --- the visitor IP behind Cloudflare ---
$mig_server = $_SERVER;
$ok( Perdita_Client_IP::in_range( '162.158.1.2', '162.158.0.0/15' ) && ! Perdita_Client_IP::in_range( '162.160.0.1', '162.158.0.0/15' ), 'client ip: IPv4 range math' );
$ok( Perdita_Client_IP::in_range( '2a06:98c7::1', '2a06:98c0::/29' ) && ! Perdita_Client_IP::in_range( '2a06:98c8::1', '2a06:98c0::/29' ), 'client ip: IPv6 range math, including a /29' );
$_SERVER['REMOTE_ADDR']           = '203.0.113.9';
$_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.7';
$ok( '203.0.113.9' === Perdita_Client_IP::get(), 'client ip: a CF-Connecting-IP header sent straight to the origin is ignored' );
$_SERVER['REMOTE_ADDR'] = '172.70.1.1';
$ok( '198.51.100.7' === Perdita_Client_IP::get(), 'client ip: from a Cloudflare edge, the visitor address Cloudflare reports is used' );
$_SERVER['HTTP_CF_CONNECTING_IP'] = 'not-an-ip';
$ok( '172.70.1.1' === Perdita_Client_IP::get(), 'client ip: a malformed Cloudflare header falls back to REMOTE_ADDR' );
$_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.7';
$ok( false === Perdita_Forms::behind_forwarding_proxy(), 'client ip: a verified Cloudflare request counts as having the real address for Forms\' throttle' );
$_SERVER = $mig_server;

// --- page cache early-serve drop-in ---
require_once PERDITA_CORE_DIR . 'inc/modules/caching/class-perdita-caching.php';
$mig_src = Perdita_Caching::dropin_source();
$ok( false !== strpos( $mig_src, Perdita_Caching::DROPIN_MARKER ), 'cache drop-in: the source names itself, so a foreign advanced-cache.php is never overwritten' );

$mig_php = defined( 'PHP_BINARY' ) && is_executable( PHP_BINARY ) && 'cli' === PHP_SAPI ? PHP_BINARY : '';
$mig_dir = trailingslashit( get_temp_dir() ) . 'perdita-dropin-' . wp_generate_password( 6, false ) . '/';
if ( '' !== $mig_php && wp_mkdir_p( $mig_dir ) ) {
	// The same source, pointed at a scratch cache directory.
	$mig_src  = str_replace( var_export( Perdita_Caching::cache_dir(), true ), var_export( $mig_dir, true ), $mig_src ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export
	$mig_drop = $mig_dir . 'advanced-cache.php';
	file_put_contents( $mig_drop, $mig_src ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	file_put_contents( $mig_dir . 'early-serve.on', '1' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	$mig_host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
	$mig_key  = 'https://' . strtolower( $mig_host ) . '/smoke-page/';
	file_put_contents( $mig_dir . sha1( $mig_key ) . '.html', '<!doctype html><p>CACHED</p>' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	file_put_contents( $mig_dir . sha1( $mig_key . '?paged=2' ) . '.html', '<!doctype html><p>PAGE2</p>' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

	$mig_run = function ( array $server, array $get = array(), array $cookie = array(), array $post = array() ) use ( $mig_php, $mig_drop, $mig_host, $mig_dir ) {
		$server = array_merge(
			array(
				'REQUEST_METHOD' => 'GET',
				'HTTP_HOST'      => $mig_host,
				'HTTPS'          => 'on',
				'REQUEST_URI'    => '/smoke-page/',
			),
			$server
		);
		$wrapper = $mig_dir . 'run-' . wp_generate_password( 6, false ) . '.php';
		file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			$wrapper,
			'<?php define( "ABSPATH", "/" ); $_SERVER = array_merge( $_SERVER, ' . var_export( $server, true ) . ' ); $_GET = ' . var_export( $get, true ) . '; $_COOKIE = ' . var_export( $cookie, true ) . '; $_POST = ' . var_export( $post, true ) . '; include ' . var_export( $mig_drop, true ) . '; echo "BYPASS";' // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export
		);
		$out = shell_exec( escapeshellarg( $mig_php ) . ' -n ' . escapeshellarg( $wrapper ) . ' 2>&1' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.shell_exec_shell_exec -- the only way to run a drop-in before WordPress, in a test.
		wp_delete_file( $wrapper );
		return (string) $out;
	};

	$ok( false !== strpos( $mig_run( array() ), 'CACHED' ), 'cache drop-in: a cached page is served before WordPress loads' );
	$ok( false !== strpos( $mig_run( array(), array( 'utm_source' => 'mail', 'gclid' => 'x' ) ), 'CACHED' ), 'cache drop-in: tracking arguments are ignored, the same page is served' );
	$ok( false !== strpos( $mig_run( array( 'REQUEST_URI' => '/smoke-page/?paged=2' ), array( 'paged' => '2' ) ), 'PAGE2' ), 'cache drop-in: a whitelisted pagination argument reaches its own cache entry' );
	$ok( 'BYPASS' === $mig_run( array(), array( 'preview' => 'true' ) ), 'cache drop-in: any other query argument goes to WordPress' );
	$ok( 'BYPASS' === $mig_run( array(), array(), array( 'wordpress_logged_in_abc' => '1' ) ), 'cache drop-in: a logged-in visitor goes to WordPress' );
	$ok( 'BYPASS' === $mig_run( array(), array(), array( 'comment_author_abc' => 'x' ) ), 'cache drop-in: a returning commenter goes to WordPress' );
	$ok( 'BYPASS' === $mig_run( array( 'REQUEST_METHOD' => 'POST' ), array(), array(), array( 'a' => 'b' ) ), 'cache drop-in: a POST goes to WordPress' );
	$ok( 'BYPASS' === $mig_run( array( 'HTTP_HOST' => 'evil.example' ) ), 'cache drop-in: a spoofed Host header goes to WordPress' );
	$ok( 'BYPASS' === $mig_run( array( 'HTTPS' => 'off', 'SERVER_PORT' => '80' ) ), 'cache drop-in: the http copy of an https page is a different entry' );
	wp_delete_file( $mig_dir . 'early-serve.on' );
	$ok( 'BYPASS' === $mig_run( array() ), 'cache drop-in: with the switch file gone it serves nothing (module or plugin turned off)' );
	file_put_contents( $mig_dir . 'early-serve.on', '1' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	touch( $mig_dir . sha1( $mig_key ) . '.html', time() - 100 * DAY_IN_SECONDS );
	$ok( 'BYPASS' === $mig_run( array() ), 'cache drop-in: a page older than the cache lifetime goes to WordPress to be rebuilt' );

	foreach ( (array) glob( $mig_dir . '*' ) as $mig_f ) {
		wp_delete_file( $mig_f );
	}
	@rmdir( $mig_dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
} else {
	echo "SKIP  cache drop-in: no PHP CLI binary to run the drop-in in its own process\n";
}

// Another page cache plugin keeps Perdita's out of the way.
$mig_ap = function ( $plugins ) {
	return array_merge( (array) $plugins, array( 'wp-super-cache/wp-cache.php' ) );
};
add_filter( 'option_active_plugins', $mig_ap );
$ok( 'WP Super Cache' === Perdita_Caching::other_page_cache(), 'cache: an active WP Super Cache is detected by name' );
remove_filter( 'option_active_plugins', $mig_ap );
$ok( '' === Perdita_Caching::other_page_cache() || 'WP Super Cache' !== Perdita_Caching::other_page_cache(), 'cache: without it, no conflict is reported for WP Super Cache' );
$ok( in_array( 'wordpress_logged_in', Perdita_Caching::no_cache_cookies(), true ) && in_array( 'wp-postpass_', Perdita_Caching::no_cache_cookies(), true ), 'cache: the drop-in and the in-WordPress cache share one no-cache cookie list' );
