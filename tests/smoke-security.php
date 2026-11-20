<?php
/**
 * Perdita Core smoke tests: security and correctness regressions.
 *
 * Loaded automatically by tests/smoke.php (see the smoke-*.php fragment loop
 * at the end of that file), so it shares the same $ok() helper, the same
 * pass/fail counters, and the same live WordPress. Run it the same way:
 *
 *   bash tests/run.sh /path/to/wordpress
 *
 * Every fixture here is created and removed inside this file: temporary
 * posts, users, subscriber rows, options and transients are all restored to
 * whatever they were before it ran.
 *
 * The theme's own suite keeps the crypto and comments.php regressions that
 * used to live here, because those classes stayed in the theme.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

global $wpdb;

echo "\n--- security regressions (tests/smoke-security.php) ---\n";


/* =============================================================
 * MCP list_posts / search_content must not leak other people's
 * unpublished content. WP_Query's 'post_status' => 'any' applies no
 * capability check at all, so both tools used to hand every author's
 * drafts to any authenticated caller.
 * ============================================================= */

require_once PERDITA_CORE_DIR . 'inc/modules/mcp/class-perdita-mcp.php';

foreach ( array( 'perdita_smoke_sec_author', 'perdita_smoke_sec_sub' ) as $psx_stale_login ) {
	$psx_stale = get_user_by( 'login', $psx_stale_login );
	if ( $psx_stale ) {
		wp_delete_user( $psx_stale->ID );
	}
}

$psx_author = wp_insert_user( array(
	'user_login' => 'perdita_smoke_sec_author',
	'user_email' => 'perdita-smoke-sec-author@example.com',
	'user_pass'  => wp_generate_password(),
	'role'       => 'author',
) );
$psx_sub = wp_insert_user( array(
	'user_login' => 'perdita_smoke_sec_sub',
	'user_email' => 'perdita-smoke-sec-sub@example.com',
	'user_pass'  => wp_generate_password(),
	'role'       => 'subscriber',
) );

$psx_secret_draft = wp_insert_post( array(
	'post_title'   => 'PerditaSmokeSecretDraftZZ',
	'post_content' => 'Unpublished editorial content, PerditaSmokeSecretDraftZZ.',
	'post_status'  => 'draft',
	'post_author'  => $psx_author,
) );

$psx_mcp     = new Perdita_MCP( perdita_core() );
$psx_mcp_ref = new ReflectionClass( $psx_mcp );

$psx_list = $psx_mcp_ref->getMethod( 'tool_list_posts' );
if ( PHP_VERSION_ID < 80100 ) { $psx_list->setAccessible( true ); }
$psx_search = $psx_mcp_ref->getMethod( 'tool_search_content' );
if ( PHP_VERSION_ID < 80100 ) { $psx_search->setAccessible( true ); }

$psx_ids = function ( $result ) {
	$items = isset( $result['structuredContent']['items'] ) ? $result['structuredContent']['items'] : array();
	return array_map(
		function ( $item ) {
			return (int) $item['id'];
		},
		(array) $items
	);
};

wp_set_current_user( $psx_sub );
$psx_sub_list   = $psx_ids( $psx_list->invoke( $psx_mcp, array( 'per_page' => 100, 'status' => 'any' ) ) );
$psx_sub_search = $psx_ids( $psx_search->invoke( $psx_mcp, array( 'query' => 'PerditaSmokeSecretDraftZZ', 'per_page' => 100 ) ) );

$ok( ! in_array( (int) $psx_secret_draft, $psx_sub_list, true ), 'mcp: list_posts run as a Subscriber does NOT return another user\'s draft' );
$ok( ! in_array( (int) $psx_secret_draft, $psx_sub_search, true ), 'mcp: search_content run as a Subscriber does NOT return another user\'s draft' );

// The tools still work for the role they are meant for: an administrator
// (edit_others_posts) sees the same draft the Subscriber could not.
wp_set_current_user( 1 );
$psx_admin_list   = $psx_ids( $psx_list->invoke( $psx_mcp, array( 'per_page' => 100, 'status' => 'any' ) ) );
$psx_admin_search = $psx_ids( $psx_search->invoke( $psx_mcp, array( 'query' => 'PerditaSmokeSecretDraftZZ', 'per_page' => 100 ) ) );
$ok( in_array( (int) $psx_secret_draft, $psx_admin_list, true ), 'mcp: list_posts still returns the draft to a user who can edit others\' posts' );
$ok( in_array( (int) $psx_secret_draft, $psx_admin_search, true ), 'mcp: search_content still returns the draft to a user who can edit others\' posts' );

wp_delete_post( $psx_secret_draft, true );
wp_delete_user( $psx_author );

/* =============================================================
 * MCP OAuth: being logged in is not enough to mint a bearer token.
 * ============================================================= */

require_once PERDITA_CORE_DIR . 'inc/modules/mcp/class-perdita-mcp-oauth.php';

$ok( 'edit_posts' === Perdita_MCP_OAuth::required_capability(), 'mcp-oauth: the consent screen requires edit_posts by default' );

wp_set_current_user( $psx_sub );
$ok( false === Perdita_MCP_OAuth::current_user_can_authorize(), 'mcp-oauth: a Subscriber cannot approve an MCP connection (no self-minted bearer token)' );

wp_set_current_user( 1 );
$ok( true === Perdita_MCP_OAuth::current_user_can_authorize(), 'mcp-oauth: an administrator can still approve an MCP connection' );

$psx_cap_filter = function () {
	return 'manage_options';
};
add_filter( 'perdita_mcp_oauth_required_cap', $psx_cap_filter );
$ok( 'manage_options' === Perdita_MCP_OAuth::required_capability(), 'mcp-oauth: perdita_mcp_oauth_required_cap can tighten the required capability' );
remove_filter( 'perdita_mcp_oauth_required_cap', $psx_cap_filter );

// Authorization-code redemption is a database-enforced test-and-set, so two
// concurrent redemptions of one code can never both mint a token pair.
$psx_oauth      = new Perdita_MCP_OAuth( perdita_core() );
$psx_claim      = new ReflectionMethod( 'Perdita_MCP_OAuth', 'claim_code_for_redemption' );
if ( PHP_VERSION_ID < 80100 ) { $psx_claim->setAccessible( true ); }
$psx_claim_name = new ReflectionMethod( 'Perdita_MCP_OAuth', 'code_claim_option_name' );
if ( PHP_VERSION_ID < 80100 ) { $psx_claim_name->setAccessible( true ); }
$psx_hash_token = new ReflectionMethod( 'Perdita_MCP_OAuth', 'hash_token' );
if ( PHP_VERSION_ID < 80100 ) { $psx_hash_token->setAccessible( true ); }

$psx_code = Perdita_MCP_OAuth::ACCESS_TOKEN_PREFIX . 'code_perditasmokeclaim';
$ok( true === $psx_claim->invoke( null, $psx_code ), 'mcp-oauth: the first redemption of an authorization code wins the claim' );
$ok( false === $psx_claim->invoke( null, $psx_code ), 'mcp-oauth: a second, concurrent redemption of the same code loses the claim (no double token issue)' );
delete_option( $psx_claim_name->invoke( null, $psx_hash_token->invoke( null, $psx_code ) ) );

/* --- OAuth storage: writes are serialised and never start from a stale copy.
   OPTION_CLIENTS, OPTION_GRANTS and OPTION_CONNECTIONS are each one serialised
   array, so every write is a read-modify-write of the whole thing, and two
   requests doing that at once (one client's token exchange landing while
   another refreshed) used to overwrite each other's unrelated keys. --- */

$psx_lock   = new ReflectionMethod( 'Perdita_MCP_OAuth', 'acquire_lock' );
$psx_unlock = new ReflectionMethod( 'Perdita_MCP_OAuth', 'release_lock' );
$psx_mutate = new ReflectionMethod( 'Perdita_MCP_OAuth', 'mutate_option' );
$psx_store  = new ReflectionMethod( 'Perdita_MCP_OAuth', 'store_grant' );
$psx_grants = new ReflectionMethod( 'Perdita_MCP_OAuth', 'all_grants' );
$psx_gcache = new ReflectionProperty( 'Perdita_MCP_OAuth', 'grants_cache' );
if ( PHP_VERSION_ID < 80100 ) {
	foreach ( array( $psx_lock, $psx_unlock, $psx_mutate, $psx_store, $psx_grants, $psx_gcache ) as $psx_r ) {
		$psx_r->setAccessible( true );
	}
}
$psx_row = function ( $name ) use ( $wpdb ) {
	return $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
};

// The lock primitive: a database-enforced test-and-set with stale takeover.
$psx_opt = 'perdita_mcp_oauth_smoke_race';
delete_option( $psx_opt );
$wpdb->delete( $wpdb->options, array( 'option_name' => $psx_opt . '_lock' ) );

$psx_l1 = $psx_lock->invoke( null, $psx_opt, 0 );
$ok( is_string( $psx_l1 ), 'mcp-oauth: acquire_lock() takes a free lock' );
$ok( false === $psx_lock->invoke( null, $psx_opt, 0 ), 'mcp-oauth: a second caller cannot take a lock that is held' );
$psx_unlock->invoke( null, $psx_l1 );
$ok( null === $psx_row( $psx_l1 ), 'mcp-oauth: release_lock() removes the lock row' );
$psx_l2 = $psx_lock->invoke( null, $psx_opt, 0 );
$ok( is_string( $psx_l2 ), 'mcp-oauth: the lock is free again after release_lock()' );
$wpdb->update( $wpdb->options, array( 'option_value' => (string) ( time() - Perdita_MCP_OAuth::LOCK_STALE_SECONDS - 5 ) ), array( 'option_name' => $psx_l2 ) );
$psx_l3 = $psx_lock->invoke( null, $psx_opt, 0 );
$ok( is_string( $psx_l3 ), 'mcp-oauth: a lock left behind by a request that died is taken over after LOCK_STALE_SECONDS' );
$psx_unlock->invoke( null, $psx_l3 );

// The stale-copy race, reproduced in one process: "another request" writes
// straight to the database after this request has already read, and so
// cached, the option.
update_option( $psx_opt, array( 'mine' => 1 ), false );
get_option( $psx_opt ); // This request now holds array( 'mine' => 1 ) in its options cache.
$wpdb->update( $wpdb->options, array( 'option_value' => maybe_serialize( array( 'mine' => 1, 'theirs' => 1 ) ) ), array( 'option_name' => $psx_opt ) );
$psx_stale = get_option( $psx_opt );
$ok( is_array( $psx_stale ) && ! isset( $psx_stale['theirs'] ), 'mcp-oauth: (control) a plain get_option() still returns the copy cached before the other request wrote, the stale read the lock exists to beat' );
$psx_merged = $psx_mutate->invoke( null, $psx_opt, function ( array $current ) { $current['also_mine'] = 1; return $current; } );
$ok( is_array( $psx_merged ) && isset( $psx_merged['mine'], $psx_merged['theirs'], $psx_merged['also_mine'] ), 'mcp-oauth: mutate_option() re-reads under the lock, so the other request\'s key survives next to this one\'s' );
$psx_db = maybe_unserialize( $psx_row( $psx_opt ) );
$ok( is_array( $psx_db ) && isset( $psx_db['theirs'], $psx_db['also_mine'] ), 'mcp-oauth: and that merged array is what the database holds' );
$ok( null === $psx_row( $psx_opt . '_lock' ), 'mcp-oauth: mutate_option() releases the lock when it is done' );
$psx_untouched = $psx_mutate->invoke( null, $psx_opt, function ( array $current ) { return $current; } );
$ok( is_array( $psx_untouched ) && $psx_untouched === $psx_db, 'mcp-oauth: a mutation that changes nothing writes nothing and still returns the current array' );
delete_option( $psx_opt );

// The real grants path goes through the same door: a grant another request
// wrote after this request's last read survives this request's store_grant(),
// and the same locked write prunes what has expired (plus its claim row).
$psx_orig_grants = get_option( Perdita_MCP_OAuth::OPTION_GRANTS );
$psx_base        = is_array( $psx_orig_grants ) ? $psx_orig_grants : array();
update_option( Perdita_MCP_OAuth::OPTION_GRANTS, $psx_base, false );
$psx_gcache->setValue( null, null );
$psx_grants->invoke( null ); // Warm the request-scoped cache from the current row.
$psx_other_key   = $psx_hash_token->invoke( null, 'perdita-smoke-other-request-token' );
$psx_expired_key = $psx_hash_token->invoke( null, 'perdita-smoke-expired-code' );
$psx_other       = $psx_base;
$psx_other[ $psx_other_key ]   = array( 'type' => 'access', 'user_id' => 1, 'client_id' => 'perdita_smoke', 'resource' => 'smoke', 'scope' => '', 'expires' => time() + 600 );
$psx_other[ $psx_expired_key ] = array( 'type' => 'code', 'user_id' => 1, 'client_id' => 'perdita_smoke', 'resource' => 'smoke', 'scope' => '', 'expires' => time() - 600, 'used' => true );
$wpdb->update( $wpdb->options, array( 'option_value' => maybe_serialize( $psx_other ) ), array( 'option_name' => Perdita_MCP_OAuth::OPTION_GRANTS ) );
add_option( $psx_claim_name->invoke( null, $psx_expired_key ), time(), '', false );

$psx_stored    = $psx_store->invoke( $psx_oauth, 'perdita-smoke-my-token', array( 'type' => 'access', 'user_id' => 1, 'client_id' => 'perdita_smoke', 'resource' => 'smoke', 'scope' => '', 'expires' => time() + 600 ) );
$psx_db_grants = maybe_unserialize( $psx_row( Perdita_MCP_OAuth::OPTION_GRANTS ) );
$ok( true === $psx_stored && is_array( $psx_db_grants ) && isset( $psx_db_grants[ $psx_other_key ], $psx_db_grants[ $psx_hash_token->invoke( null, 'perdita-smoke-my-token' ) ] ), 'mcp-oauth: store_grant() keeps a grant another request wrote after this request\'s last read (no clobbered token)' );
$ok( is_array( $psx_db_grants ) && ! isset( $psx_db_grants[ $psx_expired_key ] ), 'mcp-oauth: the same locked write prunes an expired grant from storage' );
$ok( false === get_option( $psx_claim_name->invoke( null, $psx_expired_key ) ), 'mcp-oauth: and drops the expired code\'s single-use claim row with it' );
$psx_live = $psx_grants->invoke( null );
$ok( is_array( $psx_live ) && isset( $psx_live[ $psx_other_key ] ) && ! isset( $psx_live[ $psx_expired_key ] ), 'mcp-oauth: the request-scoped grants cache is replaced with exactly what was persisted' );

if ( false === $psx_orig_grants ) {
	delete_option( Perdita_MCP_OAuth::OPTION_GRANTS );
} else {
	update_option( Perdita_MCP_OAuth::OPTION_GRANTS, $psx_orig_grants, false );
}
$psx_gcache->setValue( null, null );
$wpdb->delete( $wpdb->options, array( 'option_name' => Perdita_MCP_OAuth::OPTION_GRANTS . '_lock' ) );


/* =============================================================
 * Updater: the checksum and the release signature are verified in the
 * real upgrade path.
 *
 * The theme's updater shipped with verify_download() comparing against
 * request-scoped properties that only check() ever set. check() does not run
 * on the request that performs the upgrade, so the guard always
 * short-circuited and no package was ever verified. The plugin's updater
 * re-reads the manifest instead, and this is the regression that proves it.
 *
 * The updater is only constructed on an admin, cron or WP-CLI request, and it
 * is absent entirely from a wordpress.org build, so this loads the class
 * itself and skips when the file is not there.
 * ============================================================= */

if ( ! file_exists( PERDITA_CORE_DIR . 'inc/class-perdita-core-updater.php' ) ) {
	echo "SKIP  updater checksum verification (no self-hosted updater in this build)\n";
} else {
	require_once PERDITA_CORE_DIR . 'inc/class-perdita-core-updater.php';

	// A throwaway key pair stands in for the release key, so the suite can
	// sign manifests. The subclass only swaps trusted_keys(); every check the
	// live updater runs is the same code.
	$psx_kp      = sodium_crypto_sign_keypair();
	$psx_sk      = sodium_crypto_sign_secretkey( $psx_kp );
	$psx_updater = new class() extends Perdita_Core_Updater {
		/**
		 * Test keys.
		 *
		 * @var string[]
		 */
		public static $test_keys = array();

		/**
		 * The test key instead of the release key.
		 *
		 * @return string[]
		 */
		protected static function trusted_keys() {
			return self::$test_keys;
		}
	};
	$psx_updater::$test_keys = array( base64_encode( sodium_crypto_sign_publickey( $psx_kp ) ) );
	$psx_sign                = function ( $version, $sum, $slug = 'perdita-core', $sk = null ) use ( $psx_sk ) {
		return base64_encode( sodium_crypto_sign_detached( "perdita-release-v1\n{$slug}\n{$version}\n{$sum}", $sk ? $sk : $psx_sk ) );
	};
	$psx_unhook = function ( $u ) {
		remove_filter( 'pre_set_site_transient_update_plugins', array( $u, 'check' ) );
		remove_filter( 'plugins_api', array( $u, 'details' ) );
		remove_filter( 'auto_update_plugin', array( $u, 'auto_update' ) );
		remove_filter( 'upgrader_pre_download', array( $u, 'verify_download' ) );
		remove_action( 'upgrader_process_complete', array( $u, 'flush' ) );
		remove_action( 'init', array( $u, 'maybe_default_auto_updates' ) );
		remove_action( 'load-update-core.php', array( $u, 'flush_on_force_check' ) );
	};
	$psx_unhook( $psx_updater );

	$psx_orig_manifest = get_transient( Perdita_Core_Updater::CACHE );
	$psx_pkg           = 'https://perdita.app/updates/perdita-core-99.9.9-smoke.zip';
	$psx_pkg_bytes     = 'perdita-core-smoke-package-bytes';
	$psx_good_sum      = hash( 'sha256', $psx_pkg_bytes );
	$psx_bad_sum       = str_repeat( 'a', 64 );
	$psx_set           = function ( array $m ) {
		set_transient( Perdita_Core_Updater::CACHE, $m, MINUTE_IN_SECONDS );
	};

	// Serve the "download" from memory so the test never touches the network.
	// If the environment blocks the request before this filter runs (no DNS,
	// for instance), $psx_downloaded stays false and the precise assertions
	// below fall back to the outcome-independent one.
	$psx_downloaded = false;
	$psx_http       = function ( $pre, $args, $url ) use ( &$psx_downloaded, $psx_pkg, $psx_pkg_bytes ) {
		if ( $url !== $psx_pkg ) {
			return $pre;
		}
		$psx_downloaded = true;
		if ( ! empty( $args['filename'] ) ) {
			file_put_contents( $args['filename'], $psx_pkg_bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture standing in for a network download.
		}
		return array(
			'headers'  => array(),
			'body'     => '',
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => isset( $args['filename'] ) ? $args['filename'] : null,
		);
	};
	add_filter( 'pre_http_request', $psx_http, 10, 3 );

	// A correctly signed manifest whose checksum does not match the bytes.
	$psx_set(
		array(
			'version'      => '99.9.9',
			'download_url' => $psx_pkg,
			'checksum'     => $psx_bad_sum,
			'signature'    => $psx_sign( '99.9.9', $psx_bad_sum ),
		)
	);
	$psx_verify = $psx_updater->verify_download( false, $psx_pkg, null );
	$ok( is_wp_error( $psx_verify ), 'updater: verify_download() re-reads the manifest and actually verifies our package' );
	$ok(
		! $psx_downloaded || ( is_wp_error( $psx_verify ) && 'perdita_core_checksum_mismatch' === $psx_verify->get_error_code() ),
		'updater: a package whose sha256 does not match the manifest is rejected with perdita_core_checksum_mismatch'
	);

	// A package that is not ours falls straight through to core.
	$ok( false === $psx_updater->verify_download( false, 'https://example.com/some-other-plugin.zip', null ), 'updater: verify_download() ignores another plugin or theme package in the same request' );
	$ok( false === $psx_updater->verify_download( false, 'https://perdita.app/updates/perdita-99.9.9.zip', null ), 'updater: the theme\'s zip on the same host is left to the theme\'s updater' );

	// A manifest pointing off-host is not our package, however it is signed,
	// and our own zip cannot be installed against it either.
	$psx_set(
		array(
			'version'      => '99.9.9',
			'download_url' => 'https://example.com/perdita-core-99.9.9.zip',
			'checksum'     => $psx_good_sum,
			'signature'    => $psx_sign( '99.9.9', $psx_good_sum ),
		)
	);
	$ok( false === $psx_updater->verify_download( false, 'https://example.com/perdita-core-99.9.9.zip', null ), 'updater: a manifest whose download_url is off-host is declined, not verified' );
	$psx_off = $psx_updater->verify_download( false, $psx_pkg, null );
	$ok( is_wp_error( $psx_off ) && 'perdita_core_signature_invalid' === $psx_off->get_error_code(), 'updater: our own zip is refused while the manifest points off-host' );

	// No checksum or no signature: fail closed, never install unverified.
	$psx_set(
		array(
			'version'      => '99.9.9',
			'download_url' => $psx_pkg,
		)
	);
	$psx_nosum = $psx_updater->verify_download( false, $psx_pkg, null );
	$ok( is_wp_error( $psx_nosum ) && 'perdita_core_signature_invalid' === $psx_nosum->get_error_code(), 'updater: a manifest without a checksum is refused, not installed unverified' );
	$psx_set(
		array(
			'version'      => '99.9.9',
			'download_url' => $psx_pkg,
			'checksum'     => $psx_good_sum,
		)
	);
	$psx_downloaded = false;
	$psx_nosig      = $psx_updater->verify_download( false, $psx_pkg, null );
	$ok( is_wp_error( $psx_nosig ) && 'perdita_core_signature_invalid' === $psx_nosig->get_error_code(), 'updater: a manifest with a checksum but no signature is refused' );
	$ok( ! $psx_downloaded, 'updater: an unsigned manifest is refused before anything is downloaded' );

	// Signatures that do not cover exactly this slug, version and digest.
	$psx_other_kp = sodium_crypto_sign_keypair();
	$psx_bad_sigs = array(
		'a key other than the release key' => $psx_sign( '99.9.9', $psx_good_sum, 'perdita-core', sodium_crypto_sign_secretkey( $psx_other_kp ) ),
		'another Perdita package'          => $psx_sign( '99.9.9', $psx_good_sum, 'perdita' ),
		'an older version relabelled'      => $psx_sign( '99.9.8', $psx_good_sum ),
		'a different zip'                  => $psx_sign( '99.9.9', $psx_bad_sum ),
		'garbage'                          => 'not-base64!!',
	);
	foreach ( $psx_bad_sigs as $psx_label => $psx_sig ) {
		$psx_set(
			array(
				'version'      => '99.9.9',
				'download_url' => $psx_pkg,
				'checksum'     => $psx_good_sum,
				'signature'    => $psx_sig,
			)
		);
		$psx_r = $psx_updater->verify_download( false, $psx_pkg, null );
		$ok( is_wp_error( $psx_r ) && 'perdita_core_signature_invalid' === $psx_r->get_error_code(), "updater: a signature made for {$psx_label} is refused" );
	}

	// The shipped updater trusts only the release key, not the test key.
	$psx_live = new Perdita_Core_Updater();
	$psx_unhook( $psx_live );
	$psx_set(
		array(
			'version'      => '99.9.9',
			'download_url' => $psx_pkg,
			'checksum'     => $psx_good_sum,
			'signature'    => $psx_sign( '99.9.9', $psx_good_sum ),
		)
	);
	$psx_r = $psx_live->verify_download( false, $psx_pkg, null );
	$ok( is_wp_error( $psx_r ) && 'perdita_core_signature_invalid' === $psx_r->get_error_code(), 'updater: the shipped updater refuses a signature from any key but the release key' );
	$psx_rk = base64_decode( Perdita_Core_Updater::SIGNING_KEY, true );
	$ok( is_string( $psx_rk ) && SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES === strlen( $psx_rk ), 'updater: SIGNING_KEY is a 32-byte Ed25519 public key' );

	// One of our zips that the manifest no longer names is refused.
	$psx_old = $psx_updater->verify_download( false, 'https://perdita.app/updates/perdita-core-99.9.8.zip', null );
	$ok( is_wp_error( $psx_old ) && 'perdita_core_package_superseded' === $psx_old->get_error_code(), 'updater: a stale offer for an older Core zip is refused, not installed unverified' );

	// The matching digest under a valid signature returns the file to install.
	$psx_downloaded = false;
	$psx_verify_ok  = $psx_updater->verify_download( false, $psx_pkg, null );
	$ok(
		! $psx_downloaded || ( is_string( $psx_verify_ok ) && file_exists( $psx_verify_ok ) ),
		'updater: a signed package whose sha256 matches the manifest is accepted and handed to the installer'
	);
	if ( is_string( $psx_verify_ok ) && file_exists( $psx_verify_ok ) ) {
		wp_delete_file( $psx_verify_ok );
	}

	// check() only offers a signed manifest.
	$psx_base          = plugin_basename( PERDITA_CORE_FILE );
	$psx_tr            = new stdClass();
	$psx_tr->checked   = array( $psx_base => '0.0.1' );
	$psx_tr->response  = array();
	$psx_tr->no_update = array();
	$psx_offered       = $psx_updater->check( clone $psx_tr );
	$ok( isset( $psx_offered->response[ $psx_base ] ) && $psx_pkg === $psx_offered->response[ $psx_base ]->package, 'updater: check() offers an update whose manifest is signed' );
	$psx_set(
		array(
			'version'      => '99.9.9',
			'download_url' => $psx_pkg,
			'checksum'     => $psx_good_sum,
		)
	);
	$psx_offered = $psx_updater->check( clone $psx_tr );
	$ok( empty( $psx_offered->response[ $psx_base ] ), 'updater: check() does not offer an update whose manifest is unsigned' );

	// The beta label (0.19.6-beta): beta sorts above alpha in version_compare().
	$psx_label = function ( $installed, $offered ) use ( $psx_updater, $psx_set, $psx_sign, $psx_pkg, $psx_good_sum, $psx_base ) {
		$psx_set(
			array(
				'version'      => $offered,
				'download_url' => $psx_pkg,
				'checksum'     => $psx_good_sum,
				'signature'    => $psx_sign( $offered, $psx_good_sum ),
			)
		);
		$tr            = new stdClass();
		$tr->checked   = array( $psx_base => $installed );
		$tr->response  = array();
		$tr->no_update = array();
		$tr            = $psx_updater->check( $tr );
		return isset( $tr->response[ $psx_base ] ) ? $tr->response[ $psx_base ]->new_version : '';
	};
	$ok( '0.19.6-beta' === $psx_label( '0.19.5-alpha', '0.19.6-beta' ), 'updater: a signed beta is offered to a site on an earlier alpha' );
	$ok( '' === $psx_label( '0.19.6-beta', '0.19.6-alpha' ) && '' === $psx_label( '0.19.6-beta', '0.19.6-beta' ), 'updater: an alpha is never offered over a beta, nor a beta over itself' );

	// A wordpress.org offer for this plugin (the directory build, which has no
	// updater) never reaches a self-hosted install, even when our manifest is
	// unreachable and check() returns before offering anything of its own.
	$psx_wporg                         = clone $psx_tr;
	$psx_wporg->response[ $psx_base ] = (object) array(
		'slug'        => 'perdita-core',
		'new_version' => '99.9.9',
		'package'     => 'https://downloads.wordpress.org/plugin/perdita-core.99.9.9.zip',
	);
	set_transient( Perdita_Core_Updater::CACHE, array(), MINUTE_IN_SECONDS );
	$psx_offered = $psx_updater->check( clone $psx_wporg );
	$ok( empty( $psx_offered->response[ $psx_base ] ), 'updater: a wordpress.org offer for this plugin is dropped when our manifest is unreachable' );
	$psx_hdr = get_file_data( PERDITA_CORE_FILE, array( 'uri' => 'Update URI' ) );
	$ok( 'https://perdita.app/updates/perdita-core.json' === $psx_hdr['uri'], 'updater: the plugin header names the self-hosted channel in Update URI' );

	// The move from perdita.ericrosenberg.com to perdita.app. This build pins packages to perdita.app.
	// Installs that have not updated pin to the old host through their own MANIFEST constant.
	$psx_old_pkg = 'https://perdita.ericrosenberg.com/updates/perdita-core-99.9.9-smoke.zip';
	$psx_blank   = (object) array( 'checked' => array( $psx_base => '0.0.1' ), 'response' => array(), 'no_update' => array() );
	$psx_new_manifest = array( 'version' => '99.9.9', 'download_url' => $psx_pkg, 'checksum' => $psx_good_sum, 'signature' => $psx_sign( '99.9.9', $psx_good_sum ) );
	$psx_set( array_merge( $psx_new_manifest, array( 'download_url' => $psx_old_pkg ) ) );
	$psx_manifest_http = function ( $pre, $args, $url ) use ( $psx_new_manifest ) {
		if ( Perdita_Core_Updater::MANIFEST !== $url ) {
			return $pre;
		}
		return array( 'headers' => array(), 'body' => wp_json_encode( $psx_new_manifest ), 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array() );
	};
	add_filter( 'pre_http_request', $psx_manifest_http, 5, 3 );
	$psx_offered = $psx_updater->check( clone $psx_blank );
	$ok( isset( $psx_offered->response[ $psx_base ] ) && $psx_pkg === $psx_offered->response[ $psx_base ]->package, 'move: a manifest cached by an older build (old host) is dropped and the update is offered from perdita.app' );
	remove_filter( 'pre_http_request', $psx_manifest_http, 5 );
	$psx_set( array_merge( $psx_new_manifest, array( 'download_url' => 'https://evil.example/perdita-core-99.9.9.zip' ) ) );
	$psx_offered = $psx_updater->check( clone $psx_blank );
	$ok( empty( $psx_offered->response[ $psx_base ] ) && 'https://evil.example/perdita-core-99.9.9.zip' === get_transient( Perdita_Core_Updater::CACHE )['download_url'], 'move: a manifest naming any other host is still refused by the pin, and is not refetched' );
	$ok( false === $psx_updater->verify_download( false, $psx_old_pkg, null ), 'move: this build does not treat a package on the old host as ours (an old install pins to the old host itself)' );

	// Background auto-updates follow the owner's Plugins-screen setting, so
	// the Plugins screen is never told the setting is forced (0.19.7-beta).
	// tests/smoke-auto-update.php covers the once-per-site default.
	$psx_offer = (object) array( 'plugin' => plugin_basename( PERDITA_CORE_FILE ) );
	$ok( null === $psx_updater->auto_update( null, $psx_offer ), 'updater: the auto-update setting is the owner\'s, not forced by the plugin' );
	$psx_force = function () {
		return true;
	};
	add_filter( 'perdita_core_auto_update', $psx_force );
	$ok( true === $psx_updater->auto_update( null, $psx_offer ), 'updater: perdita_core_auto_update turns background updates back on' );
	remove_filter( 'perdita_core_auto_update', $psx_force );
	$ok( null === $psx_updater->auto_update( null, (object) array( 'plugin' => 'some-other/plugin.php' ) ), 'updater: another plugin\'s auto-update decision is left alone' );

	remove_filter( 'pre_http_request', $psx_http, 10 );
	if ( false === $psx_orig_manifest ) {
		delete_transient( Perdita_Core_Updater::CACHE );
	} else {
		set_transient( Perdita_Core_Updater::CACHE, $psx_orig_manifest, 12 * HOUR_IN_SECONDS );
	}
}


/* =============================================================
 * SMTP: opportunistic STARTTLS stays ON when no scheme is picked.
 * ============================================================= */

require_once PERDITA_CORE_DIR . 'inc/modules/smtp/class-perdita-smtp.php';

$psx_orig_smtp = get_option( Perdita_SMTP::OPTION );
$psx_smtp      = new Perdita_SMTP( perdita_core() );
remove_action( 'phpmailer_init', array( $psx_smtp, 'configure' ) );
remove_filter( 'wp_mail_from', array( $psx_smtp, 'filter_from' ), 20 );
remove_filter( 'wp_mail_from_name', array( $psx_smtp, 'filter_from_name' ), 20 );
remove_action( 'wp_mail_succeeded', array( $psx_smtp, 'log_success' ) );
remove_action( 'wp_mail_failed', array( $psx_smtp, 'log_failure' ) );

$psx_fake_mailer = function () {
	return new class() {
		public $Host        = '';    // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- mirrors PHPMailer's own property names.
		public $Port        = 0;     // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		public $SMTPSecure  = null;  // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		public $SMTPAutoTLS = null;  // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		public $SMTPAuth    = null;  // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		public $Username    = '';    // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		public $Password    = '';    // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		public function isSMTP() {} // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- mirrors PHPMailer's own method name.
	};
};

update_option( Perdita_SMTP::OPTION, array( 'host' => 'smtp.example.com', 'port' => 25, 'encryption' => '', 'auth' => false ) );
$psx_mailer_plain = $psx_fake_mailer();
$psx_smtp->configure( $psx_mailer_plain );
$ok( true === $psx_mailer_plain->SMTPAutoTLS, 'smtp: opportunistic STARTTLS stays ON when the owner picked no encryption scheme' );
$ok( '' === $psx_mailer_plain->SMTPSecure, 'smtp: SMTPSecure is empty when no scheme is picked' );

update_option( Perdita_SMTP::OPTION, array( 'host' => 'smtp.example.com', 'port' => 465, 'encryption' => 'ssl', 'auth' => false ) );
$psx_mailer_ssl = $psx_fake_mailer();
$psx_smtp->configure( $psx_mailer_ssl );
$ok( false === $psx_mailer_ssl->SMTPAutoTLS, 'smtp: auto-TLS is off once the owner picked an explicit scheme (SMTPSecure already forces it)' );

if ( false === $psx_orig_smtp ) {
	delete_option( Perdita_SMTP::OPTION );
} else {
	update_option( Perdita_SMTP::OPTION, $psx_orig_smtp );
}

/* =============================================================
 * Subscriptions: the confirmation token is spent when it is used.
 * ============================================================= */

require_once PERDITA_CORE_DIR . 'inc/modules/subscriptions/class-perdita-subscriptions.php';

$psx_subs_table = Perdita_Subscriptions::table();
$ok( $wpdb->get_var( "SHOW TABLES LIKE '$psx_subs_table'" ) === $psx_subs_table, 'subscriptions: the subscribers table is present for the confirm-token test' ); // phpcs:ignore WordPress.DB

$psx_subs        = new Perdita_Subscriptions( perdita_core() );
$psx_subs_email  = 'perdita-smoke-confirm@example.com';
$psx_blank_email = 'perdita-smoke-blank-token@example.com';
$psx_confirm_tok = bin2hex( random_bytes( 32 ) );
$psx_now         = current_time( 'mysql' );

$wpdb->delete( $psx_subs_table, array( 'email' => $psx_subs_email ) ); // phpcs:ignore WordPress.DB
$wpdb->delete( $psx_subs_table, array( 'email' => $psx_blank_email ) ); // phpcs:ignore WordPress.DB
$wpdb->insert( // phpcs:ignore WordPress.DB
	$psx_subs_table,
	array( 'email' => $psx_subs_email, 'status' => 'pending', 'confirm_token' => $psx_confirm_tok, 'last_sent' => $psx_now, 'created' => $psx_now ),
	array( '%s', '%s', '%s', '%s', '%s' )
);
// A row whose confirm_token is already '' -- exactly what every confirmed
// subscriber now looks like, and what a blank incoming token would match.
$wpdb->insert( // phpcs:ignore WordPress.DB
	$psx_subs_table,
	array( 'email' => $psx_blank_email, 'status' => 'pending', 'confirm_token' => '', 'last_sent' => $psx_now, 'created' => $psx_now ),
	array( '%s', '%s', '%s', '%s', '%s' )
);

// The confirm handler ends in wp_die(), which would take the whole CLI run
// with it, so swap in a handler that throws and catch it here.
$psx_die = function () {
	return function ( $message, $title = '', $args = array() ) {
		throw new Exception( 'perdita-smoke-wp-die' );
	};
};
add_filter( 'wp_die_handler', $psx_die, 99999 );

$psx_orig_get = $_GET;

$_GET['perdita_subscribe_confirm'] = $psx_confirm_tok;
try {
	$psx_subs->maybe_handle_confirm();
} catch ( Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- wp_die() is the handler's normal exit.
	unset( $e );
}
$psx_confirmed = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $psx_subs_table WHERE email = %s", $psx_subs_email ) ); // phpcs:ignore WordPress.DB
$ok( $psx_confirmed && 'confirmed' === $psx_confirmed->status, 'subscriptions: a valid confirm link still confirms the subscriber' );
$ok( $psx_confirmed && '' === (string) $psx_confirmed->confirm_token, 'subscriptions: the confirm token is cleared once it has been used (the link is not replayable forever)' );
$ok( $psx_confirmed && '' !== (string) $psx_confirmed->unsubscribe_token, 'subscriptions: confirming issues an unsubscribe token' );

// A blank/whitespace token must be rejected before the lookup, or it matches
// every row whose confirm_token has been cleared.
$_GET['perdita_subscribe_confirm'] = ' ';
$psx_blank_died = false;
try {
	$psx_subs->maybe_handle_confirm();
} catch ( Exception $e ) {
	$psx_blank_died = true;
	unset( $e );
}
$psx_blank_row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $psx_subs_table WHERE email = %s", $psx_blank_email ) ); // phpcs:ignore WordPress.DB
$ok( $psx_blank_died, 'subscriptions: a blank confirm token is rejected outright' );
$ok( $psx_blank_row && 'pending' === $psx_blank_row->status, 'subscriptions: a blank confirm token does not confirm a row whose own token was already cleared' );

$_GET = $psx_orig_get;
remove_filter( 'wp_die_handler', $psx_die, 99999 );

$wpdb->delete( $psx_subs_table, array( 'email' => $psx_subs_email ) ); // phpcs:ignore WordPress.DB
$wpdb->delete( $psx_subs_table, array( 'email' => $psx_blank_email ) ); // phpcs:ignore WordPress.DB

// RFC 8058: the one-click endpoint the List-Unsubscribe header points at.
$ok( 0 === strpos( Perdita_Subscriptions::one_click_unsubscribe_url( 'abc123' ), rest_url( 'perdita/v1/unsubscribe' ) ), 'subscriptions: the one-click unsubscribe URL points at the POST-only REST endpoint' );

/* =============================================================
 * Security module: the author-enumeration block must not delete real
 * author archives on a site using plain permalinks, and must not
 * hand out a permanently cached redirect.
 * ============================================================= */

require_once PERDITA_CORE_DIR . 'inc/modules/security/class-perdita-security.php';

$psx_security  = new Perdita_Security( perdita_core() );
$psx_orig_user = get_current_user_id();
$psx_orig_get  = $_GET;

// Filter the option rather than writing it: saving an empty
// permalink_structure can flush the site's rewrite rules away, and this test
// has no business touching them.
$psx_plain_perma  = '__return_empty_string';
$psx_pretty_perma = function () {
	return '/%postname%/';
};

wp_set_current_user( 0 );
$_GET['author'] = '1';

// Throwing from the wp_redirect filter stops wp_safe_redirect() before it
// can header()+exit and kill the rest of the run.
$psx_redirect_status = 0;
$psx_catch_redirect  = function ( $location, $status ) use ( &$psx_redirect_status ) {
	$psx_redirect_status = (int) $status;
	throw new Exception( 'perdita-smoke-redirect' );
};
add_filter( 'wp_redirect', $psx_catch_redirect, 1, 2 );

add_filter( 'pre_option_permalink_structure', $psx_plain_perma );
$psx_plain_redirected = false;
try {
	$psx_security->block_author_scan();
} catch ( Exception $e ) {
	$psx_plain_redirected = true;
	unset( $e );
}
$ok( false === $psx_plain_redirected, 'security: ?author=N is left alone on plain permalinks, where it IS the real author archive URL' );
remove_filter( 'pre_option_permalink_structure', $psx_plain_perma );

add_filter( 'pre_option_permalink_structure', $psx_pretty_perma );
$psx_pretty_redirected = false;
try {
	$psx_security->block_author_scan();
} catch ( Exception $e ) {
	$psx_pretty_redirected = true;
	unset( $e );
}
$ok( true === $psx_pretty_redirected, 'security: ?author=N is still blocked on pretty permalinks, where it is only ever an enumeration probe' );
$ok( 302 === $psx_redirect_status, 'security: the author-enumeration block redirects with 302, never a browser-cached 301' );

remove_filter( 'pre_option_permalink_structure', $psx_pretty_perma );
remove_filter( 'wp_redirect', $psx_catch_redirect, 1 );
$_GET = $psx_orig_get;
wp_set_current_user( $psx_orig_user ? $psx_orig_user : 1 );

/* --- cleanup ------------------------------------------------- */
wp_delete_user( $psx_sub );
wp_set_current_user( 1 );

/* =============================================================
 * Security suite: CIDR allowlists, one login message, escalating
 * lockouts, Turnstile on the login forms, hardening, HSTS, the core
 * file check, and the screen. Every option, transient, file, global,
 * and hook this block touches is put back.
 * ============================================================= */

require_once PERDITA_CORE_DIR . 'inc/modules/security/class-perdita-security-admin.php';

echo "\n--- security suite (tests/smoke-security.php) ---\n";

$psn_opt_keys = array( 'perdita_security_settings', 'perdita_security_core_check', 'perdita_forms_settings', 'perdita_forms_turnstile_key_error' );
$psn_orig_opts = array();
foreach ( $psn_opt_keys as $psn_k ) {
	$psn_orig_opts[ $psn_k ] = get_option( $psn_k, '__perdita_absent__' );
}
$psn_orig_server  = $_SERVER;
$psn_orig_post    = $_POST;
$psn_orig_request = $_REQUEST;
$psn_orig_pagenow = isset( $GLOBALS['pagenow'] ) ? $GLOBALS['pagenow'] : null;
$psn_had_generator = has_action( 'wp_head', 'wp_generator' );
$psn_orig_uploads_ht = trailingslashit( wp_get_upload_dir()['basedir'] ) . '.htaccess';
$psn_orig_uploads_md5 = is_file( $psn_orig_uploads_ht ) ? md5_file( $psn_orig_uploads_ht ) : null;

// Build a module object with chosen settings and NO hooks: the constructor
// runs on defaults, then the settings are swapped in by reflection.
$psn_make = function ( array $override = array() ) {
	delete_option( 'perdita_security_settings' );
	$inst = new Perdita_Security( perdita_core() );
	$ref  = new ReflectionProperty( 'Perdita_Security', 'settings' );
	if ( PHP_VERSION_ID < 80100 ) { $ref->setAccessible( true ); }
	$ref->setValue( $inst, array_merge( Perdita_Security::defaults(), $override ) );
	return $inst;
};
// Remove every hook an object registered.
$psn_unhook = function ( $obj ) {
	global $wp_filter;
	foreach ( $wp_filter as $hook => $wp_hook ) {
		foreach ( $wp_hook->callbacks as $prio => $cbs ) {
			foreach ( $cbs as $cb ) {
				if ( is_array( $cb['function'] ) && $cb['function'][0] === $obj ) {
					remove_filter( $hook, $cb['function'], $prio );
				}
			}
		}
	}
};
$psn_lock_keys = function ( $ip ) {
	return array( Perdita_Security::TRANSIENT_PREFIX . md5( $ip ), Perdita_Security::TRANSIENT_PREFIX . 'h_' . md5( $ip ) );
};

/* ---------- defaults and keys ---------- */
$psn_d = Perdita_Security::defaults();
$ok( false === $psn_d['login_generic_errors'] && false === $psn_d['login_long_lockout'] && false === $psn_d['login_turnstile'] && false === $psn_d['hide_wp_version'] && false === $psn_d['block_uploads_php'] && false === $psn_d['disable_app_passwords'] && false === $psn_d['hsts'] && false === $psn_d['hsts_subdomains'] && false === $psn_d['core_check'] && false === $psn_d['firewall'], 'security suite: every new protection defaults off' );
$ok( 'off' === $psn_d['restrict_rest'] && 4 === $psn_d['login_long_after'] && 24 === $psn_d['login_long_hours'] && 31536000 === $psn_d['hsts_max_age'] && 'monitor' === $psn_d['firewall_mode'] && 'plugin' === $psn_d['firewall_load'] && 'edit_posts' === $psn_d['fw_trust_roles'], 'security suite: the spec defaults (restrict_rest off, 4 lockouts, 24 hours, HSTS 31536000, firewall monitor and plugin)' );
$psn_own = Perdita_Security::own_keys();
$ok( in_array( 'hsts', $psn_own, true ) && ! in_array( 'firewall', $psn_own, true ) && ! in_array( 'fw_rules', $psn_own, true ) && ! in_array( 'firewall_mode', $psn_own, true ), 'security suite: own_keys() leaves the firewall keys to the firewall screen' );

/* ---------- allowlist entries ---------- */
foreach ( array( '203.0.113.7', '10.0.0.0/8', '198.51.100.0/24', '::1', '2001:db8::/32', '2001:db8::1/128', '1.2.3.4/32' ) as $psn_good ) {
	$ok( $psn_good === Perdita_Security::clean_allow_entry( $psn_good ), 'allowlist: accepts ' . $psn_good );
}
foreach ( array( '10.0.0.0/33', '::1/129', '1.2.3.4/0', '::/0', '1.2.3.4/', 'x/8', '10.0.0.0/8/8', '5.6.7.8-5.6.7.9', 'nope', '', '10.0.0.0/-1', '10.0.0.0/ 8' ) as $psn_bad ) {
	$ok( '' === Perdita_Security::clean_allow_entry( $psn_bad ), 'allowlist: refuses "' . $psn_bad . '"' );
}
$ok( Perdita_Security::ip_in_list( '10.1.2.3', array( '10.0.0.0/8' ) ) && ! Perdita_Security::ip_in_list( '11.0.0.1', array( '10.0.0.0/8' ) ), 'allowlist: an IPv4 range matches inside and not outside' );
$ok( Perdita_Security::ip_in_list( '2001:db8::5', array( '2001:db8::/32' ) ) && ! Perdita_Security::ip_in_list( '2001:db9::5', array( '2001:db8::/32' ) ), 'allowlist: an IPv6 range matches inside and not outside' );
$ok( Perdita_Security::ip_in_list( '2001:db8::1', array( '2001:0db8:0:0:0:0:0:1' ) ) && Perdita_Security::ip_in_list( '203.0.113.7', array( '203.0.113.7' ) ) && ! Perdita_Security::ip_in_list( '203.0.113.8', array( '203.0.113.7' ) ), 'allowlist: exact addresses match, IPv6 in any spelling' );
$ok( ! Perdita_Security::ip_in_list( '10.1.2.3', array( '10.0.0.0/abc', '1.2.3.4/0', '' ) ) && ! Perdita_Security::ip_in_list( '', array( '10.0.0.0/8' ) ) && ! Perdita_Security::ip_in_list( 'junk', array( '10.0.0.0/8' ) ), 'allowlist: a stored bad entry or a bad address never matches' );
$ok( ! Perdita_Security::ip_in_list( '2001:db8::1', array( '10.0.0.0/8' ) ) && ! Perdita_Security::ip_in_list( '10.0.0.1', array( '2001:db8::/32' ) ), 'allowlist: an IPv4 range never matches an IPv6 address, and the other way round' );
$psn_l = Perdita_Security::sanitize_login( array( 'login_limit' => '1', 'ip_allowlist' => "10.0.0.0/8\n2001:db8::/32\n10.0.0.0/8\n1.2.3.4/0\n1.2.3.4" ) );
$ok( array( '10.0.0.0/8', '2001:db8::/32', '1.2.3.4' ) === $psn_l['ip_allowlist'], 'allowlist: sanitize_login() keeps CIDR, drops duplicates and /0' );

/* ---------- the limiter honors ranges ---------- */
$psn_ip_in  = '198.51.100.7';
$psn_ip_out = '203.0.113.9';
foreach ( array( $psn_ip_in, $psn_ip_out ) as $psn_x ) {
	foreach ( $psn_lock_keys( $psn_x ) as $psn_k ) { delete_transient( $psn_k ); }
}
$psn_m = $psn_make( array( 'login_limit' => true, 'login_max' => 2, 'ip_allowlist' => array( '198.51.100.0/24' ) ) );
$_SERVER['REMOTE_ADDR'] = $psn_ip_in;
for ( $psn_i = 0; $psn_i < 5; $psn_i++ ) { $psn_m->record_failure(); }
$ok( ! is_wp_error( $psn_m->check_lockout( null ) ), 'limiter: an address inside an allowlisted range is never locked out' );
$ok( false === get_transient( $psn_lock_keys( $psn_ip_in )[0] ), 'limiter: an allowlisted address is not even counted' );
$_SERVER['REMOTE_ADDR'] = $psn_ip_out;
for ( $psn_i = 0; $psn_i < 2; $psn_i++ ) { $psn_m->record_failure(); }
$psn_lock = $psn_m->check_lockout( null );
$ok( is_wp_error( $psn_lock ) && 'perdita_security_locked' === $psn_lock->get_error_code(), 'limiter: an address outside the range still locks out' );
foreach ( array( $psn_ip_in, $psn_ip_out ) as $psn_x ) {
	foreach ( $psn_lock_keys( $psn_x ) as $psn_k ) { delete_transient( $psn_k ); }
}

/* ---------- escalating lockouts ---------- */
$psn_ip = '203.0.113.50';
$_SERVER['REMOTE_ADDR'] = $psn_ip;
list( $psn_rk, $psn_hk ) = $psn_lock_keys( $psn_ip );
$psn_expire = function () use ( $psn_rk ) {
	set_transient( $psn_rk, array( 'count' => 0, 'locked_until' => 0 ), 900 ); // The lockout ran out.
};
$psn_fail = function ( $m, $n ) { for ( $i = 0; $i < $n; $i++ ) { $m->record_failure(); } };
delete_transient( $psn_rk ); delete_transient( $psn_hk );
$psn_m = $psn_make( array( 'login_limit' => true, 'login_max' => 2, 'login_lockout' => 1, 'login_long_lockout' => true, 'login_long_after' => 2, 'login_long_hours' => 5 ) );
$psn_len = function () use ( $psn_rk ) {
	$r = get_transient( $psn_rk );
	return is_array( $r ) ? (int) $r['locked_until'] - time() : -1;
};
$psn_fail( $psn_m, 2 );
$ok( $psn_len() > 50 && $psn_len() <= 60, 'long lockout: the first lockout is the regular 1 minute' );
$psn_expire(); $psn_fail( $psn_m, 2 );
$ok( $psn_len() > 50 && $psn_len() <= 60, 'long lockout: the second lockout is still regular' );
$psn_expire(); $psn_fail( $psn_m, 2 );
$ok( $psn_len() > 5 * HOUR_IN_SECONDS - 30 && $psn_len() <= 5 * HOUR_IN_SECONDS, 'long lockout: the lockout after login_long_after earlier ones lasts login_long_hours' );
$psn_fail( $psn_m, 3 );
$ok( $psn_len() > 5 * HOUR_IN_SECONDS - 30, 'long lockout: failures during the long lockout never shorten it' );
$psn_h = get_transient( $psn_hk );
$ok( is_array( $psn_h ) && 3 === count( $psn_h ), 'long lockout: failures during a lockout are not counted as new lockouts' );
$psn_ttl = (int) get_option( '_transient_timeout_' . $psn_rk ) - time();
$ok( $psn_ttl > 5 * HOUR_IN_SECONDS - 60, 'long lockout: the record outlives the block, so it still self-expires after' );
$psn_lock = $psn_m->check_lockout( null );
$ok( is_wp_error( $psn_lock ) && 'perdita_security_locked' === $psn_lock->get_error_code(), 'long lockout: the lockout message still comes back' );
// Old history does not count.
delete_transient( $psn_rk ); set_transient( $psn_hk, array( time() - 2 * DAY_IN_SECONDS, time() - 3 * DAY_IN_SECONDS, time() - 30 * HOUR_IN_SECONDS ), DAY_IN_SECONDS );
$psn_fail( $psn_m, 2 );
$ok( $psn_len() > 50 && $psn_len() <= 60, 'long lockout: lockouts older than 24 hours do not count' );
// Off means regular every time.
delete_transient( $psn_rk ); delete_transient( $psn_hk );
$psn_m = $psn_make( array( 'login_limit' => true, 'login_max' => 2, 'login_lockout' => 1, 'login_long_lockout' => false, 'login_long_after' => 1 ) );
for ( $psn_i = 0; $psn_i < 4; $psn_i++ ) { $psn_expire(); $psn_fail( $psn_m, 2 ); }
$ok( $psn_len() <= 60 && false === get_transient( $psn_hk ), 'long lockout: with the box off every lockout is regular and no history is kept' );
// Allowlist still wins.
delete_transient( $psn_rk ); delete_transient( $psn_hk );
$psn_m = $psn_make( array( 'login_limit' => true, 'login_max' => 1, 'login_lockout' => 1, 'login_long_lockout' => true, 'login_long_after' => 1, 'login_long_hours' => 5, 'ip_allowlist' => array( '203.0.113.0/24' ) ) );
$psn_fail( $psn_m, 6 );
$ok( ! is_wp_error( $psn_m->check_lockout( null ) ) && false === get_transient( $psn_hk ), 'long lockout: an allowlisted address never gets a lockout, long or short' );
delete_transient( $psn_rk ); delete_transient( $psn_hk );
$ok( 4 === Perdita_Security::sanitize_protections( array( 'login_long_after' => 'x' ) )['login_long_after'] && 100 === Perdita_Security::sanitize_protections( array( 'login_long_after' => 900 ) )['login_long_after'] && 720 === Perdita_Security::sanitize_protections( array( 'login_long_hours' => 99999 ) )['login_long_hours'] && 1 === Perdita_Security::sanitize_protections( array( 'login_long_hours' => 0 ) )['login_long_hours'], 'long lockout: the numbers are clamped' );

/* ---------- one login message ---------- */
$psn_gen = Perdita_Security::generic_login_message();
$psn_m   = $psn_make( array( 'login_generic_errors' => true ) );
foreach ( array( 'invalid_username', 'invalid_email', 'incorrect_password' ) as $psn_code ) {
	$psn_r = $psn_m->generic_login_error( new WP_Error( $psn_code, 'Distinct ' . $psn_code ) );
	$ok( is_wp_error( $psn_r ) && $psn_gen === $psn_r->get_error_message(), 'generic errors: ' . $psn_code . ' becomes the one message' );
}
foreach ( array( 'perdita_security_locked', 'perdita_2fa_required', 'perdita_2fa_locked', 'empty_username', 'empty_password', 'perdita_turnstile' ) as $psn_code ) {
	$psn_in = new WP_Error( $psn_code, 'Own ' . $psn_code );
	$ok( $psn_in === $psn_m->generic_login_error( $psn_in ), 'generic errors: ' . $psn_code . ' keeps its own message' );
}
$psn_mixed = new WP_Error( 'incorrect_password', 'a' );
$psn_mixed->add( 'perdita_security_locked', 'b' );
$ok( $psn_mixed === $psn_m->generic_login_error( $psn_mixed ), 'generic errors: an error that also carries another code is left alone' );
$psn_u = new WP_User( 1 );
$ok( $psn_u === $psn_m->generic_login_error( $psn_u ) && null === $psn_m->generic_login_error( null ), 'generic errors: a good login and a null pass through' );

// Through the real authenticate chain, with the lockout and a 2FA stand-in.
$psn_user_id = wp_insert_user( array( 'user_login' => 'perdita_smoke_gen', 'user_email' => 'perdita-smoke-gen@example.com', 'user_pass' => 'Gen-pass-12345!', 'role' => 'subscriber' ) );
$psn_ip = '203.0.113.60';
$_SERVER['REMOTE_ADDR'] = $psn_ip;
list( $psn_rk, $psn_hk ) = $psn_lock_keys( $psn_ip );
delete_transient( $psn_rk ); delete_transient( $psn_hk );
$psn_wired = new Perdita_Security( perdita_core() ); // Hooks follow the saved option, so set it first.
$psn_unhook( $psn_wired );
update_option( 'perdita_security_settings', array_merge( Perdita_Security::defaults(), array( 'login_generic_errors' => true, 'login_limit' => true, 'login_max' => 5, 'login_lockout' => 5 ) ) );
$psn_wired = new Perdita_Security( perdita_core() );
$psn_unknown = wp_authenticate( 'perdita_no_such_user_zz', 'whatever' );
$psn_wrong   = wp_authenticate( 'perdita_smoke_gen', 'wrong-password' );
$psn_noemail = wp_authenticate( 'nobody-zz@example.com', 'whatever' );
$psn_okuser  = wp_authenticate( 'perdita_smoke_gen', 'Gen-pass-12345!' );
$ok( is_wp_error( $psn_unknown ) && is_wp_error( $psn_wrong ) && is_wp_error( $psn_noemail ) && $psn_gen === $psn_unknown->get_error_message() && $psn_gen === $psn_wrong->get_error_message() && $psn_gen === $psn_noemail->get_error_message(), 'generic errors: unknown user, wrong password, and unknown email give the same message through wp_authenticate()' );
$ok( $psn_okuser instanceof WP_User, 'generic errors: a correct login still works' );
// Lockout message survives: failures so far reached login_max.
delete_transient( $psn_rk );
for ( $psn_i = 0; $psn_i < 5; $psn_i++ ) { wp_authenticate( 'perdita_smoke_gen', 'bad' . $psn_i ); }
$psn_locked = wp_authenticate( 'perdita_smoke_gen', 'Gen-pass-12345!' );
$ok( is_wp_error( $psn_locked ) && 'perdita_security_locked' === $psn_locked->get_error_code(), 'generic errors: the lockout message is not swallowed' );
delete_transient( $psn_rk ); delete_transient( $psn_hk );
// A 2FA stand-in at 40 (Perdita Pro uses 30 and 40) survives.
$psn_2fa = function ( $user ) { return $user instanceof WP_User ? new WP_Error( 'perdita_2fa_required', 'Enter your code.' ) : $user; };
add_filter( 'authenticate', $psn_2fa, 40 );
$psn_r = wp_authenticate( 'perdita_smoke_gen', 'Gen-pass-12345!' );
$ok( is_wp_error( $psn_r ) && 'perdita_2fa_required' === $psn_r->get_error_code() && 'Enter your code.' === $psn_r->get_error_message(), 'generic errors: the two-factor prompt keeps its own message' );
remove_filter( 'authenticate', $psn_2fa, 40 );
$psn_unhook( $psn_wired );
$ok( false === has_filter( 'authenticate', array( $psn_wired, 'generic_login_error' ) ), 'generic errors: the hook is gone after the test unhooks it' );
wp_delete_user( $psn_user_id );
delete_transient( $psn_rk ); delete_transient( $psn_hk );

/* ---------- Turnstile on the login forms ---------- */
delete_option( 'perdita_forms_turnstile_key_error' );
update_option( 'perdita_forms_settings', array( 'turnstile_site_key' => '', 'turnstile_secret_key' => '' ) );
$ok( null === Perdita_Security::turnstile_keys(), 'login turnstile: no keys, no widget' );
$psn_m = $psn_make( array( 'login_turnstile' => true ) );
$ok( ! $psn_m->turnstile_active(), 'login turnstile: on in settings but inactive without Forms keys' );
ob_start(); $psn_m->render_turnstile(); $psn_out = ob_get_clean();
$ok( '' === $psn_out, 'login turnstile: nothing renders without keys' );
$psn_u = new WP_User( 1 );
$_SERVER['REQUEST_METHOD'] = 'POST'; $GLOBALS['pagenow'] = 'wp-login.php';
$ok( $psn_u === $psn_m->check_login_turnstile( $psn_u, 'admin', 'pw' ), 'login turnstile: without keys a login is never challenged' );

update_option( 'perdita_forms_settings', array( 'turnstile_site_key' => 'site-key-1', 'turnstile_secret_key' => Perdita_Crypto::encrypt( 'secret-1' ) ) );
$psn_calls = 0;
$psn_reply = array( 'code' => 200, 'body' => wp_json_encode( array( 'success' => true ) ) );
$psn_http  = function ( $pre, $args, $url ) use ( &$psn_calls, &$psn_reply ) {
	if ( false === strpos( $url, 'challenges.cloudflare.com' ) ) { return $pre; }
	$psn_calls++;
	if ( is_wp_error( $psn_reply ) ) { return $psn_reply; }
	return array( 'headers' => array(), 'body' => $psn_reply['body'], 'response' => array( 'code' => $psn_reply['code'], 'message' => '' ), 'cookies' => array(), 'filename' => null );
};
add_filter( 'pre_http_request', $psn_http, 10, 3 );
$psn_nm = function () use ( $psn_make ) { return $psn_make( array( 'login_turnstile' => true ) ); };

$psn_m = $psn_nm();
$ok( $psn_m->turnstile_active(), 'login turnstile: active once the setting is on and Forms keys exist' );
ob_start(); $psn_m->render_turnstile(); $psn_out = ob_get_clean();
$ok( false !== strpos( $psn_out, 'class="cf-turnstile"' ) && false !== strpos( $psn_out, 'data-sitekey="site-key-1"' ) && false === strpos( $psn_out, 'secret-1' ), 'login turnstile: the widget carries the site key and never the secret' );
$_POST = array();
$psn_r = $psn_m->check_login_turnstile( $psn_u, 'admin', 'pw' );
$ok( is_wp_error( $psn_r ) && 'perdita_turnstile' === $psn_r->get_error_code() && 0 === $psn_calls, 'login turnstile: a post with no token is turned away without calling Cloudflare' );
$_POST['cf-turnstile-response'] = 'tok';
$psn_m = $psn_nm();
$ok( $psn_u === $psn_m->check_login_turnstile( $psn_u, 'admin', 'pw' ) && 1 === $psn_calls, 'login turnstile: a good token lets the login through' );
$psn_m->check_login_turnstile( $psn_u, 'admin', 'pw' );
$ok( 1 === $psn_calls, 'login turnstile: one request asks Cloudflare once' );
$psn_reply = array( 'code' => 200, 'body' => wp_json_encode( array( 'success' => false, 'error-codes' => array( 'invalid-input-response' ) ) ) );
$psn_m = $psn_nm();
$psn_r = $psn_m->check_login_turnstile( $psn_u, 'admin', 'pw' );
$ok( is_wp_error( $psn_r ) && 'perdita_turnstile' === $psn_r->get_error_code(), 'login turnstile: a failed challenge blocks the login' );
$psn_m = $psn_nm();
$psn_r = $psn_m->check_login_turnstile( new WP_Error( 'incorrect_password', 'x' ), 'admin', 'pw' );
$ok( 'perdita_turnstile' === $psn_r->get_error_code(), 'login turnstile: a failed challenge also hides a wrong password' );
$psn_reg = new WP_Error();
$psn_m->check_register_turnstile( $psn_reg );
$psn_lp = new WP_Error();
$psn_m->check_lostpassword_turnstile( $psn_lp );
$ok( $psn_reg->get_error_code() === 'perdita_turnstile' && $psn_lp->get_error_code() === 'perdita_turnstile', 'login turnstile: registration and lost password get the same verdict' );
$psn_reply = array( 'code' => 200, 'body' => wp_json_encode( array( 'success' => false, 'error-codes' => array( 'invalid-input-secret' ) ) ) );
$psn_m = $psn_nm();
$ok( $psn_u === $psn_m->check_login_turnstile( $psn_u, 'admin', 'pw' ) && 1 === (int) get_option( 'perdita_forms_turnstile_key_error' ), 'login turnstile: a rejected site secret fails open and flags the owner notice' );
delete_option( 'perdita_forms_turnstile_key_error' );
$psn_ff = function () { return false; };
add_filter( 'perdita_forms_turnstile_fail_open', $psn_ff );
$psn_m = $psn_nm();
$ok( is_wp_error( $psn_m->check_login_turnstile( $psn_u, 'admin', 'pw' ) ), 'login turnstile: perdita_forms_turnstile_fail_open false makes a rejected secret fail closed' );
remove_filter( 'perdita_forms_turnstile_fail_open', $psn_ff );
delete_option( 'perdita_forms_turnstile_key_error' );
$psn_reply = array( 'code' => 503, 'body' => '<html>down</html>' );
$psn_m = $psn_nm();
$ok( $psn_u === $psn_m->check_login_turnstile( $psn_u, 'admin', 'pw' ), 'login turnstile: a 503 fails open' );
$psn_reply = array( 'code' => 429, 'body' => '' );
$psn_m = $psn_nm();
$ok( $psn_u === $psn_m->check_login_turnstile( $psn_u, 'admin', 'pw' ), 'login turnstile: a 429 fails open' );
$psn_reply = new WP_Error( 'http_request_failed', 'timeout' );
$psn_m = $psn_nm();
$ok( $psn_u === $psn_m->check_login_turnstile( $psn_u, 'admin', 'pw' ), 'login turnstile: a transport error fails open' );
// Never challenged: other entry points have another pagenow, a GET, or no creds.
$psn_reply = array( 'code' => 200, 'body' => wp_json_encode( array( 'success' => false ) ) );
$psn_calls = 0; $_POST = array();
foreach ( array( 'xmlrpc.php', 'index.php', 'wp-cron.php', 'admin-ajax.php' ) as $psn_pn ) {
	$GLOBALS['pagenow'] = $psn_pn;
	$psn_m = $psn_nm();
	$ok( $psn_u === $psn_m->check_login_turnstile( $psn_u, 'admin', 'pw' ), 'login turnstile: a post to ' . $psn_pn . ' (XML-RPC, REST, application passwords, WP-CLI) is never challenged' );
}
$GLOBALS['pagenow'] = 'wp-login.php';
$_SERVER['REQUEST_METHOD'] = 'GET';
$psn_m = $psn_nm();
$ok( $psn_u === $psn_m->check_login_turnstile( $psn_u, 'admin', 'pw' ), 'login turnstile: a GET is never challenged' );
$_SERVER['REQUEST_METHOD'] = 'POST';
$psn_m = $psn_nm();
$ok( $psn_u === $psn_m->check_login_turnstile( $psn_u, '', '' ), 'login turnstile: an empty login post is left to core' );
$ok( 0 === $psn_calls, 'login turnstile: none of those asked Cloudflare' );
// An unreadable secret: login fails open, so the owner is never locked out.
update_option( 'perdita_forms_settings', array( 'turnstile_site_key' => 'site-key-1', 'turnstile_secret_key' => 'enc:garbage' ) );
$psn_m = $psn_nm();
$ok( ! $psn_m->turnstile_active() && $psn_u === $psn_m->check_login_turnstile( $psn_u, 'admin', 'pw' ), 'login turnstile: an unreadable secret turns the check off, so the owner can always sign in' );
// Wiring: hooks exist only when the setting is on.
update_option( 'perdita_forms_settings', array( 'turnstile_site_key' => 'site-key-1', 'turnstile_secret_key' => Perdita_Crypto::encrypt( 'secret-1' ) ) );
update_option( 'perdita_security_settings', array_merge( Perdita_Security::defaults(), array( 'login_turnstile' => true ) ) );
$psn_w = new Perdita_Security( perdita_core() );
$ok( false !== has_action( 'login_form', array( $psn_w, 'render_turnstile' ) ) && false !== has_action( 'register_form', array( $psn_w, 'render_turnstile' ) ) && false !== has_action( 'lostpassword_form', array( $psn_w, 'render_turnstile' ) ) && false !== has_filter( 'registration_errors', array( $psn_w, 'check_register_turnstile' ) ) && false !== has_action( 'lostpassword_post', array( $psn_w, 'check_lostpassword_turnstile' ) ), 'login turnstile: the widget and the checks hook the login, register, and lost password forms' );
$psn_unhook( $psn_w );
remove_filter( 'pre_http_request', $psn_http, 10 );

/* ---------- hardening: version, application passwords, REST ---------- */
global $wp_version;
$psn_core_src = includes_url( 'js/jquery/jquery.min.js' ) . '?ver=' . $wp_version;
$psn_m = $psn_make( array( 'hide_wp_version' => true ) );
$ok( includes_url( 'js/jquery/jquery.min.js' ) === $psn_m->strip_core_version( $psn_core_src ), 'hide version: ver= goes from a core script when it equals the WordPress version' );
$ok( admin_url( 'css/common.css' ) === $psn_m->strip_core_version( admin_url( 'css/common.css' ) . '?ver=' . $wp_version ), 'hide version: and from a wp-admin style' );
$ok( includes_url( 'js/x.js' ) . '?ver=1.2.3' === $psn_m->strip_core_version( includes_url( 'js/x.js' ) . '?ver=1.2.3' ), 'hide version: a core URL whose ver= is not the WordPress version keeps it' );
$psn_plug = plugins_url( 'foo/foo.js' ) . '?ver=' . $wp_version;
$ok( $psn_plug === $psn_m->strip_core_version( $psn_plug ) && content_url( 'themes/t/s.css' ) . '?ver=' . $wp_version === $psn_m->strip_core_version( content_url( 'themes/t/s.css' ) . '?ver=' . $wp_version ), 'hide version: plugin and theme assets keep ver= even when it equals the WordPress version' );
$ok( includes_url( 'js/x.js' ) . '?a=1&b=2' === $psn_m->strip_core_version( includes_url( 'js/x.js' ) . '?a=1&ver=' . $wp_version . '&b=2' ), 'hide version: other query arguments stay' );
update_option( 'perdita_security_settings', array_merge( Perdita_Security::defaults(), array( 'hide_wp_version' => true, 'disable_app_passwords' => true ) ) );
$psn_w = new Perdita_Security( perdita_core() );
$ok( false === has_action( 'wp_head', 'wp_generator' ) && '' === get_the_generator( 'html' ) && '' === get_the_generator( 'rss2' ) && '' === get_the_generator( 'atom' ), 'hide version: the generator tag is gone from pages and feeds' );
$ok( false === apply_filters( 'wp_is_application_passwords_available', true ), 'app passwords: switched off by the setting' );
$psn_unhook( $psn_w );
remove_filter( 'the_generator', '__return_empty_string', 99 );
foreach ( array( 'html', 'xhtml', 'atom', 'rss2', 'rdf', 'comment', 'export' ) as $psn_t ) { remove_filter( 'get_the_generator_' . $psn_t, '__return_empty_string', 99 ); }
remove_filter( 'wp_is_application_passwords_available', '__return_false', 99 );
if ( $psn_had_generator ) { add_action( 'wp_head', 'wp_generator' ); }
$ok( false !== strpos( get_the_generator( 'html' ), 'WordPress' ), 'hide version: the generator is back once the test unhooks the module' );

// restrict_rest and the block_author_enum migration.
update_option( 'perdita_security_settings', array( 'block_author_enum' => true ) );
$ok( 'users' === Perdita_Security::get_settings()['restrict_rest'], 'rest: a site with block_author_enum and no restrict_rest keeps the users restriction' );
update_option( 'perdita_security_settings', array( 'block_author_enum' => true, 'restrict_rest' => 'off' ) );
$ok( 'off' === Perdita_Security::get_settings()['restrict_rest'], 'rest: a saved restrict_rest off is respected' );
update_option( 'perdita_security_settings', array( 'block_author_enum' => false ) );
$ok( 'off' === Perdita_Security::get_settings()['restrict_rest'], 'rest: no block_author_enum, no restriction' );
update_option( 'perdita_security_settings', array( 'block_author_enum' => true ) );
$psn_w = new Perdita_Security( perdita_core() );
$ok( false !== has_filter( 'rest_endpoints', array( $psn_w, 'restrict_rest_users' ) ), 'rest: the migrated site still has the REST users filter hooked' );
$psn_unhook( $psn_w );
update_option( 'perdita_security_settings', array( 'restrict_rest' => 'users' ) );
$psn_w = new Perdita_Security( perdita_core() );
$ok( false !== has_filter( 'rest_endpoints', array( $psn_w, 'restrict_rest_users' ) ) && false === has_action( 'template_redirect', array( $psn_w, 'block_author_scan' ) ), 'rest: restrict_rest users hooks the filter alone, without the ?author scan block' );
$psn_unhook( $psn_w );
update_option( 'perdita_security_settings', array( 'restrict_rest' => 'off', 'block_author_enum' => true ) );
$psn_w = new Perdita_Security( perdita_core() );
$ok( false === has_filter( 'rest_endpoints', array( $psn_w, 'restrict_rest_users' ) ) && false !== has_action( 'template_redirect', array( $psn_w, 'block_author_scan' ) ), 'rest: with restrict_rest off, only the ?author scan block runs' );
$psn_unhook( $psn_w );
$psn_orig_user2 = get_current_user_id();
wp_set_current_user( 0 );
$psn_eps = $psn_make()->restrict_rest_users( array( '/wp/v2/users' => 1, '/wp/v2/users/(?P<id>[\d]+)' => 1, '/wp/v2/posts' => 1 ) );
$ok( array( '/wp/v2/posts' => 1 ) === $psn_eps, 'rest: logged-out requests lose the users routes and keep the rest' );
wp_set_current_user( 1 );
$ok( 3 === count( $psn_make()->restrict_rest_users( array( '/wp/v2/users' => 1, '/wp/v2/users/(?P<id>[\d]+)' => 1, '/wp/v2/posts' => 1 ) ) ), 'rest: a logged-in request keeps every route' );
wp_set_current_user( $psn_orig_user2 ? $psn_orig_user2 : 1 );
$ok( 'users' === Perdita_Security::sanitize_protections( array( 'restrict_rest' => 'users' ) )['restrict_rest'] && 'off' === Perdita_Security::sanitize_protections( array( 'restrict_rest' => 'all' ) )['restrict_rest'] && 'off' === Perdita_Security::sanitize_protections( array() )['restrict_rest'], 'rest: restrict_rest accepts off or users only' );

/* ---------- uploads .htaccess block ---------- */
$psn_dir = trailingslashit( get_temp_dir() ) . 'perdita-sec-uploads-' . wp_generate_password( 6, false );
wp_mkdir_p( $psn_dir );
$psn_ud = function ( $u ) use ( $psn_dir ) {
	$u['basedir'] = $psn_dir; $u['path'] = $psn_dir; $u['error'] = false;
	return $u;
};
add_filter( 'upload_dir', $psn_ud, 99 );
$psn_ht = $psn_dir . '/.htaccess';
$ok( true === Perdita_Security::sync_uploads_block( true ) && is_file( $psn_ht ), 'uploads block: ticking the box writes .htaccess when none exists' );
$psn_c = (string) file_get_contents( $psn_ht );
$ok( 1 === substr_count( $psn_c, Perdita_Security::UPLOADS_BEGIN ) && 1 === substr_count( $psn_c, Perdita_Security::UPLOADS_END ) && false !== strpos( $psn_c, 'phtml' ) && false !== strpos( $psn_c, 'Require all denied' ) && Perdita_Security::uploads_block_present(), 'uploads block: one marked block that denies php, phtml, and phar' );
Perdita_Security::sync_uploads_block( true );
$ok( $psn_c === (string) file_get_contents( $psn_ht ), 'uploads block: saving twice changes nothing' );
$ok( true === Perdita_Security::sync_uploads_block( false ) && ! is_file( $psn_ht ) && ! Perdita_Security::uploads_block_present(), 'uploads block: unticking removes the block and the file that held only it' );
foreach ( array( "# keep me\nOptions -Indexes\n", "# keep me\nOptions -Indexes", "# top\n\n# middle\n\n", "" ) as $psn_orig ) {
	file_put_contents( $psn_ht, $psn_orig );
	Perdita_Security::sync_uploads_block( true );
	$psn_with = (string) file_get_contents( $psn_ht );
	$psn_keeps = ( '' === $psn_orig ) || 0 === strpos( $psn_with, rtrim( $psn_orig, "\n" ) );
	Perdita_Security::sync_uploads_block( false );
	$psn_back = is_file( $psn_ht ) ? (string) file_get_contents( $psn_ht ) : '';
	$ok( $psn_keeps && Perdita_Security::UPLOADS_BEGIN !== '' && false !== strpos( $psn_with, Perdita_Security::UPLOADS_BEGIN ) && ( rtrim( $psn_orig ) === rtrim( $psn_back ) ), 'uploads block: lines outside the block survive a write and a removal (' . strlen( $psn_orig ) . ' bytes of other rules)' );
	if ( is_file( $psn_ht ) ) { unlink( $psn_ht ); }
}
$psn_mid = "A rule\n\n" . Perdita_Security::uploads_block() . "B rule\n";
file_put_contents( $psn_ht, $psn_mid );
Perdita_Security::sync_uploads_block( false );
$ok( "A rule\nB rule\n" === file_get_contents( $psn_ht ), 'uploads block: a block between two rules comes out and both rules stay' );
file_put_contents( $psn_ht, "A rule\n" );
Perdita_Security::sync_uploads_block( false );
$ok( "A rule\n" === file_get_contents( $psn_ht ), 'uploads block: removing a block that is not there does not touch the file' );
$psn_broken = "A\n" . Perdita_Security::UPLOADS_BEGIN . "\nDeny from all\n";
file_put_contents( $psn_ht, $psn_broken );
Perdita_Security::sync_uploads_block( false );
$ok( $psn_broken === file_get_contents( $psn_ht ), 'uploads block: a block with no end marker is left for the owner' );
unlink( $psn_ht );
// teardown() removes the block and the cron event.
Perdita_Security::sync_uploads_block( true );
wp_schedule_event( time() + 3600, 'daily', Perdita_Security::CORE_CHECK_CRON );
Perdita_Security::teardown();
$ok( ! is_file( $psn_ht ) && false === wp_next_scheduled( Perdita_Security::CORE_CHECK_CRON ), 'uploads block: teardown() takes out the block and the daily check' );
// An unwritable folder returns an error and writes nothing.
chmod( $psn_dir, 0555 );
$psn_wr = Perdita_Security::sync_uploads_block( true );
chmod( $psn_dir, 0755 );
$ok( ( is_wp_error( $psn_wr ) && ! is_file( $psn_ht ) ) || is_writable( $psn_dir ) && 0 === posix_geteuid(), 'uploads block: an unwritable uploads folder gives an error and no file' );
$psn_rule = Perdita_Security::uploads_nginx_rule();
$ok( 0 === strpos( $psn_rule, 'location ~*' ) && false !== strpos( $psn_rule, 'deny all' ) && false !== strpos( $psn_rule, 'uploads' ), 'uploads block: the nginx rule names the uploads path' );
remove_filter( 'upload_dir', $psn_ud, 99 );
array_map( 'unlink', glob( $psn_dir . '/{,.}*', GLOB_BRACE | GLOB_NOSORT ) ?: array() );
@rmdir( $psn_dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- test cleanup.
$ok( ! is_dir( $psn_dir ), 'uploads block: the temp folder is gone' );
$ok( $psn_orig_uploads_md5 === ( is_file( $psn_orig_uploads_ht ) ? md5_file( $psn_orig_uploads_ht ) : null ), 'uploads block: the real uploads .htaccess never changed' );

/* ---------- HSTS ---------- */
unset( $_SERVER['HTTPS'], $_SERVER['SERVER_PORT'] );
$psn_m = $psn_make( array( 'hsts' => true, 'hsts_max_age' => 123, 'hsts_subdomains' => true ) );
$ok( '' === $psn_m->hsts_value(), 'hsts: nothing goes out over plain HTTP' );
$_SERVER['HTTPS'] = 'on';
$ok( 'max-age=123; includeSubDomains' === $psn_m->hsts_value(), 'hsts: max-age and includeSubDomains over HTTPS' );
$ok( 'max-age=123' === $psn_make( array( 'hsts' => true, 'hsts_max_age' => 123 ) )->hsts_value() && '' === $psn_make( array( 'hsts' => false ) )->hsts_value(), 'hsts: includeSubDomains only when ticked, nothing when off' );
$ok( 0 === Perdita_Security::sanitize_protections( array( 'hsts_max_age' => -5 ) )['hsts_max_age'] && 63072000 === Perdita_Security::sanitize_protections( array( 'hsts_max_age' => 999999999 ) )['hsts_max_age'] && 31536000 === Perdita_Security::sanitize_protections( array() )['hsts_max_age'], 'hsts: the lifetime is clamped, default one year' );
update_option( 'perdita_security_settings', array_merge( Perdita_Security::defaults(), array( 'hsts' => true ) ) );
$psn_w = new Perdita_Security( perdita_core() );
$ok( false !== has_action( 'send_headers', array( $psn_w, 'send_hsts' ) ) && false !== has_action( 'login_init', array( $psn_w, 'send_hsts' ) ), 'hsts: hooked on the front end and the login screen when on' );
$psn_unhook( $psn_w );
unset( $_SERVER['HTTPS'] );

/* ---------- core file check ---------- */
$psn_root = trailingslashit( get_temp_dir() ) . 'perdita-sec-core-' . wp_generate_password( 6, false );
foreach ( array( 'wp-admin/js', 'wp-includes', 'wp-content/plugins' ) as $psn_sub ) { wp_mkdir_p( $psn_root . '/' . $psn_sub ); }
file_put_contents( $psn_root . '/wp-admin/a.php', "<?php // a\n" );
file_put_contents( $psn_root . '/wp-includes/b.php', "<?php // b changed\n" );
file_put_contents( $psn_root . '/wp-includes/extra.php', "<?php // not core\n" );
file_put_contents( $psn_root . '/wp-admin/js/x.js', 'x' );
file_put_contents( $psn_root . '/wp-content/evil.php', "<?php // outside the check\n" );
file_put_contents( $psn_root . '/wp-content/plugins/hello.php', "<?php // plugin\n" );
$psn_sums = array(
	'wp-admin/a.php'                => md5( "<?php // a\n" ),
	'wp-includes/b.php'             => md5( "<?php // b\n" ),
	'wp-includes/c.php'             => md5( "<?php // c\n" ),
	'wp-content/plugins/hello.php'  => md5( 'different' ),
	'wp-content/plugins/gone.php'   => md5( 'gone' ),
);
$psn_root_filter = function () use ( $psn_root ) { return $psn_root; };
add_filter( 'perdita_security_core_check_root', $psn_root_filter );
$psn_urls = array();
$psn_sum_reply = null;
$psn_http = function ( $pre, $args, $url ) use ( &$psn_urls, &$psn_sum_reply ) {
	if ( false === strpos( $url, 'api.wordpress.org/core/checksums' ) ) { return $pre; }
	$psn_urls[] = $url;
	if ( is_wp_error( $psn_sum_reply ) ) { return $psn_sum_reply; }
	return array( 'headers' => array(), 'body' => $psn_sum_reply['body'], 'response' => array( 'code' => $psn_sum_reply['code'], 'message' => '' ), 'cookies' => array(), 'filename' => null );
};
add_filter( 'pre_http_request', $psn_http, 10, 3 );
$psn_cc_keys = function () use ( $wp_version ) {
	foreach ( array( get_locale(), 'en_US', 'de_DE' ) as $loc ) { delete_transient( 'perdita_sec_sums_' . md5( $wp_version . '|' . $loc ) ); }
};
$psn_cc_keys();
delete_option( 'perdita_security_core_check' );
$psn_sum_reply = array( 'code' => 200, 'body' => wp_json_encode( array( 'checksums' => array( $wp_version => $psn_sums ) ) ) );
$psn_res = Perdita_Security::run_core_check( true );
$ok( array( 'wp-includes/b.php' ) === $psn_res['changed'] && array( 'wp-includes/c.php' ) === $psn_res['missing'] && array( 'wp-includes/extra.php' ) === $psn_res['unknown'] && 'problems' === $psn_res['status'], 'core check: finds a changed file, a missing file, and an unknown PHP file' );
$ok( 3 === $psn_res['checked'] && ! in_array( 'wp-content/plugins/gone.php', $psn_res['missing'], true ) && ! in_array( 'wp-content/evil.php', $psn_res['unknown'], true ), 'core check: wp-content is skipped on both sides' );
$psn_saved = get_option( Perdita_Security::CORE_CHECK_OPTION );
$ok( is_array( $psn_saved ) && ! empty( $psn_saved['time'] ) && $psn_saved['changed'] === $psn_res['changed'] && $psn_saved['missing'] === $psn_res['missing'] && $psn_saved['unknown'] === $psn_res['unknown'], 'core check: the result is saved in perdita_security_core_check with a time' );
$ok( false !== strpos( $psn_urls[0], 'https://api.wordpress.org/core/checksums/1.0/' ) && false !== strpos( $psn_urls[0], 'version=' . $wp_version ) && false !== strpos( $psn_urls[0], 'locale=' . get_locale() ), 'core check: asks the wordpress.org checksums API for this version and locale' );
$psn_before = count( $psn_urls );
Perdita_Security::run_core_check( false );
$ok( count( $psn_urls ) === $psn_before, 'core check: the checksum list is cached for the version, so a second run asks nobody' );
Perdita_Security::run_core_check( true );
$ok( count( $psn_urls ) === $psn_before + 1, 'core check: Check now fetches a fresh list' );
$ok( false !== strpos( Perdita_Security::core_check_summary(), '1 changed, 1 missing, and 1 unknown' ), 'core check: the status line gives the three counts' );
// A clean tree.
unlink( $psn_root . '/wp-includes/extra.php' );
file_put_contents( $psn_root . '/wp-includes/b.php', "<?php // b\n" );
file_put_contents( $psn_root . '/wp-includes/c.php', "<?php // c\n" );
$psn_res = Perdita_Security::run_core_check( true );
$ok( 'ok' === $psn_res['status'] && ! $psn_res['changed'] && ! $psn_res['missing'] && ! $psn_res['unknown'] && false !== strpos( Perdita_Security::core_check_summary(), 'all 3 core files match' ), 'core check: a clean tree reports all files match' );
// Locale fallback to en_US.
$psn_cc_keys();
$psn_loc = function () { return 'de_DE'; };
add_filter( 'locale', $psn_loc );
$psn_urls = array();
$psn_sum_reply = null;
$psn_http2 = function ( $pre, $args, $url ) use ( &$psn_urls, $psn_sums, $wp_version ) {
	if ( false === strpos( $url, 'api.wordpress.org/core/checksums' ) ) { return $pre; }
	$psn_urls[] = $url;
	$body = false !== strpos( $url, 'locale=de_DE' ) ? wp_json_encode( array( 'checksums' => false ) ) : wp_json_encode( array( 'checksums' => array( $wp_version => $psn_sums ) ) );
	return array( 'headers' => array(), 'body' => $body, 'response' => array( 'code' => 200, 'message' => '' ), 'cookies' => array(), 'filename' => null );
};
remove_filter( 'pre_http_request', $psn_http, 10 );
add_filter( 'pre_http_request', $psn_http2, 10, 3 );
$psn_res = Perdita_Security::run_core_check( true );
$ok( 'ok' === $psn_res['status'] && 2 === count( $psn_urls ) && false !== strpos( $psn_urls[1], 'locale=en_US' ), 'core check: a locale with no list falls back to en_US' );
remove_filter( 'locale', $psn_loc );
remove_filter( 'pre_http_request', $psn_http2, 10 );
$psn_cc_keys();
// Failures save an error and never a guess.
$psn_sum_reply = new WP_Error( 'http_request_failed', 'timeout' );
add_filter( 'pre_http_request', $psn_http, 10, 3 );
$psn_res = Perdita_Security::run_core_check( true );
$ok( 'error' === $psn_res['status'] && '' !== $psn_res['error'] && ! $psn_res['changed'] && false !== strpos( Perdita_Security::core_check_summary(), 'did not finish' ), 'core check: an unreachable wordpress.org saves an error, not a clean bill' );
$psn_sum_reply = array( 'code' => 200, 'body' => wp_json_encode( array( 'checksums' => false ) ) );
$psn_res = Perdita_Security::run_core_check( true );
$ok( 'error' === $psn_res['status'], 'core check: a version wordpress.org has no list for is an error too' );
$psn_sum_reply = array( 'code' => 500, 'body' => 'oops' );
$psn_res = Perdita_Security::run_core_check( true );
$ok( 'error' === $psn_res['status'], 'core check: a 500 is an error' );
$ok( false === get_transient( 'perdita_sec_sums_' . md5( $wp_version . '|' . get_locale() ) ), 'core check: a failure caches nothing' );
// Cron follows the setting.
wp_clear_scheduled_hook( Perdita_Security::CORE_CHECK_CRON );
update_option( 'perdita_security_settings', array_merge( Perdita_Security::defaults(), array( 'core_check' => true ) ) );
$psn_w = new Perdita_Security( perdita_core() );
$ok( false !== wp_next_scheduled( Perdita_Security::CORE_CHECK_CRON ) && false !== has_action( Perdita_Security::CORE_CHECK_CRON ), 'core check: turning it on schedules the daily run' );
$psn_unhook( $psn_w );
update_option( 'perdita_security_settings', array_merge( Perdita_Security::defaults(), array( 'core_check' => false ) ) );
$psn_w = new Perdita_Security( perdita_core() );
$ok( false === wp_next_scheduled( Perdita_Security::CORE_CHECK_CRON ), 'core check: turning it off clears the schedule' );
$psn_unhook( $psn_w );
remove_filter( 'perdita_security_core_check_root', $psn_root_filter );
remove_filter( 'pre_http_request', $psn_http, 10 );
$psn_cc_keys();
$psn_rm = function ( $d ) use ( &$psn_rm ) {
	foreach ( (array) glob( $d . '/{,.}*', GLOB_BRACE ) as $f ) {
		if ( in_array( basename( $f ), array( '.', '..' ), true ) ) { continue; }
		is_dir( $f ) ? $psn_rm( $f ) : unlink( $f );
	}
	@rmdir( $d ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- test cleanup.
};
$psn_rm( $psn_root );
$ok( ! is_dir( $psn_root ), 'core check: the temp tree is gone' );

/* =============================================================
 * Round 2 review fixes: author scan order, IPv6 /64 lockouts, lost
 * password answers, lockouts that don't stretch, the owner unlock
 * link, the XML-RPC and display-name notes, and oEmbed and sitemap
 * login-name leaks.
 * ============================================================= */
echo "\n--- security round 2 (tests/smoke-security.php) ---\n";

$psr_users   = array();
$psr_tokens  = array();
$psr_ips     = array( '203.0.113.60', '203.0.113.61', '2001:db8:1:2::1', '2001:db8:1:2::ffff', '2001:db8:1:2::5', '2001:db8:1:3::1', '::ffff:203.0.113.60', '198.51.100.99' );
$psr_clean   = function () use ( $psr_ips, $psn_lock_keys ) {
	foreach ( $psr_ips as $ip ) {
		foreach ( $psn_lock_keys( $ip ) as $k ) { delete_transient( $k ); }
		delete_transient( Perdita_Security::UNLOCK_LIMIT_PREFIX . md5( $ip ) );
	}
	foreach ( array( '20010db800010002/64', '20010db800010003/64' ) as $id ) {
		delete_transient( Perdita_Security::TRANSIENT_PREFIX . md5( $id ) );
		delete_transient( Perdita_Security::TRANSIENT_PREFIX . 'h_' . md5( $id ) );
		delete_transient( Perdita_Security::UNLOCK_LIMIT_PREFIX . md5( $id ) );
	}
};
$psr_clean();
$psr_fail = function ( $m, $n ) { for ( $i = 0; $i < $n; $i++ ) { $m->record_failure(); } };
$psr_locked = function ( $m ) { return is_wp_error( $m->check_lockout( null ) ); };

$psr_redirect = '';
$psr_catch    = function ( $location ) use ( &$psr_redirect ) { $psr_redirect = $location; throw new Exception( 'perdita-smoke-redirect' ); };
add_filter( 'wp_redirect', $psr_catch, 1 );
$psr_mails = array();
$psr_mock  = function ( $pre, $atts ) use ( &$psr_mails ) { $psr_mails[] = $atts; return true; };
add_filter( 'pre_wp_mail', $psr_mock, 10, 2 );
// Run a callable that ends in a redirect, and return where it went ('' for none).
$psr_run = function ( $fn ) use ( &$psr_redirect ) {
	$psr_redirect = '';
	try { $fn(); } catch ( Exception $e ) { unset( $e ); }
	return $psr_redirect;
};

/* ---------- 1. author scan runs ahead of redirect_canonical ---------- */
update_option( 'perdita_security_settings', array_merge( Perdita_Security::defaults(), array( 'block_author_enum' => true ) ) );
$psr_w = new Perdita_Security( perdita_core() );
$psr_pri = has_action( 'template_redirect', array( $psr_w, 'block_author_scan' ) );
$psr_canon = has_action( 'template_redirect', 'redirect_canonical' );
$ok( 1 === $psr_pri && ( false === $psr_canon || $psr_pri < $psr_canon ), 'author scan: block_author_scan runs on template_redirect at priority 1, ahead of redirect_canonical' );

/* ---------- 7. oEmbed and the users sitemap ---------- */
$psr_oe = apply_filters( 'oembed_response_data', array( 'version' => '1.0', 'title' => 'T', 'author_name' => 'admin', 'author_url' => 'http://example.com/author/admin/' ), null, 0, 0 );
$ok( ! isset( $psr_oe['author_name'] ) && ! isset( $psr_oe['author_url'] ) && 'T' === $psr_oe['title'], 'oembed: author_name and author_url are dropped and the rest stays, with block_author_enum on' );
$ok( false === apply_filters( 'wp_sitemaps_add_provider', new stdClass(), 'users' ) && is_object( apply_filters( 'wp_sitemaps_add_provider', new stdClass(), 'posts' ) ), 'sitemap: the users provider is removed and the others stay' );
$psn_unhook( $psr_w );
$psr_oe = apply_filters( 'oembed_response_data', array( 'author_name' => 'admin', 'author_url' => 'x' ), null, 0, 0 );
$ok( isset( $psr_oe['author_name'] ) && is_object( apply_filters( 'wp_sitemaps_add_provider', new stdClass(), 'users' ) ), 'oembed and sitemap: with block_author_enum off both are left alone' );

/* ---------- 2. IPv6 lockouts key on the /64 ---------- */
$psr_m = $psn_make( array( 'login_limit' => true, 'login_max' => 3, 'ip_allowlist' => array( '2001:db8:1:2::5' ) ) );
$_SERVER['REMOTE_ADDR'] = '2001:db8:1:2::1';
$psr_fail( $psr_m, 3 );
$ok( $psr_locked( $psr_m ), 'ipv6: three failures lock the address' );
$_SERVER['REMOTE_ADDR'] = '2001:db8:1:2::ffff';
$ok( $psr_locked( $psr_m ), 'ipv6: another address in the same /64 is locked too' );
$_SERVER['REMOTE_ADDR'] = '2001:db8:1:3::1';
$ok( ! $psr_locked( $psr_m ), 'ipv6: a different /64 is not locked' );
$psr_m2 = $psn_make( array( 'login_limit' => true, 'login_max' => 3 ) );
$_SERVER['REMOTE_ADDR'] = '2001:db8:1:2::1';
$psr_clean();
$psr_fail( $psr_m2, 1 );
$_SERVER['REMOTE_ADDR'] = '2001:db8:1:2::ffff';
$psr_fail( $psr_m2, 1 );
$_SERVER['REMOTE_ADDR'] = '2001:db8:1:2::1';
$psr_fail( $psr_m2, 1 );
$ok( $psr_locked( $psr_m2 ), 'ipv6: failures from different addresses in one /64 add up to one lockout' );
$_SERVER['REMOTE_ADDR'] = '2001:db8:1:2::5';
$psr_fail( $psr_m, 5 );
$ok( ! $psr_locked( $psr_m ), 'ipv6: the allowlist still matches the full address, and an allowlisted address is never locked' );
$_SERVER['REMOTE_ADDR'] = '2001:db8:1:2::1';
$psr_clean();
$psr_fail( $psr_m2, 3 );
$_SERVER['REMOTE_ADDR'] = '2001:db8:1:2::1';
$psr_m2->clear_failures();
$ok( ! $psr_locked( $psr_m2 ), 'ipv6: a good login clears the /64 count' );
$psr_clean();
$_SERVER['REMOTE_ADDR'] = '203.0.113.60';
$psr_fail( $psr_m2, 3 );
$ok( $psr_locked( $psr_m2 ), 'ipv4: still counted per address' );
$_SERVER['REMOTE_ADDR'] = '203.0.113.61';
$ok( ! $psr_locked( $psr_m2 ), 'ipv4: a neighbor address is not locked' );
$_SERVER['REMOTE_ADDR'] = '::ffff:203.0.113.60';
$ok( $psr_locked( $psr_m2 ), 'ipv6: an IPv4-mapped address counts as its IPv4 address' );
$psr_clean();

/* ---------- 5. a lockout is never stretched ---------- */
$_SERVER['REMOTE_ADDR'] = '203.0.113.60';
list( $psr_rk, $psr_hk ) = $psn_lock_keys( '203.0.113.60' );
$psr_m = $psn_make( array( 'login_limit' => true, 'login_max' => 2, 'login_lockout' => 15, 'login_long_lockout' => true, 'login_long_after' => 9 ) );
$psr_fail( $psr_m, 2 );
$psr_before  = get_transient( $psr_rk );
$psr_hist    = get_transient( $psr_hk );
$psr_expires = (int) get_option( '_transient_timeout_' . $psr_rk );
$psr_fail( $psr_m, 4 );
$psr_after = get_transient( $psr_rk );
$ok( $psr_before === $psr_after && (int) $psr_before['locked_until'] > time(), 'lockout: failures during a lockout change nothing, so it ends when it was set to end' );
$ok( $psr_hist === get_transient( $psr_hk ) && 1 === count( $psr_hist ), 'lockout: escalation counts lockouts, not the failures inside one' );
$ok( $psr_expires === (int) get_option( '_transient_timeout_' . $psr_rk ), 'lockout: the record expiry does not move either' );
set_transient( $psr_rk, array( 'count' => 2, 'locked_until' => time() - 5 ), 900 );
$psr_fail( $psr_m, 1 );
$psr_new = get_transient( $psr_rk );
$ok( (int) $psr_new['locked_until'] > time() && 2 === count( get_transient( $psr_hk ) ), 'lockout: once a lockout has ended, the next failure starts a new one and escalation counts it' );
$psr_clean();

/* ---------- 5. owner unlock link ---------- */
$psr_admin_id = wp_insert_user( array( 'user_login' => 'perdita_smoke_r2_admin', 'user_email' => 'perdita-smoke-r2-admin@example.com', 'user_pass' => wp_generate_password(), 'role' => 'administrator' ) );
$psr_sub_id   = wp_insert_user( array( 'user_login' => 'perdita_smoke_r2_sub', 'user_email' => 'perdita-smoke-r2-sub@example.com', 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ) );
$psr_users    = array( $psr_admin_id, $psr_sub_id );
$_SERVER['REMOTE_ADDR'] = '203.0.113.60';
$psr_m = $psn_make( array( 'login_limit' => true, 'login_max' => 2, 'login_lockout' => 15 ) );
$_REQUEST = array();
$_GET     = array();
$ok( false === strpos( $psr_m->unlock_login_message( '' ), 'Email me an unlock link' ), 'unlock: no form while the address is not locked out' );
$psr_fail( $psr_m, 2 );
$psr_form = $psr_m->unlock_login_message( '' );
$ok( false !== strpos( $psr_form, 'Email me an unlock link' ) && false !== strpos( $psr_form, 'name="perdita_unlock_user"' ) && false !== strpos( $psr_form, 'action=perdita_unlock' ), 'unlock: a locked address sees the "Email me an unlock link" form' );
$_REQUEST = array( 'action' => 'lostpassword' );
$ok( false === strpos( $psr_m->unlock_login_message( '' ), 'Email me an unlock link' ), 'unlock: the form shows only on the login screen' );
$_REQUEST = array();

$_SERVER['REQUEST_METHOD'] = 'POST';
$psr_ask = function ( $who ) use ( $psr_m, $psr_run ) {
	$_POST = array( 'perdita_unlock_user' => $who );
	return $psr_run( function () use ( $psr_m ) { $psr_m->handle_unlock(); } );
};
$psr_mails = array();
$psr_to_admin = $psr_ask( 'perdita_smoke_r2_admin' );
$ok( 1 === count( $psr_mails ) && 'perdita-smoke-r2-admin@example.com' === $psr_mails[0]['to'], 'unlock: an administrator login gets one email' );
$psr_body = $psr_mails ? $psr_mails[0]['message'] : '';
preg_match( '/key=([A-Za-z0-9]+)/', $psr_body, $psr_km );
$psr_key = isset( $psr_km[1] ) ? $psr_km[1] : '';
$ok( 32 === strlen( $psr_key ) && false !== strpos( $psr_body, 'action=perdita_unlock' ), 'unlock: the email carries a 32 character random key' );
$ok( false === strpos( $psr_body . $psr_mails[0]['subject'], "\u{2014}" ) && false === strpos( $psr_body . $psr_mails[0]['subject'], ';' ), 'unlock: the email has no em dash and no semicolon' );
$psr_tk = Perdita_Security::UNLOCK_PREFIX . hash( 'sha256', $psr_key );
$psr_tokens[] = $psr_tk;
$ok( is_array( get_transient( $psr_tk ) ) && false === get_transient( Perdita_Security::UNLOCK_PREFIX . $psr_key ), 'unlock: the token is stored under its hash, never the raw key' );
$psr_ttl = (int) get_option( '_transient_timeout_' . $psr_tk ) - time();
$ok( $psr_ttl > 14 * 60 && $psr_ttl <= 15 * 60, 'unlock: the link lasts 15 minutes' );
$ok( false !== strpos( $psr_to_admin, 'perdita_unlock=sent' ), 'unlock: the answer is a redirect with the sent notice' );
$psr_mails = array();
$psr_unknown = $psr_ask( 'perdita_smoke_r2_nobody' );
$psr_subr    = $psr_ask( 'perdita_smoke_r2_sub' );
$psr_emailr  = $psr_ask( 'perdita-smoke-r2-sub@example.com' );
$ok( 0 === count( $psr_mails ), 'unlock: an unknown login, a subscriber, and a subscriber email send nothing' );
$ok( $psr_unknown === $psr_to_admin && $psr_subr === $psr_to_admin && $psr_emailr === $psr_to_admin, 'unlock: every answer is the same redirect, whether or not the account exists' );
// Rate limit: 3 sends an hour per address.
$psr_clean();
$psr_mails = array();
for ( $psr_i = 0; $psr_i < 5; $psr_i++ ) { $psr_ask( 'perdita-smoke-r2-admin@example.com' ); }
$ok( 3 === count( $psr_mails ), 'unlock: an address gets 3 sends an hour, found by email too' );
foreach ( $psr_mails as $psr_mail ) {
	if ( preg_match( '/key=([A-Za-z0-9]+)/', $psr_mail['message'], $psr_km2 ) ) { $psr_tokens[] = Perdita_Security::UNLOCK_PREFIX . hash( 'sha256', $psr_km2[1] ); }
}
$psr_ask( 'perdita_smoke_r2_admin' );
$ok( 3 === count( $psr_mails ), 'unlock: the fourth ask in the hour is answered the same and sends nothing' );
$psr_other = $psn_make( array( 'login_limit' => true, 'login_max' => 2 ) );
$_SERVER['REMOTE_ADDR'] = '203.0.113.61';
$_POST = array( 'perdita_unlock_user' => 'perdita_smoke_r2_admin' );
$psr_run( function () use ( $psr_other ) { $psr_other->handle_unlock(); } );
$ok( 4 === count( $psr_mails ), 'unlock: the limit is per address, so another address can still ask' );
$psr_tokens[] = Perdita_Security::UNLOCK_PREFIX . hash( 'sha256', preg_match( '/key=([A-Za-z0-9]+)/', $psr_mails[3]['message'], $psr_km3 ) ? $psr_km3[1] : '' );
// Click the link.
$_SERVER['REMOTE_ADDR'] = '203.0.113.60';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_POST = array();
$psr_clean();
$psr_fail( $psr_m, 2 );
$_SERVER['REMOTE_ADDR'] = '198.51.100.99';
$psr_fail( $psr_m, 2 );
$ok( $psr_locked( $psr_m ), 'unlock: setup, a second address is locked out' );
$_GET = array( 'key' => 'wrongkeywrongkeywrongkeywrongkey12' );
$psr_bad = $psr_run( function () use ( $psr_m ) { $psr_m->handle_unlock(); } );
$ok( false !== strpos( $psr_bad, 'perdita_unlock=invalid' ) && $psr_locked( $psr_m ), 'unlock: a wrong key says it expired and unlocks nothing' );
$_GET = array( 'key' => $psr_key );
$psr_good = $psr_run( function () use ( $psr_m ) { $psr_m->handle_unlock(); } );
$ok( false !== strpos( $psr_good, 'perdita_unlock=done' ) && ! $psr_locked( $psr_m ), 'unlock: the link clears the lockout for the address that clicked it' );
$_SERVER['REMOTE_ADDR'] = '203.0.113.60';
$ok( $psr_locked( $psr_m ), 'unlock: another locked address stays locked' );
$_SERVER['REMOTE_ADDR'] = '198.51.100.99';
$psr_fail( $psr_m, 2 );
$psr_again = $psr_run( function () use ( $psr_m ) { $psr_m->handle_unlock(); } );
$ok( false !== strpos( $psr_again, 'perdita_unlock=invalid' ) && $psr_locked( $psr_m ), 'unlock: the link works once' );
$ok( false === get_transient( $psr_tk ), 'unlock: the used token is gone' );
// A link for an account that lost its rights.
$psr_mails = array();
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REMOTE_ADDR'] = '203.0.113.60';
$psr_clean();
$_GET = array();
$psr_ask( 'perdita_smoke_r2_admin' );
preg_match( '/key=([A-Za-z0-9]+)/', $psr_mails ? $psr_mails[0]['message'] : '', $psr_km4 );
$psr_tokens[] = Perdita_Security::UNLOCK_PREFIX . hash( 'sha256', isset( $psr_km4[1] ) ? $psr_km4[1] : '' );
( new WP_User( $psr_admin_id ) )->set_role( 'subscriber' );
$psr_fail( $psr_m, 2 );
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET = array( 'key' => isset( $psr_km4[1] ) ? $psr_km4[1] : 'none' );
$psr_demoted = $psr_run( function () use ( $psr_m ) { $psr_m->handle_unlock(); } );
$ok( false !== strpos( $psr_demoted, 'perdita_unlock=invalid' ) && $psr_locked( $psr_m ), 'unlock: a link does nothing once its account can no longer manage options' );
// The hooks.
update_option( 'perdita_security_settings', array_merge( Perdita_Security::defaults(), array( 'login_limit' => true ) ) );
$psr_w = new Perdita_Security( perdita_core() );
$ok( false !== has_action( 'login_form_perdita_unlock', array( $psr_w, 'handle_unlock' ) ) && false !== has_filter( 'login_message', array( $psr_w, 'unlock_login_message' ) ), 'unlock: the login limit wires the unlock action and the form' );
$psn_unhook( $psr_w );
update_option( 'perdita_security_settings', Perdita_Security::defaults() );
$psr_w = new Perdita_Security( perdita_core() );
$ok( false === has_action( 'login_form_perdita_unlock', array( $psr_w, 'handle_unlock' ) ), 'unlock: with the login limit off there is no unlock action' );
$psn_unhook( $psr_w );
$_GET = array();
$_POST = array();
$psr_clean();

/* ---------- 3. lost password gives one answer ---------- */
$GLOBALS['pagenow']        = 'wp-login.php';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_REQUEST                  = array();
$psr_m = $psn_make( array( 'login_generic_errors' => true ) );
$psr_lp = function ( WP_Error $errors, $user_data ) use ( $psr_m, $psr_run ) {
	return $psr_run( function () use ( $psr_m, $errors, $user_data ) { $psr_m->hide_unknown_account( $errors, $user_data ); } );
};
$psr_go = $psr_lp( new WP_Error( 'invalid_email', 'Unknown email.' ), false );
$ok( false !== strpos( $psr_go, 'checkemail=confirm' ), 'lost password: an unknown email redirects to the check-your-email screen' );
$psr_go = $psr_lp( new WP_Error(), false );
$ok( false !== strpos( $psr_go, 'checkemail=confirm' ), 'lost password: an unknown login redirects the same way' );
$psr_known = new WP_Error();
$psr_go    = $psr_lp( $psr_known, get_userdata( $psr_sub_id ) );
$ok( '' === $psr_go && ! $psr_known->has_errors(), 'lost password: a known user carries on to the real flow' );
$psr_mixed = new WP_Error( 'perdita_turnstile', 'Challenge failed.' );
$psr_mixed->add( 'invalid_email', 'Unknown email.' );
$psr_go = $psr_lp( $psr_mixed, false );
$ok( '' === $psr_go && array( 'perdita_turnstile' ) === $psr_mixed->get_error_codes(), 'lost password: a failed challenge still shows, and the "no account" error is removed beside it' );
$psr_empty = new WP_Error( 'empty_username', 'Enter a username or email.' );
$psr_go    = $psr_lp( $psr_empty, false );
$ok( '' === $psr_go && array( 'empty_username' ) === $psr_empty->get_error_codes(), 'lost password: an empty field keeps its own message' );
$GLOBALS['pagenow'] = 'index.php';
$psr_go = $psr_lp( new WP_Error( 'invalid_email', 'Unknown email.' ), false );
$ok( '' === $psr_go, 'lost password: other forms that call retrieve_password() are left alone' );
$GLOBALS['pagenow'] = 'wp-login.php';
// Through WordPress's own retrieve_password(), with the hook wired the way the module wires it.
update_option( 'perdita_security_settings', array_merge( Perdita_Security::defaults(), array( 'login_generic_errors' => true ) ) );
$psr_w = new Perdita_Security( perdita_core() );
$ok( 99 === has_action( 'lostpassword_post', array( $psr_w, 'hide_unknown_account' ) ), 'lost password: generic errors wire the hook after the Turnstile check' );
$psr_mails = array();
$_POST = array( 'user_login' => 'perdita_smoke_r2_nobody' );
$psr_go = $psr_run( function () { retrieve_password(); } );
$ok( false !== strpos( $psr_go, 'checkemail=confirm' ) && 0 === count( $psr_mails ), 'lost password: through WordPress, an unknown login gets the confirm redirect and no mail' );
$_POST = array( 'user_login' => 'nobody-r2@example.com' );
$psr_go = $psr_run( function () { retrieve_password(); } );
$ok( false !== strpos( $psr_go, 'checkemail=confirm' ) && 0 === count( $psr_mails ), 'lost password: through WordPress, an unknown email gets the same answer' );
$_POST = array( 'user_login' => 'perdita_smoke_r2_sub' );
$psr_res = null;
$psr_go  = $psr_run( function () use ( &$psr_res ) { $psr_res = retrieve_password(); } );
$ok( '' === $psr_go && true === $psr_res && 1 === count( $psr_mails ) && 'perdita-smoke-r2-sub@example.com' === $psr_mails[0]['to'] && false !== strpos( $psr_mails[0]['message'], 'action=rp' ), 'lost password: a real user still gets the reset email' );
$psn_unhook( $psr_w );
update_option( 'perdita_security_settings', Perdita_Security::defaults() );
$psr_w = new Perdita_Security( perdita_core() );
$_POST = array( 'user_login' => 'perdita_smoke_r2_nobody' );
$psr_res = null;
$psr_go  = $psr_run( function () use ( &$psr_res ) { $psr_res = retrieve_password(); } );
$ok( '' === $psr_go && is_wp_error( $psr_res ), 'lost password: with generic errors off, WordPress keeps its own behavior' );
$psn_unhook( $psr_w );

/* ---------- 6 and 7. status panel notes ---------- */
wp_set_current_user( 1 );
$psr_rows_for = function ( array $settings ) use ( $psn_make ) {
	$adm = new Perdita_Security_Admin( $psn_make(), perdita_core() ); // psn_make() clears the option, so save after it.
	update_option( 'perdita_security_settings', array_merge( Perdita_Security::defaults(), $settings ) );
	return $adm->status_rows( Perdita_Security::get_settings() );
};
$psr_labels = function ( $rows ) { return wp_list_pluck( $rows, 'label' ); };
$ok( in_array( 'Turnstile and XML-RPC', $psr_labels( $psr_rows_for( array( 'login_turnstile' => true ) ) ), true ), 'status: Turnstile on with XML-RPC open shows a warning row' );
$ok( ! in_array( 'Turnstile and XML-RPC', $psr_labels( $psr_rows_for( array( 'login_turnstile' => true, 'disable_xmlrpc' => true ) ) ), true ), 'status: no warning once XML-RPC is off' );
$ok( ! in_array( 'Turnstile and XML-RPC', $psr_labels( $psr_rows_for( array() ) ), true ), 'status: no warning with Turnstile off' );
$psr_warn = null;
foreach ( $psr_rows_for( array( 'login_turnstile' => true ) ) as $psr_row ) { if ( 'Turnstile and XML-RPC' === $psr_row['label'] ) { $psr_warn = $psr_row; } }
$ok( $psr_warn && false === $psr_warn['on'] && false !== strpos( $psr_warn['note'], 'XML-RPC off' ), 'status: the warning row is off and points at the XML-RPC block' );
wp_update_user( array( 'ID' => $psr_admin_id, 'role' => 'administrator', 'display_name' => 'perdita_smoke_r2_admin' ) );
$psr_dn = null;
foreach ( $psr_rows_for( array() ) as $psr_row ) { if ( 'Administrator display names' === $psr_row['label'] ) { $psr_dn = $psr_row; } }
$ok( $psr_dn && false !== strpos( $psr_dn['note'], 'perdita_smoke_r2_admin' ), 'status: an administrator whose display name equals the login is named in a note' );
wp_update_user( array( 'ID' => $psr_admin_id, 'display_name' => 'Smoke Round Two' ) );
$psr_dn = null;
foreach ( $psr_rows_for( array() ) as $psr_row ) { if ( 'Administrator display names' === $psr_row['label'] ) { $psr_dn = $psr_row; } }
$ok( ! $psr_dn || false === strpos( $psr_dn['note'], 'perdita_smoke_r2_admin' ), 'status: a different display name drops that administrator from the note' );

/* ---------- put the round 2 fixtures back ---------- */
foreach ( $psr_users as $psr_uid ) { wp_delete_user( $psr_uid ); }
foreach ( $psr_tokens as $psr_t ) { delete_transient( $psr_t ); }
$psr_clean();
remove_filter( 'wp_redirect', $psr_catch, 1 );
remove_filter( 'pre_wp_mail', $psr_mock, 10 );
$_GET = array(); $_POST = array(); $_REQUEST = array();
delete_option( 'perdita_security_settings' );
$ok( ! get_option( '_transient_' . $psr_rk ) && 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( '_transient_' . Perdita_Security::UNLOCK_PREFIX ) . '%' ) ), 'round 2: no unlock transients are left behind' );

/* ---------- the screen ---------- */
$psn_orig_user3 = get_current_user_id();
wp_set_current_user( 1 );
delete_option( 'perdita_security_settings' );
delete_option( 'perdita_security_core_check' );
update_option( 'perdita_forms_settings', array( 'turnstile_site_key' => '', 'turnstile_secret_key' => '' ) );
$psn_m     = $psn_make();
$psn_admin = new Perdita_Security_Admin( $psn_m, perdita_core() );
$psn_rows  = $psn_admin->status_rows( Perdita_Security::get_settings() );
$ok( count( $psn_rows ) >= 14, 'screen: the status panel has a row for every protection' );
$psn_shape = true;
foreach ( $psn_rows as $psn_row ) {
	$psn_shape = $psn_shape && ! empty( $psn_row['label'] ) && is_bool( $psn_row['on'] ) && ! empty( $psn_row['note'] );
}
$ok( $psn_shape, 'screen: each row has a label, an on/off state, and a next step' );
$psn_on = array();
foreach ( $psn_rows as $psn_row ) { if ( $psn_row['on'] ) { $psn_on[] = $psn_row['label']; } }
$ok( array() === array_diff( $psn_on, array( 'File editor off' ) ) && ( ! in_array( 'File editor off', $psn_on, true ) || ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ) ), 'screen: on a fresh site every row reads Off, except the file editor when wp-config already turns it off' );
$psn_labels = wp_list_pluck( $psn_rows, 'label' );
$ok( class_exists( 'Perdita_Firewall_Config' ) === in_array( 'Firewall', $psn_labels, true ), 'screen: the firewall row appears exactly when Perdita_Firewall_Config exists' );
$psn_ts = null;
foreach ( $psn_rows as $psn_row ) { if ( 'Turnstile on login forms' === $psn_row['label'] ) { $psn_ts = $psn_row; } }
$ok( $psn_ts && ! $psn_ts['on'] && false !== strpos( $psn_ts['note'], 'Turnstile keys' ), 'screen: the Turnstile row says the keys are missing' );
update_option( 'perdita_security_settings', array_merge( Perdita_Security::defaults(), array( 'login_turnstile' => true, 'hsts' => true, 'login_limit' => true ) ) );
$psn_rows = $psn_admin->status_rows( Perdita_Security::get_settings() );
$psn_by   = array();
foreach ( $psn_rows as $psn_row ) { $psn_by[ $psn_row['label'] ] = $psn_row; }
$ok( ! $psn_by['Turnstile on login forms']['on'] && false !== strpos( $psn_by['Turnstile on login forms']['note'], 'waiting' ) && $psn_by['Login limit']['on'] && $psn_by['HSTS']['on'], 'screen: Turnstile ticked without keys shows Off and says it is waiting, and ticked rows show On' );
ob_start();
$psn_admin->render();
$psn_html = ob_get_clean();
$ok( false !== strpos( $psn_html, 'perdita-security-status' ) && false !== strpos( $psn_html, 'Check now' ) && false !== strpos( $psn_html, 'location ~*' ) && false !== strpos( $psn_html, 'name="hsts"' ) && false !== strpos( $psn_html, 'name="restrict_rest"' ) && false !== strpos( $psn_html, 'name="login_long_lockout"' ) && false !== strpos( $psn_html, 'name="core_check"' ), 'screen: renders the status panel, Check now, the nginx rule, and the new controls' );
$ok( strpos( $psn_html, 'perdita-security-status' ) < strpos( $psn_html, 'Login rate limiting' ), 'screen: the status panel sits above the sections' );
$psn_visible = preg_replace( array( '#<script.*?</script>#s', '#&[#a-z0-9]+;#i' ), '', $psn_html );
$ok( false === strpos( $psn_visible, "\u{2014}" ) && false === strpos( wp_strip_all_tags( $psn_visible ), ';' ), 'screen: the page has no em dash and no semicolon in its copy' );
$ok( false !== strpos( $psn_html, 'This stays off until you add Turnstile keys' ), 'screen: the Turnstile box says it needs keys' );
$psn_admin_ref = $psn_admin; $psn_unhook( $psn_admin );

// Save handler.
add_filter( 'upload_dir', $psn_ud = function ( $u ) use ( $psn_dir ) { $u['basedir'] = $psn_dir; $u['path'] = $psn_dir; $u['error'] = false; return $u; }, 99 );
wp_mkdir_p( $psn_dir );
$psn_redirect = '';
$psn_catch = function ( $location ) use ( &$psn_redirect ) { $psn_redirect = $location; throw new Exception( 'perdita-smoke-redirect' ); };
add_filter( 'wp_redirect', $psn_catch, 1 );
$psn_run_save = function ( array $post ) use ( $psn_admin ) {
	$_POST = $post; $_REQUEST = array( '_wpnonce' => wp_create_nonce( Perdita_Security_Admin::SAVE_ACTION ) );
	$_POST['_wpnonce'] = $_REQUEST['_wpnonce'];
	try { $psn_admin->save(); } catch ( Exception $e ) { unset( $e ); }
	return Perdita_Security::get_settings();
};
update_option( 'perdita_security_settings', array_merge( Perdita_Security::defaults(), array( 'firewall' => true, 'firewall_mode' => 'block', 'fw_trust_roles' => 'manage_options' ) ) );
$psn_s = $psn_run_save( array(
	'action'                => Perdita_Security_Admin::SAVE_ACTION,
	'login_limit'           => '1', 'login_max' => '3', 'login_window' => '10', 'login_lockout' => '20',
	'ip_allowlist'          => "198.51.100.0/24\n2001:db8::/32\n9.9.9.9\nbad\n1.2.3.4/0",
	'login_generic_errors'  => '1', 'login_long_lockout' => '1', 'login_long_after' => '6', 'login_long_hours' => '99999',
	'login_turnstile'       => '1', 'hide_wp_version' => '1', 'block_uploads_php' => '1', 'disable_app_passwords' => '1',
	'restrict_rest'         => 'bogus', 'hsts' => '1', 'hsts_max_age' => '-9', 'hsts_subdomains' => '1', 'core_check' => '1',
	'csp_mode'              => 'enforce', 'csp' => "default-src 'self'\n<b>",
) );
$ok( array( '198.51.100.0/24', '2001:db8::/32', '9.9.9.9' ) === $psn_s['ip_allowlist'] && 3 === $psn_s['login_max'] && true === $psn_s['login_limit'], 'save: the allowlist keeps CIDR and drops junk, and the limits save' );
$ok( true === $psn_s['login_generic_errors'] && true === $psn_s['login_long_lockout'] && 6 === $psn_s['login_long_after'] && 720 === $psn_s['login_long_hours'] && true === $psn_s['login_turnstile'] && true === $psn_s['hide_wp_version'] && true === $psn_s['disable_app_passwords'] && true === $psn_s['hsts'] && 0 === $psn_s['hsts_max_age'] && true === $psn_s['hsts_subdomains'] && true === $psn_s['core_check'], 'save: every new key is sanitized and saved' );
$ok( 'off' === $psn_s['restrict_rest'] && 'enforce' === $psn_s['csp_mode'] && false === strpos( $psn_s['csp'], '<' ), 'save: a bad restrict_rest becomes off and the CSP is still cleaned' );
if ( ! class_exists( 'Perdita_Security_Firewall_Admin' ) ) {
	$ok( true === $psn_s['firewall'] && 'block' === $psn_s['firewall_mode'] && 'manage_options' === $psn_s['fw_trust_roles'], 'save: firewall keys keep their stored values when the firewall screen is absent' );
}
$psn_ht = $psn_dir . '/.htaccess';
$ok( is_file( $psn_ht ) && Perdita_Security::uploads_block_present(), 'save: ticking block_uploads_php writes the uploads block' );
$psn_s = $psn_run_save( array( 'action' => Perdita_Security_Admin::SAVE_ACTION ) );
$ok( ! is_file( $psn_ht ) && false === $psn_s['block_uploads_php'] && false === $psn_s['login_limit'] && false === $psn_s['hsts'], 'save: saving with the box unticked removes the block, and every unticked box saves off' );
// Capability and nonce.
wp_set_current_user( 0 );
$psn_denied = false;
add_filter( 'wp_die_handler', $psn_dh = function () { return function () { throw new Exception( 'perdita-smoke-die' ); }; }, 99999 );
try { $psn_admin->save(); } catch ( Exception $e ) { $psn_denied = 'perdita-smoke-die' === $e->getMessage(); }
$ok( $psn_denied, 'save: a visitor without manage_options is refused' );
wp_set_current_user( 1 );
$psn_denied = false;
$_REQUEST = array(); $_POST = array();
try { $psn_admin->save(); } catch ( Exception $e ) { $psn_denied = true; }
$ok( $psn_denied, 'save: a post with no nonce is refused' );
// Check now.
$psn_cn_calls = 0;
$psn_http3 = function ( $pre, $args, $url ) use ( &$psn_cn_calls, $wp_version ) {
	if ( false === strpos( $url, 'api.wordpress.org/core/checksums' ) ) { return $pre; }
	$psn_cn_calls++;
	return array( 'headers' => array(), 'body' => wp_json_encode( array( 'checksums' => false ) ), 'response' => array( 'code' => 200, 'message' => '' ), 'cookies' => array(), 'filename' => null );
};
add_filter( 'pre_http_request', $psn_http3, 10, 3 );
$psn_cc_keys();
$_REQUEST = array( '_wpnonce' => wp_create_nonce( Perdita_Security_Admin::CHECK_ACTION ) );
$psn_redirect = '';
try { $psn_admin->check_now(); } catch ( Exception $e ) { unset( $e ); }
$ok( $psn_cn_calls >= 1 && false !== strpos( $psn_redirect, 'perdita_err' ) && 'error' === get_option( Perdita_Security::CORE_CHECK_OPTION )['status'], 'check now: runs the check, saves the result, and sends the owner back with the message' );
remove_filter( 'pre_http_request', $psn_http3, 10 );
$_REQUEST = array();
$psn_denied = false;
try { $psn_admin->check_now(); } catch ( Exception $e ) { $psn_denied = true; }
$ok( $psn_denied && 1 === $psn_cn_calls, 'check now: a request with no nonce is refused and runs nothing' );
remove_filter( 'wp_die_handler', $psn_dh, 99999 );
remove_filter( 'wp_redirect', $psn_catch, 1 );
remove_filter( 'upload_dir', $psn_ud, 99 );
$psn_cc_keys();
$psn_rm( $psn_dir );
wp_set_current_user( $psn_orig_user3 ? $psn_orig_user3 : 1 );

/* ---------- put everything back ---------- */
foreach ( $psn_orig_opts as $psn_k => $psn_v ) {
	if ( '__perdita_absent__' === $psn_v ) {
		delete_option( $psn_k );
	} else {
		update_option( $psn_k, $psn_v );
	}
}
$_SERVER  = $psn_orig_server;
$_POST    = $psn_orig_post;
$_REQUEST = $psn_orig_request;
if ( null === $psn_orig_pagenow ) { unset( $GLOBALS['pagenow'] ); } else { $GLOBALS['pagenow'] = $psn_orig_pagenow; }
wp_clear_scheduled_hook( Perdita_Security::CORE_CHECK_CRON );
$ok( $psn_had_generator === has_action( 'wp_head', 'wp_generator' ), 'security suite: wp_generator is hooked as it was' );
$ok( is_file( $psn_orig_uploads_ht ) === ( null !== $psn_orig_uploads_md5 ), 'security suite: the real uploads .htaccess is as it was' );

// A daily core check or a firewall state write must not empty the page cache.
if ( class_exists( 'Perdita_Caching' ) || is_file( PERDITA_CORE_DIR . 'inc/modules/caching/class-perdita-caching.php' ) ) {
	require_once PERDITA_CORE_DIR . 'inc/modules/caching/class-perdita-caching.php';
	$ok( ! Perdita_Caching::option_affects_render( 'perdita_security_core_check' ), 'security: the core check result does not purge the page cache' );
	$ok( ! Perdita_Caching::option_affects_render( 'perdita_firewall_trust_key' ), 'security: firewall state options do not purge the page cache' );
	$ok( Perdita_Caching::option_affects_render( 'perdita_security_settings' ), 'security: saving the security settings still purges the page cache' );
}
