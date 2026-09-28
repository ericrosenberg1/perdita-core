<?php
/**
 * Analytics module: Google Analytics 4 and cookie consent with Consent Mode v2.
 *
 * Two consent models. Opt-in (the default): every storage type starts denied,
 * and analytics storage turns on only when the visitor agrees, from a stored
 * choice in the perdita_consent cookie (applied before the page_view goes
 * out) or by clicking Accept on the banner (which then resends that page's
 * page_view). Opt-out: analytics storage starts granted and the banner offers
 * an opt out. In both, Global Privacy Control always keeps analytics off, and
 * so does Do Not Track when the site respects it, and then the banner is not
 * shown. Ad signals follow analytics only when the site turns them on.
 *
 * The consent layer runs for Perdita's own GA4 tag, or, with "manage consent
 * for other Google tags" on, for any gtag on the page (Site Kit, a hand-added
 * tag) with no measurement ID set here. It replaces the standalone Simple
 * Consent Manager plugin: its scm_consent cookie is honored, its message
 * filter still applies, and when both are active this module switches SCM's
 * hooks off so the page never carries two banners or two consent defaults.
 *
 * Consent state is read in the browser, never in PHP. Pages are served from
 * page caches, so a server-side cookie check would hand one visitor's choice
 * to the next.
 *
 * This class holds the settings contract (defaults, read, sanitize, validate)
 * and every front-end hook. The admin screen lives in the admin class.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

class Perdita_Analytics {

	/**
	 * Option name for stored settings.
	 */
	const OPTION = 'perdita_analytics_settings';

	/**
	 * Consent cookie name.
	 */
	const COOKIE = 'perdita_consent';

	/**
	 * Simple Consent Manager's cookie, honored when perdita_consent is unset.
	 */
	const SCM_COOKIE = 'scm_consent';

	/**
	 * Consent models.
	 */
	const MODEL_OPT_IN  = 'opt_in';
	const MODEL_OPT_OUT = 'opt_out';

	/**
	 * Fragment that reopens the banner from any link, e.g. a footer menu item.
	 */
	const PREFERENCES_ANCHOR = '#perdita-cookie-preferences';

	/**
	 * The wp_head priority of the consent bootstrap. 0, like Simple Consent
	 * Manager, so consent has its default before any tag another plugin prints
	 * in the head, and well before wp_print_head_scripts (priority 9).
	 */
	const HEAD_PRIORITY = 0;

	/**
	 * Earlier default banner messages, replaced by the current default on read.
	 */
	const LEGACY_BANNER_MESSAGES = array(
		'We use cookies to understand how visitors use this site. You can accept or decline analytics cookies. This choice helps you comply with privacy laws, and it is up to you.',
	);

	/**
	 * The Perdita core singleton.
	 *
	 * @var Perdita
	 */
	private $core;

	/**
	 * Constructor. Register front-end hooks.
	 *
	 * @param Perdita $core Core.
	 */
	public function __construct( $core ) {
		$this->core = $core;

		self::maybe_adopt_scm();

		// Front-end only. The admin context loads the admin class instead.
		if ( ! is_admin() ) {
			if ( $this->consent_active() ) {
				self::take_over_scm();
			}
			// Consent Mode v2 default + gtag bootstrap must run before gtag.js
			// and before any other plugin's Google tag, so print it very early
			// in the head.
			add_action( 'wp_head', array( $this, 'print_consent_bootstrap' ), self::HEAD_PRIORITY );
			add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
			add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_gtag' ) );
			add_action( 'wp_footer', array( $this, 'print_banner' ) );
			add_shortcode( 'perdita_cookie_preferences', array( $this, 'shortcode_preferences' ) );
		}
	}

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'measurement_id'        => '',
			'consent_model'         => self::MODEL_OPT_IN,
			'manage_without_id'     => false,
			'ad_signals'            => false,
			'show_banner'           => true,
			'banner_message'        => self::default_message( self::MODEL_OPT_IN ),
			'accept_label'          => __( 'Accept', 'perdita-core' ),
			'decline_label'         => self::default_decline_label( self::MODEL_OPT_IN ),
			'show_preferences_link' => true,
			'preferences_label'     => __( 'Cookie Preferences', 'perdita-core' ),
			'accent_color'          => '',
			'respect_dnt'           => true,
		);
	}

	/**
	 * The default banner message for a consent model.
	 *
	 * @param string $model Consent model.
	 * @return string
	 */
	public static function default_message( $model ) {
		if ( self::MODEL_OPT_OUT === $model ) {
			return __( 'We use cookies to see how the site is used. You can opt out anytime.', 'perdita-core' );
		}
		return __( 'We use cookies to understand how visitors use this site. You can accept or decline analytics cookies.', 'perdita-core' );
	}

	/**
	 * The default decline button label for a consent model.
	 *
	 * @param string $model Consent model.
	 * @return string
	 */
	public static function default_decline_label( $model ) {
		return self::MODEL_OPT_OUT === $model ? __( 'Opt out', 'perdita-core' ) : __( 'Decline', 'perdita-core' );
	}

	/**
	 * Read stored settings merged over defaults.
	 *
	 * @return array
	 */
	public function get_settings() {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		// Sites that saved the settings screen before 0.19.3-alpha stored the old
		// default message, whose last sentence spoke to the site owner, not the
		// visitor. An unedited copy of it reads as the current default.
		if ( isset( $stored['banner_message'] ) && in_array( trim( (string) $stored['banner_message'] ), self::LEGACY_BANNER_MESSAGES, true ) ) {
			unset( $stored['banner_message'] );
		}
		$s = array_merge( self::defaults(), $stored );

		$s['consent_model'] = self::MODEL_OPT_OUT === $s['consent_model'] ? self::MODEL_OPT_OUT : self::MODEL_OPT_IN;

		// The message and decline label default per model. A stored copy of
		// either model's unedited default follows the current model, so
		// switching models never leaves "Decline" on an opt-out banner.
		$models = array( self::MODEL_OPT_IN, self::MODEL_OPT_OUT );
		foreach ( $models as $m ) {
			if ( trim( (string) $s['banner_message'] ) === self::default_message( $m ) ) {
				$s['banner_message'] = self::default_message( $s['consent_model'] );
			}
			if ( trim( (string) $s['decline_label'] ) === self::default_decline_label( $m ) ) {
				$s['decline_label'] = self::default_decline_label( $s['consent_model'] );
			}
		}
		return $s;
	}

	/**
	 * Whether a string is a validly shaped GA4 measurement ID.
	 *
	 * @param string $id Candidate ID.
	 * @return bool
	 */
	public static function is_valid_measurement_id( $id ) {
		return is_string( $id ) && 1 === preg_match( '/^G-[A-Z0-9]+$/i', $id );
	}

	/**
	 * A validated hex color (#abc or #aabbcc), lower-cased, or '' for anything
	 * else. The value is printed into a style block, so nothing but a hex color
	 * may pass.
	 *
	 * @param mixed $color Candidate.
	 * @return string
	 */
	public static function sanitize_accent_color( $color ) {
		if ( ! is_string( $color ) ) {
			return '';
		}
		$color = trim( $color );
		return 1 === preg_match( '/^#[0-9a-fA-F]{3}(?:[0-9a-fA-F]{3})?$/', $color ) ? strtolower( $color ) : '';
	}

	/**
	 * The configured measurement ID if it is valid, otherwise an empty string.
	 *
	 * @return string
	 */
	public function measurement_id() {
		$id = $this->get_settings()['measurement_id'];
		return self::is_valid_measurement_id( $id ) ? strtoupper( $id ) : '';
	}

	/**
	 * Whether the consent layer runs on this site: for Perdita's own GA4 tag,
	 * or for other Google tags when the site asked for that. A blank ID with
	 * the setting off prints nothing, which other code relies on to switch
	 * this module off on the front end.
	 *
	 * @return bool
	 */
	public function consent_active() {
		return '' !== $this->measurement_id() || ! empty( $this->get_settings()['manage_without_id'] );
	}

	/**
	 * The banner accent color: the setting, then the SCM_ACCENT_COLOR constant
	 * a Simple Consent Manager site set in wp-config.php, then '' (the
	 * stylesheet default).
	 *
	 * @return string
	 */
	public function accent_color() {
		$color = self::sanitize_accent_color( $this->get_settings()['accent_color'] );
		if ( '' === $color && defined( 'SCM_ACCENT_COLOR' ) ) {
			$color = self::sanitize_accent_color( constant( 'SCM_ACCENT_COLOR' ) );
		}
		return $color;
	}

	/**
	 * Text color for the accept button: white or near-black, whichever
	 * contrasts more with the accent.
	 *
	 * @param string $hex Validated hex color.
	 * @return string
	 */
	public static function accent_text_color( $hex ) {
		$hex = ltrim( $hex, '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		$lum = 0.0;
		foreach ( array( 0.2126, 0.7152, 0.0722 ) as $i => $weight ) {
			$c    = hexdec( substr( $hex, $i * 2, 2 ) ) / 255;
			$c    = $c <= 0.03928 ? $c / 12.92 : pow( ( $c + 0.055 ) / 1.055, 2.4 );
			$lum += $weight * $c;
		}
		// Contrast against white is 1.05 / (L + 0.05), against #111 (L about
		// 0.0056) it is (L + 0.05) / 0.0556. Pick the larger.
		return ( 1.05 / ( $lum + 0.05 ) ) >= ( ( $lum + 0.05 ) / 0.0556 ) ? '#ffffff' : '#111111';
	}

	/**
	 * The privacy policy URL for the banner link: the page set under Settings,
	 * Privacy, else a published page at /privacy-policy/, else '' (no link).
	 * Filterable through perdita_consent_privacy_url.
	 *
	 * @return string
	 */
	public static function privacy_url() {
		$url = (string) get_privacy_policy_url();
		if ( '' === $url ) {
			$page = get_page_by_path( 'privacy-policy' );
			if ( $page instanceof WP_Post && 'publish' === $page->post_status ) {
				$url = (string) get_permalink( $page );
			}
		}
		/**
		 * Filters the privacy policy URL linked from the consent banner.
		 * Return '' to drop the link.
		 *
		 * @param string $url Privacy policy URL, or ''.
		 */
		return (string) apply_filters( 'perdita_consent_privacy_url', $url );
	}

	/**
	 * Sanitize a settings array on write. Static so the admin class can reuse it
	 * without a front-end instance.
	 *
	 * @param array $input Raw input.
	 * @return array Clean settings.
	 */
	public static function sanitize( $input ) {
		$defaults = self::defaults();
		$input    = is_array( $input ) ? $input : array();

		$id = isset( $input['measurement_id'] ) ? trim( (string) $input['measurement_id'] ) : '';
		// Keep only a validly shaped ID. Anything else is dropped to empty, so a
		// typo never ends up printed into the page.
		$id = self::is_valid_measurement_id( $id ) ? strtoupper( $id ) : '';

		$model = isset( $input['consent_model'] ) && self::MODEL_OPT_OUT === $input['consent_model'] ? self::MODEL_OPT_OUT : self::MODEL_OPT_IN;

		$text = function ( $key, $fallback ) use ( $input ) {
			return isset( $input[ $key ] ) && '' !== trim( (string) $input[ $key ] ) ? sanitize_text_field( (string) $input[ $key ] ) : $fallback;
		};

		return array(
			'measurement_id'        => $id,
			'consent_model'         => $model,
			'manage_without_id'     => ! empty( $input['manage_without_id'] ),
			'ad_signals'            => ! empty( $input['ad_signals'] ),
			'show_banner'           => ! empty( $input['show_banner'] ),
			'banner_message'        => isset( $input['banner_message'] ) ? sanitize_textarea_field( (string) $input['banner_message'] ) : self::default_message( $model ),
			'accept_label'          => $text( 'accept_label', $defaults['accept_label'] ),
			'decline_label'         => $text( 'decline_label', self::default_decline_label( $model ) ),
			'show_preferences_link' => ! empty( $input['show_preferences_link'] ),
			'preferences_label'     => $text( 'preferences_label', $defaults['preferences_label'] ),
			'accent_color'          => isset( $input['accent_color'] ) ? self::sanitize_accent_color( $input['accent_color'] ) : '',
			'respect_dnt'           => ! empty( $input['respect_dnt'] ),
		);
	}

	/* ---------- Simple Consent Manager hand-over ---------- */

	/**
	 * Whether the standalone Simple Consent Manager plugin is loaded.
	 *
	 * @return bool
	 */
	public static function scm_active() {
		/**
		 * Filters whether Simple Consent Manager counts as active.
		 *
		 * @param bool $active Whether SCM's consent function exists.
		 */
		return (bool) apply_filters( 'perdita_analytics_scm_active', function_exists( 'scm_consent_mode_script' ) );
	}

	/**
	 * Switch Simple Consent Manager's head script and footer banner off, so
	 * the page carries one consent default and one banner. Called only when
	 * this module's own consent layer is going to print.
	 *
	 * @return bool Whether SCM was active and got switched off.
	 */
	public static function take_over_scm() {
		if ( ! self::scm_active() ) {
			return false;
		}
		remove_action( 'wp_head', 'scm_consent_mode_script', 0 );
		remove_action( 'wp_footer', 'scm_consent_banner', 5 );
		return true;
	}

	/**
	 * On a site that runs Simple Consent Manager and never chose a consent
	 * model here, adopt SCM's behavior once and store it: opt-out, managing
	 * whatever Google tags are on the page, in SCM's accent color. Stored, not
	 * derived, so the site keeps it after SCM is deactivated. A site that
	 * already ran its own GA4 through this module keeps opt-in, which is what
	 * its visitors were given.
	 *
	 * @return bool Whether anything was stored.
	 */
	public static function maybe_adopt_scm() {
		if ( ! self::scm_active() ) {
			return false;
		}
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		if ( array_key_exists( 'consent_model', $stored ) && array_key_exists( 'manage_without_id', $stored ) ) {
			return false;
		}
		$had_id = isset( $stored['measurement_id'] ) && self::is_valid_measurement_id( $stored['measurement_id'] );
		if ( ! array_key_exists( 'consent_model', $stored ) ) {
			$stored['consent_model'] = $had_id ? self::MODEL_OPT_IN : self::MODEL_OPT_OUT;
		}
		if ( ! array_key_exists( 'manage_without_id', $stored ) ) {
			$stored['manage_without_id'] = true;
		}
		if ( empty( $stored['accent_color'] ) && defined( 'SCM_ACCENT_COLOR' ) ) {
			$stored['accent_color'] = self::sanitize_accent_color( constant( 'SCM_ACCENT_COLOR' ) );
		}
		return update_option( self::OPTION, $stored );
	}

	/* ---------- front-end output ---------- */

	/**
	 * The config object the bootstrap and the banner read.
	 *
	 * @return array
	 */
	public function client_config() {
		$settings = $this->get_settings();
		return array(
			// Validated to /^G-[A-Z0-9]+$/ and upper-cased by measurement_id(),
			// or '' for consent-only operation.
			'id'         => $this->measurement_id(),
			'model'      => $settings['consent_model'],
			'adSignals'  => ! empty( $settings['ad_signals'] ),
			'respectDnt' => ! empty( $settings['respect_dnt'] ),
		);
	}

	/**
	 * Print the Consent Mode v2 bootstrap (assets/consent-bootstrap.js).
	 *
	 * Order matters twice. The bootstrap runs before gtag.js and any other
	 * Google tag loads, so consent has its default before anything can
	 * measure. And a visitor's stored Accept is applied BEFORE gtag('config'),
	 * because config sends the page_view: a page_view sent while analytics
	 * storage is denied is one GA4 never reports. Global Privacy Control, and
	 * Do Not Track when the site respects it, keep analytics off.
	 */
	public function print_consent_bootstrap() {
		if ( ! $this->consent_active() ) {
			return;
		}

		echo "<!-- Perdita Analytics: Google Consent Mode v2 -->\n";
		wp_print_inline_script_tag(
			'window.perditaAnalytics = ' . wp_json_encode( $this->client_config(), JSON_HEX_TAG | JSON_HEX_AMP ) . ";\n" . self::bootstrap_js(),
			// Keep Cloudflare Rocket Loader from deferring it past the tags it gates.
			array( 'data-cfasync' => 'false' )
		);
		echo "<!-- /Perdita Analytics -->\n";
	}

	/**
	 * The bootstrap script's source, read once per request.
	 *
	 * @return string
	 */
	public static function bootstrap_js() {
		static $js = null;
		if ( null === $js ) {
			$js = (string) file_get_contents( __DIR__ . '/assets/consent-bootstrap.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A file shipped in this plugin, inlined so it runs before gtag.js.
		}
		return $js;
	}

	/**
	 * Enqueue gtag.js. It is registered through the script API (not printed as
	 * a raw tag) so plugins that manage consent or defer scripts can see it,
	 * and it prints in the head after the consent bootstrap above, which is
	 * hooked at wp_head priority 0. Google requires the loader to come from
	 * its own host, which is why the source is remote. Only with a measurement
	 * ID: consent-only operation never loads Google's script itself.
	 */
	public function enqueue_gtag() {
		$id = $this->measurement_id();
		if ( '' === $id ) {
			return;
		}
		$src = 'https://www.googletagmanager.com/gtag/js?id=' . rawurlencode( $id );
		wp_enqueue_script( 'perdita-gtag', $src, array(), null, array( 'in_footer' => false, 'strategy' => 'async' ) ); // phpcs:ignore PluginCheck.CodeAnalysis.EnqueuedResourceOffloading.OffloadedContent, WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Google's loader must be served by Google; it is unversioned by design.
	}

	/**
	 * Whether the banner prints on this site.
	 *
	 * @return bool
	 */
	private function banner_enabled() {
		return $this->consent_active() && ! empty( $this->get_settings()['show_banner'] );
	}

	/**
	 * Enqueue the banner CSS and JS, plus the accent color when one is set.
	 */
	public function enqueue_assets() {
		if ( ! $this->banner_enabled() ) {
			return;
		}

		wp_enqueue_style(
			'perdita-analytics-banner',
			PERDITA_CORE_URL . 'inc/modules/analytics/assets/banner.css',
			array(),
			PERDITA_CORE_VERSION
		);
		$accent = $this->accent_color();
		if ( '' !== $accent ) {
			// Both values are validated hex colors (sanitize_accent_color() and
			// a fixed pair), so nothing else can reach this style block.
			wp_add_inline_style(
				'perdita-analytics-banner',
				'.perdita-consent-banner,.perdita-consent-prefs{--perdita-consent-accent:' . $accent . ';--perdita-consent-accent-text:' . self::accent_text_color( $accent ) . ';}'
			);
		}
		wp_enqueue_script(
			'perdita-analytics-banner',
			PERDITA_CORE_URL . 'inc/modules/analytics/assets/banner.js',
			array(),
			PERDITA_CORE_VERSION,
			true
		);
	}

	/**
	 * The banner message, after the perdita_consent_message filter and, for
	 * sites moving over from Simple Consent Manager, its scm_consent_message
	 * filter.
	 *
	 * @param array $settings Settings.
	 * @return string
	 */
	public static function banner_message( $settings ) {
		/**
		 * Filters the consent banner message.
		 *
		 * @param string $message Message from the settings screen.
		 * @param string $model   Consent model, opt_in or opt_out.
		 */
		$message = (string) apply_filters( 'perdita_consent_message', (string) $settings['banner_message'], $settings['consent_model'] );
		/**
		 * Simple Consent Manager's message filter, kept so a site that set its
		 * wording there keeps it after the switch.
		 *
		 * @param string $message Consent banner message.
		 */
		return (string) apply_filters( 'scm_consent_message', $message );
	}

	/**
	 * Print the consent banner and the Cookie Preferences button in the footer.
	 *
	 * Both start hidden, whatever the visitor's cookie says: the page may come
	 * from a page cache, so the JS decides what shows. The banner shows when
	 * no choice is stored and no privacy signal blocks it, the preferences
	 * button when a choice is stored.
	 */
	public function print_banner() {
		if ( ! $this->banner_enabled() ) {
			return;
		}
		$settings = $this->get_settings();
		$message  = self::banner_message( $settings );
		// Escaped first, so a URL esc_url() refuses (javascript:, say) drops
		// the link instead of printing an empty href.
		$privacy = esc_url( self::privacy_url() );
		$link    = '' === $privacy ? '' : ' <a class="perdita-consent-banner__link" href="' . $privacy . '">' . esc_html__( 'Privacy Policy', 'perdita-core' ) . '</a>';
		?>
<div id="perdita-consent-banner" class="perdita-consent-banner" role="region" aria-label="<?php esc_attr_e( 'Cookie consent', 'perdita-core' ); ?>" hidden>
	<p class="perdita-consent-banner__message"><?php echo esc_html( $message ) . $link; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $link is built from esc_url() and esc_html__() above. ?></p>
	<div class="perdita-consent-banner__actions">
		<button type="button" class="perdita-consent-banner__btn perdita-consent-banner__btn--decline" data-perdita-consent="denied"><?php echo esc_html( $settings['decline_label'] ); ?></button>
		<button type="button" class="perdita-consent-banner__btn perdita-consent-banner__btn--accept" data-perdita-consent="granted"><?php echo esc_html( $settings['accept_label'] ); ?></button>
	</div>
</div>
		<?php if ( ! empty( $settings['show_preferences_link'] ) ) : ?>
<button type="button" id="perdita-consent-prefs" class="perdita-consent-prefs" hidden><?php echo esc_html( $settings['preferences_label'] ); ?></button>
		<?php endif; ?>
		<?php
	}

	/**
	 * [perdita_cookie_preferences label="..."]: a link that reopens the
	 * consent banner. Any link to #perdita-cookie-preferences does the same,
	 * so a footer menu item works too.
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public function shortcode_preferences( $atts ) {
		if ( ! $this->banner_enabled() ) {
			return '';
		}
		$settings = $this->get_settings();
		$atts     = shortcode_atts( array( 'label' => $settings['preferences_label'] ), $atts, 'perdita_cookie_preferences' );
		return '<a href="' . esc_attr( self::PREFERENCES_ANCHOR ) . '" class="perdita-cookie-preferences">' . esc_html( $atts['label'] ) . '</a>';
	}
}
