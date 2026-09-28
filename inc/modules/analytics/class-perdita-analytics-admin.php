<?php
/**
 * Analytics module admin: the settings screen.
 *
 * A submenu under Perdita for the GA4 measurement ID, the consent model, and
 * the consent banner's copy, color and behavior. Saving goes through
 * admin-post.php with a capability check and a nonce. Inputs are sanitized on
 * write and every value is escaped on output. Also the notice that tells the
 * site owner Simple Consent Manager can be deactivated.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

class Perdita_Analytics_Admin {

	/**
	 * The main module instance.
	 *
	 * @var Perdita_Analytics
	 */
	private $main;

	/**
	 * The Perdita core singleton.
	 *
	 * @var Perdita
	 */
	private $core;

	/**
	 * admin-post action for saving.
	 */
	const ACTION = 'perdita_analytics_save';

	/**
	 * Menu slug for the settings page.
	 */
	const PAGE = 'perdita-analytics';

	/**
	 * Constructor.
	 *
	 * @param Perdita_Analytics $main Main module.
	 * @param Perdita           $core Core.
	 */
	public function __construct( $main, $core ) {
		$this->main = $main;
		$this->core = $core;

		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'save' ) );
		add_action( 'admin_notices', array( $this, 'scm_notice' ) );
	}

	/**
	 * Tell the site owner where Simple Consent Manager stands. When this
	 * module's consent layer runs, SCM's hooks are switched off on the front
	 * end (Perdita_Analytics::take_over_scm()) and SCM can be deactivated. When
	 * it does not, SCM is still the one doing the work, which the settings
	 * screen says.
	 */
	public function scm_notice() {
		if ( ! current_user_can( 'activate_plugins' ) || ! Perdita_Analytics::scm_active() ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$id     = $screen ? (string) $screen->id : '';
		$ours   = false !== strpos( $id, self::PAGE );
		if ( ! $ours && ! in_array( $id, array( 'plugins', 'dashboard' ), true ) ) {
			return;
		}
		$settings_url = admin_url( 'admin.php?page=' . self::PAGE );
		if ( $this->main->consent_active() ) {
			?>
			<div class="notice notice-info">
				<p>
					<?php esc_html_e( 'Perdita Core now handles cookie consent, so the Simple Consent Manager banner and consent defaults are switched off. You can deactivate Simple Consent Manager. Visitors who already made a choice keep it.', 'perdita-core' ); ?>
					<?php if ( ! $ours ) : ?>
						<a href="<?php echo esc_url( $settings_url ); ?>"><?php esc_html_e( 'Consent settings', 'perdita-core' ); ?></a>
					<?php endif; ?>
				</p>
			</div>
			<?php
		} elseif ( $ours ) {
			?>
			<div class="notice notice-info">
				<p><?php esc_html_e( 'Simple Consent Manager is still handling cookie consent on this site. To have Perdita take over, add a measurement ID or turn on "Manage consent for other Google tags" below. Then you can deactivate Simple Consent Manager.', 'perdita-core' ); ?></p>
			</div>
			<?php
		}
	}

	/**
	 * Register the settings submenu under Perdita.
	 */
	public function menu() {
		add_submenu_page(
			'perdita',
			__( 'Analytics', 'perdita-core' ),
			__( 'Analytics', 'perdita-core' ),
			'manage_options',
			self::PAGE,
			array( $this, 'render' )
		);
	}

	/**
	 * Handle the save. Capability + nonce, sanitize, store, redirect back.
	 */
	public function save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'perdita-core' ) );
		}
		check_admin_referer( self::ACTION );

		$raw   = isset( $_POST['perdita_analytics'] ) ? wp_unslash( $_POST['perdita_analytics'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized in Perdita_Analytics::sanitize().
		$clean = Perdita_Analytics::sanitize( $raw );
		update_option( Perdita_Analytics::OPTION, $clean );

		// Flag whether the submitted ID or color was rejected as malformed, so we
		// can warn the user rather than silently dropping it.
		$submitted_id    = isset( $raw['measurement_id'] ) ? trim( (string) $raw['measurement_id'] ) : '';
		$bad_id          = ( '' !== $submitted_id && '' === $clean['measurement_id'] ) ? '1' : '0';
		$submitted_color = isset( $raw['accent_color'] ) && is_string( $raw['accent_color'] ) ? trim( $raw['accent_color'] ) : '';
		$bad_color       = ( '' !== $submitted_color && '' === $clean['accent_color'] ) ? '1' : '0';

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'              => self::PAGE,
					'perdita_saved'     => '1',
					'perdita_bad_id'    => $bad_id,
					'perdita_bad_color' => $bad_color,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Render the settings page.
	 */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s = $this->main->get_settings();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only notice flags, no state change.
		$saved     = isset( $_GET['perdita_saved'] ) && '1' === $_GET['perdita_saved'];
		$bad_id    = isset( $_GET['perdita_bad_id'] ) && '1' === $_GET['perdita_bad_id'];
		$bad_color = isset( $_GET['perdita_bad_color'] ) && '1' === $_GET['perdita_bad_color'];
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$privacy      = Perdita_Analytics::privacy_url();
		$scm_fallback = '' === Perdita_Analytics::sanitize_accent_color( $s['accent_color'] ) ? $this->main->accent_color() : '';
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Analytics', 'perdita-core' ); ?></h1>

			<?php if ( $saved && ! $bad_id && ! $bad_color ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'perdita-core' ); ?></p></div>
			<?php endif; ?>
			<?php if ( $bad_id ) : ?>
				<div class="notice notice-warning is-dismissible"><p><?php esc_html_e( 'Settings saved, but the measurement ID was not in the expected G-XXXXXXX shape, so it was cleared. Tracking stays off until you enter a valid ID.', 'perdita-core' ); ?></p></div>
			<?php endif; ?>
			<?php if ( $bad_color ) : ?>
				<div class="notice notice-warning is-dismissible"><p><?php esc_html_e( 'Settings saved, but the accent color was not a hex color like #2e6b29, so it was cleared.', 'perdita-core' ); ?></p></div>
			<?php endif; ?>

			<p class="description" style="max-width:720px;">
				<?php esc_html_e( 'Add Google Analytics 4 and a cookie banner that sets Google Consent Mode v2. Opt-in measures nothing until a visitor agrees. Opt-out measures until a visitor opts out. Global Privacy Control always turns measurement off. The banner helps you comply with privacy laws like GDPR and CCPA. It does not by itself make your site compliant, so review your own legal obligations.', 'perdita-core' ); ?>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>" />
				<?php wp_nonce_field( self::ACTION ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="perdita-analytics-id"><?php esc_html_e( 'GA4 Measurement ID', 'perdita-core' ); ?></label>
						</th>
						<td>
							<input
								type="text"
								id="perdita-analytics-id"
								name="perdita_analytics[measurement_id]"
								class="regular-text"
								value="<?php echo esc_attr( $s['measurement_id'] ); ?>"
								placeholder="G-XXXXXXX"
								pattern="G-[A-Za-z0-9]+"
								aria-describedby="perdita-analytics-id-help"
							/>
							<p class="description" id="perdita-analytics-id-help"><?php esc_html_e( 'Find this in Google Analytics under Admin, Data Streams. It looks like G-XXXXXXX. Leave blank if another plugin (Site Kit, for example) adds your Google tag.', 'perdita-core' ); ?></p>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'Other Google tags', 'perdita-core' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="perdita_analytics[manage_without_id]" value="1" <?php checked( ! empty( $s['manage_without_id'] ) ); ?> />
								<?php esc_html_e( 'Manage consent for other Google tags', 'perdita-core' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Sets the consent defaults and shows the banner even with no measurement ID above, for Google tags another plugin or your theme adds. Perdita does not load Google\'s script in that case.', 'perdita-core' ); ?></p>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'Consent model', 'perdita-core' ); ?></th>
						<td>
							<fieldset>
								<legend class="screen-reader-text"><?php esc_html_e( 'Consent model', 'perdita-core' ); ?></legend>
								<label>
									<input type="radio" name="perdita_analytics[consent_model]" value="<?php echo esc_attr( Perdita_Analytics::MODEL_OPT_IN ); ?>" <?php checked( Perdita_Analytics::MODEL_OPT_IN, $s['consent_model'] ); ?> />
									<?php esc_html_e( 'Opt-in: measure nothing until a visitor accepts', 'perdita-core' ); ?>
								</label><br />
								<label>
									<input type="radio" name="perdita_analytics[consent_model]" value="<?php echo esc_attr( Perdita_Analytics::MODEL_OPT_OUT ); ?>" <?php checked( Perdita_Analytics::MODEL_OPT_OUT, $s['consent_model'] ); ?> />
									<?php esc_html_e( 'Opt-out: measure until a visitor opts out', 'perdita-core' ); ?>
								</label>
							</fieldset>
							<p class="description"><?php esc_html_e( 'Opt-in is what the GDPR expects for visitors in the EU and UK. Opt-out matches US state laws like the CCPA. Either way, Global Privacy Control turns measurement off.', 'perdita-core' ); ?></p>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'Ad signals', 'perdita-core' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="perdita_analytics[ad_signals]" value="1" <?php checked( ! empty( $s['ad_signals'] ) ); ?> />
								<?php esc_html_e( 'Grant ad signals along with analytics', 'perdita-core' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Turns on ad_storage, ad_user_data and ad_personalization whenever analytics is on. Leave this off unless the site runs Google Ads or AdSense and your banner message says so.', 'perdita-core' ); ?></p>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'Cookie-consent banner', 'perdita-core' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="perdita_analytics[show_banner]" value="1" <?php checked( ! empty( $s['show_banner'] ) ); ?> />
								<?php esc_html_e( 'Show a cookie-consent banner', 'perdita-core' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'When on, visitors see a banner and can accept or decline analytics cookies.', 'perdita-core' ); ?></p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="perdita-analytics-message"><?php esc_html_e( 'Banner message', 'perdita-core' ); ?></label>
						</th>
						<td>
							<textarea
								id="perdita-analytics-message"
								name="perdita_analytics[banner_message]"
								class="large-text"
								rows="3"
							><?php echo esc_textarea( $s['banner_message'] ); ?></textarea>
							<p class="description">
								<?php if ( '' !== $privacy ) : ?>
									<?php
									/* translators: %s: privacy policy URL. */
									printf( esc_html__( 'A Privacy Policy link to %s follows the message.', 'perdita-core' ), '<a href="' . esc_url( $privacy ) . '">' . esc_html( $privacy ) . '</a>' );
									?>
								<?php else : ?>
									<?php esc_html_e( 'Set a privacy policy page under Settings, Privacy and the banner links to it.', 'perdita-core' ); ?>
								<?php endif; ?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="perdita-analytics-accept"><?php esc_html_e( 'Accept button label', 'perdita-core' ); ?></label>
						</th>
						<td>
							<input type="text" id="perdita-analytics-accept" name="perdita_analytics[accept_label]" class="regular-text" value="<?php echo esc_attr( $s['accept_label'] ); ?>" />
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="perdita-analytics-decline"><?php esc_html_e( 'Decline button label', 'perdita-core' ); ?></label>
						</th>
						<td>
							<input type="text" id="perdita-analytics-decline" name="perdita_analytics[decline_label]" class="regular-text" value="<?php echo esc_attr( $s['decline_label'] ); ?>" />
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="perdita-analytics-accent"><?php esc_html_e( 'Accent color', 'perdita-core' ); ?></label>
						</th>
						<td>
							<input
								type="text"
								id="perdita-analytics-accent"
								name="perdita_analytics[accent_color]"
								class="small-text"
								style="width:8em;"
								value="<?php echo esc_attr( $s['accent_color'] ); ?>"
								placeholder="<?php echo esc_attr( '' !== $scm_fallback ? $scm_fallback : '#2563eb' ); ?>"
								pattern="#([0-9A-Fa-f]{3}|[0-9A-Fa-f]{6})"
								aria-describedby="perdita-analytics-accent-help"
							/>
							<p class="description" id="perdita-analytics-accent-help">
								<?php esc_html_e( 'A hex color like #2e6b29 for the Accept button and the focus ring. Leave blank for the default.', 'perdita-core' ); ?>
								<?php if ( '' !== $scm_fallback ) : ?>
									<?php
									/* translators: %s: hex color. */
									printf( esc_html__( 'Blank uses %s from the SCM_ACCENT_COLOR constant.', 'perdita-core' ), '<code>' . esc_html( $scm_fallback ) . '</code>' );
									?>
								<?php endif; ?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'Cookie Preferences link', 'perdita-core' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="perdita_analytics[show_preferences_link]" value="1" <?php checked( ! empty( $s['show_preferences_link'] ) ); ?> />
								<?php esc_html_e( 'Show a small button that reopens the banner after a visitor chooses', 'perdita-core' ); ?>
							</label>
							<p>
								<label for="perdita-analytics-prefs-label"><?php esc_html_e( 'Label', 'perdita-core' ); ?></label>
								<input type="text" id="perdita-analytics-prefs-label" name="perdita_analytics[preferences_label]" class="regular-text" value="<?php echo esc_attr( $s['preferences_label'] ); ?>" />
							</p>
							<p class="description">
								<?php
								printf(
									/* translators: 1: shortcode, 2: link URL. */
									esc_html__( 'To put the link in your footer instead, use the %1$s shortcode, or add a menu link to %2$s.', 'perdita-core' ),
									'<code>[perdita_cookie_preferences]</code>',
									'<code>' . esc_html( Perdita_Analytics::PREFERENCES_ANCHOR ) . '</code>'
								);
								?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'Do Not Track', 'perdita-core' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="perdita_analytics[respect_dnt]" value="1" <?php checked( ! empty( $s['respect_dnt'] ) ); ?> />
								<?php esc_html_e( 'Respect Do Not Track', 'perdita-core' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'When a browser sends a Do Not Track signal, keep tracking off and do not show the banner. A Global Privacy Control signal always does both.', 'perdita-core' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save changes', 'perdita-core' ) ); ?>
			</form>
		</div>
		<?php
	}
}
