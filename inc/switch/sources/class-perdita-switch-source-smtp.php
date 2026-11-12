<?php
/**
 * Shared parts of the mailer sources. A password or API key reaches
 * Perdita_SMTP::sanitize() as password_plain and is encrypted there, the
 * way the Email screen stores one. The preview shows its last 4 characters
 * at most, and nothing here writes a secret to a log.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

abstract class Perdita_Switch_SMTP_Source extends Perdita_Switch_Source {

	public function module() {
		return 'smtp';
	}

	/**
	 * Offer one mailer's settings.
	 *
	 * @param array $m mailer (smtp, sendgrid, postmark or the plugin's own
	 *                 name), from_email, from_name, host, port, encryption
	 *                 ('', tls or ssl), auth (bool), username, secret, and
	 *                 secret_error (why a stored secret can't be read).
	 */
	protected function offer_mailer( array $m ) {
		$m = array_merge(
			array(
				'mailer'       => 'smtp',
				'from_email'   => '',
				'from_name'    => '',
				'host'         => '',
				'port'         => 0,
				'encryption'   => '',
				'auth'         => false,
				'username'     => '',
				'secret'       => '',
				'secret_error' => '',
			),
			$m
		);
		if ( '' !== trim( (string) $m['from_email'] ) ) {
			$this->offer( __( 'From email', 'perdita-core' ), $m['from_email'], 'from_email', trim( (string) $m['from_email'] ), __( 'From email', 'perdita-core' ) );
		}
		if ( '' !== trim( (string) $m['from_name'] ) ) {
			$this->offer( __( 'From name', 'perdita-core' ), $m['from_name'], 'from_name', trim( (string) $m['from_name'] ), __( 'From name', 'perdita-core' ) );
		}

		$secret_label = __( 'Password', 'perdita-core' );
		switch ( $m['mailer'] ) {
			case 'smtp':
				break;
			case 'sendgrid':
				$this->note( __( 'You can use the same SendGrid API key over SMTP, so you\'ll send through smtp.sendgrid.net with that key.', 'perdita-core' ) );
				$m           = array_merge(
					$m,
					array(
						'host'       => 'smtp.sendgrid.net',
						'port'       => 587,
						'encryption' => 'tls',
						'auth'       => true,
						'username'   => 'apikey',
					)
				);
				$secret_label = __( 'API key', 'perdita-core' );
				break;
			case 'postmark':
				$this->note( __( 'You can use the Postmark server token as both the SMTP username and the password, so you\'ll send through smtp.postmarkapp.com with that token.', 'perdita-core' ) );
				$m            = array_merge(
					$m,
					array(
						'host'       => 'smtp.postmarkapp.com',
						'port'       => 587,
						'encryption' => 'tls',
						'auth'       => true,
						'username'   => (string) $m['secret'],
					)
				);
				$secret_label = __( 'Server API token', 'perdita-core' );
				break;
			default:
				/* translators: %s: mailer name. */
				$this->unsupported( __( 'Mailer', 'perdita-core' ), $m['mailer'], sprintf( __( 'In Perdita, you send over SMTP. If %s offers SMTP credentials, create them in its dashboard and enter them on Perdita, Email.', 'perdita-core' ), ucfirst( (string) $m['mailer'] ) ) );
				return;
		}

		if ( '' === trim( (string) $m['host'] ) ) {
			$this->note( __( 'You didn\'t set this plugin to send over SMTP, so you have no server to import.', 'perdita-core' ) );
			return;
		}
		$this->offer( __( 'SMTP host', 'perdita-core' ), $m['host'], 'host', trim( (string) $m['host'] ), __( 'SMTP host', 'perdita-core' ) );
		if ( (int) $m['port'] > 0 ) {
			$this->offer( __( 'SMTP port', 'perdita-core' ), $m['port'], 'port', (int) $m['port'], __( 'Port', 'perdita-core' ) );
		}
		$this->offer( __( 'Encryption', 'perdita-core' ), '' === $m['encryption'] ? __( 'none', 'perdita-core' ) : strtoupper( (string) $m['encryption'] ), 'encryption', (string) $m['encryption'], __( 'Encryption', 'perdita-core' ) );
		$this->offer( __( 'Authentication', 'perdita-core' ), (bool) $m['auth'], 'auth', (bool) $m['auth'], __( 'Authentication', 'perdita-core' ) );
		if ( $m['auth'] && '' !== (string) $m['username'] ) {
			$is_secret = 'postmark' === $m['mailer'];
			$this->offer( __( 'SMTP username', 'perdita-core' ), $m['username'], 'username', (string) $m['username'], __( 'Username', 'perdita-core' ), array( 'secret' => $is_secret ) );
		}
		if ( $m['auth'] ) {
			if ( '' !== (string) $m['secret_error'] ) {
				$this->skip( $secret_label, '••••', (string) $m['secret_error'], __( 'Password', 'perdita-core' ) );
			} elseif ( '' !== (string) $m['secret'] ) {
				$this->offer(
					$secret_label,
					$m['secret'],
					'password_plain',
					(string) $m['secret'],
					__( 'Password', 'perdita-core' ),
					array(
						'secret'  => true,
						'note'    => __( 'You get it saved encrypted, the same way as on Perdita, Email.', 'perdita-core' ),
						'refused' => __( 'This is longer than the longest password you can store in Perdita, so enter it on Perdita, Email.', 'perdita-core' ),
					)
				);
			}
		}
	}

	/**
	 * Perdita's encryption from a plugin's name for it.
	 *
	 * @param mixed $value none, ssl, tls, starttls.
	 * @return string
	 */
	protected static function encryption( $value ) {
		$value = strtolower( trim( (string) $value ) );
		if ( 'ssl' === $value ) {
			return 'ssl';
		}
		if ( 'tls' === $value || 'starttls' === $value ) {
			return 'tls';
		}
		return '';
	}
}
