<?php
/**
 * Solid Security, formerly iThemes Security, now Kadence Security Basic. It's
 * found by its plugin slug and its itsec-storage option, never by name.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

class Perdita_Switch_Source_Solid extends Perdita_Switch_Security_Source {

	public function id() {
		return 'solid-core';
	}

	public function label() {
		return 'Solid Security';
	}

	public function plugin_files() {
		return array( 'better-wp-security/better-wp-security.php', 'ithemes-security-pro/ithemes-security-pro.php' );
	}

	/**
	 * The module settings, one array per module id.
	 *
	 * @return array
	 */
	private static function storage() {
		$raw = get_site_option( 'itsec-storage', array() );
		if ( ! is_array( $raw ) ) {
			$raw = get_option( 'itsec-storage', array() );
		}
		return is_array( $raw ) ? self::scalars_only( $raw ) : array();
	}

	/**
	 * Whether a module is active. A module with no row in itsec_active_modules
	 * follows its default status (module.json).
	 *
	 * @param string $module Module id.
	 * @return bool
	 */
	private static function active( $module ) {
		$mods = get_site_option( 'itsec_active_modules', array() );
		if ( is_array( $mods ) && array_key_exists( $module, $mods ) ) {
			return ! empty( $mods[ $module ] );
		}
		return in_array( $module, array( 'brute-force', 'ban-users', 'firewall', 'network-brute-force', 'global', 'wordpress-tweaks', 'system-tweaks', 'hide-backend', 'password-requirements' ), true );
	}

	public function has_settings() {
		return is_array( get_site_option( 'itsec-storage', false ) ) || is_array( get_option( 'itsec-storage', false ) );
	}

	public function plan() {
		$this->begin();
		$s = self::storage();
		if ( ! $s ) {
			return $this->finish();
		}
		$mod = function ( $m, array $defaults ) use ( $s ) {
			return array_merge( $defaults, isset( $s[ $m ] ) && is_array( $s[ $m ] ) ? $s[ $m ] : array() );
		};
		$g  = $mod( 'global', array( 'lockout_period' => 15, 'blacklist' => true, 'blacklist_count' => 3, 'lockout_white_list' => array(), 'write_files' => true ) );
		$bf = $mod( 'brute-force', array( 'max_attempts_host' => 5, 'check_period' => 5 ) );
		$wt = $mod( 'wordpress-tweaks', array( 'disable_xmlrpc' => 'enable', 'rest_api' => 'default-access', 'file_editor' => true, 'disable_unused_author_pages' => false ) );
		$st = $mod( 'system-tweaks', array( 'uploads_php' => true ) );

		// A host limit of 0 means Solid logs failed logins and locks nobody out.
		$lock = self::active( 'brute-force' ) && (int) $bf['max_attempts_host'] > 0;
		$this->offer_limit( __( 'Local brute force protection', 'perdita-core' ), $lock, $lock ? (int) $bf['max_attempts_host'] : null );
		if ( $lock ) {
			$this->offer_whole_minutes( __( 'Minutes to remember an invalid login attempt', 'perdita-core' ), $bf['check_period'], 'login_window', __( 'Count failures over (minutes)', 'perdita-core' ) );
			$this->offer_whole_minutes( __( 'Minutes to lockout', 'perdita-core' ), $g['lockout_period'], 'login_lockout', __( 'Lockout length (minutes)', 'perdita-core' ) );
			if ( ! empty( $g['blacklist'] ) && (int) $g['blacklist_count'] > 0 ) {
				$this->offer_escalation( __( 'Automatically ban repeat offenders', 'perdita-core' ), (int) $g['blacklist_count'], 24 * HOUR_IN_SECONDS, __( 'Solid bans the address for good. Perdita locks it out for a longer time instead, and you can set that on Perdita, Security.', 'perdita-core' ) );
			}
		}
		$this->offer_allowlist( __( 'Lockout allowlist', 'perdita-core' ), self::items( $g['lockout_white_list'] ) );

		if ( 'disable' === $wt['disable_xmlrpc'] ) {
			$this->offer_flag( __( 'XML-RPC', 'perdita-core' ), true, 'disable_xmlrpc', __( 'Turn off XML-RPC', 'perdita-core' ) );
		} elseif ( 'disable_pingbacks' === $wt['disable_xmlrpc'] ) {
			$this->unsupported( __( 'XML-RPC', 'perdita-core' ), 'disable_pingbacks', __( 'Perdita turns XML-RPC off completely or leaves it on.', 'perdita-core' ) );
		}
		$this->offer_flag( __( 'REST API', 'perdita-core' ), 'restrict-access' === $wt['rest_api'], 'restrict_rest', __( 'Hide the REST users endpoint', 'perdita-core' ), __( 'Perdita hides the users endpoint from logged-out visitors. Solid restricts more of the REST API.', 'perdita-core' ) );
		$this->offer_flag( __( 'File editor', 'perdita-core' ), empty( $wt['file_editor'] ), 'disable_file_edit', __( 'Turn off the file editor', 'perdita-core' ) );
		$this->offer_flag( __( 'Disable extra User Archives', 'perdita-core' ), ! empty( $wt['disable_unused_author_pages'] ), 'block_author_enum', __( 'Block author enumeration', 'perdita-core' ) );
		$this->offer_flag( __( 'Disable PHP execution in uploads', 'perdita-core' ), ! empty( $st['uploads_php'] ) && ! empty( $g['write_files'] ), 'block_uploads_php', __( 'Block PHP in uploads', 'perdita-core' ), __( 'Perdita writes a marked block to the .htaccess file in your uploads folder, and Undo removes the block.', 'perdita-core' ) );
		if ( self::active( 'file-change' ) ) {
			$this->offer_flag( __( 'File change detection', 'perdita-core' ), true, 'core_check', __( 'Daily core file check', 'perdita-core' ), __( 'Perdita compares WordPress core files with wordpress.org once a day. It doesn\'t track changes to your plugins, themes, or uploads.', 'perdita-core' ) );
		}

		$bu  = $mod( 'ban-users', array( 'default' => false, 'agent_list' => array() ) );
		$hr  = self::active( 'ban-users' ) && ( ! empty( $bu['default'] ) || ! empty( $bu['agent_list'] ) );
		$cats = array();
		if ( $hr ) {
			$cats['bad_bots'] = __( 'Ban user agents', 'perdita-core' );
		}
		$this->offer_firewall( __( 'Firewall', 'perdita-core' ), self::active( 'firewall' ) || $hr, $cats, __( 'Solid\'s firewall runs virtual patches that Perdita doesn\'t copy. Perdita\'s rules cover common attack patterns instead.', 'perdita-core' ) );

		$bans = self::count_rows( self::table( array( 'itsec_bans' ) ) );
		if ( $bans ) {
			$this->pro_row( 'blocklist', __( 'Ban Users, banned IPs', 'perdita-core' ), $bans );
		}
		if ( ! empty( $bu['agent_list'] ) ) {
			$this->pro_row( 'rules', __( 'Banned user agents', 'perdita-core' ), $bu['agent_list'] );
		}
		if ( self::active( 'hide-backend' ) && ! empty( $s['hide-backend']['enabled'] ) ) {
			$this->pro_row( 'login_url', __( 'Hide Backend', 'perdita-core' ), isset( $s['hide-backend']['slug'] ) ? $s['hide-backend']['slug'] : '' );
		}
		if ( self::active( 'two-factor' ) ) {
			$this->pro_row( '2fa', __( 'Two-Factor Authentication', 'perdita-core' ), true );
		}
		if ( ! empty( $s['password-requirements']['enabled_requirements']['hibp'] ) ) {
			$this->pro_row( 'breach', __( 'Refuse compromised passwords', 'perdita-core' ), true );
		}
		if ( isset( $wt['valid_user_login_type'] ) && 'both' !== $wt['valid_user_login_type'] ) {
			$this->unsupported( __( 'Login with username or email', 'perdita-core' ), $wt['valid_user_login_type'], __( 'WordPress lets people log in with either, and Perdita leaves that alone.', 'perdita-core' ) );
		}
		if ( ! empty( $g['proxy'] ) && 'automatic' !== $g['proxy'] ) {
			$this->unsupported( __( 'Proxy detection', 'perdita-core' ), $g['proxy'], __( 'Perdita reads the visitor address itself, and trusts Cloudflare\'s header only from Cloudflare\'s own addresses.', 'perdita-core' ) );
		}
		return $this->finish();
	}
}
