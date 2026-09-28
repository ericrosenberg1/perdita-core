<?php
/**
 * Secret encryption at rest.
 *
 * Some modules (SMTP, payment gateways) need a secret a beginner can just
 * type into the admin, but the user-wide rule is that a secret must never sit
 * in the database as plaintext. This encrypts those values with a key derived
 * from the site's wp-config salts, which live in wp-config.php, not the
 * database. So a stolen DB dump alone does not reveal the secret: an attacker
 * would also need the wp-config file.
 *
 * This is a pragmatic middle ground, not a hardware secret store. A site that
 * wants stronger separation can define PERDITA_SECRET_KEY (32+ random bytes,
 * base64) in wp-config.php or a chmod-600 env file loaded into it, and that is
 * used instead of the salt-derived key.
 *
 * @package Perdita
 */

defined( 'ABSPATH' ) || exit;

/**
 * Secret encryption at rest.
 *
 * @package Perdita
 */
class Perdita_Crypto {

	const PREFIX = 'psx1:'; // Versioned so the scheme can change later.

	/**
	 * Encrypt a string for storage. Returns '' for an empty input so a blank
	 * field stays blank rather than storing an encrypted empty string.
	 *
	 * @param string $plaintext Secret value.
	 * @return string Prefixed, base64 ciphertext, or '' on empty input.
	 */
	public static function encrypt( $plaintext ) {
		$plaintext = (string) $plaintext;
		if ( '' === $plaintext ) {
			return '';
		}
		$key = self::key();
		if ( function_exists( 'sodium_crypto_secretbox' ) ) {
			$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$cipher = sodium_crypto_secretbox( $plaintext, $nonce, $key );
			$out    = self::PREFIX . base64_encode( $nonce . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- binary transport.
			// Only the real extension can wipe memory. On a host without
			// ext-sodium the function still exists -- WordPress bundles the
			// pure-PHP sodium_compat, which is why the branch above works at
			// all -- but its memzero() THROWS SodiumException by design, since
			// PHP cannot securely wipe a string. Calling it unguarded fataled
			// every settings save that stores a secret (SMTP password, Stripe
			// secret key, Turnstile secret, PageSpeed and Search Console keys).
			// The wipe is best-effort hardening, so skip it where it is
			// impossible rather than taking the whole save down with it.
			if ( extension_loaded( 'sodium' ) ) {
				sodium_memzero( $plaintext );
			}
			return $out;
		}
		// openssl fallback for hosts without libsodium.
		$iv     = random_bytes( 16 );
		$cipher = openssl_encrypt( $plaintext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
		if ( false === $cipher ) {
			return '';
		}
		$mac = hash_hmac( 'sha256', $iv . $cipher, $key, true );
		return self::PREFIX . 'ossl:' . base64_encode( $iv . $mac . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- binary transport.
	}

	/**
	 * Decrypt a value produced by encrypt(). Returns '' if the value isn't
	 * ours or can't be authenticated.
	 *
	 * The '' return is deliberately ambiguous -- it means BOTH "nothing was
	 * ever stored" and "something was stored but we could not read it" --
	 * which is why decrypt_strict() exists alongside it. Every caller that
	 * makes a security decision on the result (is this check enabled? do we
	 * have a secret to verify with?) must use decrypt_strict() and handle
	 * false, or it will silently treat an unreadable secret as an absent
	 * one. Callers where '' and "unreadable" genuinely lead to the same
	 * safe outcome (no password, so no authenticated send) can keep using
	 * this. The plugin ecosystem depends on the string return type, so the
	 * contract here does not change.
	 *
	 * Failures are recorded (see record_failure()) so the site owner gets an
	 * admin notice instead of a feature that just quietly stops working.
	 *
	 * @param string $stored  Stored value.
	 * @param string $context Optional human-readable feature name, used in
	 *                        the admin notice to say what stopped working.
	 * @return string
	 */
	public static function decrypt( $stored, $context = '' ) {
		$plain = self::decrypt_strict( $stored );
		if ( false === $plain ) {
			// A non-empty value we cannot read is always worth naming,
			// whether it is one of our blobs that no longer authenticates
			// (the salt-change case) or something that was never ours. An
			// empty stored value is not a failure, it is an empty setting,
			// and decrypt_strict() returns '' for that rather than false.
			self::record_failure( $context );
			return '';
		}
		return $plain;
	}

	/**
	 * Decrypt with an unambiguous failure signal: false when the value
	 * cannot be read (not one of our blobs, or it fails authentication
	 * because the wp-config salts or PERDITA_SECRET_KEY changed), and ''
	 * only when nothing was stored in the first place.
	 *
	 * A changed salt is the realistic case. wp_salt() feeds the key, so
	 * rotating salts (a host's "reset security keys" button, a migration,
	 * a restored wp-config) makes every stored secret unreadable at once.
	 * With decrypt()'s string contract alone, a caller cannot tell that
	 * from "the owner never filled this in", so anti-spam and payment
	 * checks silently turn themselves off.
	 *
	 * @param string $stored Stored value.
	 * @return string|false Plaintext, '' if nothing was stored, false on failure.
	 */
	public static function decrypt_strict( $stored ) {
		$stored = (string) $stored;
		if ( '' === $stored ) {
			return '';
		}
		if ( 0 !== strpos( $stored, self::PREFIX ) ) {
			return false;
		}
		$key  = self::key();
		$body = substr( $stored, strlen( self::PREFIX ) );

		if ( 0 === strpos( $body, 'ossl:' ) ) {
			$raw = base64_decode( substr( $body, 5 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- binary transport.
			if ( false === $raw || strlen( $raw ) < 48 ) {
				return false;
			}
			$iv     = substr( $raw, 0, 16 );
			$mac    = substr( $raw, 16, 32 );
			$cipher = substr( $raw, 48 );
			$calc   = hash_hmac( 'sha256', $iv . $cipher, $key, true );
			if ( ! hash_equals( $calc, $mac ) ) {
				return false;
			}
			$plain = openssl_decrypt( $cipher, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
			return false === $plain ? false : $plain;
		}

		if ( ! function_exists( 'sodium_crypto_secretbox_open' ) ) {
			return false;
		}
		$raw = base64_decode( $body, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- binary transport.
		if ( false === $raw || strlen( $raw ) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return false;
		}
		$nonce  = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$plain  = sodium_crypto_secretbox_open( $cipher, $nonce, $key );
		return false === $plain ? false : $plain;
	}

	/**
	 * Feature names whose stored secret could not be decrypted during this
	 * request. Request-scoped on purpose: this is a "tell the owner what
	 * just happened" signal, not persisted state to clean up later.
	 *
	 * @var string[]
	 */
	private static $failures = array();

	/**
	 * Whether the admin_notices callback has already been registered, so a
	 * page that decrypts several secrets only ever hooks it once.
	 *
	 * @var bool
	 */
	private static $notice_hooked = false;

	/**
	 * Record an unreadable secret and make sure the admin notice is wired up.
	 *
	 * @param string $context Human-readable feature name, or '' if unknown.
	 */
	private static function record_failure( $context ) {
		$context = trim( (string) $context );
		if ( '' === $context ) {
			$context = __( 'an encrypted setting', 'perdita-core' );
		}
		if ( ! in_array( $context, self::$failures, true ) ) {
			self::$failures[] = $context;
		}
		if ( ! self::$notice_hooked && function_exists( 'add_action' ) ) {
			self::$notice_hooked = true;
			add_action( 'admin_notices', array( __CLASS__, 'render_failure_notice' ) );
		}
	}

	/**
	 * Feature names that failed to decrypt in this request.
	 *
	 * @return string[]
	 */
	public static function failures() {
		return self::$failures;
	}

	/**
	 * Whether anything failed to decrypt in this request.
	 *
	 * @return bool
	 */
	public static function had_failure() {
		return ! empty( self::$failures );
	}

	/**
	 * Admin notice: an encrypted setting could not be read. Names the
	 * affected features and the one thing that actually causes this, so the
	 * owner re-enters the secret rather than hunting a phantom bug.
	 */
	public static function render_failure_notice() {
		if ( empty( self::$failures ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p><strong>';
		esc_html_e( 'Perdita could not read one of its encrypted settings.', 'perdita-core' );
		echo '</strong> ';
		printf(
			/* translators: %s: comma-separated list of affected feature names. */
			esc_html__( 'Affected: %s.', 'perdita-core' ),
			esc_html( implode( ', ', self::$failures ) )
		);
		echo ' ';
		esc_html_e( 'Stored secrets are encrypted with a key derived from your wp-config.php salts, so changing those salts (or PERDITA_SECRET_KEY) makes existing values unreadable. Re-enter and save the affected secrets to fix this.', 'perdita-core' );
		echo '</p></div>';
	}

	/**
	 * Whether a stored value is one of our encrypted blobs (vs a blank or a
	 * value that predates encryption).
	 *
	 * @param string $stored Stored value.
	 * @return bool
	 */
	public static function is_encrypted( $stored ) {
		return 0 === strpos( (string) $stored, self::PREFIX );
	}

	/**
	 * 32-byte key. Prefers an explicit PERDITA_SECRET_KEY constant (base64 of
	 * 32+ bytes), else derives one from wp-config salts, which are not in the
	 * database.
	 *
	 * @return string 32 raw bytes.
	 */
	private static function key() {
		if ( defined( 'PERDITA_SECRET_KEY' ) && PERDITA_SECRET_KEY ) {
			$raw = base64_decode( PERDITA_SECRET_KEY, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- configured key.
			if ( false !== $raw && strlen( $raw ) >= 32 ) {
				return substr( $raw, 0, 32 );
			}
		}
		// wp_salt() reads from wp-config.php constants (or a WP-managed value
		// outside typical table backups). hash to a fixed 32 bytes.
		return substr( hash( 'sha256', 'perdita-crypto|' . wp_salt( 'secure_auth' ), true ), 0, 32 );
	}
}
