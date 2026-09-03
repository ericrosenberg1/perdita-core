<?php
/**
 * Analytics module admin: the settings screen.
 *
 * A submenu under Perdita for the GA4 measurement ID and the consent-banner
 * copy and behavior. Saving goes through admin-post.php with a capability
 * check and a nonce. Inputs are sanitized on write and every value is escaped
 * on output.
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

		$raw = isset( $_POST['perdita_analytics'] ) ? wp_unslash( $_POST['perdita_analytics'] ) : array(); // phpcs:ignore WordPress.Security.ValidationSanitization.InputNotSanitized -- sanitized in Perdita_Analytics::sanitize().
		$clean = Perdita_Analytics::sanitize( $raw );
		update_option( Perdita_Analytics::OPTION, $clean );

		// Flag whether the submitted ID was rejected as malformed, so we can warn
		// the user rather than silently dropping it.
		$submitted_id = isset( $raw['measurement_id'] ) ? trim( (string) $raw['measurement_id'] ) : '';
		$bad_id       = ( '' !== $submitted_id && '' === $clean['measurement_id'] ) ? '1' : '0';

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'           => self::PAGE,
					'perdita_saved'  => '1',
					'perdita_bad_id' => $bad_id,
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
		$saved  = isset( $_GET['perdita_saved'] ) && '1' === $_GET['perdita_saved'];
		$bad_id = isset( $_GET['perdita_bad_id'] ) && '1' === $_GET['perdita_bad_id'];
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Analytics', 'perdita-core' ); ?></h1>

			<?php if ( $saved && ! $bad_id ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'perdita-core' ); ?></p></div>
			<?php endif; ?>
			<?php if ( $bad_id ) : ?>
				<div class="notice notice-warning is-dismissible"><p><?php esc_html_e( 'Settings saved, but the measurement ID was not in the expected G-XXXXXXX shape, so it was cleared. Tracking stays off until you enter a valid ID.', 'perdita-core' ); ?></p></div>
			<?php endif; ?>

			<p class="description" style="max-width:720px;">
				<?php esc_html_e( 'Add Google Analytics 4 with consent-first tracking. Nothing is measured until a visitor agrees, using Google Consent Mode v2. The optional banner helps you comply with privacy laws like GDPR and CCPA. It does not by itself make your site compliant, so review your own legal obligations.', 'perdita-core' ); ?>
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
							<p class="description" id="perdita-analytics-id-help"><?php esc_html_e( 'Find this in Google Analytics under Admin, Data Streams. It looks like G-XXXXXXX. Leave blank to turn tracking off.', 'perdita-core' ); ?></p>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'Cookie-consent banner', 'perdita-core' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="perdita_analytics[show_banner]" value="1" <?php checked( ! empty( $s['show_banner'] ) ); ?> />
								<?php esc_html_e( 'Show a cookie-consent banner', 'perdita-core' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'When on, visitors see a banner and can accept or decline analytics cookies. Tracking stays off until they accept.', 'perdita-core' ); ?></p>
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
						<th scope="row"><?php esc_html_e( 'Do Not Track', 'perdita-core' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="perdita_analytics[respect_dnt]" value="1" <?php checked( ! empty( $s['respect_dnt'] ) ); ?> />
								<?php esc_html_e( 'Respect Do Not Track', 'perdita-core' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'When a browser sends a Do Not Track signal, keep tracking off and do not show the banner.', 'perdita-core' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save changes', 'perdita-core' ) ); ?>
			</form>
		</div>
		<?php
	}
}
