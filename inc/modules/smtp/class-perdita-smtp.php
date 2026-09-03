<?php
/**
 * SMTP mailer: route wp_mail() through an SMTP server and log delivery.
 *
 * A drop-in replacement for the "WP Mail SMTP" style plugin. When SMTP is
 * enabled and configured, wp_mail() sends through the configured host instead
 * of the local PHP mail() sink, which is why site email actually reaches the
 * inbox.
 *
 * DESIGN NUANCE (secrets): the SMTP password is encrypted at rest with
 * Perdita_Crypto, whose key is derived from the site's wp-config salts (which
 * live in wp-config.php, not the database). So a database dump alone does not
 * reveal the password. This is the pragmatic balance between the "no plaintext
 * secret in the DB" rule and letting a non-technical site owner just type the
 * password into the admin screen and have it work.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

class Perdita_SMTP {

	/**
	 * Option name holding the settings array.
	 */
	const OPTION = 'perdita_smtp_settings';

	/**
	 * How many log rows to keep. The log is pruned to this after each insert.
	 */
	const LOG_LIMIT = 200;

	/**
	 * The Perdita core singleton.
	 *
	 * @var Perdita
	 */
	private $core;

	/**
	 * Constructor. Register the mail hooks.
	 *
	 * @param Perdita $core Core.
	 */
	public function __construct( $core ) {
		$this->core = $core;

		add_action( 'phpmailer_init', array( $this, 'configure' ) );

		// From address and name, only when the owner set them.
		add_filter( 'wp_mail_from', array( $this, 'filter_from' ), 20 );
		add_filter( 'wp_mail_from_name', array( $this, 'filter_from_name' ), 20 );

		// Delivery log.
		add_action( 'wp_mail_succeeded', array( $this, 'log_success' ) );
		add_action( 'wp_mail_failed', array( $this, 'log_failure' ) );
	}

	/**
	 * The log table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'perdita_smtp_log';
	}

	/**
	 * Read the settings array with defaults filled in.
	 *
	 * @return array
	 */
	public static function settings() {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		return array_merge(
			array(
				'from_email' => '',
				'from_name'  => '',
				'host'       => '',
				'port'       => 587,
				'encryption' => 'tls', // '', 'tls', or 'ssl'.
				'auth'       => true,
				'username'   => '',
				'password'   => '', // Encrypted blob at rest.
			),
			$stored
		);
	}

	/**
	 * Whether SMTP is configured enough to send (a host is set).
	 *
	 * @return bool
	 */
	public static function is_configured() {
		$s = self::settings();
		return '' !== trim( (string) $s['host'] );
	}

	/**
	 * Configure PHPMailer to send through SMTP.
	 *
	 * @param PHPMailer\PHPMailer\PHPMailer $phpmailer PHPMailer instance (passed by reference).
	 */
	public function configure( $phpmailer ) {
		if ( ! self::is_configured() ) {
			return;
		}
		$s = self::settings();

		$phpmailer->isSMTP();
		$phpmailer->Host = $s['host']; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$phpmailer->Port = (int) $s['port']; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

		// Encryption: '' means none, otherwise 'tls' or 'ssl'.
		$enc = in_array( $s['encryption'], array( 'tls', 'ssl' ), true ) ? $s['encryption'] : '';
		$phpmailer->SMTPSecure = $enc; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		// Only enable auto-TLS negotiation when the owner did not pick a
		// scheme. The condition used to be inverted against this comment:
		// picking 'tls' or 'ssl' turned auto-TLS ON (harmless, SMTPSecure
		// already forces encryption) while leaving it blank turned it OFF,
		// which silently disabled opportunistic STARTTLS and sent the
		// username and password in the clear to any server that would have
		// upgraded the connection.
		$phpmailer->SMTPAutoTLS = ( '' === $enc ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

		$phpmailer->SMTPAuth = (bool) $s['auth']; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		if ( $s['auth'] ) {
			$phpmailer->Username = $s['username']; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			// Decrypt the stored password only here, at send time.
			// decrypt(), not decrypt_strict(): an unreadable password and an
			// empty one lead to the same safe outcome here (the server
			// rejects the login and wp_mail() fails loudly), so there is
			// nothing to fail closed on. Passing a context means the owner
			// still gets an admin notice naming SMTP as the thing that
			// broke when the salts changed.
			$phpmailer->Password = Perdita_Crypto::decrypt( $s['password'], __( 'SMTP password', 'perdita-core' ) ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		}
	}

	/**
	 * Override the From address when the owner set one.
	 *
	 * @param string $from Current From address.
	 * @return string
	 */
	public function filter_from( $from ) {
		$s = self::settings();
		$set = trim( (string) $s['from_email'] );
		return ( '' !== $set && is_email( $set ) ) ? $set : $from;
	}

	/**
	 * Override the From name when the owner set one.
	 *
	 * @param string $name Current From name.
	 * @return string
	 */
	public function filter_from_name( $name ) {
		$s = self::settings();
		$set = trim( (string) $s['from_name'] );
		return ( '' !== $set ) ? $set : $name;
	}

	/**
	 * Log a successful send.
	 *
	 * @param array $mail The mail data array WordPress passes to the action.
	 */
	public function log_success( $mail ) {
		if ( class_exists( 'Perdita_SMTP_Pro' ) && Perdita_SMTP_Pro::is_brokering_send() ) {
			// SMTP Pro is mid-failover (or running a one-off route test):
			// this wp_mail() call is one of ITS internal attempts, not a
			// distinct application email, and Pro's own log already
			// records the real, consolidated outcome once failover
			// finishes. Logging it here too would show several rows for
			// what is really just one email.
			return;
		}
		$to      = is_array( $mail ) && isset( $mail['to'] ) ? $mail['to'] : '';
		$subject = is_array( $mail ) && isset( $mail['subject'] ) ? $mail['subject'] : '';
		$this->insert_log( $this->first_recipient( $to ), (string) $subject, 'sent', '' );
	}

	/**
	 * Log a failed send.
	 *
	 * @param WP_Error $error The error WordPress passes to the action.
	 */
	public function log_failure( $error ) {
		if ( class_exists( 'Perdita_SMTP_Pro' ) && Perdita_SMTP_Pro::is_brokering_send() ) {
			return; // See the matching guard in log_success() for why.
		}
		$to      = '';
		$subject = '';
		$message = '';
		if ( is_wp_error( $error ) ) {
			$message = $error->get_error_message();
			$data    = $error->get_error_data();
			if ( is_array( $data ) ) {
				if ( isset( $data['to'] ) ) {
					$to = $data['to'];
				}
				if ( isset( $data['subject'] ) ) {
					$subject = $data['subject'];
				}
			}
		}
		$this->insert_log( $this->first_recipient( $to ), (string) $subject, 'failed', (string) $message );
	}

	/**
	 * Normalize a recipient (which may be a string or an array) to one address.
	 *
	 * @param mixed $to Recipient(s).
	 * @return string
	 */
	private function first_recipient( $to ) {
		if ( is_array( $to ) ) {
			$to = reset( $to );
		}
		$to = (string) $to;
		if ( false !== strpos( $to, ',' ) ) {
			$parts = explode( ',', $to );
			$to    = trim( $parts[0] );
		}
		return $to;
	}

	/**
	 * Insert one log row and prune the log to LOG_LIMIT newest rows.
	 *
	 * @param string $recipient Recipient address.
	 * @param string $subject   Subject.
	 * @param string $status    'sent' or 'failed'.
	 * @param string $error     Error text (empty on success).
	 */
	public function insert_log( $recipient, $subject, $status, $error ) {
		global $wpdb;
		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- module log table, no core API.
		$wpdb->insert(
			$table,
			array(
				'recipient' => substr( (string) $recipient, 0, 255 ),
				'subject'   => substr( (string) $subject, 0, 255 ),
				'status'    => ( 'sent' === $status ) ? 'sent' : 'failed',
				'error'     => (string) $error,
				'created'   => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%s' )
		);

		$this->prune();
	}

	/**
	 * Keep the log bounded to the newest LOG_LIMIT rows.
	 */
	private function prune() {
		global $wpdb;
		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is a computed literal, not user input.
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		if ( $total <= self::LOG_LIMIT ) {
			return;
		}

		// Find the id of the newest row we want to keep, then delete anything older.
		$offset = self::LOG_LIMIT - 1;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is a computed literal.
		$cutoff = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} ORDER BY id DESC LIMIT 1 OFFSET %d", $offset ) );
		if ( $cutoff > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is a computed literal.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id < %d", $cutoff ) );
		}
	}

	/**
	 * Read recent log rows, newest first.
	 *
	 * @param int $limit How many to return.
	 * @return array Row objects.
	 */
	public static function recent_log( $limit = 50 ) {
		global $wpdb;
		$table = self::table();
		$limit = max( 1, (int) $limit );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is a computed literal.
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, recipient, subject, status, error, created FROM {$table} ORDER BY id DESC LIMIT %d", $limit ) );
	}

	/**
	 * Empty the log.
	 */
	public static function clear_log() {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is a computed literal, TRUNCATE takes no args.
		$wpdb->query( "TRUNCATE TABLE {$table}" );
	}

	/**
	 * Create the log table. Runs on module activation.
	 */
	public static function install() {
		global $wpdb;
		$table           = self::table();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			recipient VARCHAR(255) NOT NULL DEFAULT '',
			subject VARCHAR(255) NOT NULL DEFAULT '',
			status VARCHAR(20) NOT NULL DEFAULT '',
			error TEXT NULL,
			created DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY created (created)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}
}
