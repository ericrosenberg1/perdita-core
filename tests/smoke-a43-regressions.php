<?php
/**
 * Perdita Core smoke tests: regressions for three audit fixes.
 *
 *  - on_deactivate() clears cron on every site after a network deactivation.
 *  - nginx_snippet() blocks the cache folder under the real uploads path.
 *  - on_woo_product_change() purges a cached product page when WooCommerce
 *    writes a price or stock without firing save_post.
 *
 * Loaded by tests/smoke.php, so $ok() and the counters are the ones it
 * defines. Settings go through a pre_option filter. The cron array, the page
 * cache drop-in, every post and cache file this fragment makes, and every
 * hook its Perdita_Caching instance adds are put back at the end, and again
 * from a shutdown function if a fatal cuts the run short.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

echo "\n--- audit regressions (tests/smoke-a43-regressions.php) ---\n";

require_once PERDITA_CORE_DIR . 'inc/modules/caching/class-perdita-caching.php';

/* ------------------------------------------------------------------ */
/* Harness.                                                             */
/* ------------------------------------------------------------------ */

$GLOBALS['a43_settings'] = array( 'enabled' => true );
$a43_pre_option          = static function () {
	return $GLOBALS['a43_settings'];
};
$a43_core_on             = static function ( $value ) {
	$value = is_array( $value ) ? $value : array();
	if ( ! isset( $value['modules'] ) || ! is_array( $value['modules'] ) ) {
		$value['modules'] = array();
	}
	$value['modules']['caching'] = true;
	return $value;
};
add_filter( 'pre_option_' . Perdita_Caching::OPTION, $a43_pre_option );
add_filter( 'option_perdita_core', $a43_core_on );
add_filter( 'default_option_perdita_core', $a43_core_on );

Perdita_Caching::install();
$a43_cron       = _get_cron_array();
$a43_dropin     = Perdita_Caching::dropin_path();
$a43_dropin_was = is_file( $a43_dropin ) ? (string) file_get_contents( $a43_dropin ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions
$a43_before     = array_map( 'basename', (array) glob( Perdita_Caching::cache_dir() . '{,.}[!.]*', GLOB_BRACE ) );
$a43_hook_ids   = static function () {
	$ids = array();
	foreach ( $GLOBALS['wp_filter'] as $hook => $obj ) {
		foreach ( $obj->callbacks as $priority => $callbacks ) {
			foreach ( array_keys( $callbacks ) as $idx ) {
				$ids[ $hook . '|' . $priority . '|' . $idx ] = true;
			}
		}
	}
	return $ids;
};
$a43_ids_before = $a43_hook_ids();
$GLOBALS['a43_posts']        = array();
$GLOBALS['a43_cleanup_done'] = false;
$a43_cleanup                 = static function () use ( $a43_pre_option, $a43_core_on, $a43_cron, $a43_dropin, $a43_dropin_was, $a43_before, $a43_ids_before, $a43_hook_ids ) {
	if ( $GLOBALS['a43_cleanup_done'] ) {
		return;
	}
	$GLOBALS['a43_cleanup_done'] = true;
	foreach ( $GLOBALS['a43_posts'] as $id ) {
		wp_delete_post( $id, true );
	}
	remove_filter( 'pre_option_' . Perdita_Caching::OPTION, $a43_pre_option );
	remove_filter( 'option_perdita_core', $a43_core_on );
	remove_filter( 'default_option_perdita_core', $a43_core_on );
	// Hooks this fragment's own Perdita_Caching instance added.
	foreach ( $GLOBALS['wp_filter'] as $hook => $obj ) {
		foreach ( $obj->callbacks as $prio => $cbs ) {
			foreach ( $cbs as $idx => $cb ) {
				if ( ! isset( $a43_ids_before[ $hook . '|' . $prio . '|' . $idx ] ) ) {
					remove_filter( $hook, $cb['function'], $prio );
				}
			}
		}
	}
	_set_cron_array( $a43_cron );
	if ( null !== $a43_dropin_was && ! is_file( $a43_dropin ) ) {
		file_put_contents( $a43_dropin, $a43_dropin_was ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
	foreach ( (array) glob( Perdita_Caching::cache_dir() . '{,.}[!.]*', GLOB_BRACE ) as $f ) {
		if ( is_file( $f ) && ! in_array( basename( $f ), array_merge( $a43_before, array( '.htaccess', 'index.php' ) ), true ) ) {
			unlink( $f ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		}
	}
};
register_shutdown_function( $a43_cleanup );

/* ------------------------------------------------------------------ */
/* 1. on_deactivate( $network_wide ) clears cron.                       */
/* ------------------------------------------------------------------ */

$a43_deact = new ReflectionMethod( 'Perdita_Core', 'on_deactivate' );
$a43_param = $a43_deact->getParameters();
$ok( 1 === count( $a43_param ) && 'network_wide' === $a43_param[0]->getName() && $a43_param[0]->isOptional() && false === $a43_param[0]->getDefaultValue(), 'deactivate: on_deactivate() takes one optional $network_wide, default false, the argument WordPress passes' );

$a43_scheduled = static function () {
	$left = array();
	foreach ( Perdita_Core::CRON_HOOKS as $hook ) {
		if ( false !== wp_next_scheduled( $hook ) ) {
			$left[] = $hook;
		}
	}
	return $left;
};
foreach ( array( false, true ) as $a43_net ) {
	foreach ( Perdita_Core::CRON_HOOKS as $a43_hook ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', $a43_hook );
	}
	$ok( count( Perdita_Core::CRON_HOOKS ) === count( $a43_scheduled() ), 'deactivate: fixture, every CRON_HOOKS event is scheduled before on_deactivate( ' . var_export( $a43_net, true ) . ' )' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export
	$a43_err = null;
	try {
		Perdita_Core::on_deactivate( $a43_net );
	} catch ( Throwable $e ) {
		$a43_err = $e;
	}
	$ok( null === $a43_err && array() === $a43_scheduled(), 'deactivate: on_deactivate( ' . var_export( $a43_net, true ) . ' ) on a single site throws nothing and clears every CRON_HOOKS event' . ( $a43_err ? ' (' . $a43_err->getMessage() . ')' : '' ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export
}

if ( is_multisite() ) {
	$a43_blog = (int) get_current_blog_id();
	$a43_ids  = get_sites( array( 'fields' => 'ids', 'number' => 0 ) );
	$a43_ok   = count( $a43_ids ) > 1;
	foreach ( $a43_ids as $a43_id ) {
		switch_to_blog( $a43_id );
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', Perdita_Core::CRON_HOOKS[0] );
		restore_current_blog();
	}
	Perdita_Core::on_deactivate( true );
	$a43_left = array();
	foreach ( $a43_ids as $a43_id ) {
		switch_to_blog( $a43_id );
		if ( false !== wp_next_scheduled( Perdita_Core::CRON_HOOKS[0] ) ) {
			$a43_left[] = $a43_id;
		}
		restore_current_blog();
	}
	$ok( $a43_ok && array() === $a43_left && get_current_blog_id() === $a43_blog, 'deactivate: a network deactivation clears the cron event on every site and returns to the site it started on' . ( $a43_left ? ' (left on ' . implode( ',', $a43_left ) . ')' : '' ) );
}
// Cron events that existed before the test come back.
_set_cron_array( $a43_cron );

/* ------------------------------------------------------------------ */
/* 2. nginx_snippet() blocks the cache folder.                          */
/* ------------------------------------------------------------------ */

$a43_up   = wp_upload_dir( null, false );
$a43_path = trim( (string) wp_parse_url( $a43_up['baseurl'], PHP_URL_PATH ), '/' );
$a43_snip = Perdita_Caching::nginx_snippet();
$ok( '' !== $a43_path && false !== strpos( $a43_snip, 'location ^~ /' . $a43_path . '/perdita-cache/ {' ), 'nginx: the snippet blocks /' . $a43_path . '/perdita-cache/, the uploads path wp_upload_dir() reports' );
$ok( 1 === preg_match( '#location \^~ /' . preg_quote( $a43_path, '#' ) . '/perdita-cache/ \{\s*return 404;\s*\}#', $a43_snip ), 'nginx: the cache folder block answers 404' );
$a43_custom_up = static function ( $dirs ) {
	$dirs['baseurl'] = home_url( '/assets/up' );
	return $dirs;
};
add_filter( 'upload_dir', $a43_custom_up );
$a43_snip2 = Perdita_Caching::nginx_snippet();
remove_filter( 'upload_dir', $a43_custom_up );
$ok( false !== strpos( $a43_snip2, 'location ^~ /assets/up/perdita-cache/ {' ) && false === strpos( $a43_snip2, '/' . $a43_path . '/perdita-cache/' ), 'nginx: a moved uploads folder moves the block with it' );

/* ------------------------------------------------------------------ */
/* 3. WooCommerce product writes purge the cached page.                 */
/* ------------------------------------------------------------------ */

$a43_inst = new Perdita_Caching( perdita_core() );
// The handler purges a product once per request. Each check below stands for a new request.
$a43_wp    = new ReflectionProperty( $a43_inst, 'woo_purged' );
if ( PHP_VERSION_ID < 80100 ) {
	$a43_wp->setAccessible( true );
}
$a43_reset = static function () use ( $a43_wp, $a43_inst ) {
	$a43_wp->setValue( $a43_inst, array() );
};
$a43_woo  = array( 'woocommerce_update_product', 'woocommerce_update_product_variation', 'woocommerce_product_set_stock', 'woocommerce_variation_set_stock', 'woocommerce_product_set_stock_status', 'woocommerce_variation_set_stock_status' );
foreach ( $a43_woo as $a43_h ) {
	$ok( 10 === has_action( $a43_h, array( $a43_inst, 'on_woo_product_change' ) ), 'woo: on_woo_product_change() is hooked on ' . $a43_h );
}

// A cache entry for every key a URL is stored under, and a bystander page.
$a43_put = static function ( $url ) {
	foreach ( Perdita_Caching::keys_for_url( $url ) as $key ) {
		file_put_contents( Perdita_Caching::file_for_key( $key ), '<!doctype html><html><body>' . $url . '</body></html>' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
};
$a43_has = static function ( $url ) {
	clearstatcache();
	$keys = Perdita_Caching::keys_for_url( $url );
	foreach ( $keys as $key ) {
		if ( is_file( Perdita_Caching::file_for_key( $key ) ) ) {
			return true;
		}
	}
	return false;
};
$a43_mk = static function ( $title, array $extra = array() ) {
	$id = wp_insert_post( array_merge( array( 'post_title' => $title, 'post_status' => 'publish', 'post_type' => 'post', 'post_content' => 'x' ), $extra ) );
	$GLOBALS['a43_posts'][] = $id;
	return (int) $id;
};
$a43_by = home_url( '/a43-bystander/' );

$a43_post = $a43_mk( 'A43 Product Stand-in' );
$a43_url  = get_permalink( $a43_post );
$ok( $a43_post > 0 && array() !== Perdita_Caching::keys_for_url( $a43_url ), 'woo: fixture, a published post with a cacheable URL' );

$a43_put( $a43_url );
$a43_put( $a43_by );
$ok( $a43_has( $a43_url ) && $a43_has( $a43_by ), 'woo: fixture, both pages are cached' );
$a43_reset();
do_action( 'woocommerce_update_product', $a43_post );
$ok( ! $a43_has( $a43_url ), 'woo: woocommerce_update_product purges the product page' );

// Stock hooks hand over the product object.
$a43_obj = new class( $a43_post ) {
	private $id;
	public function __construct( $id ) {
		$this->id = $id;
	}
	public function get_id() {
		return $this->id;
	}
};
foreach ( array( 'woocommerce_product_set_stock', 'woocommerce_product_set_stock_status' ) as $a43_h ) {
	$a43_put( $a43_url );
	$a43_reset();
	do_action( $a43_h, $a43_obj );
	$ok( ! $a43_has( $a43_url ), 'woo: ' . $a43_h . ' with a product object purges the product page' );
}
foreach ( array( 'woocommerce_update_product_variation' ) as $a43_h ) {
	$a43_put( $a43_url );
	$a43_reset();
	do_action( $a43_h, $a43_post );
	$ok( ! $a43_has( $a43_url ), 'woo: ' . $a43_h . ' with an id purges the page' );
}

// A variation is a child post. Its parent's page is the one that changes.
$a43_var = $a43_mk( 'A43 Variation', array( 'post_type' => 'product_variation', 'post_parent' => $a43_post, 'post_status' => 'publish' ) );
$ok( $a43_var > 0 && 'product_variation' === get_post_type( $a43_var ) && $a43_post === (int) wp_get_post_parent_id( $a43_var ), 'woo: fixture, a product_variation child post' );
foreach ( array( 'woocommerce_variation_set_stock', 'woocommerce_variation_set_stock_status' ) as $a43_h ) {
	$a43_put( $a43_url );
	$a43_reset();
	do_action( $a43_h, new class( $a43_var ) {
		private $id;
		public function __construct( $id ) {
			$this->id = $id;
		}
		public function get_id() {
			return $this->id;
		}
	} );
	$ok( ! $a43_has( $a43_url ), 'woo: ' . $a43_h . ' on a variation purges its parent product page' );
}
$a43_put( $a43_url );
$a43_put( $a43_by );
$a43_reset();
do_action( 'woocommerce_update_product_variation', $a43_var );
$ok( ! $a43_has( $a43_url ), 'woo: woocommerce_update_product_variation purges the parent product page' );

// Nonsense ids do nothing and do not fatal.
$a43_put( $a43_by );
$a43_err = null;
try {
	$a43_inst->on_woo_product_change( 0 );
	$a43_inst->on_woo_product_change( 99999999 );
	$a43_inst->on_woo_product_change( null );
} catch ( Throwable $e ) {
	$a43_err = $e;
}
$ok( null === $a43_err && $a43_has( $a43_by ), 'woo: a zero, missing or null id purges nothing and throws nothing' );

// Smart mode: a price or stock write clears the product's own pages, not the whole cache.
$GLOBALS['a43_settings'] = array( 'enabled' => true, 'purge_on_save' => 'smart' );
$a43_put( $a43_url );
$a43_put( $a43_by );
$a43_reset();
do_action( 'woocommerce_update_product', $a43_post );
$ok( ! $a43_has( $a43_url ), 'woo: in smart mode a product write purges the product page' );
$ok( $a43_has( $a43_by ), 'woo: in smart mode a product write leaves unrelated pages cached' );
$a43_put( $a43_url );
do_action( 'woocommerce_product_set_stock_status', $a43_post );
$ok( $a43_has( $a43_url ), 'woo: the same product is purged once per request, however many stock hooks one sale fires' );
$GLOBALS['a43_settings'] = array( 'enabled' => true );

$a43_cleanup();
$a43_left = array_keys( array_diff_key( $a43_hook_ids(), $a43_ids_before ) );
$ok( array() === $a43_left, 'audit regressions: the test instance leaves no hook behind' . ( $a43_left ? ' (' . implode( ', ', array_slice( $a43_left, 0, 4 ) ) . ')' : '' ) ); // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- same keys, any order.
$ok( _get_cron_array() == $a43_cron, 'audit regressions: the cron array is back as it was' ); // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual
