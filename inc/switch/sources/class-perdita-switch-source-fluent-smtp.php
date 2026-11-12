<?php
/**
 * FluentSMTP. Its connections are in the fluentmail-settings option, and
 * the default connection is the one Perdita imports. It can encrypt the
 * password with AES-256-CTR, keyed on FLUENTMAIL_ENCRYPT_KEY or
 * LOGGED_IN_KEY. Perdita decrypts it in memory only to encrypt it again.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

class Perdita_Switch_Source_FluentSMTP extends Perdita_Switch_SMTP_Source {

	public function id() {
		return 'fluent-smtp';
	}

	public function label() {
		return 'FluentSMTP';
	}

	public function plugin_files() {
		return array( 'fluent-smtp/fluent-smtp.php' );
	}

	public function has_settings() {
		$o = get_option( 'fluentmail-settings' );
		return is_array( $o ) && ! empty( $o['connections'] );
	}

	/**
	 * A secret FluentSMTP encrypted, or null when it can't be read.
	 *
	 * @param string $stored Stored value.
	 * @return string|null
	 */
	public static function decrypt( $stored ) {
		if ( ! function_exists( 'openssl_decrypt' ) ) {
			return null;
		}
		$key  = defined( 'FLUENTMAIL_ENCRYPT_KEY' ) ? (string) constant( 'FLUENTMAIL_ENCRYPT_KEY' ) : ( defined( 'LOGGED_IN_KEY' ) ? (string) constant( 'LOGGED_IN_KEY' ) : '' );
		$salt = defined( 'FLUENTMAIL_ENCRYPT_SALT' ) ? (string) constant( 'FLUENTMAIL_ENCRYPT_SALT' ) : ( defined( 'LOGGED_IN_SALT' ) ? (string) constant( 'LOGGED_IN_SALT' ) : '' );
		$raw  = base64_decode( (string) $stored, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- FluentSMTP's stored format.
		$len  = openssl_cipher_iv_length( 'aes-256-ctr' );
		if ( false === $raw || ! $len || strlen( $raw ) <= $len ) {
			return null;
		}
		$plain = openssl_decrypt( substr( $raw, $len ), 'aes-256-ctr', $key, 0, substr( $raw, 0, $len ) );
		if ( ! is_string( $plain ) || ( '' !== $salt && substr( $plain, -strlen( $salt ) ) !== $salt ) ) {
			return null;
		}
		return '' === $salt ? $plain : substr( $plain, 0, -strlen( $salt ) );
	}

	public function plan() {
		$this->begin();
		$o     = self::array_option( 'fluentmail-settings' );
		$conns = isset( $o['connections'] ) && is_array( $o['connections'] ) ? $o['connections'] : array();
		if ( ! $conns ) {
			return $this->finish();
		}
		$default = isset( $o['misc']['default_connection'] ) && is_scalar( $o['misc']['default_connection'] ) ? (string) $o['misc']['default_connection'] : '';
		$conn    = isset( $conns[ $default ] ) && is_array( $conns[ $default ] ) ? $conns[ $default ] : reset( $conns );
		$p       = is_array( $conn ) && isset( $conn['provider_settings'] ) && is_array( $conn['provider_settings'] ) ? $conn['provider_settings'] : array();
		$encrypt = isset( $o['misc']['use_encrypt'] ) && 'yes' === $o['misc']['use_encrypt'];

		$provider = (string) self::val( $p, 'provider' );
		$m        = array(
			'mailer'     => 'default' === $provider ? 'smtp' : $provider,
			'from_email' => self::val( $p, 'sender_email' ),
			'from_name'  => self::val( $p, 'sender_name' ),
		);
		$field = '';
		if ( 'smtp' === $provider ) {
			$m['host']       = self::val( $p, 'host' );
			$m['port']       = (int) self::val( $p, 'port', 0 );
			$m['encryption'] = self::encryption( self::val( $p, 'encryption' ) );
			$m['auth']       = 'yes' === self::val( $p, 'auth' );
			$m['username']   = self::val( $p, 'username' );
			$field           = 'password';
		} elseif ( 'sendgrid' === $provider || 'postmark' === $provider ) {
			$field = 'api_key';
		}
		if ( '' !== $field ) {
			$stored = (string) self::val( $p, $field );
			if ( 'wp_config' === self::val( $p, 'key_store' ) ) {
				$m['secret_error'] = __( 'You set this in wp-config.php for FluentSMTP. Enter the value on Perdita, Email.', 'perdita-core' );
			} elseif ( '' !== $stored ) {
				$plain = $encrypt ? self::decrypt( $stored ) : $stored;
				if ( null === $plain ) {
					$m['secret_error'] = __( 'The encrypted password couldn\'t be read. Enter the password on Perdita, Email.', 'perdita-core' );
				} else {
					$m['secret'] = $plain;
				}
			}
		}
		$this->offer_mailer( $m );
		if ( count( $conns ) > 1 ) {
			/* translators: %d: number of connections. */
			$this->note( sprintf( _n( 'FluentSMTP has %d more connection. In Perdita, you send through one server, so you get the default connection only.', 'FluentSMTP has %d more connections. In Perdita, you send through one server, so you get the default connection only.', count( $conns ) - 1, 'perdita-core' ), count( $conns ) - 1 ) );
		}
		return $this->finish();
	}
}
