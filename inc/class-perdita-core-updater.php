<?php
/**
 * Self-hosted plugin updates.
 *
 * Perdita Core is distributed from perdita.ericrosenberg.com as well as (in
 * time) wordpress.org, so it checks a manifest hosted alongside the zip and
 * feeds WordPress the same update data the directory would. Live sites then
 * see the update on the Updates screen and can install it in one click.
 *
 * Manifest (JSON) shape, served over HTTPS:
 *   {
 *     "name": "Perdita Core", "version": "1.0.0-alpha",
 *     "download_url": "https://perdita.ericrosenberg.com/updates/perdita-core-1.0.0-alpha.zip",
 *     "checksum": "sha256 hex digest of the zip, e.g. from `shasum -a 256`",
 *     "requires": "6.4", "requires_php": "8.0", "tested": "7.1",
 *     "url": "https://perdita.ericrosenberg.com", "last_updated": "2026-09-03"
 *   }
 *
 * "download_url" must name a VERSIONED artifact, never a mutable filename
 * like perdita-core-latest.zip. A mutable name lets the bytes a checksum pins
 * be replaced without the version changing, and /updates is served with a
 * ten-year max-age, so whatever the file holds at the next edge purge is
 * pinned for a decade. The theme learned this the expensive way (ROS-2177);
 * bin/build-release.sh only ever emits perdita-core-<version>.zip.
 *
 * HTTPS-only plus host pinning stop a tampered manifest from pointing
 * WordPress at an attacker's zip, but they only protect the network path. The
 * manifest and the zip live on the same server, so a checksum published next
 * to the zip proves nothing if that server is compromised: whoever can
 * replace the zip can replace the checksum too. Since 0.19.1-alpha the
 * manifest also carries "signature", an Ed25519 signature over
 *
 *   perdita-release-v1 \n perdita-core \n <version> \n <sha256 of the zip>
 *
 * made at build time with a key that never leaves the release machine. The
 * public half is SIGNING_KEY below. verify_download() refuses our package
 * unless the checksum matches AND the signature verifies, so a compromised
 * update server can no longer push code to every site. The slug and version
 * are inside the signed message, so a signed zip cannot be relabelled as a
 * newer version or as another Perdita package.
 *
 * This whole file is absent from a wordpress.org build (bin/build-release.sh
 * --wporg removes it), and Perdita_Core::init() only requires it when it
 * exists, so a directory install takes its updates from the directory.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

class Perdita_Core_Updater {

	const MANIFEST = 'https://perdita.ericrosenberg.com/updates/perdita-core.json';
	const CACHE    = 'perdita_core_update_manifest';

	/**
	 * Ed25519 public key (base64) that signs every Perdita release. The
	 * secret half lives only on the release machine; bin/build-release.sh
	 * signs with it. Same key for the theme, Core, and Pro.
	 */
	const SIGNING_KEY = 'qyIk/wCucadP76dYQ8cTt6djiPcpocCPqO2lYCnVQ8k=';

	/**
	 * First line of every signed message, so a signature made for anything
	 * else can never verify here.
	 */
	const SIGNATURE_CONTEXT = 'perdita-release-v1';

	/**
	 * The slug inside the signed message.
	 */
	const SIGNATURE_SLUG = 'perdita-core';

	/**
	 * Plugin basename, e.g. perdita-core/perdita-core.php. This is the key
	 * WordPress uses in the update transient and in wp_get_active_plugins().
	 *
	 * @var string
	 */
	private $basename;

	/**
	 * Plugin directory slug, e.g. perdita-core.
	 *
	 * @var string
	 */
	private $slug;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->basename = plugin_basename( PERDITA_CORE_FILE );
		$this->slug     = dirname( $this->basename );

		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check' ) );
		add_filter( 'plugins_api', array( $this, 'details' ), 10, 3 );
		add_filter( 'auto_update_plugin', array( $this, 'auto_update' ), 10, 2 );
		add_filter( 'upgrader_pre_download', array( $this, 'verify_download' ), 10, 3 );
		add_action( 'upgrader_process_complete', array( $this, 'flush' ), 10, 0 );
	}

	/**
	 * Fetch the manifest, cached for 12 hours (failures cached briefly so a
	 * down server does not get hammered on every admin page load).
	 *
	 * @return array
	 */
	private function manifest() {
		$cached = get_transient( self::CACHE );
		if ( false !== $cached ) {
			return is_array( $cached ) ? $cached : array();
		}
		$res = wp_remote_get( self::MANIFEST, array( 'timeout' => 10 ) );
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			set_transient( self::CACHE, array(), 2 * HOUR_IN_SECONDS );
			return array();
		}
		$data = json_decode( wp_remote_retrieve_body( $res ), true );
		$data = is_array( $data ) ? $data : array();
		set_transient( self::CACHE, $data, 12 * HOUR_IN_SECONDS );
		return $data;
	}

	/**
	 * The update row WordPress expects for a plugin. Returned as an object,
	 * which is what both response[] and no_update[] hold.
	 *
	 * @param array $m Decoded manifest.
	 * @return object
	 */
	private function row( array $m ) {
		return (object) array(
			'id'           => $this->basename,
			'slug'         => $this->slug,
			'plugin'       => $this->basename,
			'new_version'  => $m['version'],
			'url'          => isset( $m['url'] ) ? $m['url'] : 'https://perdita.ericrosenberg.com',
			'package'      => $m['download_url'],
			'requires'     => isset( $m['requires'] ) ? $m['requires'] : '',
			'requires_php' => isset( $m['requires_php'] ) ? $m['requires_php'] : '',
			'tested'       => isset( $m['tested'] ) ? $m['tested'] : '',
			'icons'        => array(),
			'banners'      => array(),
			'banners_rtl'  => array(),
		);
	}

	/**
	 * Inject our update into the plugins update transient.
	 *
	 * @param object $transient Update transient.
	 * @return object
	 */
	public function check( $transient ) {
		if ( ! is_object( $transient ) || empty( $transient->checked ) ) {
			return $transient;
		}
		$m = $this->manifest();
		if ( empty( $m['version'] ) || ! is_string( $m['version'] ) || empty( $m['download_url'] ) || ! is_string( $m['download_url'] ) ) {
			return $transient;
		}

		// Only install a package served over HTTPS from the update host.
		// Without this, a tampered manifest could point WordPress at any ZIP
		// and have it installed as a plugin update.
		if ( ! self::package_url_is_pinned( $m['download_url'] ) ) {
			return $transient;
		}

		// Never offer an update that verify_download() is bound to refuse.
		// An unsigned manifest is either a publishing mistake or a tampered
		// server, and in both cases the site is better off not seeing it.
		if ( ! static::manifest_is_signed( $m ) ) {
			return $transient;
		}

		$current = isset( $transient->checked[ $this->basename ] ) ? $transient->checked[ $this->basename ] : PERDITA_CORE_VERSION;
		$row     = $this->row( $m );

		if ( version_compare( $m['version'], $current, '>' ) ) {
			$transient->response[ $this->basename ] = $row;
			unset( $transient->no_update[ $this->basename ] );
		} else {
			$transient->no_update[ $this->basename ] = $row;
			unset( $transient->response[ $this->basename ] );
		}
		return $transient;
	}

	/**
	 * Answer the "View details" modal. Basic on purpose: the manifest carries
	 * compatibility data and a link, not a full wordpress.org listing, and
	 * inventing sections here would be inventing content.
	 *
	 * @param false|object|array $result The result object or array.
	 * @param string             $action The API action being performed.
	 * @param object             $args   Plugin API arguments.
	 * @return false|object|array
	 */
	public function details( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! isset( $args->slug ) || $args->slug !== $this->slug ) {
			return $result;
		}
		$m = $this->manifest();
		if ( empty( $m['version'] ) ) {
			return $result;
		}

		return (object) array(
			'name'          => isset( $m['name'] ) ? $m['name'] : 'Perdita Core',
			'slug'          => $this->slug,
			'version'       => $m['version'],
			'author'        => '<a href="https://ericrosenberg.com">Eric Rosenberg</a>',
			'homepage'      => isset( $m['url'] ) ? $m['url'] : 'https://perdita.ericrosenberg.com',
			'requires'      => isset( $m['requires'] ) ? $m['requires'] : '',
			'requires_php'  => isset( $m['requires_php'] ) ? $m['requires_php'] : '',
			'tested'        => isset( $m['tested'] ) ? $m['tested'] : '',
			'last_updated'  => isset( $m['last_updated'] ) ? $m['last_updated'] : '',
			'download_link' => self::package_url_is_pinned( isset( $m['download_url'] ) ? $m['download_url'] : '' ) ? $m['download_url'] : '',
			'sections'      => array(
				'description' => esc_html__( 'The free companion plugin for the Perdita theme: SEO, forms, caching, security, analytics, email, and more, as modules you turn on one at a time.', 'perdita-core' ),
			),
		);
	}

	/**
	 * Whether a package URL is one we are willing to install: HTTPS, and on
	 * the same host the manifest itself is served from. Shared by check()
	 * (which decides what to offer) and verify_download() (which re-derives
	 * the offer from the manifest rather than trusting anything the earlier
	 * request left behind), so the pin can never drift between the two.
	 *
	 * @param string $url Package URL from the manifest.
	 * @return bool
	 */
	private static function package_url_is_pinned( $url ) {
		$pkg  = wp_parse_url( (string) $url );
		$host = wp_parse_url( self::MANIFEST, PHP_URL_HOST );
		return ! empty( $pkg['scheme'] )
			&& 'https' === $pkg['scheme']
			&& ! empty( $pkg['host'] )
			&& $pkg['host'] === $host;
	}

	/**
	 * The manifest's sha256 checksum, normalized, or '' when the manifest did
	 * not publish a usable one (the field is optional, so an older manifest
	 * without it still installs).
	 *
	 * @param array $m Decoded manifest.
	 * @return string Lowercase 64-char hex digest, or ''.
	 */
	private static function manifest_checksum( array $m ) {
		if ( ! isset( $m['checksum'] ) || ! is_string( $m['checksum'] ) ) {
			return '';
		}
		$checksum = strtolower( trim( $m['checksum'] ) );
		return preg_match( '/^[a-f0-9]{64}$/', $checksum ) ? $checksum : '';
	}

	/**
	 * Public keys a release signature may verify against. A method rather
	 * than the constant so the test suite can substitute its own key pair;
	 * nothing on a live site overrides it.
	 *
	 * @return string[] Base64 Ed25519 public keys.
	 */
	protected static function trusted_keys() {
		return array( self::SIGNING_KEY );
	}

	/**
	 * The exact bytes a release signature covers.
	 *
	 * @param string $version Version the manifest advertises.
	 * @param string $sha256  Lowercase hex sha256 of the zip.
	 * @return string
	 */
	public static function signature_message( $version, $sha256 ) {
		return self::SIGNATURE_CONTEXT . "\n" . self::SIGNATURE_SLUG . "\n" . $version . "\n" . $sha256;
	}

	/**
	 * Whether the manifest carries a version, a usable checksum, and a
	 * signature over them from a trusted key. Says nothing about the zip
	 * itself: verify_download() hashes the bytes it actually downloaded.
	 *
	 * @param array $m Decoded manifest.
	 * @return bool
	 */
	protected static function manifest_is_signed( array $m ) {
		$checksum = self::manifest_checksum( $m );
		if ( '' === $checksum || empty( $m['version'] ) || ! is_string( $m['version'] ) || empty( $m['signature'] ) || ! is_string( $m['signature'] ) ) {
			return false;
		}
		return static::signature_is_valid( self::signature_message( $m['version'], $checksum ), $m['signature'] );
	}

	/**
	 * Verify a detached Ed25519 signature against the trusted keys. WordPress
	 * ships sodium_compat, so this works on hosts without ext-sodium.
	 *
	 * @param string $message   Signed message.
	 * @param string $signature Base64 signature.
	 * @return bool
	 */
	protected static function signature_is_valid( $message, $signature ) {
		if ( ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
			return false;
		}
		$sig = base64_decode( trim( (string) $signature ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decoding a published signature, not obfuscated code.
		if ( false === $sig || 64 !== strlen( $sig ) ) {
			return false;
		}
		foreach ( static::trusted_keys() as $b64 ) {
			$key = base64_decode( (string) $b64, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decoding a public key.
			if ( false === $key || 32 !== strlen( $key ) ) {
				continue;
			}
			try {
				if ( sodium_crypto_sign_verify_detached( $sig, $message, $key ) ) {
					return true;
				}
			} catch ( Throwable $e ) {
				continue;
			}
		}
		return false;
	}

	/**
	 * Whether a URL names one of our release zips on the update host
	 * (perdita-core-<version>.zip), whatever the manifest currently says.
	 *
	 * @param string $url Package URL.
	 * @return bool
	 */
	private static function is_our_package( $url ) {
		if ( ! self::package_url_is_pinned( $url ) ) {
			return false;
		}
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		return (bool) preg_match( '#/perdita-core-[0-9][^/]*\.zip$#', $path );
	}

	/**
	 * Background auto-updates are OFF by default here, the opposite of the
	 * theme's default.
	 *
	 * A theme update changes presentation. A plugin update in this codebase
	 * can change what runs on a login form, what an SMTP route does, and what
	 * an MCP endpoint exposes, so the site owner picks the moment. Flip it
	 * with the 'perdita_core_auto_update' filter, or per site from the
	 * Plugins screen's own auto-update column.
	 *
	 * @param bool|null $update Whether to auto-update.
	 * @param object    $item   Update offer.
	 * @return bool|null
	 */
	public function auto_update( $update, $item ) {
		if ( is_object( $item ) && isset( $item->plugin ) && $this->basename === $item->plugin ) {
			return (bool) apply_filters( 'perdita_core_auto_update', false );
		}
		return $update;
	}

	/**
	 * Drop the cached manifest after any upgrade so the next check is fresh.
	 */
	public function flush() {
		delete_transient( self::CACHE );
	}

	/**
	 * Verify the downloaded package against the manifest's checksum before
	 * WordPress installs it. Hooked to 'upgrader_pre_download' so the package
	 * is checked as it is fetched rather than after it is already unzipped.
	 *
	 * This re-reads the manifest instead of consulting anything check() left
	 * on the instance. check() runs on 'pre_set_site_transient_update_plugins',
	 * which fires when WordPress REFRESHES the update transient; the request
	 * that actually performs the upgrade reads that already-stored transient
	 * and never calls check() at all. A request-scoped "pending checksum"
	 * would therefore always be empty here and the verification would silently
	 * never happen, which is exactly the bug the theme's updater shipped with
	 * before this pattern was fixed. manifest() is transient-cached (12
	 * hours), so re-reading it here normally costs one get_transient(), and
	 * the HTTPS + same-host pin is re-applied to the manifest's URL exactly as
	 * check() applies it before offering anything.
	 *
	 * @param bool|WP_Error $reply    False (the default) to let WordPress
	 *                                handle the download normally.
	 * @param string        $package  Package URL being downloaded.
	 * @param WP_Upgrader   $upgrader Upgrader instance (unused).
	 * @return bool|WP_Error|string False to defer to core, a local temp file
	 *                              path on a verified download, or a WP_Error
	 *                              to abort the update on a checksum mismatch.
	 */
	public function verify_download( $reply, $package, $upgrader ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		if ( false !== $reply || ! is_string( $package ) || '' === $package ) {
			return $reply;
		}

		$m = $this->manifest();
		if ( empty( $m['download_url'] ) || ! is_string( $m['download_url'] ) ) {
			// No usable manifest on the request that performs the upgrade.
			// If the package is ours (same pinned host) that is not a reason
			// to install it unverified: fail closed and let the owner retry.
			if ( self::package_url_is_pinned( $package ) ) {
				return new WP_Error(
					'perdita_core_manifest_unavailable',
					__( 'The update manifest could not be fetched, so this package could not be verified. Nothing was installed. Try again in a few minutes.', 'perdita-core' )
				);
			}
			return $reply;
		}

		// Same pin check as check(): a manifest that has been tampered with
		// to point somewhere else is not something we verify a checksum for,
		// it is something we decline to recognize as our package. Our own
		// zip still cannot be installed against such a manifest, though.
		if ( ! self::package_url_is_pinned( $m['download_url'] ) ) {
			if ( self::is_our_package( $package ) ) {
				return new WP_Error(
					'perdita_core_signature_invalid',
					__( 'The Perdita Core update is not signed with the Perdita release key, so it was not installed. Please try again later. If it keeps happening, check perdita.ericrosenberg.com directly before updating.', 'perdita-core' )
				);
			}
			return $reply;
		}

		// Only ever intercept our own package. Every other plugin and theme
		// update running in this same request must fall through to core.
		// One of our own zips that is NOT the one the manifest names now
		// (an update offered before a newer release, or a stored offer that
		// was tampered with) cannot be verified, so it is refused.
		if ( $package !== $m['download_url'] ) {
			if ( self::is_our_package( $package ) ) {
				return new WP_Error(
					'perdita_core_package_superseded',
					__( 'This Perdita Core update is no longer the one the update server offers, so it could not be verified. Nothing was installed. Check for updates again, then retry.', 'perdita-core' )
				);
			}
			return $reply;
		}

		// Fail closed without a signed manifest. An unsigned manifest for
		// our own package means a publishing mistake or a tampered server.
		$checksum = self::manifest_checksum( $m );
		if ( ! static::manifest_is_signed( $m ) ) {
			return new WP_Error(
				'perdita_core_signature_invalid',
				__( 'The Perdita Core update is not signed with the Perdita release key, so it was not installed. Please try again later. If it keeps happening, check perdita.ericrosenberg.com directly before updating.', 'perdita-core' )
			);
		}

		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$tmp = download_url( $package, 300 );
		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}
		$actual = hash_file( 'sha256', $tmp );
		if ( ! is_string( $actual ) || ! hash_equals( $checksum, $actual ) ) {
			wp_delete_file( $tmp );
			return new WP_Error(
				'perdita_core_checksum_mismatch',
				__( 'The Perdita Core update package failed checksum verification and was not installed. This can mean the download was corrupted, or that the update server or connection was tampered with. Please try again later. If it keeps happening, check perdita.ericrosenberg.com directly before updating.', 'perdita-core' )
			);
		}
		return $tmp;
	}
}
