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


/* =============================================================
 * Updater: the checksum is verified in the real upgrade path.
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

	$psx_updater = new Perdita_Core_Updater();
	remove_filter( 'pre_set_site_transient_update_plugins', array( $psx_updater, 'check' ) );
	remove_filter( 'plugins_api', array( $psx_updater, 'details' ) );
	remove_filter( 'auto_update_plugin', array( $psx_updater, 'auto_update' ) );
	remove_filter( 'upgrader_pre_download', array( $psx_updater, 'verify_download' ) );
	remove_action( 'upgrader_process_complete', array( $psx_updater, 'flush' ) );

	$psx_orig_manifest = get_transient( Perdita_Core_Updater::CACHE );
	$psx_pkg           = 'https://perdita.ericrosenberg.com/updates/perdita-core-99.9.9-smoke.zip';
	$psx_pkg_bytes     = 'perdita-core-smoke-package-bytes';
	$psx_good_sum      = hash( 'sha256', $psx_pkg_bytes );
	$psx_bad_sum       = str_repeat( 'a', 64 );

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

	set_transient(
		Perdita_Core_Updater::CACHE,
		array(
			'version'      => '99.9.9',
			'download_url' => $psx_pkg,
			'checksum'     => $psx_bad_sum,
		),
		MINUTE_IN_SECONDS
	);

	$psx_verify = $psx_updater->verify_download( false, $psx_pkg, null );
	$ok( is_wp_error( $psx_verify ), 'updater: verify_download() re-reads the manifest and actually verifies our package' );
	$ok(
		! $psx_downloaded || ( is_wp_error( $psx_verify ) && 'perdita_core_checksum_mismatch' === $psx_verify->get_error_code() ),
		'updater: a package whose sha256 does not match the manifest is rejected with perdita_core_checksum_mismatch'
	);

	// A package that is not ours falls straight through to core.
	$ok( false === $psx_updater->verify_download( false, 'https://example.com/some-other-plugin.zip', null ), 'updater: verify_download() ignores another plugin or theme package in the same request' );

	// A manifest pointing off-host is not our package, however it is signed.
	set_transient(
		Perdita_Core_Updater::CACHE,
		array(
			'version'      => '99.9.9',
			'download_url' => 'https://example.com/perdita-core-99.9.9.zip',
			'checksum'     => $psx_good_sum,
		),
		MINUTE_IN_SECONDS
	);
	$ok( false === $psx_updater->verify_download( false, 'https://example.com/perdita-core-99.9.9.zip', null ), 'updater: a manifest whose download_url is off-host is declined, not verified' );

	// A manifest with no usable checksum still installs (the field is optional).
	set_transient(
		Perdita_Core_Updater::CACHE,
		array(
			'version'      => '99.9.9',
			'download_url' => $psx_pkg,
		),
		MINUTE_IN_SECONDS
	);
	$ok( false === $psx_updater->verify_download( false, $psx_pkg, null ), 'updater: a manifest without a checksum defers to core rather than blocking the update' );

	// The matching digest returns the downloaded file for core to install.
	set_transient(
		Perdita_Core_Updater::CACHE,
		array(
			'version'      => '99.9.9',
			'download_url' => $psx_pkg,
			'checksum'     => $psx_good_sum,
		),
		MINUTE_IN_SECONDS
	);
	$psx_downloaded = false;
	$psx_verify_ok  = $psx_updater->verify_download( false, $psx_pkg, null );
	$ok(
		! $psx_downloaded || ( is_string( $psx_verify_ok ) && file_exists( $psx_verify_ok ) ),
		'updater: a package whose sha256 matches the manifest is accepted and handed to the installer'
	);
	if ( is_string( $psx_verify_ok ) && file_exists( $psx_verify_ok ) ) {
		wp_delete_file( $psx_verify_ok );
	}

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
