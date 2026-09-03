<?php
/**
 * Perdita Core performance regressions.
 *
 * A focused fragment of the smoke suite: one assertion per performance fix, so
 * a later refactor that quietly puts the work back gets caught. tests/smoke.php
 * requires every tests/smoke-*.php right before it prints its summary, in the
 * same scope, so the $ok() helper and the $pass/$fail counters below are the
 * ones defined there.
 *
 * Everything here restores what it touched: options are captured and put back,
 * temporary posts and comments are hard-deleted, cache files are removed, and
 * the cache directory is only left behind if it already existed.
 *
 * The theme's own suite keeps the assertions about the migration tooling, the
 * Customizer, the CSS cache and the font map, because those subsystems stayed
 * in the theme.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

global $wpdb;

/**
 * Whether any callback on a hook is an instance of a given class. Used to
 * assert that a screen-bound subsystem was NOT constructed on this (front-end,
 * non-admin) request.
 *
 * @param string $hook  Hook name.
 * @param string $class Class name.
 * @return bool
 */
$__perf_hook_has_instance = function ( $hook, $class ) {
	if ( empty( $GLOBALS['wp_filter'][ $hook ] ) ) {
		return false;
	}
	foreach ( $GLOBALS['wp_filter'][ $hook ]->callbacks as $callbacks ) {
		foreach ( $callbacks as $registered ) {
			$cb = isset( $registered['function'] ) ? $registered['function'] : null;
			if ( is_array( $cb ) && isset( $cb[0] ) && is_object( $cb[0] ) && $cb[0] instanceof $class ) {
				return true;
			}
		}
	}
	return false;
};

/* ------------------------------------------------------------------ */
/* 1. Screen-bound subsystems are not built on a front-end request     */
/* ------------------------------------------------------------------ */

// This harness runs outside is_admin(), outside the Customizer preview, and
// outside a REST request, which is exactly the shape of an ordinary page view.
$ok( ! $__perf_hook_has_instance( 'admin_menu', 'Perdita_Core_Admin' ), 'perf: the Modules screen is not constructed on a front-end request' );
$ok( ! $__perf_hook_has_instance( 'upgrader_pre_download', 'Perdita_Core_Updater' ), 'perf: the self-hosted updater is not constructed on a front-end request' );
$ok( ! $__perf_hook_has_instance( 'admin_menu', 'Perdita_SEO_Admin' ), 'perf: the SEO admin screen is not constructed on a front-end request' );

// Perdita_Section is built from rest_api_init instead, at priority 0 (the same
// priority the module registry boots at), so the route its constructor adds
// still registers in the same do_action pass.
$ok( 0 === has_action( 'rest_api_init', array( perdita_core(), 'boot_rest_subsystems' ) ), 'perf: the REST subsystems are wired lazily from rest_api_init at priority 0' );

// Booting twice must yield one instance, not two sets of routes.
perdita_core()->boot_rest_subsystems();
perdita_core()->boot_rest_subsystems();
$__perf_section_hooks = 0;
foreach ( $GLOBALS['wp_filter']['rest_api_init']->callbacks as $__perf_cbs ) {
	foreach ( $__perf_cbs as $__perf_reg ) {
		$__perf_fn = isset( $__perf_reg['function'] ) ? $__perf_reg['function'] : null;
		if ( is_array( $__perf_fn ) && isset( $__perf_fn[0] ) && $__perf_fn[0] instanceof Perdita_Section ) {
			++$__perf_section_hooks;
		}
	}
}
$ok( 1 === $__perf_section_hooks, 'perf: boot_rest_subsystems() is idempotent (one Perdita_Section, however many times it is called)' );

// The module registry itself is registered once per request, not once per
// boot pass. boot_modules() fires on both init and rest_api_init, and
// re-globbing inc/modules/ on every MCP JSON-RPC call was the cost this
// guards against.
$__perf_module_count = count( perdita_core()->modules->all() );
perdita_core()->boot_modules();
$ok( $__perf_module_count === count( perdita_core()->modules->all() ), 'perf: a second boot_modules() pass re-registers nothing (no second glob of inc/modules/)' );

// An admin-only class never loads outside wp-admin. Perdita_Modules::boot()
// only requires a descriptor's 'admin_file' behind is_admin(), so on a page
// view these files are never even parsed. class_exists() with autoload off,
// because the point is whether the file was read, not whether it could be.
$ok( ! class_exists( 'Perdita_Pagespeed_Admin', false ), 'perf: a module\'s admin class is not loaded on a front-end request' );
$ok( ! class_exists( 'Perdita_SEO_Admin', false ), 'perf: the SEO admin class is not loaded on a front-end request' );

/* ------------------------------------------------------------------ */
/* 2. Page cache: tracking params, host keying, cart cookie, ETag      */
/* ------------------------------------------------------------------ */

require_once PERDITA_CORE_DIR . 'inc/modules/caching/class-perdita-caching.php';

$__perf_cache = new Perdita_Caching( perdita_core() );

// The comment hooks are the ones this change rewired, so check them before
// unhooking this throwaway instance again.
$ok( false !== has_action( 'comment_post', array( $__perf_cache, 'on_comment' ) ), 'caching: comment_post now runs the targeted purge, not purge_all()' );
$ok( false !== has_action( 'transition_comment_status', array( $__perf_cache, 'on_comment_status' ) ), 'caching: transition_comment_status now runs the targeted purge, not purge_all()' );
$ok( false !== has_action( 'switch_theme', array( $__perf_cache, 'purge_all' ) ), 'caching: the other triggers still clear the whole cache' );

// The constructor wires purge and serve hooks. This is a throwaway instance
// for driving the decision methods directly, so unhook it again rather than
// leaving a second set of purge handlers behind for the rest of the suite.
remove_action( 'template_redirect', array( $__perf_cache, 'serve_or_capture' ), 1 );
remove_action( 'save_post', array( $__perf_cache, 'on_save_post' ), 10 );
remove_action( 'comment_post', array( $__perf_cache, 'on_comment' ) );
remove_action( 'transition_comment_status', array( $__perf_cache, 'on_comment_status' ), 10 );
remove_action( 'switch_theme', array( $__perf_cache, 'purge_all' ) );
remove_action( 'customize_save_after', array( $__perf_cache, 'purge_all' ) );
remove_action( 'update_option_perdita_settings', array( $__perf_cache, 'purge_all' ) );
remove_action( 'update_option_' . Perdita_Caching::OPTION, array( $__perf_cache, 'purge_all' ) );

$__perf_is_cacheable = new ReflectionMethod( 'Perdita_Caching', 'is_cacheable' );
$__perf_is_cacheable->setAccessible( true );
$__perf_request_key = new ReflectionMethod( 'Perdita_Caching', 'request_key' );
$__perf_request_key->setAccessible( true );
$__perf_disallowed = new ReflectionMethod( 'Perdita_Caching', 'has_disallowed_query' );
$__perf_disallowed->setAccessible( true );

// A settings array built here rather than read from the option, so a site's
// own exclusions cannot change the answer.
$__perf_cache_settings = array(
	'enabled'       => true,
	'ttl_hours'     => 10,
	'exclude_paths' => array(),
	'browser_cache' => false,
);

// Capture the request state these methods read, so it can be put back.
$__perf_orig_get     = $_GET;
$__perf_orig_cookie  = $_COOKIE;
$__perf_orig_host    = isset( $_SERVER['HTTP_HOST'] ) ? $_SERVER['HTTP_HOST'] : null;
$__perf_orig_uri     = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : null;
$__perf_orig_user    = get_current_user_id();
$__perf_site_host    = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );

// is_cacheable() refuses a logged-in visitor, and earlier fragments leave a
// user set. A cache decision is about anonymous traffic.
wp_set_current_user( 0 );
$_SERVER['HTTP_HOST']    = $__perf_site_host;
$_SERVER['REQUEST_URI']  = '/';
$_COOKIE                 = array();

// Another module (Forms, Subscriptions) may legitimately hold this filter open
// after rendering a form earlier in the suite. Pin it shut for these checks.
$__perf_allow_cache = function () {
	return false;
};
add_filter( 'perdita_cache_exclude', $__perf_allow_cache, PHP_INT_MAX );

// --- tracking params no longer defeat the cache ---
$_GET = array( 'utm_source' => 'newsletter' );
$ok( false === $__perf_disallowed->invoke( $__perf_cache ), 'caching: a utm_source-only query is not a disallowed query' );
$ok( true === $__perf_is_cacheable->invoke( $__perf_cache, $__perf_cache_settings ), 'caching: a request carrying only utm_source is cacheable (it used to bypass the cache entirely)' );

$_GET = array(
	'utm_source'   => 'newsletter',
	'utm_medium'   => 'email',
	'utm_campaign' => 'launch',
	'fbclid'       => 'abc123',
	'gclid'        => 'def456',
	'msclkid'      => 'ghi789',
	'mc_cid'       => 'j',
	'mc_eid'       => 'k',
	'ttclid'       => 'l',
	'twclid'       => 'm',
	'utm_term'     => 'n',
	'utm_content'  => 'o',
	'utm_id'       => 'p',
);
$ok( true === $__perf_is_cacheable->invoke( $__perf_cache, $__perf_cache_settings ), 'caching: every tracking param on the ignore list is stripped, not treated as unknown' );
$__perf_key_tracked = $__perf_request_key->invoke( $__perf_cache );

$_GET = array();
$__perf_key_plain = $__perf_request_key->invoke( $__perf_cache );
$ok( $__perf_key_tracked === $__perf_key_plain, 'caching: request_key() ignores utm_/click-id params, so a tracked visit shares the plain URL cache entry' );

// Whitelisted args still key the entry; tracking params alongside them do not.
$_GET = array(
	'paged'      => '2',
	'utm_source' => 'newsletter',
);
$__perf_key_paged_tracked = $__perf_request_key->invoke( $__perf_cache );
$_GET = array( 'paged' => '2' );
$__perf_key_paged = $__perf_request_key->invoke( $__perf_cache );
$ok( $__perf_key_paged_tracked === $__perf_key_paged, 'caching: a whitelisted arg still keys the entry, and a tracking param next to it still does not' );
$ok( $__perf_key_paged !== $__perf_key_plain, 'caching: ?paged=2 is still a distinct cache entry' );

// An unknown, non-tracking arg must still keep the page out of the cache.
$_GET = array( 'preview_token' => 'x' );
$ok( false === $__perf_is_cacheable->invoke( $__perf_cache, $__perf_cache_settings ), 'caching: an unknown query arg still makes the page uncacheable' );

// The ignore list is filterable, and dropping a param from it puts that param
// back to being an ordinary cache-defeating arg.
$__perf_drop_fbclid = function ( $args ) {
	return array_values( array_diff( (array) $args, array( 'fbclid' ) ) );
};
add_filter( 'perdita_cache_query_ignore', $__perf_drop_fbclid );
$_GET = array( 'fbclid' => 'abc123' );
$ok( false === $__perf_is_cacheable->invoke( $__perf_cache, $__perf_cache_settings ), 'caching: perdita_cache_query_ignore is filterable (removing fbclid restores the old behavior for it)' );
remove_filter( 'perdita_cache_query_ignore', $__perf_drop_fbclid );

// --- the cache key is the site's host, not the client's ---
$_GET                 = array();
$_SERVER['HTTP_HOST'] = 'evil.example.com';
$__perf_key_spoofed   = $__perf_request_key->invoke( $__perf_cache );
$ok( $__perf_key_spoofed === $__perf_key_plain, 'caching: request_key() keys on home_url()\'s host, so a spoofed Host cannot mint extra cache files' );
$ok( false === $__perf_is_cacheable->invoke( $__perf_cache, $__perf_cache_settings ), 'caching: a request whose Host does not match the site is refused outright' );

$_SERVER['HTTP_HOST'] = strtoupper( $__perf_site_host ) . ':8080';
$ok( true === $__perf_is_cacheable->invoke( $__perf_cache, $__perf_cache_settings ), 'caching: the Host check is case-insensitive and ignores the port' );
$_SERVER['HTTP_HOST'] = $__perf_site_host;

// --- the Pro cart cookie keeps a visitor out of the cache ---
foreach ( array( 'perdita_cart', 'perdita_cart_pro', 'perdita_sales_pro_cart' ) as $__perf_cookie ) {
	$_COOKIE = array( $__perf_cookie => 'abc' );
	$ok( false === $__perf_is_cacheable->invoke( $__perf_cache, $__perf_cache_settings ), "caching: the {$__perf_cookie} cookie makes the request non-cacheable" );
}
$_COOKIE = array( 'perdita_consent' => '1' );
$ok( true === $__perf_is_cacheable->invoke( $__perf_cache, $__perf_cache_settings ), 'caching: perdita_consent is still cacheable (the banner is a client-side decision)' );
$_COOKIE = array();

remove_filter( 'perdita_cache_exclude', $__perf_allow_cache, PHP_INT_MAX );

/* --- ETag comes from the file's mtime + size, not a hash of its body --- */

$__perf_cache_dir     = Perdita_Caching::cache_dir();
$__perf_dir_preexists = is_dir( $__perf_cache_dir );
Perdita_Caching::install();

$__perf_store = new ReflectionMethod( 'Perdita_Caching', 'store' );
$__perf_store->setAccessible( true );
$__perf_etag_for = new ReflectionMethod( 'Perdita_Caching', 'etag_for_file' );
$__perf_etag_for->setAccessible( true );

$__perf_etag_key  = 'https://' . $__perf_site_host . '/perdita-perf-etag/';
$__perf_etag_file = Perdita_Caching::file_for_key( $__perf_etag_key );
$__perf_store->invoke( $__perf_cache, $__perf_etag_file, '<!doctype html><html><body>etag</body></html>' );

if ( is_file( $__perf_etag_file ) ) {
	clearstatcache( true, $__perf_etag_file );
	$__perf_expected_etag = '"' . (int) filemtime( $__perf_etag_file ) . '-' . (int) filesize( $__perf_etag_file ) . '"';
	$ok( $__perf_expected_etag === $__perf_etag_for->invoke( null, $__perf_etag_file ), 'caching: the ETag is filemtime-filesize, not an md5 of the whole cached document' );
	$ok( $__perf_etag_for->invoke( null, $__perf_etag_file ) === $__perf_etag_for->invoke( null, $__perf_etag_file ), 'caching: the ETag is stable while the file is unchanged (conditional GETs still 304)' );

	// A rewrite with different content changes the tag even inside one second.
	$__perf_store->invoke( $__perf_cache, $__perf_etag_file, '<!doctype html><html><body>etag, but longer now</body></html>' );
	clearstatcache( true, $__perf_etag_file );
	$ok( $__perf_expected_etag !== $__perf_etag_for->invoke( null, $__perf_etag_file ), 'caching: rewriting the cache file changes the ETag' );
	unlink( $__perf_etag_file );
} else {
	$ok( false, 'caching: could not write a cache file to test the ETag (is ' . $__perf_cache_dir . ' writable?)' );
}

/* --- a comment purges its own post and the front page, nothing else --- */

$__perf_post = wp_insert_post(
	array(
		'post_title'   => 'Perdita perf comment target',
		'post_content' => 'Body.',
		'post_status'  => 'publish',
		'post_type'    => 'post',
	)
);

$__perf_comment = wp_insert_comment(
	array(
		'comment_post_ID'  => $__perf_post,
		'comment_content'  => 'Perdita perf comment.',
		'comment_approved' => 1,
	)
);

// Build the same keys purge_url() will: the site's own host, the URL's path,
// under both schemes (a purge can run from either side of a proxy).
$__perf_paths = array(
	'post'     => (string) wp_parse_url( (string) get_permalink( $__perf_post ), PHP_URL_PATH ),
	'home'     => (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ),
	'survivor' => '/perdita-perf-untouched/',
);
$__perf_files = array();
foreach ( $__perf_paths as $__perf_which => $__perf_path ) {
	$__perf_path = '' === $__perf_path ? '/' : $__perf_path;
	foreach ( array( 'https', 'http' ) as $__perf_scheme ) {
		$__perf_file = Perdita_Caching::file_for_key( $__perf_scheme . '://' . $__perf_site_host . $__perf_path );
		$__perf_store->invoke( $__perf_cache, $__perf_file, '<!doctype html><html><body>' . $__perf_which . '</body></html>' );
		$__perf_files[ $__perf_which ][] = $__perf_file;
	}
}

// The survivor path must not collide with the other two, or the assertion
// below would be vacuous. With plain permalinks the post permalink IS the home
// path, which is fine: both are supposed to be purged.
$__perf_survivor_distinct = ! in_array( $__perf_files['survivor'][0], array_merge( $__perf_files['post'], $__perf_files['home'] ), true );

$__perf_removed = $__perf_cache->on_comment( $__perf_comment );

$__perf_targets_gone = true;
foreach ( array_merge( $__perf_files['post'], $__perf_files['home'] ) as $__perf_file ) {
	if ( is_file( $__perf_file ) ) {
		$__perf_targets_gone = false;
	}
}
$ok( $__perf_targets_gone, 'caching: on_comment() removes the commented post\'s cache file and the front page\'s' );

if ( $__perf_survivor_distinct ) {
	$ok( is_file( $__perf_files['survivor'][0] ) && is_file( $__perf_files['survivor'][1] ), 'caching: on_comment() leaves every other cached page alone (it no longer purges the whole cache)' );
} else {
	$ok( true, 'caching: SKIPPED the "other pages survive" check (plain permalinks make the post permalink identical to the home path)' );
}
$ok( $__perf_removed > 0, 'caching: on_comment() reports how many files it removed' );

// transition_comment_status hands its args in a different order; the wrapper
// must still resolve the same post.
foreach ( $__perf_files['home'] as $__perf_file ) {
	$__perf_store->invoke( $__perf_cache, $__perf_file, '<!doctype html><html><body>home again</body></html>' );
}
$__perf_cache->on_comment_status( 'approved', 'hold', get_comment( $__perf_comment ) );
$ok( ! is_file( $__perf_files['home'][0] ) && ! is_file( $__perf_files['home'][1] ), 'caching: on_comment_status() purges the same pages when a comment is approved or unapproved' );

// Clean up every file this fragment wrote, then the directory if we made it.
foreach ( $__perf_files as $__perf_group ) {
	foreach ( $__perf_group as $__perf_file ) {
		if ( is_file( $__perf_file ) ) {
			unlink( $__perf_file );
		}
	}
}
if ( ! $__perf_dir_preexists && is_dir( $__perf_cache_dir ) ) {
	if ( is_file( $__perf_cache_dir . 'index.php' ) ) {
		unlink( $__perf_cache_dir . 'index.php' );
	}
	@rmdir( $__perf_cache_dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- best effort; a non-empty dir is left in place.
}

wp_delete_comment( $__perf_comment, true );

// Restore the request state.
$_GET    = $__perf_orig_get;
$_COOKIE = $__perf_orig_cookie;
if ( null === $__perf_orig_host ) {
	unset( $_SERVER['HTTP_HOST'] );
} else {
	$_SERVER['HTTP_HOST'] = $__perf_orig_host;
}
if ( null === $__perf_orig_uri ) {
	unset( $_SERVER['REQUEST_URI'] );
} else {
	$_SERVER['REQUEST_URI'] = $__perf_orig_uri;
}
wp_set_current_user( $__perf_orig_user );

/* ------------------------------------------------------------------ */
/* 3. Related posts: the ID list is memoized in post meta              */
/* ------------------------------------------------------------------ */

require_once PERDITA_CORE_DIR . 'inc/modules/related-posts/class-perdita-related-posts.php';

// Enough published posts that the block has something to show even on a bare
// test site (the backfill path, which is what a post with no terms hits).
$__perf_rp_posts = array();
for ( $__perf_i = 1; $__perf_i <= 3; $__perf_i++ ) {
	$__perf_rp_posts[] = wp_insert_post(
		array(
			'post_title'   => 'Perdita perf related ' . $__perf_i,
			'post_content' => 'Body ' . $__perf_i,
			'post_status'  => 'publish',
			'post_type'    => 'post',
		)
	);
}

$__perf_rp = new Perdita_Related_Posts( perdita_core() );

$__perf_find = new ReflectionMethod( 'Perdita_Related_Posts', 'find_related' );
$__perf_find->setAccessible( true );
$__perf_rp_key_method = new ReflectionMethod( 'Perdita_Related_Posts', 'cache_key' );
$__perf_rp_key_method->setAccessible( true );
$__perf_rp_key = $__perf_rp_key_method->invoke( null );

$ok( 0 === strpos( $__perf_rp_key, Perdita_Related_Posts::META_PREFIX ), 'related posts: the cache meta key is prefixed and settings-hashed' );

$__perf_rp_subject   = $__perf_rp_posts[0];
$__perf_rp_settings  = Perdita_Related_Posts::settings();
$__perf_rp_count     = (int) $__perf_rp_settings['count'];
$__perf_rp_match     = (string) $__perf_rp_settings['match_by'];
delete_post_meta( $__perf_rp_subject, $__perf_rp_key );

$__perf_first = $__perf_find->invoke( $__perf_rp, $__perf_rp_subject, $__perf_rp_count, $__perf_rp_match );
$ok( is_array( $__perf_first ) && ! empty( $__perf_first ), 'related posts: the first lookup finds something to show' );
$ok( ! empty( $__perf_first ) && reset( $__perf_first ) instanceof WP_Post, 'related posts: find_related() still returns WP_Post objects, not the cached ID list' );
$ok( is_array( get_post_meta( $__perf_rp_subject, $__perf_rp_key, true ) ), 'related posts: the resolved ID list is written to post meta' );

// update_post_meta() invalidates the post's meta cache. WordPress re-primes it
// for every post in the main query before a template renders, so prime it the
// same way here and then measure what the cached lookup actually costs.
update_meta_cache( 'post', array( $__perf_rp_subject ) );
$__perf_q_before = $wpdb->num_queries;
$__perf_second   = $__perf_find->invoke( $__perf_rp, $__perf_rp_subject, $__perf_rp_count, $__perf_rp_match );
$__perf_q_after  = $wpdb->num_queries;

$ok( $__perf_q_before === $__perf_q_after, 'related posts: the second lookup runs ZERO queries (it used to run 2 term lookups plus up to 2 WP_Query on every single-post view)' );
$ok( wp_list_pluck( $__perf_first, 'ID' ) === wp_list_pluck( $__perf_second, 'ID' ), 'related posts: the cached list is identical to the computed one' );

// A save invalidates it. So does a term change.
wp_update_post(
	array(
		'ID'         => $__perf_rp_subject,
		'post_title' => 'Perdita perf related 1 (edited)',
	)
);
$ok( '' === get_post_meta( $__perf_rp_subject, $__perf_rp_key, true ), 'related posts: save_post clears the post\'s cached list' );

$__perf_find->invoke( $__perf_rp, $__perf_rp_subject, $__perf_rp_count, $__perf_rp_match );
$ok( is_array( get_post_meta( $__perf_rp_subject, $__perf_rp_key, true ) ), 'related posts: the list is rebuilt after an invalidation' );
$__perf_rp->purge_post_cache( $__perf_rp_subject );
$ok( '' === get_post_meta( $__perf_rp_subject, $__perf_rp_key, true ), 'related posts: purge_post_cache() clears every _perdita_related_* key (set_object_terms and deleted_post use it too)' );

remove_filter( 'the_content', array( $__perf_rp, 'append' ) );
remove_action( 'wp_enqueue_scripts', array( $__perf_rp, 'maybe_enqueue' ) );
remove_action( 'save_post', array( $__perf_rp, 'purge_post_cache' ) );
remove_action( 'set_object_terms', array( $__perf_rp, 'purge_post_cache' ) );
remove_action( 'deleted_post', array( $__perf_rp, 'purge_post_cache' ) );

foreach ( $__perf_rp_posts as $__perf_id ) {
	wp_delete_post( $__perf_id, true );
}
wp_delete_post( $__perf_post, true );

/* ------------------------------------------------------------------ */
/* 4. The SEO store caches a dangling attachment id's empty result     */
/* ------------------------------------------------------------------ */

$__perf_orig_seo_opt = get_option( Perdita_SEO_Store::OPTION );

// An id that resolves to nothing: exactly the shape of a default social image
// or org logo that was deleted from the media library.
$__perf_bogus_id = 999000001;
while ( null !== get_post( $__perf_bogus_id ) ) {
	++$__perf_bogus_id;
}

$__perf_seo_doc = is_array( $__perf_orig_seo_opt ) ? $__perf_orig_seo_opt : array();
$__perf_seo_doc['default_image_id'] = $__perf_bogus_id;
unset( $__perf_seo_doc['default_image_cache'] );
update_option( Perdita_SEO_Store::OPTION, $__perf_seo_doc );

$__perf_seo_first = new Perdita_SEO_Store();
$ok( '' === $__perf_seo_first->image_url(), 'seo store: a dangling default image id still resolves to an empty URL' );

$__perf_seo_cached = get_option( Perdita_SEO_Store::OPTION );
$ok( is_array( $__perf_seo_cached ) && isset( $__perf_seo_cached['default_image_cache'] ) && array_key_exists( 'url', (array) $__perf_seo_cached['default_image_cache'] ), 'seo store: the EMPTY result is cached too (it used to re-query the attachment on every page view forever)' );

$__perf_seo_second = new Perdita_SEO_Store();
$__perf_seo_q_before = $wpdb->num_queries;
$__perf_seo_url      = $__perf_seo_second->image_url();
$__perf_seo_q_after  = $wpdb->num_queries;
$ok( $__perf_seo_q_before === $__perf_seo_q_after, 'seo store: the second read of a dangling id runs zero queries' );
$ok( '' === $__perf_seo_url, 'seo store: and still returns an empty URL' );

// A changed id must still invalidate the cache.
$__perf_seo_doc['default_image_id'] = $__perf_bogus_id + 1;
update_option( Perdita_SEO_Store::OPTION, $__perf_seo_doc );
$__perf_seo_third = new Perdita_SEO_Store();
$__perf_seo_third->image_url();
$__perf_seo_after_change = get_option( Perdita_SEO_Store::OPTION );
$__perf_seo_cached_id    = is_array( $__perf_seo_after_change ) && isset( $__perf_seo_after_change['default_image_cache']['id'] )
	? (int) $__perf_seo_after_change['default_image_cache']['id']
	: 0;
$ok( ( $__perf_bogus_id + 1 ) === $__perf_seo_cached_id, 'seo store: the cache still busts when the attachment id changes' );

if ( false === $__perf_orig_seo_opt ) {
	delete_option( Perdita_SEO_Store::OPTION );
} else {
	update_option( Perdita_SEO_Store::OPTION, $__perf_orig_seo_opt );
}

/* ------------------------------------------------------------------ */
/* 5. Sales: store.css is not enqueued on every front-end page         */
/* ------------------------------------------------------------------ */

if ( file_exists( PERDITA_CORE_DIR . 'inc/modules/sales/class-perdita-sales.php' ) ) {
	require_once PERDITA_CORE_DIR . 'inc/modules/sales/class-perdita-sales.php';
	$__perf_sales_tags = new ReflectionMethod( 'Perdita_Sales', 'shortcode_tags' );
	$__perf_sales_tags->setAccessible( true );
	$__perf_tags = $__perf_sales_tags->invoke( null );
	$__perf_sales_src = (string) file_get_contents( PERDITA_CORE_DIR . 'inc/modules/sales/class-perdita-sales.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- reading the theme's own source to check the two lists agree.
	$__perf_registered = array();
	if ( preg_match_all( "/add_shortcode\(\s*'([a-z_]+)'/", $__perf_sales_src, $__perf_m ) ) {
		$__perf_registered = $__perf_m[1];
	}
	sort( $__perf_registered );
	$__perf_tags_sorted = $__perf_tags;
	sort( $__perf_tags_sorted );
	$ok( $__perf_registered === $__perf_tags_sorted, 'sales: the shortcode list the stylesheet gate reads matches the tags the module actually registers' );

	$__perf_needs = new ReflectionMethod( 'Perdita_Sales', 'needs_store_css' );
	$__perf_needs->setAccessible( true );
	// Built without its constructor: the gate reads only the query, so this
	// avoids registering a second set of the module's shortcodes and REST
	// routes just to ask it a question.
	$__perf_sales = ( new ReflectionClass( 'Perdita_Sales' ) )->newInstanceWithoutConstructor();
	// This harness is not a singular request and not a product archive, which
	// is what an ordinary blog post or contact page looks like to the gate.
	$ok( false === $__perf_needs->invoke( $__perf_sales ), 'sales: store.css is not enqueued on a page with no store markup' );
	$ok( false === $__perf_needs->invoke( $__perf_sales ), 'sales: and the gate is a pure read, so asking twice gives the same answer' );
} else {
	$ok( true, 'sales: SKIPPED (module file not present in this build)' );
}
