<?php
/**
 * Patchstack: one patchstack_* option per setting. Its hardening, login, and
 * IP modules only run on a paid license, so a site on the free plan has
 * nothing running to bring over.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

class Perdita_Switch_Source_Patchstack extends Perdita_Switch_Security_Source {

	public function id() {
		return 'patchstack-core';
	}

	public function label() {
		return 'Patchstack';
	}

	public function plugin_files() {
		return array( 'patchstack/patchstack.php' );
	}

	public function has_settings() {
		return false !== get_option( 'patchstack_license_free', false ) || false !== get_option( 'patchstack_license_activated', false ) || false !== get_option( 'patchstack_pluginedit', false ) || false !== get_option( 'patchstack_ip_block_list', false );
	}

	/**
	 * Whether Patchstack ran its settings: a license that is active and not free.
	 *
	 * @return bool
	 */
	private static function ran() {
		if ( (int) get_option( 'patchstack_license_free', 0 ) === 1 ) {
			return false;
		}
		if ( get_option( 'patchstack_license_activated', 0 ) ) {
			return true;
		}
		$expiry = (string) get_option( 'patchstack_license_expiry', '' );
		return '' !== $expiry && strtotime( $expiry ) > time() - DAY_IN_SECONDS;
	}

	public function plan() {
		$this->begin();
		if ( ! $this->has_settings() ) {
			return $this->finish();
		}
		if ( ! self::ran() ) {
			$this->note( __( 'Patchstack runs its hardening, login, and IP settings only on a paid plan with an active license. This site isn\'t on one, so none of them were running, and nothing moves to Perdita.', 'perdita-core' ) );
			return $this->finish();
		}
		$flag = function ( $k, $default ) {
			return self::on( get_option( 'patchstack_' . $k, $default ) );
		};
		$num = function ( $k, $default ) {
			$v = (int) get_option( 'patchstack_' . $k, 0 );
			return $v > 0 ? $v : $default;
		};

		$this->offer_flag( __( 'Disable the plugin and theme editor', 'perdita-core' ), $flag( 'pluginedit', 1 ), 'disable_file_edit', __( 'Turn off the file editor', 'perdita-core' ) );
		if ( $flag( 'userenum', 1 ) ) {
			$this->offer_flag( __( 'Prevent user enumeration', 'perdita-core' ), true, 'block_author_enum', __( 'Block author enumeration', 'perdita-core' ) );
			$this->offer_flag( __( 'Prevent user enumeration, REST API', 'perdita-core' ), true, 'restrict_rest', __( 'Hide the REST users endpoint', 'perdita-core' ) );
		}
		$this->offer_flag( __( 'Hide the WordPress version', 'perdita-core' ), $flag( 'hidewpversion', 1 ), 'hide_wp_version', __( 'Hide the WordPress version', 'perdita-core' ) );
		$this->offer_flag( __( 'Disable XML-RPC', 'perdita-core' ), $flag( 'xmlrpc_is_disabled', 1 ), 'disable_xmlrpc', __( 'Turn off XML-RPC', 'perdita-core' ) );
		$this->offer_flag( __( 'Disable application passwords', 'perdita-core' ), $flag( 'application_passwords_disabled', 1 ), 'disable_app_passwords', __( 'Turn off application passwords', 'perdita-core' ) );
		if ( $flag( 'json_is_disabled', 0 ) ) {
			$this->unsupported( __( 'Disable the REST API for logged-out visitors', 'perdita-core' ), true, __( 'Perdita hides the users endpoint, not the whole REST API.', 'perdita-core' ) );
		}
		if ( $flag( 'add_security_headers', 1 ) ) {
			$this->offer_flag( __( 'Add security headers', 'perdita-core' ), true, 'headers', __( 'Send security headers', 'perdita-core' ), __( 'Perdita sends one fixed set of headers, with a report-only Content-Security-Policy.', 'perdita-core' ) );
			$this->offer_flag( __( 'Add security headers, Strict-Transport-Security', 'perdita-core' ), true, 'hsts', __( 'Send HSTS', 'perdita-core' ), __( 'Patchstack sent max-age=31536000 with no subdomains, and Perdita starts from the same.', 'perdita-core' ) );
		}
		if ( $flag( 'block_bruteforce_ips', 0 ) ) {
			$this->offer_limit( __( 'Block brute force login attempts', 'perdita-core' ), true, $num( 'anti_bruteforce_attempts', 10 ) );
			$this->offer_whole_minutes( __( 'Brute force attempts window (minutes)', 'perdita-core' ), $num( 'anti_bruteforce_minutes', 30 ), 'login_window', __( 'Count failures over (minutes)', 'perdita-core' ) );
			$this->offer_whole_minutes( __( 'Brute force block time (minutes)', 'perdita-core' ), $num( 'anti_bruteforce_blocktime', 60 ), 'login_lockout', __( 'Lockout length (minutes)', 'perdita-core' ) );
		}
		$this->offer_allowlist( __( 'Login allowlist', 'perdita-core' ), self::items( get_option( 'patchstack_login_whitelist', '' ) ) );
		$this->offer_allowlist( __( 'Firewall allowlist', 'perdita-core' ), self::items( get_option( 'patchstack_whitelist', '' ) ) );
		if ( $flag( 'captcha_login_form', 0 ) ) {
			if ( 'turnstile' === get_option( 'patchstack_captcha_type', '' ) ) {
				$this->offer_turnstile( __( 'Captcha on login, Cloudflare Turnstile', 'perdita-core' ) );
			} else {
				$this->unsupported( __( 'Captcha on login', 'perdita-core' ), get_option( 'patchstack_captcha_type', '' ), __( 'Perdita uses Cloudflare Turnstile on the login screens, not this captcha.', 'perdita-core' ) );
			}
		}
		$block = self::items( get_option( 'patchstack_ip_block_list', '' ) );
		if ( $block ) {
			$this->pro_row( 'blocklist', __( 'IP block list', 'perdita-core' ), count( $block ) );
		}
		if ( $flag( 'login_2fa', 0 ) ) {
			$this->pro_row( '2fa', __( 'Two-factor authentication', 'perdita-core' ), true );
		}
		if ( $flag( 'mv_wp_login', 0 ) ) {
			$this->pro_row( 'login_url', __( 'Hide the login URL', 'perdita-core' ), get_option( 'patchstack_rename_wp_login', '' ) );
		}
		if ( $flag( 'activity_log_is_enabled', 1 ) ) {
			$this->pro_row( 'audit', __( 'Activity log', 'perdita-core' ), true );
		}
		if ( $flag( 'basic_firewall', 0 ) ) {
			$this->unsupported( __( 'Basic firewall', 'perdita-core' ), true, __( 'Patchstack\'s firewall runs virtual patches per vulnerability, which Perdita doesn\'t copy. You can turn on Perdita\'s own firewall on Perdita, Security.', 'perdita-core' ) );
		}
		return $this->finish();
	}
}
