<?php
/**
 * Wordfence: its settings are rows in the wfConfig table.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

class Perdita_Switch_Source_Wordfence extends Perdita_Switch_Security_Source {

	public function id() {
		return 'wordfence-core';
	}

	public function label() {
		return 'Wordfence';
	}

	public function plugin_files() {
		return array( 'wordfence/wordfence.php' );
	}

	/**
	 * The settings rows, name to value. Serialized and oversized values are left out.
	 *
	 * @return array
	 */
	private static function config() {
		global $wpdb;
		$t = self::table( array( 'wfConfig', 'wfconfig' ) );
		if ( '' === $t ) {
			return array();
		}
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT name, val FROM %i LIMIT 2000', $t ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$out  = array();
		foreach ( $rows as $row ) {
			$val                 = (string) $row['val'];
			$out[ $row['name'] ] = strlen( $val ) > 65536 || is_serialized( $val ) ? '' : $val;
		}
		return $out;
	}

	/**
	 * Login Security's settings, name to value.
	 *
	 * @return array
	 */
	private static function login_security() {
		global $wpdb;
		$t = self::table( array( 'wfls_settings' ) );
		if ( '' === $t ) {
			return array();
		}
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT name, value FROM %i LIMIT 500', $t ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$out  = array();
		foreach ( $rows as $row ) {
			$out[ $row['name'] ] = strlen( (string) $row['value'] ) > 4096 ? '' : (string) $row['value'];
		}
		return $out;
	}

	public function has_settings() {
		return (bool) self::config();
	}

	public function plan() {
		$this->begin();
		$c = self::config();
		if ( ! $c ) {
			return $this->finish();
		}
		// Wordfence's own defaults, for a row it never wrote.
		$d = array_merge(
			array(
				'loginSecurityEnabled'                 => '1',
				'loginSec_maxFailures'                 => '20',
				'loginSec_countFailMins'               => '240',
				'loginSec_lockoutMins'                 => '240',
				'loginSec_maskLoginErrors'             => '1',
				'loginSec_disableAuthorScan'           => '1',
				'loginSec_disableApplicationPasswords' => '1',
				'firewallEnabled'                      => '1',
				'autoBlockScanners'                    => '1',
				'scheduledScansEnabled'                => '1',
				'scansEnabled_core'                    => '1',
			),
			$c
		);
		$on = function ( $k ) use ( $d ) {
			return isset( $d[ $k ] ) && self::on( $d[ $k ] );
		};

		$this->offer_limit( __( 'Enable brute force protection', 'perdita-core' ), $on( 'loginSecurityEnabled' ), max( 1, (int) $d['loginSec_maxFailures'] ) );
		$this->offer_whole_minutes( __( 'Count failures over the last (minutes)', 'perdita-core' ), $d['loginSec_countFailMins'], 'login_window', __( 'Count failures over (minutes)', 'perdita-core' ) );
		$this->offer_whole_minutes( __( 'Amount of time a user is locked out (minutes)', 'perdita-core' ), $d['loginSec_lockoutMins'], 'login_lockout', __( 'Lockout length (minutes)', 'perdita-core' ) );
		if ( $on( 'loginSec_lockInvalidUsers' ) ) {
			$this->unsupported( __( 'Immediately lock out invalid usernames', 'perdita-core' ), true, __( 'Perdita has one rule for every failed login, whatever the username.', 'perdita-core' ) );
		}
		$this->offer_allowlist( __( 'Allowlisted IP addresses', 'perdita-core' ), self::items( isset( $d['whitelisted'] ) ? $d['whitelisted'] : '' ) );
		$services = isset( $d['whitelistedServices'] ) ? json_decode( (string) $d['whitelistedServices'], true ) : array();
		if ( is_array( $services ) && array_filter( $services ) ) {
			$this->unsupported( __( 'Allowlisted services', 'perdita-core' ), array_keys( array_filter( $services ) ), __( 'Perdita allowlists addresses, not services.', 'perdita-core' ) );
		}
		$this->offer_flag( __( 'Don\'t let WordPress reveal valid users in login errors', 'perdita-core' ), $on( 'loginSec_maskLoginErrors' ), 'login_generic_errors', __( 'One message for every failed login', 'perdita-core' ) );
		if ( $on( 'loginSec_disableAuthorScan' ) ) {
			$this->offer_flag( __( 'Prevent discovery of usernames through author scans', 'perdita-core' ), true, 'block_author_enum', __( 'Block author enumeration', 'perdita-core' ) );
			$this->offer_flag( __( 'Prevent discovery of usernames through the REST API', 'perdita-core' ), true, 'restrict_rest', __( 'Hide the REST users endpoint', 'perdita-core' ) );
		}
		$this->offer_flag( __( 'Disable application passwords', 'perdita-core' ), $on( 'loginSec_disableApplicationPasswords' ), 'disable_app_passwords', __( 'Turn off application passwords', 'perdita-core' ) );
		$this->offer_flag( __( 'Hide WordPress version', 'perdita-core' ), $on( 'other_hideWPVersion' ), 'hide_wp_version', __( 'Hide the WordPress version', 'perdita-core' ) );
		$this->offer_flag( __( 'Disable code execution for uploads directory', 'perdita-core' ), $on( 'disableCodeExecutionUploads' ), 'block_uploads_php', __( 'Block PHP in uploads', 'perdita-core' ), __( 'Perdita writes a marked block to the .htaccess file in your uploads folder, and Undo removes the block.', 'perdita-core' ) );
		if ( $on( 'scheduledScansEnabled' ) && $on( 'scansEnabled_core' ) ) {
			$this->offer_flag( __( 'Check core files against the WordPress repository', 'perdita-core' ), true, 'core_check', __( 'Daily core file check', 'perdita-core' ), __( 'Perdita checks WordPress core files once a day. It doesn\'t scan themes, plugins, or file contents for malware.', 'perdita-core' ) );
		}

		// The firewall. Wordfence keeps its mode (learning or enabled) in its wflogs files, so the import goes by the switch.
		$cats = array(
			'sqli'       => __( 'Firewall rules (SQL injection)', 'perdita-core' ),
			'xss'        => __( 'Firewall rules (cross-site scripting)', 'perdita-core' ),
			'traversal'  => __( 'Firewall rules (directory traversal and file inclusion)', 'perdita-core' ),
			'php_upload' => __( 'Firewall rules (malicious file upload)', 'perdita-core' ),
		);
		if ( $on( 'autoBlockScanners' ) || $on( 'other_blockBadPOST' ) ) {
			$cats['bad_bots'] = $on( 'autoBlockScanners' ) ? __( 'Block scanners', 'perdita-core' ) : __( 'Block bad POST requests', 'perdita-core' );
		}
		$this->offer_firewall( __( 'Enable firewall', 'perdita-core' ), $on( 'firewallEnabled' ), $cats, __( 'Wordfence keeps its firewall mode in files Perdita doesn\'t read, so the import goes by the firewall switch.', 'perdita-core' ) );
		if ( ! empty( $c['bannedURLs'] ) ) {
			$this->unsupported( __( 'Blocked URLs', 'perdita-core' ), self::items( $c['bannedURLs'] ), __( 'Perdita blocks common probes, not a list of URLs.', 'perdita-core' ) );
		}

		// Only Perdita Pro covers these.
		foreach ( array( 'maxGlobalRequests', 'maxRequestsCrawlers', 'maxRequestsHumans', 'max404Crawlers', 'max404Humans' ) as $k ) {
			if ( isset( $c[ $k ] ) && 'DISABLED' !== strtoupper( $c[ $k ] ) && '' !== $c[ $k ] ) {
				$this->pro_row( 'rate', __( 'Rate limiting', 'perdita-core' ), $k . ' = ' . $c[ $k ] );
				break;
			}
		}
		global $wpdb;
		$blocks = self::table( array( 'wfBlocks7', 'wfblocks7' ) );
		if ( '' !== $blocks ) {
			$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT type, parameters FROM %i WHERE expiration = 0 AND type IN (1, 3, 4) LIMIT 500', $blocks ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$ips  = 0;
			$cc   = array();
			$pat  = 0;
			foreach ( $rows as $row ) {
				if ( 1 === (int) $row['type'] ) {
					++$ips;
				} elseif ( 3 === (int) $row['type'] ) {
					$p = json_decode( (string) $row['parameters'], true );
					if ( is_array( $p ) && isset( $p['countries'] ) && is_array( $p['countries'] ) ) {
						$cc = array_merge( $cc, array_filter( $p['countries'], 'is_scalar' ) );
					}
				} else {
					++$pat;
				}
			}
			if ( $ips ) {
				$this->pro_row( 'blocklist', __( 'Blocked IP addresses', 'perdita-core' ), $ips );
			}
			if ( $cc ) {
				$this->pro_row( 'country', __( 'Country blocking', 'perdita-core' ), array_values( array_unique( $cc ) ) );
			}
			if ( $pat ) {
				$this->pro_row( 'rules', __( 'Advanced blocking (IP range, hostname, user agent, referrer)', 'perdita-core' ), $pat );
			}
		}
		$ls = self::login_security();
		if ( $on( 'loginSec_requireAdminTwoFactor' ) || ( isset( $ls['enable-2fa'] ) && self::on( $ls['enable-2fa'] ) ) || ( isset( $ls['enable-passkeys'] ) && self::on( $ls['enable-passkeys'] ) ) ) {
			$this->pro_row( '2fa', __( 'Two-factor authentication and passkeys', 'perdita-core' ), true );
		}
		if ( isset( $ls['enable-auth-captcha'] ) && self::on( $ls['enable-auth-captcha'] ) ) {
			$this->unsupported( __( 'reCAPTCHA on login', 'perdita-core' ), true, __( 'Perdita uses Cloudflare Turnstile on the login screens, not reCAPTCHA. You set it up on Perdita, Security.', 'perdita-core' ) );
		}
		if ( isset( $c['auditLogMode'] ) && in_array( $c['auditLogMode'], array( 'significant', 'all' ), true ) ) {
			$this->pro_row( 'audit', __( 'Audit log', 'perdita-core' ), $c['auditLogMode'] );
		}
		if ( ! empty( $c['howGetIPs'] ) ) {
			$this->unsupported( __( 'How to get IPs', 'perdita-core' ), $c['howGetIPs'], __( 'Perdita reads the visitor address itself, and trusts Cloudflare\'s header only from Cloudflare\'s own addresses.', 'perdita-core' ) );
		}
		return $this->finish();
	}
}
