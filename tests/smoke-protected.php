<?php
/**
 * Perdita Core smoke tests: password-protected posts stay protected.
 *
 * Loaded by tests/smoke.php. Regressions:
 *  - %excerpt%, the default description, printed the start of a protected
 *    post's body in meta, Open Graph, Twitter and JSON-LD tags.
 *  - the page cache stored a protected post once any visitor unlocked it and
 *    served the unlocked copy to everyone.
 *  - a robots.txt override replaced core's Disallow: / on a site set to
 *    discourage search engines.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

echo "\n--- password-protected content (tests/smoke-protected.php) ---\n";

$pp_post = wp_insert_post(
	array(
		'post_title'    => 'Smoke Protected SEO Post',
		'post_content'  => 'protected-body-should-never-appear in any tag',
		'post_excerpt'  => 'protected-excerpt-should-never-appear',
		'post_status'   => 'publish',
		'post_password' => 'letmein',
	)
);
$pp_open = wp_insert_post(
	array(
		'post_title'   => 'Smoke Open SEO Post',
		'post_content' => 'open body text for the excerpt',
		'post_status'  => 'publish',
	)
);
$ok( '' === Perdita_SEO_Variables::excerpt( array( 'post' => get_post( $pp_post ) ) ), 'seo: %excerpt% is empty for a password-protected post' );
$ok( false !== strpos( Perdita_SEO_Variables::excerpt( array( 'post' => get_post( $pp_open ) ) ), 'open body text' ), 'seo: %excerpt% still reads an ordinary post' );
wp_delete_post( $pp_post, true );
wp_delete_post( $pp_open, true );

if ( class_exists( 'Perdita_Caching' ) || file_exists( PERDITA_CORE_DIR . 'inc/modules/caching/class-perdita-caching.php' ) ) {
	require_once PERDITA_CORE_DIR . 'inc/modules/caching/class-perdita-caching.php';
	$pp_cache  = ( new ReflectionClass( 'Perdita_Caching' ) )->newInstanceWithoutConstructor();
	$pp_cookie = new ReflectionMethod( 'Perdita_Caching', 'has_no_cache_cookie' );
	if ( PHP_VERSION_ID < 80100 ) {
		$pp_cookie->setAccessible( true );
	}
	$pp_saved = $_COOKIE;
	$_COOKIE  = array( 'wp-postpass_' . md5( 'x' ) => 'hash' );
	$ok( true === $pp_cookie->invoke( $pp_cache ), 'caching: a visitor carrying the post-password cookie is never served or stored in the shared cache' );
	$_COOKIE = $pp_saved;
}

if ( class_exists( 'Perdita_SEO' ) && Perdita_SEO::instance() instanceof Perdita_SEO && ! Perdita_SEO::other_seo_active() ) {
	$pp_store = perdita_core()->seo;
	$pp_prev  = (string) $pp_store->get( 'robots_txt' );
	$pp_store->save( array( 'robots_txt' => "User-agent: *\nAllow: /" ) );
	$pp_core_private = "User-agent: *\nDisallow: /\n";
	$ok( $pp_core_private === Perdita_SEO::instance()->robots_txt( $pp_core_private, false ), 'robots.txt: an override never replaces core\'s Disallow: / while the site discourages search engines' );
	$pp_store->save( array( 'robots_txt' => $pp_prev ) );
}
