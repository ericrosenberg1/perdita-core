<?php
/**
 * WP Mail SMTP. Its settings are the wp_mail_smtp option, or constants in
 * wp-config.php when WPMS_ON is set. It encrypts the SMTP password with
 * libsodium and a key in WPMS_CRYPTO_KEY or the wp_mail_smtp_mail_key
 * option. Perdita decrypts it in memory only to encrypt it again its own way.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

class Perdita_Switch_Source_WP_Mail_SMTP extends Perdita_Switch_SMTP_Source {

	public function id() {
		return 'wp-mail-smtp';
	}

	public function label() {
		return 'WP Mail SMTP';
	}

	public function plugin_files() {
		return array( 'wp-mail-smtp/wp_mail_smtp.php', 'wp-mail-smtp-pro/wp_mail_smtp.php' );
	}

	public function has_settings() {
		return is_array( get_option( 'wp_mail_smtp' ) );
	}

	/**
	 * One setting, from its constant when WPMS_ON is set.
	 *
	 * @param array  $o        Option.
	 * @param string $group    Group.
	 * @param string $key      Key.
	 * @param string $constant Constant name.
	 * @return mixed
	 */
	private static function setting( array $o, $group, $key, $constant ) {
		if ( defined( 'WPMS_ON' ) && constant( 'WPMS_ON' ) && defined( $constant ) ) {
			$value = constant( $constant );
			return is_scalar( $value ) ? $value : '';
		}
		return isset( $o[ $group ][ $key ] ) && is_scalar( $o[ $group ][ $key ] ) ? $o[ $group ][ $key ] : '';
	}

	/**
	 * The plain SMTP password, or null when it can't be read.
	 *
	 * @param string $stored Stored value.
	 * @return string|null
	 */
	public static function decrypt( $stored ) {
		$stored = (string) $stored;
		if ( '' === $stored ) {
			return '';
		}
		$raw = base64_decode( $stored, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- WP Mail SMTP's stored format.
		$min = defined( 'SODIUM_CRYPTO_SECRETBOX_NONCEBYTES' ) ? SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES : 40;
		if ( false === $raw || strlen( $raw ) < $min ) {
			// WP Mail SMTP kept passwords saved before it encrypted them as
			// they were, and reads them the same way.
			return $stored;
		}
		if ( ! function_exists( 'sodium_crypto_secretbox_open' ) ) {
			return null;
		}
		$key = '';
		if ( defined( 'WPMS_CRYPTO_KEY' ) ) {
			$key = (string) constant( 'WPMS_CRYPTO_KEY' );
		} else {
			$opt = get_option( 'wp_mail_smtp_mail_key', '' );
			$key = is_string( $opt ) ? (string) base64_decode( $opt, true ) : ''; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- WP Mail SMTP's stored key format.
		}
		if ( SODIUM_CRYPTO_SECRETBOX_KEYBYTES !== strlen( $key ) ) {
			return null;
		}
		$nonce = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$box   = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		try {
			$plain = sodium_crypto_secretbox_open( $box, $nonce, $key );
		} catch ( Exception $e ) {
			$plain = false;
		}
		return false === $plain ? null : $plain;
	}

	public function plan() {
		$this->begin();
		$o = self::array_option( 'wp_mail_smtp' );
		if ( ! $o ) {
			return $this->finish();
		}
		$mailer = (string) self::setting( $o, 'mail', 'mailer', 'WPMS_MAILER' );
		$m      = array(
			'mailer'     => in_array( $mailer, array( 'smtp', 'sendgrid', 'postmark' ), true ) ? $mailer : ( 'mail' === $mailer || '' === $mailer ? 'smtp' : $mailer ),
			'from_email' => self::setting( $o, 'mail', 'from_email', 'WPMS_MAIL_FROM' ),
			'from_name'  => self::setting( $o, 'mail', 'from_name', 'WPMS_MAIL_FROM_NAME' ),
		);
		if ( 'smtp' === $mailer ) {
			$m['host']       = self::setting( $o, 'smtp', 'host', 'WPMS_SMTP_HOST' );
			$m['port']       = (int) self::setting( $o, 'smtp', 'port', 'WPMS_SMTP_PORT' );
			$m['encryption'] = self::encryption( self::setting( $o, 'smtp', 'encryption', 'WPMS_SSL' ) );
			$m['auth']       = self::on( self::setting( $o, 'smtp', 'auth', 'WPMS_SMTP_AUTH' ) );
			$m['username']   = self::setting( $o, 'smtp', 'user', 'WPMS_SMTP_USER' );
			$from_constant   = defined( 'WPMS_ON' ) && constant( 'WPMS_ON' ) && defined( 'WPMS_SMTP_PASS' );
			$pass            = $from_constant ? (string) self::setting( $o, 'smtp', 'pass', 'WPMS_SMTP_PASS' ) : self::decrypt( self::setting( $o, 'smtp', 'pass', '' ) );
			if ( null === $pass ) {
				$m['secret_error'] = __( 'The encrypted password couldn\'t be read. Enter the password on Perdita, Email.', 'perdita-core' );
			} else {
				$m['secret'] = $pass;
			}
		} elseif ( 'sendgrid' === $mailer ) {
			$m['secret'] = (string) self::setting( $o, 'sendgrid', 'api_key', 'WPMS_SENDGRID_API_KEY' );
		} elseif ( 'postmark' === $mailer ) {
			$m['secret'] = (string) self::setting( $o, 'postmark', 'server_api_token', 'WPMS_POSTMARK_SERVER_API_TOKEN' );
		}
		$this->offer_mailer( $m );
		return $this->finish();
	}
}
