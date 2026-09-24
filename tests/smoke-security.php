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
	};
	$psx_unhook( $psx_updater );

	$psx_orig_manifest = get_transient( Perdita_Core_Updater::CACHE );
	$psx_pkg           = 'https://perdita.ericrosenberg.com/updates/perdita-core-99.9.9-smoke.zip';
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
	$ok( false === $psx_updater->verify_download( false, 'https://perdita.ericrosenberg.com/updates/perdita-99.9.9.zip', null ), 'updater: the theme\'s zip on the same host is left to the theme\'s updater' );

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
	$psx_old = $psx_updater->verify_download( false, 'https://perdita.ericrosenberg.com/updates/perdita-core-99.9.8.zip', null );
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

	// Background auto-updates are off by default for the plugin, unlike the
	// theme: an update here can change what runs on a login form or an MCP
	// endpoint, so the site owner picks the moment.
	$psx_offer = (object) array( 'plugin' => plugin_basename( PERDITA_CORE_FILE ) );
	$ok( false === $psx_updater->auto_update( null, $psx_offer ), 'updater: background auto-updates are off by default for this plugin' );
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
