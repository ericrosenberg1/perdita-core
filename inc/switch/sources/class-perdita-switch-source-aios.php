<?php
/**
 * All In One WP Security and Firewall. Its settings sit in three places: the
 * aio_wp_security_configs option, a firewall settings file in uploads, and an
 * allow list file beside it. The files are read as text, never included.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

class Perdita_Switch_Source_AIOS extends Perdita_Switch_Security_Source {

	public function id() {
		return 'aios-core';
	}

	public function label() {
		return 'All In One WP Security';
	}

	public function plugin_files() {
		return array( 'all-in-one-wp-security-and-firewall/wp-security.php' );
	}

	/**
	 * The folder AIOS keeps its firewall files in, on the main site's uploads.
	 *
	 * @return string
	 */
	private static function dir() {
		$u    = wp_get_upload_dir();
		$base = isset( $u['basedir'] ) ? (string) $u['basedir'] : '';
		if ( is_multisite() && ! is_main_site() ) {
			$base = (string) preg_replace( '#/sites/' . get_current_blog_id() . '/?$#', '', $base );
		}
		return rtrim( wp_normalize_path( $base ), '/' ) . '/aios/firewall-rules/';
	}

	/**
	 * The main option.
	 *
	 * @return array
	 */
	private static function configs() {
		$raw = is_multisite() ? get_blog_option( get_main_site_id(), 'aio_wp_security_configs', array() ) : get_option( 'aio_wp_security_configs', array() );
		return is_array( $raw ) ? self::scalars_only( $raw ) : array();
	}

	/**
	 * The firewall settings file.
	 *
	 * @return array
	 */
	private static function firewall() {
		$text = self::php_data_file( self::dir() . 'settings.php', 'comment' );
		$data = '' === $text ? null : json_decode( trim( $text ), true );
		return is_array( $data ) ? self::scalars_only( $data ) : array();
	}

	/**
	 * The allow list file.
	 *
	 * @return string[]
	 */
	private static function allow_file() {
		return self::items( self::php_data_file( self::dir() . 'allowlist.php', 'comment' ) );
	}

	public function has_settings() {
		return (bool) self::configs();
	}

	public function plan() {
		$this->begin();
		$c = self::configs();
		if ( ! $c ) {
			return $this->finish();
		}
		$fw = self::firewall();
		$on = function ( $k ) use ( $c ) {
			return isset( $c[ $k ] ) && self::on( $c[ $k ] );
		};
		$fon = function ( $k ) use ( $fw ) {
			return isset( $fw[ $k ] ) && ( true === $fw[ $k ] || self::on( $fw[ $k ] ) );
		};

		$lock = $on( 'aiowps_enable_login_lockdown' );
		// A count that isn't a positive whole number (an array, 'abc', '') would
		// cast to 0 and import as a lockout after 1 failure. Skip the row and
		// keep Perdita's default instead.
		$tries = 3;
		if ( isset( $c['aiowps_max_login_attempts'] ) ) {
			$raw = $c['aiowps_max_login_attempts'];
			if ( ( is_int( $raw ) || ( is_string( $raw ) && ctype_digit( trim( $raw ) ) ) ) && (int) $raw >= 1 ) {
				$tries = (int) $raw;
			} else {
				$tries = null;
			}
		}
		$this->offer_limit( __( 'Enable login lockout feature', 'perdita-core' ), $lock, $tries );
		if ( null === $tries ) {
			$this->unsupported( __( 'Max login attempts', 'perdita-core' ), $c['aiowps_max_login_attempts'], __( 'This value is not a whole number of 1 or more, so Perdita keeps its own default of 5 attempts.', 'perdita-core' ) );
		}
		$this->offer_whole_minutes( __( 'Login retry time period (minutes)', 'perdita-core' ), isset( $c['aiowps_retry_time_period'] ) ? (int) $c['aiowps_retry_time_period'] : 5, 'login_window', __( 'Count failures over (minutes)', 'perdita-core' ) );
		$this->offer_whole_minutes( __( 'Time length of lockout (minutes)', 'perdita-core' ), isset( $c['aiowps_lockout_time_length'] ) ? (int) $c['aiowps_lockout_time_length'] : 5, 'login_lockout', __( 'Lockout length (minutes)', 'perdita-core' ) );
		if ( $lock ) {
			$max = isset( $c['aiowps_max_lockout_time_length'] ) ? (int) $c['aiowps_max_lockout_time_length'] : 60;
			$len = isset( $c['aiowps_lockout_time_length'] ) ? (int) $c['aiowps_lockout_time_length'] : 5;
			if ( $max > $len ) {
				$this->unsupported( __( 'Maximum lockout time length', 'perdita-core' ), $max, __( 'AIOS lengthens a lockout with each failure up to this limit. Perdita can instead give a longer lockout after repeated lockouts, which you set under Escalating lockouts on Perdita, Security.', 'perdita-core' ) );
			}
		}

		// Allow lists: the login lockout list, the login allow list, and the firewall allow list file.
		if ( $on( 'aiowps_lockdown_enable_whitelisting' ) ) {
			$this->offer_allowlist( __( 'Lockout allowed IP addresses', 'perdita-core' ), self::items( isset( $c['aiowps_lockdown_allowed_ip_addresses'] ) ? $c['aiowps_lockdown_allowed_ip_addresses'] : '' ) );
		}
		if ( $on( 'aiowps_enable_whitelisting' ) ) {
			$this->offer_allowlist( __( 'Allowed IP addresses', 'perdita-core' ), self::items( isset( $c['aiowps_allowed_ip_addresses'] ) ? $c['aiowps_allowed_ip_addresses'] : '' ) );
		}
		$this->offer_allowlist( __( 'Firewall allow list', 'perdita-core' ), self::allow_file() );

		$this->offer_flag( __( 'Show a generic login error message', 'perdita-core' ), $on( 'aiowps_set_generic_login_msg' ), 'login_generic_errors', __( 'One message for every failed login', 'perdita-core' ) );
		$this->offer_flag( __( 'Disable application passwords', 'perdita-core' ), $on( 'aiowps_disable_application_password' ), 'disable_app_passwords', __( 'Turn off application passwords', 'perdita-core' ) );
		$this->offer_flag( __( 'Disable the file editor', 'perdita-core' ), $on( 'aiowps_disable_file_editing' ), 'disable_file_edit', __( 'Turn off the file editor', 'perdita-core' ) );
		$this->offer_flag( __( 'Remove the WP generator meta info', 'perdita-core' ), $on( 'aiowps_remove_wp_generator_meta_info' ), 'hide_wp_version', __( 'Hide the WordPress version', 'perdita-core' ) );
		$this->offer_flag( __( 'Disable users enumeration', 'perdita-core' ), $on( 'aiowps_prevent_users_enumeration' ), 'block_author_enum', __( 'Block author enumeration', 'perdita-core' ) );
		$this->offer_flag( __( 'Disallow unauthorized REST requests', 'perdita-core' ), $on( 'aiowps_disallow_unauthorized_rest_requests' ), 'restrict_rest', __( 'Hide the REST users endpoint', 'perdita-core' ), __( 'Perdita hides the users endpoint from logged-out visitors. AIOS restricts the whole REST API.', 'perdita-core' ) );
		$this->offer_flag( __( 'Prevent your site from being displayed in a frame', 'perdita-core' ), $on( 'aiowps_prevent_site_display_inside_frame' ), 'headers', __( 'Send security headers', 'perdita-core' ), __( 'Perdita sends X-Frame-Options with its other security headers, and a Content-Security-Policy in report-only mode.', 'perdita-core' ) );
		$this->offer_flag( __( 'Completely block access to XMLRPC', 'perdita-core' ), $fon( 'aiowps_enable_pingback_firewall' ), 'disable_xmlrpc', __( 'Turn off XML-RPC', 'perdita-core' ) );
		if ( $on( 'aiowps_disable_xmlrpc_pingback_methods' ) && ! $fon( 'aiowps_enable_pingback_firewall' ) ) {
			$this->unsupported( __( 'Disable XML-RPC pingback methods', 'perdita-core' ), true, __( 'Perdita turns XML-RPC off completely or leaves it on.', 'perdita-core' ) );
		}
		if ( $on( 'aiowps_enable_automated_fcd_scan' ) ) {
			$this->offer_flag( __( 'File change detection', 'perdita-core' ), true, 'core_check', __( 'Daily core file check', 'perdita-core' ), __( 'Perdita compares WordPress core files with wordpress.org once a day. AIOS compares against its own earlier scan of every file.', 'perdita-core' ) );
		}
		if ( $on( 'aiowps_enable_login_captcha' ) ) {
			if ( isset( $c['aiowps_default_captcha'] ) && 'cloudflare-turnstile' === $c['aiowps_default_captcha'] ) {
				$this->offer_turnstile( __( 'Login captcha (Cloudflare Turnstile)', 'perdita-core' ) );
			} else {
				$this->unsupported( __( 'Login captcha', 'perdita-core' ), isset( $c['aiowps_default_captcha'] ) ? $c['aiowps_default_captcha'] : true, __( 'Perdita uses Cloudflare Turnstile on the login screens, not this captcha.', 'perdita-core' ) );
			}
		}
		if ( $on( 'aiowps_enable_login_honeypot' ) ) {
			$this->unsupported( __( 'Login form honeypot', 'perdita-core' ), true, __( 'You won\'t find a setting like this one in Perdita.', 'perdita-core' ) );
		}

		// The firewall. 5G, 6G, and the basic rules are the signs.
		$cats    = array();
		$content = $fon( 'aiowps_deny_bad_query_strings' ) || $fon( 'aiowps_advanced_char_string_filter' ) || $fon( 'aiowps_6g_block_query' ) || $fon( 'aiowps_6g_block_request' ) || $on( 'aiowps_enable_5g_firewall' );
		if ( $content ) {
			$cats['sqli']      = __( 'Bad query strings and request filters', 'perdita-core' );
			$cats['xss']       = __( 'Bad query strings and request filters', 'perdita-core' );
			$cats['traversal'] = __( 'Bad query strings and request filters', 'perdita-core' );
		}
		if ( $fon( 'aiowps_6g_block_agents' ) || $fon( 'aiowps_block_fake_googlebots' ) || $fon( 'aiowps_ban_post_blank_headers' ) || ! empty( $fw['aiowps_blacklist_user_agents'] ) ) {
			$cats['bad_bots'] = __( 'Block bad user agents', 'perdita-core' );
		}
		if ( $on( 'aiowps_block_debug_log_file_access' ) ) {
			$cats['probes'] = __( 'Block access to debug.log', 'perdita-core' );
		}
		if ( $fon( 'aiowps_enable_pingback_firewall' ) ) {
			$cats['xmlrpc'] = __( 'Completely block access to XMLRPC', 'perdita-core' );
		}
		$fw_on = $content || $on( 'aiowps_enable_basic_firewall' ) || $on( 'aiowps_enable_6g_firewall' ) || ! empty( $cats );
		$this->offer_firewall( __( 'Firewall settings', 'perdita-core' ), $fw_on, $cats );

		// Only Perdita Pro covers these.
		$blocked = array();
		if ( $on( 'aiowps_enable_blacklisting' ) ) {
			$blocked = self::items( isset( $c['aiowps_banned_ip_addresses'] ) ? $c['aiowps_banned_ip_addresses'] : '' );
		}
		$perm = self::count_rows( self::table( array( 'aiowps_permanent_block' ) ) );
		if ( $blocked || $perm ) {
			$this->pro_row( 'blocklist', __( 'Banned IP addresses and permanent blocks', 'perdita-core' ), count( $blocked ) + $perm );
		}
		if ( $on( 'aiowps_enable_blacklisting' ) && ! empty( $c['aiowps_banned_user_agents'] ) ) {
			$this->pro_row( 'rules', __( 'Banned user agents', 'perdita-core' ), self::items( $c['aiowps_banned_user_agents'] ) );
		}
		if ( $on( 'aiowps_enable_404_IP_lockout' ) ) {
			$this->pro_row( 'rate', __( '404 detection lockout', 'perdita-core' ), true );
		}
		if ( $on( 'aiowps_enable_invalid_username_lockdown' ) || ! empty( $c['aiowps_instantly_lockout_specific_usernames'] ) ) {
			$this->pro_row( 'usernames', __( 'Instantly lockout invalid usernames', 'perdita-core' ), ! empty( $c['aiowps_instantly_lockout_specific_usernames'] ) ? $c['aiowps_instantly_lockout_specific_usernames'] : true );
		}
		if ( $on( 'aiowps_enable_rename_login_page' ) ) {
			$this->pro_row( 'login_url', __( 'Rename login page', 'perdita-core' ), isset( $c['aiowps_login_page_slug'] ) ? $c['aiowps_login_page_slug'] : true );
		}
		foreach ( array_keys( (array) wp_roles()->get_names() ) as $role ) {
			if ( '1' === (string) get_option( 'tfa_' . $role, '' ) ) {
				$this->pro_row( '2fa', __( 'Two-factor authentication', 'perdita-core' ), true );
				break;
			}
		}
		if ( isset( $c['aiowps_ip_retrieve_method'] ) && '0' !== (string) $c['aiowps_ip_retrieve_method'] ) {
			$this->unsupported( __( 'IP address detection', 'perdita-core' ), $c['aiowps_ip_retrieve_method'], __( 'Perdita reads the visitor address itself, and trusts Cloudflare\'s header only from Cloudflare\'s own addresses.', 'perdita-core' ) );
		}
		return $this->finish();
	}
}
