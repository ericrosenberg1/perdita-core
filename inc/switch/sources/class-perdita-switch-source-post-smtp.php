<?php
/**
 * Post SMTP. Its settings are the postman_options option, with the
 * password and API keys stored base64 encoded.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

class Perdita_Switch_Source_Post_SMTP extends Perdita_Switch_SMTP_Source {

	public function id() {
		return 'post-smtp';
	}

	public function label() {
		return 'Post SMTP';
	}

	public function plugin_files() {
		return array( 'post-smtp/postman-smtp.php' );
	}

	public function has_settings() {
		return is_array( get_option( 'postman_options' ) );
	}

	/**
	 * A base64 value as text, or null.
	 *
	 * @param mixed $value Stored.
	 * @return string|null
	 */
	private static function decode( $value ) {
		$value = (string) $value;
		if ( '' === $value ) {
			return '';
		}
		$plain = base64_decode( $value, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Post SMTP's stored format.
		return false === $plain ? null : $plain;
	}

	public function plan() {
		$this->begin();
		$o = self::array_option( 'postman_options' );
		if ( ! $o ) {
			return $this->finish();
		}
		$transport = (string) self::val( $o, 'transport_type', 'smtp' );
		$names     = array(
			'smtp'          => 'smtp',
			'sendgrid_api'  => 'sendgrid',
			'postmark_api'  => 'postmark',
			'default'       => 'smtp',
		);
		$m         = array(
			'mailer'     => isset( $names[ $transport ] ) ? $names[ $transport ] : $transport,
			'from_email' => self::val( $o, 'sender_email' ),
			'from_name'  => self::val( $o, 'sender_name' ),
		);
		$secret = null;
		if ( 'smtp' === $transport ) {
			$auth = (string) self::val( $o, 'auth_type', 'none' );
			if ( 'oauth2' === $auth ) {
				$this->unsupported( __( 'Authentication', 'perdita-core' ), 'OAuth 2.0', __( 'In Perdita, you sign in to the SMTP server with a username and password. Create an app password with your provider and enter it on Perdita, Email.', 'perdita-core' ) );
				$auth = 'none';
			}
			$m['host']       = self::val( $o, 'hostname' );
			$m['port']       = (int) self::val( $o, 'port', 0 );
			$m['encryption'] = self::encryption( self::val( $o, 'enc_type' ) );
			$m['auth']       = 'none' !== $auth;
			$m['username']   = self::val( $o, 'basic_auth_username' );
			$secret          = self::decode( self::val( $o, 'basic_auth_password' ) );
		} elseif ( 'sendgrid_api' === $transport ) {
			$secret = self::decode( self::val( $o, 'sendgrid_api_key' ) );
		} elseif ( 'postmark_api' === $transport ) {
			$secret = self::decode( self::val( $o, 'postmark_api_key' ) );
		} else {
			$secret = '';
		}
		if ( null === $secret ) {
			$m['secret_error'] = __( 'The stored password couldn\'t be read. Enter the password on Perdita, Email.', 'perdita-core' );
		} else {
			$m['secret'] = $secret;
		}
		$this->offer_mailer( $m );
		if ( '' !== (string) self::val( $o, 'fallback_smtp_enabled' ) && 'no' !== self::val( $o, 'fallback_smtp_enabled' ) ) {
			$this->unsupported( __( 'Fallback', 'perdita-core' ), true, __( 'In Perdita, you send through one server.', 'perdita-core' ) );
		}
		return $this->finish();
	}
}
