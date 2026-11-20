<?php
/**
 * Perdita Core smoke tests: Switch to Perdita, the security sources.
 *
 * Wordfence, Sucuri, Solid, All In One WP Security, Shield, Defender, and
 * Patchstack, plus the Loginizer and Limit Login Attempts Reloaded fixes.
 * Each fixture follows the option, table, and file shapes read from the
 * plugins' own code. Every source runs plan, import, and undo, and Undo has
 * to put Perdita's settings back byte for byte.
 *
 * Loaded by tests/smoke.php. Every option, table, file, and folder this makes
 * is removed at the end, and by a shutdown function if a test dies. A table
 * the site already had is never touched: its tests skip.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

echo "\n--- Switch to Perdita: security sources (tests/smoke-switch-security-sources.php) ---\n";

require_once PERDITA_CORE_DIR . 'inc/switch/class-perdita-switch-source.php';
require_once PERDITA_CORE_DIR . 'inc/switch/class-perdita-switch-target.php';
require_once PERDITA_CORE_DIR . 'inc/switch/class-perdita-switch-php-config.php';
require_once PERDITA_CORE_DIR . 'inc/switch/class-perdita-switch-backups.php';
require_once PERDITA_CORE_DIR . 'inc/switch/class-perdita-switch-leftovers.php';
require_once PERDITA_CORE_DIR . 'inc/switch/class-perdita-switch.php';
require_once PERDITA_CORE_DIR . 'inc/switch/class-perdita-switch-catalog.php';
Perdita_Switch_Target::load( 'security' );

global $wpdb;

/* ---------- what this test touches ---------- */

$pss_missing = '__pss_missing__';
$pss_names   = array(
	'perdita_security_settings',
	'perdita_switch_state',
	'perdita_switch_snapshots',
	'perdita_firewall_loader_state',
	'perdita_firewall_key',
	'perdita_core',
	// Loginizer and LLAR.
	'loginizer_options',
	'loginizer_whitelist',
	'loginizer_blacklist',
	'loginizer_disable_brute',
	'loginizer_user_enum',
	'loginizer_security',
	'loginizer_username_blacklist',
	'loginizer_captcha',
	'loginizer_2fa',
	'loginizer_ip_method',
	'limit_login_allowed_retries',
	'limit_login_lockout_duration',
	'limit_login_allowed_lockouts',
	'limit_login_long_duration',
	'limit_login_valid_duration',
	'limit_login_whitelist',
	'limit_login_whitelist_usernames',
	'limit_login_blacklist',
	'limit_login_blacklist_usernames',
	'limit_login_active_app',
	'limit_login_mfa_enabled',
	'limit_login_trusted_ip_origins',
	// The seven new sources.
	'aio_wp_security_configs',
	'tfa_administrator',
	'itsec-storage',
	'itsec_active_modules',
	'icwp_wpsf_opts_all',
	'wd_login_lockout_settings',
	'wd_notfound_lockout_settings',
	'wd_blacklist_lockout_settings',
	'wd_lockdown_settings',
	'wd_user_agent_settings',
	'wd_global_ip_settings',
	'wd_antibot_global_firewall_settings',
	'wd_masking_login_settings',
	'wd_2auth_settings',
	'wd_recaptcha_settings',
	'wd_security_headers_settings',
	'hardener_settings',
	'wd_hardener_settings',
	'wd_scan_settings',
	'wd_audit_settings',
	'wd_password_protection_settings',
);
foreach ( array( 'pluginedit', 'userenum', 'hidewpversion', 'xmlrpc_is_disabled', 'json_is_disabled', 'application_passwords_disabled', 'activity_log_is_enabled', 'captcha_type', 'captcha_login_form', 'add_security_headers', 'ip_block_list', 'whitelist', 'login_whitelist', 'block_bruteforce_ips', 'anti_bruteforce_attempts', 'anti_bruteforce_minutes', 'anti_bruteforce_blocktime', 'login_2fa', 'mv_wp_login', 'rename_wp_login', 'basic_firewall', 'license_free', 'license_activated', 'license_expiry' ) as $pss_k ) {
	$pss_names[] = 'patchstack_' . $pss_k;
}
$pss_saved = array();
foreach ( $pss_names as $pss_n ) {
	$pss_saved[ $pss_n ] = get_option( $pss_n, $pss_missing );
}

// Files and folders: what existed, so the end puts it back.
$pss_up      = wp_get_upload_dir();
$pss_base    = rtrim( $pss_up['basedir'], '/' );
$pss_files   = array(
	$pss_base . '/.htaccess',
	$pss_base . '/sucuri/sucuri-settings.php',
	$pss_base . '/sucuri/sucuri-trustip.php',
	$pss_base . '/aios/firewall-rules/settings.php',
	$pss_base . '/aios/firewall-rules/allowlist.php',
);
$pss_dirs    = array( $pss_base . '/sucuri', $pss_base . '/aios/firewall-rules', $pss_base . '/aios' );
$pss_file_was = array();
foreach ( $pss_files as $pss_f ) {
	$pss_file_was[ $pss_f ] = is_file( $pss_f ) ? file_get_contents( $pss_f ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local test file.
}
$pss_dir_was = array();
foreach ( $pss_dirs as $pss_d ) {
	$pss_dir_was[ $pss_d ] = is_dir( $pss_d );
}

// Tables this test creates, and the ones that were already there.
$pss_created = array();
$pss_have    = array();
$pss_make    = static function ( $name, $cols ) use ( &$pss_created, &$pss_have, $wpdb ) {
	$t = $wpdb->prefix . $name;
	if ( $t === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $t ) ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$pss_have[ $name ] = true;
		return '';
	}
	$wpdb->query( "CREATE TABLE `{$t}` ({$cols})" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$pss_created[] = $t;
	return $t;
};

$pss_filters = array(
	'perdita_firewall_dir'            => static function () use ( $pss_base ) {
		return trailingslashit( get_temp_dir() ) . 'pss-fw-' . md5( $pss_base ) . '/';
	},
	'perdita_firewall_bootstrap_path' => static function () {
		return trailingslashit( get_temp_dir() ) . 'pss-fw-bootstrap.php';
	},
	'perdita_firewall_mu_dir'         => static function () {
		return trailingslashit( get_temp_dir() ) . 'pss-fw-mu';
	},
	'perdita_firewall_user_ini_path'  => static function () {
		return trailingslashit( get_temp_dir() ) . 'pss-fw.user.ini';
	},
	'perdita_firewall_htaccess_path'  => static function () {
		return trailingslashit( get_temp_dir() ) . 'pss-fw.htaccess';
	},
);
foreach ( $pss_filters as $pss_h => $pss_cb ) {
	add_filter( $pss_h, $pss_cb );
}

$pss_rrmdir = static function ( $dir ) use ( &$pss_rrmdir ) {
	if ( ! is_dir( $dir ) ) {
		return;
	}
	foreach ( (array) scandir( $dir ) as $e ) {
		if ( '.' === $e || '..' === $e ) {
			continue;
		}
		is_dir( "$dir/$e" ) ? $pss_rrmdir( "$dir/$e" ) : unlink( "$dir/$e" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
	}
	rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rmdir_rmdir
};

$GLOBALS['pss_done'] = false;
$pss_cleanup         = static function () use ( &$pss_created, $pss_saved, $pss_missing, $pss_files, $pss_file_was, $pss_dirs, $pss_dir_was, $pss_filters, $pss_rrmdir, $wpdb ) {
	if ( $GLOBALS['pss_done'] ) {
		return;
	}
	$GLOBALS['pss_done'] = true;
	foreach ( $pss_created as $t ) {
		$wpdb->query( "DROP TABLE IF EXISTS `{$t}`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
	foreach ( $pss_saved as $name => $value ) {
		if ( $pss_missing === $value ) {
			delete_option( $name );
		} else {
			update_option( $name, $value );
		}
	}
	foreach ( $pss_files as $f ) {
		if ( null === $pss_file_was[ $f ] ) {
			if ( is_file( $f ) ) {
				unlink( $f ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			}
		} else {
			file_put_contents( $f, $pss_file_was[ $f ] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
	}
	foreach ( $pss_dirs as $d ) {
		if ( ! $pss_dir_was[ $d ] && is_dir( $d ) ) {
			@rmdir( $d ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rmdir_rmdir
		}
	}
	foreach ( $pss_filters as $h => $cb ) {
		remove_filter( $h, $cb );
	}
	$pss_rrmdir( trailingslashit( get_temp_dir() ) . 'pss-fw-' . md5( rtrim( wp_get_upload_dir()['basedir'], '/' ) ) );
	Perdita_Switch::sources( true );
};
register_shutdown_function( $pss_cleanup );

/* ---------- helpers ---------- */

$pss_put = static function ( $path, $text ) {
	wp_mkdir_p( dirname( $path ) );
	file_put_contents( $path, $text ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
};
$pss_rows = static function ( array $plan, $target = null, $status = null ) {
	$out = array();
	foreach ( $plan['rows'] as $row ) {
		if ( ( null === $target || $row['target'] === $target ) && ( null === $status || $row['status'] === $status ) ) {
			$out[] = $row;
		}
	}
	return $out;
};
$pss_pro = static function ( array $plan ) {
	$n = 0;
	foreach ( $plan['rows'] as $row ) {
		if ( 'none' === $row['status'] && false !== strpos( $row['reason'], 'Perdita Pro' ) ) {
			++$n;
		}
	}
	return $n;
};
$pss_row = static function ( array $plan, $setting ) {
	foreach ( $plan['rows'] as $row ) {
		if ( $row['setting'] === $setting ) {
			return $row;
		}
	}
	return null;
};
// Plan, import, read Perdita's settings, undo, and say whether Undo put the option back byte for byte.
$pss_cycle = static function ( $id ) use ( $pss_missing ) {
	delete_option( 'perdita_security_settings' );
	delete_option( 'perdita_switch_snapshots' );
	delete_option( 'perdita_switch_state' );
	Perdita_Switch::sources( true );
	$source = Perdita_Switch::get( $id );
	$before = get_option( 'perdita_security_settings', $pss_missing );
	$plan   = $source->plan();
	$res    = Perdita_Switch::import( $id );
	$after  = Perdita_Security::get_settings();
	$undo   = Perdita_Switch::undo( $id );
	return array(
		'plan'     => $plan,
		'import'   => $res,
		'settings' => $after,
		'undo'     => $undo,
		'restored' => serialize( get_option( 'perdita_security_settings', $pss_missing ) ) === serialize( $before ),
	);
};
$pss_state = static function () use ( $pss_names, $pss_missing ) {
	$h = array();
	foreach ( $pss_names as $n ) {
		if ( 0 === strpos( $n, 'perdita_' ) ) {
			continue;
		}
		$h[ $n ] = get_option( $n, $pss_missing );
	}
	return md5( serialize( $h ) );
};
$pss_def = Perdita_Security::defaults();

/* =============================================================
 * Allowlist helpers: ranges, wildcards, and the registry.
 * ============================================================= */

$pss_cls = 'Perdita_Switch_Security_Source';
Perdita_Switch::sources( true );
$ok( '10.0.0.0/8' === $pss_cls::range_to_cidr( '10.0.0.0', '10.255.255.255' ), 'allowlist: a start-end range that is one block becomes CIDR' );
$ok( '192.0.2.64/26' === $pss_cls::range_to_cidr( '192.0.2.64', '192.0.2.127' ), 'allowlist: 192.0.2.64 to 192.0.2.127 is a /26' );
$ok( '5.6.7.8/31' === $pss_cls::range_to_cidr( '5.6.7.9', '5.6.7.8' ), 'allowlist: a reversed range still converts' );
$ok( '' === $pss_cls::range_to_cidr( '192.0.2.20', '192.0.2.30' ), 'allowlist: a range that is not one block gives nothing' );
$ok( '203.0.113.9' === $pss_cls::range_to_cidr( '203.0.113.9', '203.0.113.9' ), 'allowlist: start equal to end is a single address' );
$ok( '2001:db8::/32' === $pss_cls::range_to_cidr( '2001:db8::', '2001:db8:ffff:ffff:ffff:ffff:ffff:ffff' ), 'allowlist: IPv6 ranges convert' );
$ok( '' === $pss_cls::range_to_cidr( '0.0.0.0', '255.255.255.255' ) || '' === $pss_cls::allow_entry( '0.0.0.0-255.255.255.255' ), 'allowlist: a range of every address never gets in' );
$ok( '203.0.113.0/24' === $pss_cls::wildcard_to_cidr( '203.0.113.*' ) && '10.0.0.0/8' === $pss_cls::wildcard_to_cidr( '10.*.*.*' ) && '' === $pss_cls::wildcard_to_cidr( '10.*.5.1' ) && '' === $pss_cls::wildcard_to_cidr( '*.*.*.*' ), 'allowlist: trailing wildcards convert and odd ones do not' );
$ok( '198.51.100.0/24' === $pss_cls::allow_entry( '198.51.100.0 - 198.51.100.255' ) && '' === $pss_cls::allow_entry( 'not an ip' ), 'allowlist: spaces around the dash are fine and junk is refused' );
foreach ( array( 'wordfence-core', 'sucuri-core', 'solid-core', 'aios-core', 'shield-core', 'defender-core', 'patchstack-core', 'loginizer', 'limit-login-attempts-reloaded' ) as $pss_id ) {
	$ok( null !== Perdita_Switch::get( $pss_id ) && 'security' === Perdita_Switch::get( $pss_id )->module(), "registry: $pss_id is a security source" );
}
$ok( Perdita_Switch::get( 'wordfence-core' ) instanceof Perdita_Switch_Source_Wordfence && Perdita_Switch::get( 'solid-core' ) instanceof Perdita_Switch_Source_Solid && Perdita_Switch::get( 'aios-core' ) instanceof Perdita_Switch_Source_AIOS, 'registry: the Core ids stay Core\'s own, so Perdita Pro\'s sources for the same plugins never replace them' );
$ok( ! array_intersect( array_keys( Perdita_Switch_Catalog::pro_sources() ), array( 'wordfence-core', 'sucuri-core', 'solid-core', 'aios-core', 'shield-core', 'defender-core', 'patchstack-core' ) ), 'registry: no Core id shares a name with a Pro-only catalog entry' );

// The Security target saves the new keys through the module's own sanitizers.
delete_option( 'perdita_security_settings' );
$ok( true === Perdita_Switch_Target::check( 'security', 'hide_wp_version', true )['ok'] && true === Perdita_Switch_Target::check( 'security', 'fw_rules.sqli', true )['ok'] && 'monitor' === Perdita_Switch_Target::check( 'security', 'firewall_mode', 'monitor' )['clean'], 'target: the new security keys pass the check' );
$ok( ! Perdita_Switch_Target::check( 'security', 'firewall_mode', 'whatever' )['ok'] && ! Perdita_Switch_Target::check( 'security', 'login_long_after', 500 )['ok'], 'target: a bad firewall mode and an out-of-range count are refused' );
Perdita_Switch_Target::write( 'security', array( 'fw_rules' => array( 'xss' => false ), 'hsts_max_age' => 99999999999 ) );
$pss_w = Perdita_Security::get_settings();
$ok( false === $pss_w['fw_rules']['xss'] && true === $pss_w['fw_rules']['sqli'] && 63072000 === $pss_w['hsts_max_age'], 'target: a write merges fw_rules by category and clamps numbers' );
delete_option( 'perdita_security_settings' );

/* =============================================================
 * Wordfence.
 * ============================================================= */

$pss_wf = $pss_make( 'wfConfig', 'name varchar(100) NOT NULL, val longblob, autoload enum(\'no\',\'yes\') NOT NULL DEFAULT \'yes\', PRIMARY KEY (name)' );
$pss_wb = $pss_make( 'wfBlocks7', 'id int unsigned NOT NULL AUTO_INCREMENT, type int unsigned NOT NULL DEFAULT 0, IP binary(16) NOT NULL DEFAULT 0, expiration bigint unsigned NOT NULL DEFAULT 0, parameters text, PRIMARY KEY (id)' );
$pss_wl = $pss_make( 'wfls_settings', 'name varchar(191) NOT NULL, value longblob, autoload enum(\'no\',\'yes\') NOT NULL DEFAULT \'yes\', PRIMARY KEY (name)' );
if ( '' !== $pss_wf && '' !== $pss_wb && '' !== $pss_wl ) {
	$pss_cfg = array(
		'firewallEnabled'                      => '1',
		'autoBlockScanners'                    => '0',
		'loginSecurityEnabled'                 => '1',
		'loginSec_maskLoginErrors'             => '0',
		'loginSec_lockInvalidUsers'            => '1',
		'loginSec_disableAuthorScan'           => '0',
		'loginSec_disableApplicationPasswords' => '1',
		'other_hideWPVersion'                  => '1',
		'other_blockBadPOST'                   => '1',
		'disableCodeExecutionUploads'          => '1',
		'loginSec_maxFailures'                 => '5',
		'loginSec_countFailMins'               => '60',
		'loginSec_lockoutMins'                 => '120',
		'loginSec_requireAdminTwoFactor'       => '1',
		'whitelisted'                          => '203.0.113.10,198.51.100.0/24,192.0.2.20-192.0.2.30,192.0.2.64-192.0.2.127',
		'whitelistedServices'                  => '{"sucuri":true,"pingdom":false}',
		'bannedURLs'                           => '/xmlrpc-backup.php,/old-admin/*',
		'howGetIPs'                            => 'HTTP_CF_CONNECTING_IP',
		'maxGlobalRequests'                    => '240',
		'maxGlobalRequests_action'             => 'block',
		'maxRequestsHumans'                    => 'DISABLED',
		'scheduledScansEnabled'                => '1',
		'auditLogMode'                         => 'significant',
		'apiKey'                               => 'wf-secret-api-key-should-never-be-read',
		'scanSched'                            => serialize( array( 3, 15, 22 ) ),
	);
	foreach ( $pss_cfg as $k => $v ) {
		$wpdb->insert( $pss_wf, array( 'name' => $k, 'val' => $v ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
	$wpdb->insert( $pss_wb, array( 'type' => 1, 'IP' => '', 'expiration' => 0, 'parameters' => null ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->insert( $pss_wb, array( 'type' => 3, 'IP' => '', 'expiration' => 0, 'parameters' => '{"blockLogin":1,"blockSite":0,"countries":["CN","RU","KP"]}' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->insert( $pss_wb, array( 'type' => 4, 'IP' => '', 'expiration' => 0, 'parameters' => '{"userAgent":"*BadBot*"}' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->insert( $pss_wb, array( 'type' => 1, 'IP' => '', 'expiration' => 99999999999, 'parameters' => null ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->insert( $pss_wl, array( 'name' => 'enable-2fa', 'value' => '1' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->insert( $pss_wl, array( 'name' => 'enable-auth-captcha', 'value' => '1' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

	$pss_htaccess = $pss_base . '/.htaccess';
	$pss_put( $pss_htaccess, "# keep me\n" );
	$pss_h0 = file_get_contents( $pss_htaccess ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	Perdita_Switch::sources( true );
	$pss_src = Perdita_Switch::get( 'wordfence-core' );
	$ok( $pss_src->has_settings() && $pss_src->detect()['has_settings'], 'wordfence: the wfConfig table counts as settings' );
	$pss_s0 = $pss_state();
	$pss_r  = $pss_cycle( 'wordfence-core' );
	$pss_p  = $pss_r['plan'];
	$pss_s  = $pss_r['settings'];
	$ok( true === $pss_p['patch']['login_limit'] && 5 === $pss_p['patch']['login_max'] && 60 === $pss_p['patch']['login_window'] && 120 === $pss_p['patch']['login_lockout'], 'wordfence: the login limit, window, and lockout come over in minutes' );
	$ok( array( '203.0.113.10', '198.51.100.0/24', '192.0.2.64/26' ) === $pss_p['patch']['ip_allowlist'] && 1 === count( $pss_rows( $pss_p, 'Never lock out these IPs', 'skip' ) ), 'wordfence: the allowlist takes addresses, CIDR, and an exact range, and shows the other range as refused' );
	$ok( $pss_r['import']['ok'] && true === $pss_s['login_limit'] && 5 === $pss_s['login_max'] && array( '203.0.113.10', '198.51.100.0/24', '192.0.2.64/26' ) === $pss_s['ip_allowlist'], 'wordfence: the import lands in perdita_security_settings' );
	$ok( true === $pss_s['disable_app_passwords'] && true === $pss_s['hide_wp_version'] && true === $pss_s['block_uploads_php'] && true === $pss_s['core_check'], 'wordfence: application passwords, version, uploads PHP, and the core check come over' );
	$ok( false === $pss_s['login_generic_errors'] && false === $pss_s['block_author_enum'] && 'off' === $pss_s['restrict_rest'], 'wordfence: a switch that was off never turns on in Perdita' );
	$ok( true === $pss_s['firewall'] && 'monitor' === $pss_s['firewall_mode'] && 'plugin' === $pss_s['firewall_load'], 'wordfence: the firewall comes over in monitor mode' );
	$ok( 'monitor' === $pss_p['patch']['firewall_mode'] && true === $pss_p['patch']['fw_rules']['sqli'] && true === $pss_p['patch']['fw_rules']['php_upload'] && isset( $pss_p['patch']['fw_rules']['bad_bots'] ), 'wordfence: the plan names the matching firewall categories, and bad POST blocking maps to bad bots' );
	$ok( $pss_p['patch']['fw_rules'] === array_intersect_key( $pss_p['patch']['fw_rules'], array_flip( array( 'sqli', 'xss', 'traversal', 'php_upload', 'bad_bots' ) ) ), 'wordfence: no category without a Wordfence match is offered' );
	$ok( null !== $pss_row( $pss_p, 'Rate limiting' ) && null !== $pss_row( $pss_p, 'Blocked IP addresses' ) && null !== $pss_row( $pss_p, 'Country blocking' ) && null !== $pss_row( $pss_p, 'Advanced blocking (IP range, hostname, user agent, referrer)' ), 'wordfence: rate limits, blocked IPs, countries, and pattern blocks show as Perdita Pro' );
	$ok( 1 === (int) $pss_row( $pss_p, 'Blocked IP addresses' )['found'] && false !== strpos( $pss_row( $pss_p, 'Country blocking' )['found'], 'CN, RU, KP' ), 'wordfence: only permanent blocks count, and the countries list' );
	$ok( null !== $pss_row( $pss_p, 'Two-factor authentication and passkeys' ) && null !== $pss_row( $pss_p, 'Audit log' ) && null !== $pss_row( $pss_p, 'Immediately lock out invalid usernames' ) && null !== $pss_row( $pss_p, 'reCAPTCHA on login' ) && null !== $pss_row( $pss_p, 'How to get IPs' ) && null !== $pss_row( $pss_p, 'Allowlisted services' ), 'wordfence: two-factor, the audit log, and the settings Perdita has no match for each get a row' );
	$ok( 0 === count( array_filter( $pss_p['rows'], static function ( $r ) {
		return false !== strpos( $r['found'], 'wf-secret' ) || false !== strpos( $r['setting'], 'apiKey' );
	} ) ), 'wordfence: the API key is never read' );
	$ok( $pss_r['undo']['ok'] && $pss_r['restored'], 'wordfence: Undo puts perdita_security_settings back byte for byte' );
	$ok( $pss_h0 === file_get_contents( $pss_htaccess ), 'wordfence: Undo takes the uploads .htaccess block out and keeps the other lines' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	$ok( $pss_s0 === $pss_state() && 5 === (int) $wpdb->get_var( "SELECT val FROM `{$pss_wf}` WHERE name = 'loginSec_maxFailures'" ), 'wordfence: its own table and every other plugin option are untouched' ); // phpcs:ignore WordPress.DB
	// A block Perdita already has stays as it is.
	update_option( 'perdita_security_settings', array_merge( $pss_def, array( 'firewall' => true, 'firewall_mode' => 'block' ) ) );
	Perdita_Switch::sources( true );
	$pss_p = Perdita_Switch::get( 'wordfence-core' )->plan();
	$ok( ! isset( $pss_p['patch']['firewall_mode'] ) && ! isset( $pss_p['patch']['firewall'] ) && (bool) array_filter( $pss_p['notes'], static function ( $n ) {
		return false !== strpos( $n, 'already on' );
	} ), 'wordfence: a firewall already on in Perdita keeps its mode, and the plan says so' );
	delete_option( 'perdita_security_settings' );
	// Wordfence's own defaults when it never wrote a row.
	$wpdb->query( "DELETE FROM `{$pss_wf}` WHERE name <> 'apiKey'" ); // phpcs:ignore WordPress.DB
	Perdita_Switch::sources( true );
	$pss_p = Perdita_Switch::get( 'wordfence-core' )->plan();
	$ok( true === $pss_p['patch']['login_limit'] && 20 === $pss_p['patch']['login_max'] && 240 === $pss_p['patch']['login_window'] && 240 === $pss_p['patch']['login_lockout'] && true === $pss_p['patch']['login_generic_errors'] && true === $pss_p['patch']['block_author_enum'] && 'users' === $pss_p['patch']['restrict_rest'], 'wordfence: a row it never wrote takes Wordfence\'s default (20 failures, 240 minutes, masked errors, author scan blocked)' );
} else {
	echo "SKIP  wordfence (a Wordfence table already exists on this site)\n";
}

/* =============================================================
 * Sucuri.
 * ============================================================= */

$pss_sdir = $pss_base . '/sucuri';
if ( ! is_file( $pss_sdir . '/sucuri-settings.php' ) ) {
	$pss_opts = array(
		'sucuriscan_account'                 => 'owner@example.com',
		'sucuriscan_addr_header'             => 'HTTP_CF_CONNECTING_IP',
		'sucuriscan_maximum_failed_logins'   => 15,
		'sucuriscan_notify_to'               => 'ops@example.com',
		'sucuriscan_notify_failed_login'     => 'enabled',
		'sucuriscan_notify_scan_checksums'   => 'enabled',
		'sucuriscan_twofactor_mode'          => 'selected_users',
		'sucuriscan_hardening_xmlrpc'        => 'enabled',
		'sucuriscan_headers_csp'             => 'enabled',
		'sucuriscan_headers_cors'            => 'disabled',
		'sucuriscan_cloudproxy_apikey'       => 'abcdef0123456789abcdef0123456789',
	);
	$pss_put( $pss_sdir . '/sucuri-settings.php', "<?php exit(0); ?>\n" . wp_json_encode( $pss_opts ) . "\n" );
	$pss_put( $pss_sdir . '/sucuri-trustip.php', "<?php exit(0); ?>\n" . md5( '203.0.113.0' ) . ':' . wp_json_encode( array( 'remote_addr' => '203.0.113.0', 'cidr_range' => '24', 'cidr_format' => '203.0.113.0/24', 'added_at' => 1760000000 ) ) . "\n" );
	$pss_sucuri_rules = "# before\n<FilesMatch \"\\.(?i:php)\$\">\n  <IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n  </IfModule>\n  <IfModule mod_authz_core.c>\n    Require all denied\n  </IfModule>\n</FilesMatch>\n";
	$pss_put( $pss_base . '/.htaccess', $pss_sucuri_rules );
	Perdita_Switch::sources( true );
	$ok( Perdita_Switch::get( 'sucuri-core' )->has_settings(), 'sucuri: the settings file in uploads counts as settings' );
	$pss_s0 = $pss_state();
	$pss_r  = $pss_cycle( 'sucuri-core' );
	$pss_p  = $pss_r['plan'];
	$pss_s  = $pss_r['settings'];
	$ok( $pss_r['import']['ok'] && true === $pss_s['disable_xmlrpc'] && true === $pss_s['block_uploads_php'] && true === $pss_s['core_check'], 'sucuri: XML-RPC, the uploads PHP rule, and the checksum scan come over' );
	$ok( ! isset( $pss_p['patch']['login_limit'] ) && null !== $pss_row( $pss_p, 'Maximum failed logins per hour' ), 'sucuri: the failed-login alert threshold never becomes a login limit' );
	$ok( ! isset( $pss_p['patch']['ip_allowlist'] ) && null !== $pss_row( $pss_p, 'Trusted IP addresses' ) && false !== strpos( $pss_row( $pss_p, 'Trusted IP addresses' )['found'], '203.0.113.0/24' ), 'sucuri: trusted IPs silence alerts, so they are listed and not allowlisted' );
	$ok( null !== $pss_row( $pss_p, 'Two-factor authentication' ) && null !== $pss_row( $pss_p, 'Email alerts' ) && null !== $pss_row( $pss_p, 'Content-Security-Policy header' ) && null !== $pss_row( $pss_p, 'IP address header' ), 'sucuri: two-factor, alerts, the CSP header, and the IP header each get a row' );
	$ok( 0 === count( array_filter( $pss_p['rows'], static function ( $r ) {
		return false !== strpos( $r['found'], 'abcdef0123' );
	} ) ), 'sucuri: the firewall key is never read' );
	$ok( $pss_r['undo']['ok'] && $pss_r['restored'], 'sucuri: Undo puts perdita_security_settings back byte for byte' );
	$ok( $pss_sucuri_rules === file_get_contents( $pss_base . '/.htaccess' ), 'sucuri: Undo leaves Sucuri\'s own .htaccess rule exactly as it was' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	$ok( $pss_s0 === $pss_state(), 'sucuri: nothing else changed' );
	// A file that isn't PHP-prefixed JSON is not read.
	$pss_put( $pss_sdir . '/sucuri-settings.php', '{"sucuriscan_hardening_xmlrpc":"enabled"}' );
	Perdita_Switch::sources( true );
	$ok( ! Perdita_Switch::get( 'sucuri-core' )->has_settings(), 'sucuri: a settings file without the PHP header is ignored' );
	$pss_put( $pss_base . '/.htaccess', "# keep me\n" );
} else {
	echo "SKIP  sucuri (the site already has a Sucuri settings file)\n";
}

/* =============================================================
 * Solid / Kadence Security Basic.
 * ============================================================= */

$pss_ib = $pss_make( 'itsec_bans', 'id int unsigned NOT NULL AUTO_INCREMENT, host varchar(64) NOT NULL, type varchar(20) NOT NULL DEFAULT \'ip\', PRIMARY KEY (id)' );
if ( '' !== $pss_ib ) {
	$wpdb->insert( $pss_ib, array( 'host' => '203.0.113.99', 'type' => 'ip' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->insert( $pss_ib, array( 'host' => '198.51.100.0/24', 'type' => 'range' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	update_option(
		'itsec-storage',
		array(
			'global'           => array( 'lockout_period' => 30, 'blacklist' => true, 'blacklist_count' => 5, 'lockout_white_list' => array( '203.0.113.5', '198.51.100.0/24', '192.0.2.*', '1.2.3.4-1.2.3.9' ), 'write_files' => true, 'proxy' => 'manual' ),
			'ban-users'        => array( 'default' => true, 'agent_list' => array( 'BadBot/1.0' ) ),
			'brute-force'      => array( 'max_attempts_host' => 4, 'max_attempts_user' => 8, 'check_period' => 10 ),
			'hide-backend'     => array( 'enabled' => true, 'slug' => 'secret-login' ),
			'wordpress-tweaks' => array( 'file_editor' => false, 'disable_xmlrpc' => 'disable', 'rest_api' => 'restrict-access', 'valid_user_login_type' => 'email', 'disable_unused_author_pages' => true ),
			'system-tweaks'    => array( 'uploads_php' => true ),
			'password-requirements' => array( 'enabled_requirements' => array( 'strength' => true, 'hibp' => true ) ),
		)
	);
	update_option( 'itsec_active_modules', array( 'ban-users' => true, 'brute-force' => true, 'firewall' => true, 'two-factor' => true, 'file-change' => false ) );
	$pss_put( $pss_base . '/.htaccess', "# keep me\n" );
	Perdita_Switch::sources( true );
	$pss_src = Perdita_Switch::get( 'solid-core' );
	$ok( $pss_src->has_settings() && array( 'better-wp-security/better-wp-security.php', 'ithemes-security-pro/ithemes-security-pro.php' ) === $pss_src->plugin_files(), 'solid: found by its slug and its itsec-storage option, never by name' );
	$pss_s0 = $pss_state();
	$pss_r  = $pss_cycle( 'solid-core' );
	$pss_p  = $pss_r['plan'];
	$pss_s  = $pss_r['settings'];
	$ok( $pss_r['import']['ok'] && true === $pss_s['login_limit'] && 4 === $pss_s['login_max'] && 10 === $pss_s['login_window'] && 30 === $pss_s['login_lockout'], 'solid: the host limit, check period, and lockout period come over' );
	$ok( true === $pss_s['login_long_lockout'] && 5 === $pss_s['login_long_after'] && 24 === $pss_s['login_long_hours'], 'solid: banning repeat offenders becomes a longer lockout after the same count' );
	$ok( array( '203.0.113.5', '198.51.100.0/24', '192.0.2.0/24' ) === $pss_s['ip_allowlist'] && 1 === count( $pss_rows( $pss_p, 'Never lock out these IPs', 'skip' ) ), 'solid: the allowlist takes a wildcard as a block and refuses an uneven range' );
	$ok( true === $pss_s['disable_xmlrpc'] && 'users' === $pss_s['restrict_rest'] && true === $pss_s['disable_file_edit'] && true === $pss_s['block_author_enum'] && true === $pss_s['block_uploads_php'], 'solid: XML-RPC, REST users, the file editor (an inverted switch), author pages, and uploads PHP come over' );
	$ok( false === $pss_s['core_check'], 'solid: file change detection that was off stays off' );
	$ok( true === $pss_s['firewall'] && 'monitor' === $pss_s['firewall_mode'] && array( 'bad_bots' ) === array_keys( $pss_p['patch']['fw_rules'] ), 'solid: the firewall comes over in monitor mode with the bad-bot category from the user agent list' );
	$ok( null !== $pss_row( $pss_p, 'Ban Users, banned IPs' ) && 2 === (int) $pss_row( $pss_p, 'Ban Users, banned IPs' )['found'] && null !== $pss_row( $pss_p, 'Hide Backend' ) && null !== $pss_row( $pss_p, 'Two-Factor Authentication' ) && null !== $pss_row( $pss_p, 'Refuse compromised passwords' ) && null !== $pss_row( $pss_p, 'Banned user agents' ), 'solid: bans, Hide Backend, two-factor, breached passwords, and user agents show as Perdita Pro' );
	$ok( $pss_r['undo']['ok'] && $pss_r['restored'], 'solid: Undo puts perdita_security_settings back byte for byte' );
	$ok( "# keep me\n" === file_get_contents( $pss_base . '/.htaccess' ), 'solid: Undo takes out the uploads block' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	$ok( $pss_s0 === $pss_state(), 'solid: its options are untouched' );
	// A default-on module with no row, and a limit of 0.
	update_option( 'itsec-storage', array( 'brute-force' => array( 'max_attempts_host' => 0 ) ) );
	delete_option( 'itsec_active_modules' );
	Perdita_Switch::sources( true );
	$pss_p = Perdita_Switch::get( 'solid-core' )->plan();
	$ok( false === $pss_p['patch']['login_limit'], 'solid: a host limit of 0 means Solid locked nobody out' );
	update_option( 'itsec-storage', array( 'global' => array() ) );
	Perdita_Switch::sources( true );
	$pss_p = Perdita_Switch::get( 'solid-core' )->plan();
	$ok( true === $pss_p['patch']['login_limit'] && 5 === $pss_p['patch']['login_max'] && 5 === $pss_p['patch']['login_window'] && 15 === $pss_p['patch']['login_lockout'], 'solid: Solid\'s own defaults (5 attempts, 5 minutes, 15 minutes) fill what it never saved' );
} else {
	echo "SKIP  solid (an itsec_bans table already exists on this site)\n";
}

/* =============================================================
 * All In One WP Security.
 * ============================================================= */

$pss_ap = $pss_make( 'aiowps_permanent_block', 'id bigint unsigned NOT NULL AUTO_INCREMENT, blocked_ip varchar(100) NOT NULL DEFAULT \'\', block_reason varchar(128) NOT NULL DEFAULT \'\', PRIMARY KEY (id)' );
if ( '' !== $pss_ap && ! is_file( $pss_base . '/aios/firewall-rules/settings.php' ) ) {
	$wpdb->insert( $pss_ap, array( 'blocked_ip' => '192.0.2.77', 'block_reason' => 'spam' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	update_option(
		'aio_wp_security_configs',
		array(
			'aiowps_enable_login_lockdown'                => '1',
			'aiowps_max_login_attempts'                   => '5',
			'aiowps_retry_time_period'                    => '10',
			'aiowps_lockout_time_length'                  => '30',
			'aiowps_max_lockout_time_length'              => '120',
			'aiowps_set_generic_login_msg'                => '1',
			'aiowps_enable_invalid_username_lockdown'     => '1',
			'aiowps_instantly_lockout_specific_usernames' => array( 'admin', 'root' ),
			'aiowps_lockdown_enable_whitelisting'         => '',
			'aiowps_enable_whitelisting'                  => '1',
			'aiowps_allowed_ip_addresses'                 => "203.0.113.5\n198.51.100.0/24",
			'aiowps_enable_blacklisting'                  => '1',
			'aiowps_banned_ip_addresses'                  => "192.0.2.10\n203.0.113.*\n2001:db8::/32",
			'aiowps_banned_user_agents'                   => "BadBot\nEvilScraper",
			'aiowps_enable_rename_login_page'             => '1',
			'aiowps_login_page_slug'                      => 'my-login',
			'aiowps_enable_login_honeypot'                => '1',
			'aiowps_disable_application_password'         => '1',
			'aiowps_enable_basic_firewall'                => '1',
			'aiowps_disable_xmlrpc_pingback_methods'      => '1',
			'aiowps_block_debug_log_file_access'          => '1',
			'aiowps_enable_6g_firewall'                   => '1',
			'aiowps_prevent_users_enumeration'            => '1',
			'aiowps_prevent_site_display_inside_frame'    => '1',
			'aiowps_disable_file_editing'                 => '1',
			'aiowps_remove_wp_generator_meta_info'        => '1',
			'aiowps_disallow_unauthorized_rest_requests'  => '1',
			'aiowps_enable_404_IP_lockout'                => '1',
			'aiowps_default_captcha'                      => 'cloudflare-turnstile',
			'aiowps_enable_login_captcha'                 => '1',
			'aiowps_turnstile_secret_key'                 => '1x0000000000000000000000000000000AA',
			'aiowps_enable_automated_fcd_scan'            => '1',
			'aiowps_ip_retrieve_method'                   => '1',
		)
	);
	update_option( 'tfa_administrator', '1' );
	$pss_prefix = "<?php __halt_compiler();\n/**\n * This file was created by All In One Security (AIOS) plugin.\n * The file is required for storing and retrieving your firewall's settings.\n */\n";
	$pss_put(
		$pss_base . '/aios/firewall-rules/settings.php',
		$pss_prefix . wp_json_encode(
			array(
				'aiowps_enable_pingback_firewall' => true,
				'aiowps_deny_bad_query_strings'   => true,
				'aiowps_6g_block_query'           => true,
				'aiowps_6g_block_agents'          => true,
				'aiowps_block_fake_googlebots'    => true,
				'aiowps_blacklist_user_agents'    => array( 'BadBot', 'EvilScraper' ),
			)
		)
	);
	$pss_put( $pss_base . '/aios/firewall-rules/allowlist.php', "<?php __halt_compiler();\n/**\n * This file was created by All In One Security (AIOS) plugin.\n * The file is required for storing and retrieving your firewall's allow list.\n */\n198.51.100.0/24\n10.1.0.0-10.1.255.255" );
	Perdita_Switch::sources( true );
	$ok( Perdita_Switch::get( 'aios-core' )->has_settings(), 'aios: the option counts as settings' );
	$pss_s0 = $pss_state();
	$pss_r  = $pss_cycle( 'aios-core' );
	$pss_p  = $pss_r['plan'];
	$pss_s  = $pss_r['settings'];
	$ok( $pss_r['import']['ok'] && true === $pss_s['login_limit'] && 5 === $pss_s['login_max'] && 10 === $pss_s['login_window'] && 30 === $pss_s['login_lockout'], 'aios: the lockdown count, retry period, and lockout length come over in minutes' );
	$ok( array( '203.0.113.5', '198.51.100.0/24', '10.1.0.0/16' ) === $pss_s['ip_allowlist'], 'aios: the login allow list and the allow list file merge into one allowlist, with the file\'s range as a block' );
	$ok( true === $pss_s['login_generic_errors'] && true === $pss_s['disable_app_passwords'] && true === $pss_s['disable_file_edit'] && true === $pss_s['hide_wp_version'] && true === $pss_s['block_author_enum'] && 'users' === $pss_s['restrict_rest'] && true === $pss_s['headers'], 'aios: the main option\'s hardening switches come over' );
	$ok( true === $pss_s['disable_xmlrpc'] && true === $pss_s['core_check'] && true === $pss_s['login_turnstile'], 'aios: the firewall file\'s XML-RPC block, the scan, and Turnstile come over' );
	$ok( true === $pss_s['firewall'] && 'monitor' === $pss_s['firewall_mode'] && array( 'sqli', 'xss', 'traversal', 'bad_bots', 'probes', 'xmlrpc' ) === array_keys( $pss_p['patch']['fw_rules'] ), 'aios: the firewall file maps to the matching categories, in monitor mode' );
	$ok( null !== $pss_row( $pss_p, 'Maximum lockout time length' ) && null === $pss_row( $pss_p, 'Disable XML-RPC pingback methods' ), 'aios: the growing lockout shows as no match, and the pingback switch stays quiet when XML-RPC is fully blocked' );
	$ok( 4 === (int) $pss_row( $pss_p, 'Banned IP addresses and permanent blocks' )['found'], 'aios: banned addresses plus permanent blocks count for Perdita Pro' );
	$ok( null !== $pss_row( $pss_p, 'Banned user agents' ) && null !== $pss_row( $pss_p, '404 detection lockout' ) && null !== $pss_row( $pss_p, 'Instantly lockout invalid usernames' ) && null !== $pss_row( $pss_p, 'Rename login page' ) && null !== $pss_row( $pss_p, 'Two-factor authentication' ), 'aios: user agents, 404 lockout, usernames, the renamed login, and two-factor show as Perdita Pro' );
	$ok( null !== $pss_row( $pss_p, 'Login form honeypot' ) && null !== $pss_row( $pss_p, 'IP address detection' ), 'aios: the honeypot and IP detection show as no match' );
	$ok( 0 === count( array_filter( $pss_p['rows'], static function ( $r ) {
		return false !== strpos( $r['found'], '1x00000000' );
	} ) ), 'aios: the captcha secret is never shown' );
	$ok( $pss_r['undo']['ok'] && $pss_r['restored'], 'aios: Undo puts perdita_security_settings back byte for byte' );
	$ok( $pss_s0 === $pss_state() && is_file( $pss_base . '/aios/firewall-rules/settings.php' ), 'aios: its option and files are untouched' );
	// A max login attempts value that isn't a positive whole number never imports as "lock out after 1 failure".
	$pss_aio_cfg = get_option( 'aio_wp_security_configs' );
	foreach ( array( 'abc', '', '0', '-3', '2.5', array( '5' ) ) as $pss_bad_max ) {
		$pss_cfg = $pss_aio_cfg;
		$pss_cfg['aiowps_max_login_attempts'] = $pss_bad_max;
		update_option( 'aio_wp_security_configs', $pss_cfg );
		Perdita_Switch::sources( true );
		$pss_r = $pss_cycle( 'aios-core' );
		$pss_label = is_array( $pss_bad_max ) ? 'an array' : '"' . $pss_bad_max . '"';
		$pss_note  = $pss_row( $pss_r['plan'], 'Max login attempts' );
		$ok( null === $pss_row( $pss_r['plan'], 'Allowed retries' ) && $pss_note && 'none' === $pss_note['status'] && 5 === $pss_r['settings']['login_max'], 'aios: a max login attempts of ' . $pss_label . ' is skipped with a note, and the default of 5 holds' );
	}
	foreach ( array( '7' => '7', 7 => 7 ) as $pss_good_max => $pss_expect ) {
		$pss_cfg = $pss_aio_cfg;
		$pss_cfg['aiowps_max_login_attempts'] = is_int( $pss_expect ) ? $pss_expect : (string) $pss_good_max;
		update_option( 'aio_wp_security_configs', $pss_cfg );
		Perdita_Switch::sources( true );
		$pss_r = $pss_cycle( 'aios-core' );
		$ok( 7 === $pss_r['settings']['login_max'], 'aios: a max login attempts of ' . var_export( $pss_cfg['aiowps_max_login_attempts'], true ) . ' still imports as 7' );
	}
	update_option( 'aio_wp_security_configs', $pss_aio_cfg );
	Perdita_Switch::sources( true );
	// A firewall file written for a Perdita-less site is plain text, never executed.
	$pss_put( $pss_base . '/aios/firewall-rules/settings.php', "<?php __halt_compiler();\n/**\n * x\n * y\n */\n" . '{"aiowps_enable_pingback_firewall":true}' . "\n<?php die('boom');" );
	Perdita_Switch::sources( true );
	$ok( is_array( Perdita_Switch::get( 'aios-core' )->plan() ), 'aios: a settings file with trailing PHP is read as text and never run' );
} else {
	echo "SKIP  aios (an AIOS table or firewall file already exists on this site)\n";
}

/* =============================================================
 * Shield.
 * ============================================================= */

$pss_si = $pss_make( 'icwp_wpsf_ips', 'id int unsigned NOT NULL AUTO_INCREMENT, ip varbinary(16) NOT NULL, PRIMARY KEY (id)' );
$pss_sr = $pss_make( 'icwp_wpsf_ip_rules', 'id int unsigned NOT NULL AUTO_INCREMENT, ip_ref int unsigned NOT NULL, cidr int unsigned NOT NULL DEFAULT 0, is_range tinyint NOT NULL DEFAULT 0, type char(2) NOT NULL, label varchar(255) NOT NULL DEFAULT \'\', deleted_at int unsigned NOT NULL DEFAULT 0, PRIMARY KEY (id)' );
if ( '' !== $pss_si && '' !== $pss_sr ) {
	$pss_ip = static function ( $addr, $type, $cidr = 0, $range = 0, $deleted = 0 ) use ( $wpdb, $pss_si, $pss_sr ) {
		$hex = strtoupper( bin2hex( inet_pton( $addr ) ) );
		$wpdb->query( "INSERT INTO `{$pss_si}` (ip) VALUES (UNHEX('{$hex}'))" ); // phpcs:ignore WordPress.DB
		$wpdb->insert( $pss_sr, array( 'ip_ref' => $wpdb->insert_id, 'cidr' => $cidr, 'is_range' => $range, 'type' => $type, 'deleted_at' => $deleted ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	};
	$pss_ip( '198.51.100.0', 'MW', 24, 1 );
	$pss_ip( '203.0.113.9', 'MW' );
	$pss_ip( '192.0.2.200', 'MW', 0, 0, 1700000000 );
	$pss_ip( '203.0.113.7', 'MB' );
	$pss_ip( '2001:db8::', 'MB', 32, 1 );
	update_option(
		'icwp_wpsf_opts_all',
		array(
			'version' => '22.3.4',
			'values'  => array(
				'free' => array(
					'block_dir_traversal'        => 'N',
					'block_sql_queries'          => 'Y',
					'block_php_code'             => 'Y',
					'block_aggressive'           => 'Y',
					'block_author_discovery'     => 'N',
					'disable_anonymous_restapi'  => 'Y',
					'disable_file_editing'       => 'Y',
					'disable_xmlrpc'             => 'Y',
					'enable_core_file_integrity_scan' => 'Y',
					'enable_google_authenticator' => 'Y',
					'rename_wplogin_path'        => 'my-login',
					'track_loginfailed'          => 'transgression-double',
					'transgression_limit'        => 5,
					'auto_expire'                => 'day',
					'x_frame'                    => 'on_deny',
					'x_xss_protect'              => 'Y',
					'x_content_type'             => 'Y',
					'enable_logger'              => 'Y',
					'visitor_address_source'     => 'HTTP_CF_CONNECTING_IP',
					'page_params_whitelist'      => array( 'my_param' ),
					'global_enable_plugin_features' => 'Y',
				),
				'pro'  => array(
					'enable_limiter' => 'Y',
					'limit_requests' => 30,
				),
			),
			'xfer_excluded' => array(),
		)
	);
	Perdita_Switch::sources( true );
	$ok( Perdita_Switch::get( 'shield-core' )->has_settings(), 'shield: icwp_wpsf_opts_all counts as settings' );
	$pss_s0 = $pss_state();
	$pss_r  = $pss_cycle( 'shield-core' );
	$pss_p  = $pss_r['plan'];
	$pss_s  = $pss_r['settings'];
	$ok( $pss_r['import']['ok'] && true === $pss_s['login_limit'] && 3 === $pss_s['login_max'] && 1440 === $pss_s['login_lockout'], 'shield: the offense limit (5, two per failed login) becomes 3 failures, and a day-long block becomes a 1440 minute lockout' );
	$ok( array( '198.51.100.0/24', '203.0.113.9' ) === $pss_s['ip_allowlist'], 'shield: bypass rules read from its tables, as a block and an address, and a deleted rule stays out' );
	$ok( true === $pss_s['disable_xmlrpc'] && true === $pss_s['disable_file_edit'] && true === $pss_s['core_check'] && 'users' === $pss_s['restrict_rest'] && true === $pss_s['headers'] && false === $pss_s['block_author_enum'], 'shield: XML-RPC, the file editor, the core scan, REST, and headers come over, and username fishing that was off stays off' );
	$ok( true === $pss_s['firewall'] && array( 'sqli', 'rfi' ) === array_keys( $pss_p['patch']['fw_rules'] ), 'shield: SQL queries and PHP code map to categories, and directory traversal that was off is left out' );
	$ok( null !== $pss_row( $pss_p, 'Manual IP block rules' ) && 2 === (int) $pss_row( $pss_p, 'Manual IP block rules' )['found'] && null !== $pss_row( $pss_p, 'Two-factor authentication' ) && null !== $pss_row( $pss_p, 'Rename WP login path' ) && null !== $pss_row( $pss_p, 'Traffic rate limiter' ) && null !== $pss_row( $pss_p, 'Activity log' ), 'shield: block rules, two-factor, the login path, the limiter (a Pro value), and the activity log show as Perdita Pro' );
	$ok( null !== $pss_row( $pss_p, 'Aggressive scan' ) && null !== $pss_row( $pss_p, 'Firewall exclusions' ) && null !== $pss_row( $pss_p, 'Visitor IP address source' ), 'shield: aggressive rules, exclusions, and the IP source show as no match' );
	$ok( $pss_r['undo']['ok'] && $pss_r['restored'], 'shield: Undo puts perdita_security_settings back byte for byte' );
	$ok( $pss_s0 === $pss_state(), 'shield: its option is untouched' );
	// A week-long block is longer than Perdita allows.
	$pss_o = get_option( 'icwp_wpsf_opts_all' );
	$pss_o['values']['free']['auto_expire'] = 'week';
	update_option( 'icwp_wpsf_opts_all', $pss_o );
	Perdita_Switch::sources( true );
	$pss_p = Perdita_Switch::get( 'shield-core' )->plan();
	$ok( ! isset( $pss_p['patch']['login_lockout'] ) && (bool) $pss_rows( $pss_p, 'Lockout length (minutes)', 'skip' ), 'shield: a week-long block is refused with a reason' );
	// A master switch that was off.
	$pss_o['values']['free']['global_enable_plugin_features'] = 'N';
	update_option( 'icwp_wpsf_opts_all', $pss_o );
	Perdita_Switch::sources( true );
	$pss_p = Perdita_Switch::get( 'shield-core' )->plan();
	$ok( empty( $pss_p['patch'] ) && (bool) $pss_p['notes'], 'shield: with the master switch off nothing was running, and nothing moves' );
} else {
	echo "SKIP  shield (a Shield table already exists on this site)\n";
}

/* =============================================================
 * Defender.
 * ============================================================= */

$pss_def_opts = array(
	'wd_login_lockout_settings'           => array( 'enabled' => true, 'attempt' => 3, 'timeframe' => 600, 'duration' => 30, 'duration_unit' => 'minutes', 'lockout_type' => 'timeframe', 'username_blacklist' => "admin\nadministrator" ),
	'wd_notfound_lockout_settings'        => array( 'enabled' => true, 'attempt' => 15, 'timeframe' => 300, 'duration' => 1, 'duration_unit' => 'hours' ),
	'wd_blacklist_lockout_settings'       => array( 'ip_blacklist' => "192.0.2.50\n192.0.2.100-192.0.2.200\n198.51.100.0/24\n2001:db8::/32", 'ip_whitelist' => "203.0.113.10\n203.0.113.0/28\n192.0.2.100-192.0.2.200", 'country_blacklist' => array( 'RU', 'KP' ), 'country_whitelist' => array(), 'maxmind_license_key' => 'mm-license-never-read-1234' ),
	'wd_lockdown_settings'                => array( 'ip_detection_type' => 'manual', 'http_ip_header' => 'HTTP_X_FORWARDED_FOR' ),
	'wd_user_agent_settings'              => array( 'enabled' => true, 'blacklist' => "badbot\nscrapy", 'whitelist' => 'goodbot' ),
	'wd_global_ip_settings'               => array( 'enabled' => true ),
	'wd_antibot_global_firewall_settings' => array( 'enabled' => true, 'mode' => 'strict' ),
	'wd_masking_login_settings'           => array( 'enabled' => true, 'mask_url' => 'team-login' ),
	'wd_2auth_settings'                   => array( 'enabled' => true ),
	'wd_recaptcha_settings'               => array( 'enabled' => true, 'provider' => 'turnstile', 'locations' => array( 'login', 'register' ), 'data_turnstile' => array( 'key' => '0x4AAAAAAAexample', 'secret' => '0x4AAAAAAAsecretexample' ) ),
	'wd_security_headers_settings'        => array( 'sh_xframe' => true, 'sh_xframe_mode' => 'deny', 'sh_strict_transport' => true, 'hsts_preload' => 1, 'include_subdomain' => 1, 'hsts_cache_duration' => '30 days' ),
	'hardener_settings'                   => array( 'issues' => array( 'disable-xml-rpc', 'prevent-enum-users' ), 'fixed' => array( 'disable-file-editor', 'disable-trackback', 'hide-error' ), 'ignore' => array( 'php-version' ) ),
	'wd_scan_settings'                    => array( 'check_core' => true, 'scheduled_scanning' => true, 'frequency' => 'weekly' ),
	'wd_audit_settings'                   => array( 'enabled' => true ),
	'wd_password_protection_settings'     => array( 'enabled' => true ),
);
foreach ( $pss_def_opts as $pss_n => $pss_v ) {
	update_site_option( $pss_n, wp_json_encode( $pss_v ) );
}
Perdita_Switch::sources( true );
$ok( Perdita_Switch::get( 'defender-core' )->has_settings(), 'defender: the JSON site options count as settings' );
$pss_s0 = $pss_state();
$pss_r  = $pss_cycle( 'defender-core' );
$pss_p  = $pss_r['plan'];
$pss_s  = $pss_r['settings'];
$ok( $pss_r['import']['ok'] && true === $pss_s['login_limit'] && 3 === $pss_s['login_max'] && 10 === $pss_s['login_window'] && 30 === $pss_s['login_lockout'], 'defender: attempts, timeframe seconds, and a duration with its unit come over' );
$ok( array( '203.0.113.10', '203.0.113.0/28' ) === $pss_s['ip_allowlist'] && 1 === count( $pss_rows( $pss_p, 'Never lock out these IPs', 'skip' ) ), 'defender: the allowlist keeps an address and a block, and refuses an uneven range' );
$ok( true === $pss_s['disable_file_edit'] && false === $pss_s['disable_xmlrpc'] && false === $pss_s['block_author_enum'], 'defender: only the tweaks Defender marked fixed come over, not the ones still listed as issues' );
$ok( true === $pss_s['headers'] && true === $pss_s['hsts'] && 2592000 === $pss_s['hsts_max_age'] && true === $pss_s['hsts_subdomains'], 'defender: headers, HSTS, "30 days" as seconds, and subdomains come over' );
$ok( true === $pss_s['firewall'] && array( 'bad_bots' ) === array_keys( $pss_p['patch']['fw_rules'] ) && true === $pss_s['login_turnstile'] && true === $pss_s['core_check'], 'defender: user agent banning maps to the firewall, and Turnstile and the scheduled core scan come over' );
$ok( null !== $pss_row( $pss_p, 'IP blocklist' ) && null !== $pss_row( $pss_p, 'Blocked countries' ) && null !== $pss_row( $pss_p, 'Banned usernames' ) && null !== $pss_row( $pss_p, '404 Detection' ) && null !== $pss_row( $pss_p, 'User agent lists' ) && null !== $pss_row( $pss_p, 'Mask Login Area' ) && null !== $pss_row( $pss_p, 'Two-Factor Authentication' ) && null !== $pss_row( $pss_p, 'Pwned password check' ) && null !== $pss_row( $pss_p, 'Audit Logging' ), 'defender: blocklists, countries, usernames, 404 detection, agents, the masked login, two-factor, breached passwords, and the audit log show as Perdita Pro' );
$ok( null !== $pss_row( $pss_p, 'Global IP list and AntiBot Global Firewall' ) && null !== $pss_row( $pss_p, 'IP detection' ), 'defender: the shared blocklist and manual IP detection show as no match' );
$ok( 0 === count( array_filter( $pss_p['rows'], static function ( $r ) {
	return false !== strpos( $r['found'], 'mm-license' ) || false !== strpos( $r['found'], 'secretexample' );
} ) ), 'defender: license keys and secrets never appear' );
$ok( $pss_r['undo']['ok'] && $pss_r['restored'], 'defender: Undo puts perdita_security_settings back byte for byte' );
$ok( $pss_s0 === $pss_state(), 'defender: its site options are untouched' );
update_site_option( 'wd_login_lockout_settings', wp_json_encode( array( 'enabled' => true, 'lockout_type' => 'permanent' ) ) );
Perdita_Switch::sources( true );
$pss_p = Perdita_Switch::get( 'defender-core' )->plan();
$ok( 5 === $pss_p['patch']['login_max'] && 5 === $pss_p['patch']['login_window'] && ! isset( $pss_p['patch']['login_lockout'] ) && null !== $pss_row( $pss_p, 'Lockout type: permanent ban' ), 'defender: its own defaults fill the limit, and a permanent ban has no lockout length' );

/* =============================================================
 * Patchstack.
 * ============================================================= */

$pss_ps = array(
	'license_free'                   => 0,
	'license_activated'              => 1,
	'pluginedit'                     => 1,
	'userenum'                       => 0,
	'hidewpversion'                  => 1,
	'xmlrpc_is_disabled'             => 0,
	'json_is_disabled'               => 1,
	'application_passwords_disabled' => 1,
	'add_security_headers'           => 1,
	'captcha_type'                   => 'turnstile',
	'captcha_login_form'             => 1,
	'basic_firewall'                 => 1,
	'ip_block_list'                  => "203.0.113.99\n198.51.100.*\n192.0.2.0/24\n192.0.2.50-192.0.2.60",
	'whitelist'                      => '203.0.113.10',
	'login_whitelist'                => "203.0.113.10\n198.51.100.0/24",
	'block_bruteforce_ips'           => 1,
	'anti_bruteforce_attempts'       => 5,
	'anti_bruteforce_minutes'        => 15,
	'anti_bruteforce_blocktime'      => 120,
	'login_2fa'                      => 1,
	'mv_wp_login'                    => 1,
	'rename_wp_login'                => 'my-door',
	'activity_log_is_enabled'        => 1,
);
foreach ( $pss_ps as $pss_k => $pss_v ) {
	update_option( 'patchstack_' . $pss_k, $pss_v );
}
Perdita_Switch::sources( true );
$ok( Perdita_Switch::get( 'patchstack-core' )->has_settings(), 'patchstack: its options count as settings' );
$pss_s0 = $pss_state();
$pss_r  = $pss_cycle( 'patchstack-core' );
$pss_p  = $pss_r['plan'];
$pss_s  = $pss_r['settings'];
$ok( $pss_r['import']['ok'] && true === $pss_s['login_limit'] && 5 === $pss_s['login_max'] && 15 === $pss_s['login_window'] && 120 === $pss_s['login_lockout'], 'patchstack: the brute force attempts, window, and block time come over in minutes' );
$ok( array( '203.0.113.10', '198.51.100.0/24' ) === $pss_s['ip_allowlist'], 'patchstack: both allowlists merge without repeats' );
$ok( true === $pss_s['disable_file_edit'] && true === $pss_s['hide_wp_version'] && true === $pss_s['disable_app_passwords'] && true === $pss_s['headers'] && true === $pss_s['hsts'] && 31536000 === $pss_s['hsts_max_age'] && false === $pss_s['hsts_subdomains'], 'patchstack: the editor, version, application passwords, headers, and HSTS come over' );
$ok( false === $pss_s['block_author_enum'] && false === $pss_s['disable_xmlrpc'] && true === $pss_s['login_turnstile'], 'patchstack: a switch set to 0 stays off, and Turnstile on login comes over' );
$ok( null !== $pss_row( $pss_p, 'Disable the REST API for logged-out visitors' ) && null !== $pss_row( $pss_p, 'Basic firewall' ), 'patchstack: whole-API blocking and the virtual patch firewall show as no match' );
$ok( null !== $pss_row( $pss_p, 'IP block list' ) && 4 === (int) $pss_row( $pss_p, 'IP block list' )['found'] && null !== $pss_row( $pss_p, 'Two-factor authentication' ) && null !== $pss_row( $pss_p, 'Hide the login URL' ) && null !== $pss_row( $pss_p, 'Activity log' ), 'patchstack: the block list, two-factor, the hidden login, and the activity log show as Perdita Pro' );
$ok( $pss_r['undo']['ok'] && $pss_r['restored'], 'patchstack: Undo puts perdita_security_settings back byte for byte' );
$ok( $pss_s0 === $pss_state(), 'patchstack: its options are untouched' );
update_option( 'patchstack_license_free', 1 );
Perdita_Switch::sources( true );
$pss_p = Perdita_Switch::get( 'patchstack-core' )->plan();
$ok( empty( $pss_p['patch'] ) && (bool) $pss_p['notes'], 'patchstack: on a free license none of its settings ran, so none move' );
update_option( 'patchstack_license_free', 0 );
delete_option( 'patchstack_license_activated' );
Perdita_Switch::sources( true );
$pss_p = Perdita_Switch::get( 'patchstack-core' )->plan();
$ok( empty( $pss_p['patch'] ), 'patchstack: with no active license nothing was running either' );

/* =============================================================
 * Loginizer.
 * ============================================================= */

delete_option( 'loginizer_whitelist' );
delete_option( 'loginizer_blacklist' );
delete_option( 'loginizer_disable_brute' );
update_option( 'loginizer_options', array() );
Perdita_Switch::sources( true );
$pss_r = $pss_cycle( 'loginizer' );
$pss_p = $pss_r['plan'];
$ok( true === $pss_p['patch']['login_limit'] && 3 === $pss_p['patch']['login_max'] && 15 === $pss_p['patch']['login_lockout'] && 1440 === $pss_p['patch']['login_window'], 'loginizer: an empty option runs on the plugin\'s defaults (3 retries, 900 seconds, 86400 seconds)' );
$ok( true === $pss_p['patch']['login_long_lockout'] && 5 === $pss_p['patch']['login_long_after'] && 24 === $pss_p['patch']['login_long_hours'], 'loginizer: the default 5 lockouts and 86400 second extension map to escalating lockouts' );
$ok( $pss_r['import']['ok'] && $pss_r['restored'], 'loginizer: the defaults import and Undo restores Perdita\'s settings' );
update_option( 'loginizer_options', array( 'max_retries' => 0, 'lockout_time' => '', 'reset_retries' => 0, 'max_lockouts' => 0, 'lockouts_extend' => 0 ) );
Perdita_Switch::sources( true );
$pss_p = Perdita_Switch::get( 'loginizer' )->plan();
$ok( 3 === $pss_p['patch']['login_max'] && 15 === $pss_p['patch']['login_lockout'] && 1440 === $pss_p['patch']['login_window'] && 5 === $pss_p['patch']['login_long_after'], 'loginizer: a stored 0 or empty means the default, the way the plugin reads it' );
update_option( 'loginizer_options', array( 'max_retries' => 5, 'lockout_time' => 1800, 'max_lockouts' => 3, 'lockouts_extend' => 172800, 'reset_retries' => 43200, 'trusted_ips' => 'on' ) );
update_option( 'loginizer_whitelist', array( 1 => array( 'start' => '203.0.113.10', 'end' => '203.0.113.10' ), 2 => array( 'start' => '198.51.100.0', 'end' => '198.51.100.255' ), 3 => array( 'start' => '2001:db8::1', 'end' => '2001:db8::1' ), 4 => array( 'start' => '192.0.2.20', 'end' => '192.0.2.30' ) ) );
update_option( 'loginizer_blacklist', array( 1 => array( 'start' => '192.0.2.50', 'end' => '192.0.2.50' ), 2 => array( 'start' => '192.0.2.100', 'end' => '192.0.2.200' ) ) );
update_option( 'loginizer_user_enum', array( 'disable_rest_users' => '1', 'hide_login_errors' => '1' ) );
update_option( 'loginizer_security', array( 'login_slug' => 'team-login', 'xmlrpc_disable' => 1, 'pingbacks_disable' => 1 ) );
update_option( 'loginizer_username_blacklist', array( 'admin' ) );
update_option( 'loginizer_captcha', array( 'captcha_status' => 4, 'captcha_login' => 1 ) );
update_option( 'loginizer_2fa', array( '2fa_app' => 1 ) );
update_option( 'loginizer_ip_method', 1 );
$pss_s0 = $pss_state();
$pss_r  = $pss_cycle( 'loginizer' );
$pss_p  = $pss_r['plan'];
$pss_s  = $pss_r['settings'];
$ok( 5 === $pss_s['login_max'] && 30 === $pss_s['login_lockout'] && 720 === $pss_s['login_window'] && true === $pss_s['login_long_lockout'] && 3 === $pss_s['login_long_after'] && 48 === $pss_s['login_long_hours'], 'loginizer: retries, lockout, reset time, max lockouts, and the extended lockout convert' );
$ok( array( '203.0.113.10', '198.51.100.0/24', '2001:db8::1' ) === $pss_s['ip_allowlist'] && 1 === count( $pss_rows( $pss_p, 'Never lock out these IPs', 'skip' ) ), 'loginizer: whitelist ranges that are one block become CIDR, and the uneven range is refused' );
$ok( true === $pss_s['login_generic_errors'] && 'users' === $pss_s['restrict_rest'] && true === $pss_s['disable_xmlrpc'] && true === $pss_s['login_turnstile'] && false === $pss_s['block_author_enum'], 'loginizer: hidden login errors, REST users, XML-RPC, and Turnstile come over' );
$ok( null !== $pss_row( $pss_p, 'Blacklist IP' ) && null !== $pss_row( $pss_p, 'Rename login page' ) && null !== $pss_row( $pss_p, 'Username blacklist' ) && null !== $pss_row( $pss_p, 'Two-factor authentication' ) && null !== $pss_row( $pss_p, 'Allow only whitelisted IPs to log in' ) && null !== $pss_row( $pss_p, 'Get the IP address from' ), 'loginizer: the blacklist, hidden login, usernames, and two-factor show as Perdita Pro, and the whitelist-only switch and IP method as no match' );
$ok( $pss_r['undo']['ok'] && $pss_r['restored'] && $pss_s0 === $pss_state(), 'loginizer: Undo puts perdita_security_settings back byte for byte' );
update_option( 'loginizer_disable_brute', 1 );
Perdita_Switch::sources( true );
$pss_p = Perdita_Switch::get( 'loginizer' )->plan();
$ok( false === $pss_p['patch']['login_limit'], 'loginizer: brute force protection that was switched off imports as off' );

/* =============================================================
 * Limit Login Attempts Reloaded.
 * ============================================================= */

foreach ( array( 'allowed_retries', 'lockout_duration', 'allowed_lockouts', 'long_duration', 'valid_duration', 'whitelist', 'whitelist_usernames', 'blacklist', 'blacklist_usernames', 'active_app', 'mfa_enabled', 'trusted_ip_origins' ) as $pss_k ) {
	delete_option( 'limit_login_' . $pss_k );
}
Perdita_Switch::sources( true );
$ok( ! Perdita_Switch::get( 'limit-login-attempts-reloaded' )->has_settings() && empty( Perdita_Switch::get( 'limit-login-attempts-reloaded' )->plan()['patch'] ), 'llar: nothing stored and the plugin not installed means nothing to import' );
update_option( 'limit_login_active_app', 'local' );
Perdita_Switch::sources( true );
$pss_r = $pss_cycle( 'limit-login-attempts-reloaded' );
$pss_p = $pss_r['plan'];
$ok( 4 === $pss_p['patch']['login_max'] && 20 === $pss_p['patch']['login_lockout'] && 1440 === $pss_p['patch']['login_window'] && 4 === $pss_p['patch']['login_long_after'] && 24 === $pss_p['patch']['login_long_hours'], 'llar: a site that never saved runs on the plugin\'s defaults (4 retries, 1200 seconds, 4 lockouts, 86400 seconds)' );
$ok( $pss_r['import']['ok'] && $pss_r['restored'], 'llar: the defaults import and Undo restores Perdita\'s settings' );
update_option( 'limit_login_allowed_retries', 6 );
update_option( 'limit_login_lockout_duration', 3600 );
update_option( 'limit_login_allowed_lockouts', 3 );
update_option( 'limit_login_long_duration', 172800 );
update_option( 'limit_login_valid_duration', 43200 );
update_option( 'limit_login_whitelist', array( '203.0.113.10', '198.51.100.0/24', '198.51.100.20-198.51.100.30', '192.0.2.64-192.0.2.127' ) );
update_option( 'limit_login_whitelist_usernames', array( 'deploybot' ) );
update_option( 'limit_login_blacklist', array( '192.0.2.50', '192.0.2.0/28' ) );
update_option( 'limit_login_blacklist_usernames', array( 'admin' ) );
update_option( 'limit_login_mfa_enabled', 1 );
update_option( 'limit_login_trusted_ip_origins', array( 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' ) );
$pss_s0 = $pss_state();
$pss_r  = $pss_cycle( 'limit-login-attempts-reloaded' );
$pss_p  = $pss_r['plan'];
$pss_s  = $pss_r['settings'];
$ok( 6 === $pss_s['login_max'] && 60 === $pss_s['login_lockout'] && 720 === $pss_s['login_window'] && true === $pss_s['login_long_lockout'] && 3 === $pss_s['login_long_after'] && 48 === $pss_s['login_long_hours'], 'llar: retries, lockout, reset window, allowed lockouts, and the long duration convert' );
$ok( array( '203.0.113.10', '198.51.100.0/24', '192.0.2.64/26' ) === $pss_s['ip_allowlist'] && 1 === count( $pss_rows( $pss_p, 'Never lock out these IPs', 'skip' ) ), 'llar: safelist CIDR and an exact range import, and an uneven range is refused' );
$ok( null !== $pss_row( $pss_p, 'Safelist usernames' ) && null !== $pss_row( $pss_p, 'Denylist IPs' ) && null !== $pss_row( $pss_p, 'Denylist usernames' ) && null !== $pss_row( $pss_p, 'Two-factor authentication' ) && null !== $pss_row( $pss_p, 'Trusted IP origins' ), 'llar: usernames, the denylist, two-factor, and IP origins each get a row' );
$ok( $pss_r['undo']['ok'] && $pss_r['restored'] && $pss_s0 === $pss_state(), 'llar: Undo puts perdita_security_settings back byte for byte' );
update_option( 'limit_login_long_duration', 5000000 );
Perdita_Switch::sources( true );
$pss_p = Perdita_Switch::get( 'limit-login-attempts-reloaded' )->plan();
$ok( ! isset( $pss_p['patch']['login_long_hours'] ) && (bool) $pss_rows( $pss_p, 'Long lockout (hours)', 'skip' ), 'llar: a long lockout over 30 days is refused with a reason' );

/* ---------- restore ---------- */

$pss_cleanup();
$pss_same = true;
foreach ( $pss_saved as $pss_n => $pss_was ) {
	if ( get_option( $pss_n, $pss_missing ) !== $pss_was ) {
		$pss_same = false;
		echo "      changed: $pss_n\n";
	}
}
$ok( $pss_same, 'security sources: every option is back the way it was' );
$pss_files_same = true;
foreach ( $pss_files as $pss_f ) {
	$pss_now = is_file( $pss_f ) ? file_get_contents( $pss_f ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	if ( $pss_now !== $pss_file_was[ $pss_f ] ) {
		$pss_files_same = false;
		echo "      changed: $pss_f\n";
	}
}
$ok( $pss_files_same, 'security sources: every file is back the way it was' );
$pss_tables_gone = true;
foreach ( $pss_created as $pss_t ) {
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $pss_t ) ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$pss_tables_gone = false;
	}
}
$ok( $pss_tables_gone, 'security sources: every table this made is dropped' );
