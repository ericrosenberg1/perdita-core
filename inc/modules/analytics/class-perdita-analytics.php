<?php
/**
 * Analytics module: Google Analytics 4 with Consent Mode v2.
 *
 * Tracking is consent-gated. Before gtag.js loads, consent defaults to denied
 * for every storage type. Nothing is measured until the visitor agrees, either
 * from a stored choice in the perdita_consent cookie or by clicking Accept on
 * the banner. If the browser sends Do Not Track and the site respects it,
 * consent stays denied and the banner is not shown.
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

		// Front-end only. The admin context loads the admin class instead.
		if ( ! is_admin() ) {
			// Consent Mode v2 default + gtag bootstrap must run before gtag.js,
			// so print it very early in the head.
			add_action( 'wp_head', array( $this, 'print_consent_bootstrap' ), 1 );
			add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
			add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_gtag' ) );
			add_action( 'wp_footer', array( $this, 'print_banner' ) );
		}
	}

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'measurement_id'  => '',
			'show_banner'     => true,
			'banner_message'  => __( 'We use cookies to understand how visitors use this site. You can accept or decline analytics cookies. This choice helps you comply with privacy laws, and it is up to you.', 'perdita-core' ),
			'accept_label'    => __( 'Accept', 'perdita-core' ),
			'decline_label'   => __( 'Decline', 'perdita-core' ),
			'respect_dnt'     => true,
		);
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
		return array_merge( self::defaults(), $stored );
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
	 * The configured measurement ID if it is valid, otherwise an empty string.
	 *
	 * @return string
	 */
	public function measurement_id() {
		$id = $this->get_settings()['measurement_id'];
		return self::is_valid_measurement_id( $id ) ? strtoupper( $id ) : '';
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

		return array(
			'measurement_id' => $id,
			'show_banner'    => ! empty( $input['show_banner'] ),
			'banner_message' => isset( $input['banner_message'] ) ? sanitize_textarea_field( (string) $input['banner_message'] ) : $defaults['banner_message'],
			'accept_label'   => isset( $input['accept_label'] ) && '' !== trim( (string) $input['accept_label'] ) ? sanitize_text_field( (string) $input['accept_label'] ) : $defaults['accept_label'],
			'decline_label'  => isset( $input['decline_label'] ) && '' !== trim( (string) $input['decline_label'] ) ? sanitize_text_field( (string) $input['decline_label'] ) : $defaults['decline_label'],
			'respect_dnt'    => ! empty( $input['respect_dnt'] ),
		);
	}

	/* ---------- front-end output ---------- */

	/**
	 * Print the Consent Mode v2 bootstrap and load gtag.js.
	 *
	 * Order matters. We set consent defaults to denied BEFORE gtag.js loads, so
	 * no measurement happens until consent is granted. A small inline reader then
	 * grants consent immediately if the visitor chose Accept on a prior visit.
	 * Do Not Track, when respected, keeps everything denied.
	 */
	public function print_consent_bootstrap() {
		$id = $this->measurement_id();
		if ( '' === $id ) {
			return;
		}

		$settings    = $this->get_settings();
		$respect_dnt = ! empty( $settings['respect_dnt'] );

		// $id is validated to /^G-[A-Z0-9]+$/i and upper-cased, so it is safe to
		// print. esc_js is still applied as defense in depth.
		$id_js  = esc_js( $id );
		$dnt_js = $respect_dnt ? 'true' : 'false';
		?>
<!-- Perdita Analytics: Google Consent Mode v2 -->
<script>
window.dataLayer = window.dataLayer || [];
function gtag(){dataLayer.push(arguments);}
gtag('consent', 'default', {
	'analytics_storage': 'denied',
	'ad_storage': 'denied',
	'ad_user_data': 'denied',
	'ad_personalization': 'denied'
});
gtag('js', new Date());
gtag('config', '<?php echo $id_js; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- validated GA4 id, esc_js applied. ?>');
(function () {
	var respectDnt = <?php echo $dnt_js; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- literal true/false. ?>;
	var dntOn = respectDnt && (navigator.doNotTrack === '1' || window.doNotTrack === '1' || navigator.msDoNotTrack === '1');
	if (dntOn) {
		document.documentElement.setAttribute('data-perdita-consent', 'dnt');
		return;
	}
	var m = document.cookie.match(/(?:^|;\s*)perdita_consent=([^;]+)/);
	var choice = m ? decodeURIComponent(m[1]) : '';
	if (choice === 'granted') {
		gtag('consent', 'update', {
			'analytics_storage': 'granted',
			'ad_storage': 'granted',
			'ad_user_data': 'granted',
			'ad_personalization': 'granted'
		});
	}
	document.documentElement.setAttribute('data-perdita-consent', choice || 'unset');
})();
</script>
<!-- /Perdita Analytics (gtag.js itself is enqueued, see enqueue_gtag()) -->
		<?php
	}

	/**
	 * Enqueue gtag.js. It is registered through the script API (not printed as
	 * a raw tag) so plugins that manage consent or defer scripts can see it,
	 * and it prints in the head after the consent bootstrap above, which is
	 * hooked at wp_head priority 1. Google requires the loader to come from
	 * its own host, which is why the source is remote.
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
	 * Enqueue the banner CSS and JS. Only on the front end and only when a valid
	 * measurement ID is set and the banner is enabled.
	 */
	public function enqueue_assets() {
		if ( '' === $this->measurement_id() ) {
			return;
		}
		$settings = $this->get_settings();
		if ( empty( $settings['show_banner'] ) ) {
			return;
		}

		wp_enqueue_style(
			'perdita-analytics-banner',
			PERDITA_CORE_URL . 'inc/modules/analytics/assets/banner.css',
			array(),
			PERDITA_CORE_VERSION
		);
		wp_enqueue_script(
			'perdita-analytics-banner',
			PERDITA_CORE_URL . 'inc/modules/analytics/assets/banner.js',
			array(),
			PERDITA_CORE_VERSION,
			true
		);
	}

	/**
	 * Print the consent banner in the footer.
	 *
	 * Rendered only when the banner is enabled, a valid ID is set, and no choice
	 * is stored yet. The banner starts hidden and the JS reveals it after
	 * confirming the cookie is unset and Do Not Track is not blocking, so it
	 * never flashes for visitors who already chose.
	 */
	public function print_banner() {
		if ( '' === $this->measurement_id() ) {
			return;
		}
		$settings = $this->get_settings();
		if ( empty( $settings['show_banner'] ) ) {
			return;
		}

		$message = $settings['banner_message'];
		$accept  = $settings['accept_label'];
		$decline = $settings['decline_label'];
		?>
<div id="perdita-consent-banner" class="perdita-consent-banner" role="region" aria-label="<?php esc_attr_e( 'Cookie consent', 'perdita-core' ); ?>" hidden>
	<p class="perdita-consent-banner__message"><?php echo esc_html( $message ); ?></p>
	<div class="perdita-consent-banner__actions">
		<button type="button" class="perdita-consent-banner__btn perdita-consent-banner__btn--decline" data-perdita-consent="denied"><?php echo esc_html( $decline ); ?></button>
		<button type="button" class="perdita-consent-banner__btn perdita-consent-banner__btn--accept" data-perdita-consent="granted"><?php echo esc_html( $accept ); ?></button>
	</div>
</div>
		<?php
	}
}
