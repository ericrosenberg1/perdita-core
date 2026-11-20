<?php
/**
 * Sucuri Security: its settings file in uploads. Line 1 is a PHP exit, line 2
 * is one JSON object. The file is read as text and never included.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

class Perdita_Switch_Source_Sucuri extends Perdita_Switch_Security_Source {

	public function id() {
		return 'sucuri-core';
	}

	public function label() {
		return 'Sucuri Security';
	}

	public function plugin_files() {
		return array( 'sucuri-scanner/sucuri.php' );
	}

	/**
	 * The folder Sucuri keeps its data in.
	 *
	 * @return string
	 */
	private static function dir() {
		$dir = defined( 'SUCURI_DATA_STORAGE' ) && is_string( SUCURI_DATA_STORAGE ) ? SUCURI_DATA_STORAGE : WP_CONTENT_DIR . '/uploads/sucuri';
		return rtrim( $dir, '/\\' );
	}

	/**
	 * Sucuri's options.
	 *
	 * @return array
	 */
	private static function options() {
		$text = self::php_data_file( self::dir() . '/sucuri-settings.php', 'line' );
		$data = '' === $text ? null : json_decode( trim( $text ), true );
		return is_array( $data ) ? self::scalars_only( $data ) : array();
	}

	/**
	 * The trusted IP entries, from the trustip cache file.
	 *
	 * @return string[]
	 */
	private static function trusted() {
		$text = self::php_data_file( self::dir() . '/sucuri-trustip.php', 'line' );
		$out  = array();
		foreach ( array_slice( preg_split( '/\r\n|\r|\n/', $text ), 0, 500 ) as $line ) {
			$pos = strpos( $line, ':' );
			$row = false === $pos ? null : json_decode( substr( $line, $pos + 1 ), true );
			if ( is_array( $row ) && isset( $row['cidr_format'] ) && is_scalar( $row['cidr_format'] ) ) {
				$out[] = (string) $row['cidr_format'];
			}
		}
		return $out;
	}

	/**
	 * Whether the uploads folder carries Sucuri's PHP deny rule.
	 *
	 * @return bool
	 */
	private static function uploads_hardened() {
		$u = wp_get_upload_dir();
		if ( empty( $u['basedir'] ) ) {
			return false;
		}
		$file = trailingslashit( $u['basedir'] ) . '.htaccess';
		if ( ! is_file( $file ) || is_link( $file ) || filesize( $file ) > 1048576 ) {
			return false;
		}
		$c = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file.
		return is_string( $c ) && false !== strpos( $c, '<FilesMatch "\.(?i:php)$">' ) && false !== strpos( $c, 'Require all denied' );
	}

	public function has_settings() {
		return (bool) self::options();
	}

	public function plan() {
		$this->begin();
		$o = self::options();
		if ( ! $o ) {
			return $this->finish();
		}
		$flag = function ( $k ) use ( $o ) {
			return isset( $o[ 'sucuriscan_' . $k ] ) && 'enabled' === $o[ 'sucuriscan_' . $k ];
		};
		$this->offer_flag( __( 'Disable XML-RPC', 'perdita-core' ), $flag( 'hardening_xmlrpc' ), 'disable_xmlrpc', __( 'Turn off XML-RPC', 'perdita-core' ) );
		$this->offer_flag( __( 'Block PHP files in the uploads folder', 'perdita-core' ), self::uploads_hardened(), 'block_uploads_php', __( 'Block PHP in uploads', 'perdita-core' ), __( 'Perdita writes a marked block to the .htaccess file in your uploads folder, and Undo removes the block.', 'perdita-core' ) );
		$this->offer_flag( __( 'Alerts about core file checksum scans', 'perdita-core' ), $flag( 'notify_scan_checksums' ), 'core_check', __( 'Daily core file check', 'perdita-core' ), __( 'Sucuri compares your core files with wordpress.org, and you chose to hear about the result. Perdita runs the same comparison once a day. Perdita reads the alert switch as the sign you used the check.', 'perdita-core' ) );
		if ( isset( $o['sucuriscan_maximum_failed_logins'] ) ) {
			$this->unsupported( __( 'Maximum failed logins per hour', 'perdita-core' ), $o['sucuriscan_maximum_failed_logins'], __( 'Sucuri emails you at this number of failed logins and never locks anyone out, so Perdita leaves your login limit alone. You can turn it on on Perdita, Security.', 'perdita-core' ) );
		}
		$trusted = self::trusted();
		if ( $trusted ) {
			$this->unsupported( __( 'Trusted IP addresses', 'perdita-core' ), $trusted, __( 'Sucuri uses these to silence its alerts. They aren\'t a lockout allowlist, so nothing moves to Perdita\'s allowlist. Add the addresses on Perdita, Security if you want them exempt.', 'perdita-core' ) );
		}
		foreach ( array( 'headers_csp' => 'Content-Security-Policy', 'headers_cache_control' => 'Cache-Control', 'headers_cors' => 'CORS' ) as $k => $name ) {
			if ( $flag( $k ) ) {
				/* translators: %s: header name. */
				$this->unsupported( sprintf( __( '%s header', 'perdita-core' ), $name ), true, __( 'Perdita sends one fixed set of security headers and a policy you edit on Perdita, Security. Copy the directives by hand.', 'perdita-core' ) );
			}
		}
		if ( isset( $o['sucuriscan_twofactor_mode'] ) && 'disabled' !== $o['sucuriscan_twofactor_mode'] ) {
			$this->pro_row( '2fa', __( 'Two-factor authentication', 'perdita-core' ), $o['sucuriscan_twofactor_mode'] );
		}
		foreach ( $o as $k => $v ) {
			if ( 0 === strpos( (string) $k, 'sucuriscan_notify_' ) && 'enabled' === $v ) {
				$this->pro_row( 'alerts', __( 'Email alerts', 'perdita-core' ), isset( $o['sucuriscan_notify_to'] ) ? $o['sucuriscan_notify_to'] : '' );
				break;
			}
		}
		if ( ! empty( $o['sucuriscan_addr_header'] ) && 'HTTP_X_SUCURI_CLIENTIP' !== $o['sucuriscan_addr_header'] ) {
			$this->unsupported( __( 'IP address header', 'perdita-core' ), $o['sucuriscan_addr_header'], __( 'Perdita reads the visitor address itself, and trusts Cloudflare\'s header only from Cloudflare\'s own addresses.', 'perdita-core' ) );
		}
		$this->note( __( 'The free Sucuri plugin has no firewall, login limit, or IP block list. Its firewall is a separate cloud service, so you manage that at Sucuri.', 'perdita-core' ) );
		return $this->finish();
	}
}
