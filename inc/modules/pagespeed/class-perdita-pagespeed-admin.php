<?php
/**
 * PageSpeed Insights admin: settings screen, save, and "Run test now".
 *
 * A submenu under Perdita for the PSI API key and the last test results.
 * Saving goes through admin-post.php with a capability check and a nonce,
 * same as every other module. Running a fresh test is its own admin-post
 * action behind the same capability + nonce gate; it is an authenticated
 * admin action reading a public Google API about the site's own public
 * pages, so there is no additional rate-limiting concern beyond that gate.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

class Perdita_Pagespeed_Admin {

	/**
	 * The main module instance.
	 *
	 * @var Perdita_Pagespeed
	 */
	private $main;

	/**
	 * The Perdita core singleton.
	 *
	 * @var Perdita
	 */
	private $core;

	/**
	 * admin-post action for saving settings.
	 */
	const ACTION_SAVE = 'perdita_pagespeed_save';

	/**
	 * admin-post action for running a fresh test.
	 */
	const ACTION_RUN = 'perdita_pagespeed_run';

	/**
	 * Menu slug for the settings page.
	 */
	const PAGE = 'perdita-pagespeed';

	/**
	 * Constructor.
	 *
	 * @param Perdita_Pagespeed $main Main module.
	 * @param Perdita           $core Core.
	 */
	public function __construct( $main, $core ) {
		$this->main = $main;
		$this->core = $core;

		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_' . self::ACTION_SAVE, array( $this, 'handle_save' ) );
		add_action( 'admin_post_' . self::ACTION_RUN, array( $this, 'handle_run' ) );
	}

	/**
	 * Register the settings submenu under Perdita.
	 */
	public function menu() {
		add_submenu_page(
			'perdita',
			__( 'PageSpeed Insights', 'perdita-core' ),
			__( 'PageSpeed Insights', 'perdita-core' ),
			'manage_options',
			self::PAGE,
			array( $this, 'render' )
		);
	}

	/**
	 * The settings page URL.
	 *
	 * @return string
	 */
	private function page_url() {
		return admin_url( 'admin.php?page=' . self::PAGE );
	}

	/**
	 * Handle the settings save. Capability + nonce, sanitize, encrypt, store,
	 * redirect back.
	 */
	public function handle_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'perdita-core' ) );
		}
		check_admin_referer( self::ACTION_SAVE );

		// The API key is an opaque secret: read it unslashed only, never
		// through a text sanitizer (which can corrupt a token), and encrypt
		// it immediately in Perdita_Pagespeed::sanitize().
		$raw = array(
			'api_key'        => isset( $_POST['api_key'] ) ? (string) wp_unslash( $_POST['api_key'] ) : '', // phpcs:ignore WordPress.Security.ValidationSanitization.InputNotSanitized -- opaque secret, encrypted in sanitize(), never echoed.
			'remove_api_key' => ! empty( $_POST['remove_api_key'] ),
		);

		$current = Perdita_Pagespeed::get_settings();
		$clean   = Perdita_Pagespeed::sanitize( $raw, $current['api_key'] );
		update_option( Perdita_Pagespeed::OPTION, $clean );

		$this->redirect_back( array( 'perdita_saved' => '1' ) );
	}

	/**
	 * Handle "Run test now". Capability + nonce, force both strategies to
	 * bypass the cache, then redirect back so results show on a normal GET.
	 */
	public function handle_run() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'perdita-core' ) );
		}
		check_admin_referer( self::ACTION_RUN );

		if ( ! Perdita_Pagespeed::has_api_key() ) {
			$this->redirect_back( array( 'perdita_psi_ran' => '0' ) );
			return;
		}

		// A forced run always re-requests both strategies. run_test() itself
		// stores whichever ones succeed into the transient, so a run that
		// fails outright on mobile does not stop desktop from being tried.
		Perdita_Pagespeed::run_test( 'mobile', true );
		Perdita_Pagespeed::run_test( 'desktop', true );

		$this->redirect_back( array( 'perdita_psi_ran' => '1' ) );
	}

	/**
	 * Redirect back to the settings page with status args.
	 *
	 * @param array $args Query args to add.
	 */
	private function redirect_back( array $args ) {
		wp_safe_redirect( add_query_arg( $args, $this->page_url() ) );
		exit;
	}

	/**
	 * Render the settings page.
	 */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s        = Perdita_Pagespeed::get_settings();
		$has_key  = '' !== (string) $s['api_key'];

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only notice flags from a redirect, no state change.
		$saved = isset( $_GET['perdita_saved'] ) && '1' === $_GET['perdita_saved'];
		$ran   = isset( $_GET['perdita_psi_ran'] ) ? sanitize_key( wp_unslash( $_GET['perdita_psi_ran'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'PageSpeed Insights', 'perdita-core' ); ?></h1>
			<p class="description" style="max-width:720px;">
				<?php esc_html_e( 'See your Core Web Vitals and a Lighthouse performance score straight from Google, so a caching or performance change has a number to point at instead of a guess. This reads a public Google API about your site\'s own public pages: no visitor data leaves your site.', 'perdita-core' ); ?>
			</p>

			<?php if ( $saved ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'perdita-core' ); ?></p></div>
			<?php endif; ?>
			<?php if ( '1' === $ran ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Test run complete.', 'perdita-core' ); ?></p></div>
			<?php elseif ( '0' === $ran ) : ?>
				<div class="notice notice-warning is-dismissible"><p><?php esc_html_e( 'Add an API key before running a test.', 'perdita-core' ); ?></p></div>
			<?php endif; ?>

			<?php $this->render_setup_guide(); ?>

			<h2><?php esc_html_e( 'API key', 'perdita-core' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_SAVE ); ?>" />
				<?php wp_nonce_field( self::ACTION_SAVE ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="perdita-psi-api-key"><?php esc_html_e( 'PageSpeed Insights API key', 'perdita-core' ); ?></label>
						</th>
						<td>
							<?php $key_placeholder = $has_key ? esc_attr__( 'saved (leave blank to keep)', 'perdita-core' ) : ''; ?>
							<input
								type="password"
								id="perdita-psi-api-key"
								name="api_key"
								class="regular-text"
								value=""
								autocomplete="new-password"
								placeholder="<?php echo $key_placeholder; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escaped via esc_attr__ above. ?>"
							/>
							<?php if ( $has_key ) : ?>
								<label style="margin-left:8px;">
									<input type="checkbox" name="remove_api_key" value="1" />
									<?php esc_html_e( 'Remove key', 'perdita-core' ); ?>
								</label>
							<?php endif; ?>
							<p class="description">
								<?php esc_html_e( 'The key is encrypted before it is saved, so a database backup alone does not reveal it. Type a new value only when you want to change it.', 'perdita-core' ); ?>
							</p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save settings', 'perdita-core' ) ); ?>
			</form>

			<?php if ( $has_key ) : ?>
				<hr />
				<h2><?php esc_html_e( 'Run a test', 'perdita-core' ); ?></h2>
				<p>
					<?php
					printf(
						/* translators: %s: URL under test */
						esc_html__( 'Testing: %s', 'perdita-core' ),
						'<code>' . esc_html( Perdita_Pagespeed::url_under_test() ) . '</code>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_html applied above, wrapped in a static <code> tag.
					);
					?>
				</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_RUN ); ?>" />
					<?php wp_nonce_field( self::ACTION_RUN ); ?>
					<?php submit_button( __( 'Run test now', 'perdita-core' ), 'primary', 'submit', false ); ?>
				</form>
				<p class="description"><?php esc_html_e( 'Results are cached for 6 hours. Loading this page again will not re-run the test, use the button above for a fresh result.', 'perdita-core' ); ?></p>

				<?php $this->render_results(); ?>
			<?php else : ?>
				<hr />
				<p><?php esc_html_e( 'Add an API key above to run your first test.', 'perdita-core' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render the numbered setup guide for getting a PSI API key.
	 */
	private function render_setup_guide() {
		?>
		<div class="notice notice-info" style="padding:12px 16px;">
			<h2 style="margin-top:0;"><?php esc_html_e( 'Setup', 'perdita-core' ); ?></h2>
			<ol>
				<li><?php esc_html_e( 'Create or select a project in the Google Cloud console.', 'perdita-core' ); ?></li>
				<li>
					<?php
					printf(
						/* translators: %s: link to the PageSpeed Insights API library page */
						esc_html__( 'Enable the "PageSpeed Insights API" for that project: %s', 'perdita-core' ),
						'<a href="https://console.cloud.google.com/apis/library/pagespeedonline.googleapis.com" target="_blank" rel="noopener noreferrer">console.cloud.google.com/apis/library/pagespeedonline.googleapis.com</a>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static trusted link markup, no user input.
					);
					?>
				</li>
				<li><?php esc_html_e( 'Create an API key under APIs & Services, Credentials. Optionally restrict it to only the PageSpeed Insights API, so it cannot be used for anything else if it ever leaks.', 'perdita-core' ); ?></li>
				<li><?php esc_html_e( 'Paste the key into the field below and save.', 'perdita-core' ); ?></li>
			</ol>
		</div>
		<?php
	}

	/**
	 * Render the last known results for both strategies, pulling straight
	 * from the transient cache (run_test() without force reads the cache).
	 * Shown even right after a save, so the admin sees the previous run
	 * while a fresh one is only a click away.
	 */
	private function render_results() {
		$mobile  = Perdita_Pagespeed::run_test( 'mobile' );
		$desktop = Perdita_Pagespeed::run_test( 'desktop' );

		echo '<h2>' . esc_html__( 'Results', 'perdita-core' ) . '</h2>';
		echo '<div style="display:flex;gap:24px;flex-wrap:wrap;">';
		$this->render_strategy_card( __( 'Mobile', 'perdita-core' ), $mobile );
		$this->render_strategy_card( __( 'Desktop', 'perdita-core' ), $desktop );
		echo '</div>';
	}

	/**
	 * Render one strategy's result card (score + badges), or an error/empty
	 * notice in its place. A bad API response never produces a fatal error
	 * or a blank page, it always resolves to one of these three branches.
	 *
	 * @param string          $label  'Mobile' or 'Desktop', already translated.
	 * @param array|WP_Error $result Result from Perdita_Pagespeed::run_test().
	 */
	private function render_strategy_card( $label, $result ) {
		echo '<div style="flex:1;min-width:280px;border:1px solid #dcdcde;border-radius:4px;padding:16px;background:#fff;">';
		echo '<h3 style="margin-top:0;">' . esc_html( $label ) . '</h3>';

		if ( is_wp_error( $result ) ) {
			echo '<p style="color:#b32d2e;">' . esc_html( $result->get_error_message() ) . '</p>';
			echo '</div>';
			return;
		}

		if ( ! is_array( $result ) || ! isset( $result['score'] ) ) {
			echo '<p>' . esc_html__( 'No result yet.', 'perdita-core' ) . '</p>';
			echo '</div>';
			return;
		}

		if ( ! empty( $result['cached'] ) ) {
			echo '<p class="description">' . esc_html__( 'Cached result.', 'perdita-core' ) . '</p>';
		}

		// Performance score, 0-100.
		if ( null !== $result['score'] ) {
			echo '<p style="font-size:32px;font-weight:600;margin:4px 0;">' . esc_html( (string) $result['score'] ) . '<span style="font-size:16px;font-weight:400;">/100</span></p>';
		} else {
			echo '<p>' . esc_html__( 'Performance score unavailable.', 'perdita-core' ) . '</p>';
		}

		echo '<table class="widefat" style="margin-top:8px;"><tbody>';
		$this->render_metric_row(
			__( 'Largest Contentful Paint', 'perdita-core' ),
			$result['lcp'],
			/* translators: %s: seconds, e.g. "2.1s" */
			static function ( $value ) {
				/* translators: %s: number of seconds, e.g. 2.1 */
				return sprintf( __( '%ss', 'perdita-core' ), number_format_i18n( $value, 2 ) );
			},
			$this->lcp_badge( $result['lcp']['value'] )
		);
		$this->render_metric_row(
			__( 'Cumulative Layout Shift', 'perdita-core' ),
			$result['cls'],
			static function ( $value ) {
				return number_format_i18n( $value, 3 );
			},
			$this->cls_badge( $result['cls']['value'] )
		);
		$tbt_inp_label = ( isset( $result['tbt_inp']['metric'] ) && 'inp' === $result['tbt_inp']['metric'] )
			? __( 'Interaction to Next Paint', 'perdita-core' )
			: __( 'Total Blocking Time', 'perdita-core' );
		$this->render_metric_row(
			$tbt_inp_label,
			$result['tbt_inp'],
			static function ( $value ) {
				/* translators: %s: number of milliseconds, e.g. 150 */
				return sprintf( __( '%sms', 'perdita-core' ), number_format_i18n( $value, 0 ) );
			},
			( isset( $result['tbt_inp']['metric'] ) && 'inp' === $result['tbt_inp']['metric'] )
				? $this->inp_badge( $result['tbt_inp']['value'] )
				: null
		);
		echo '</tbody></table>';

		echo '</div>';
	}

	/**
	 * Render one metric's table row: label, formatted value, data-source note
	 * (field vs lab), and a color badge when thresholds apply to this metric.
	 *
	 * @param string        $label     Metric label, already translated.
	 * @param array         $metric    array( 'value' => float|null, 'source' => 'field'|'lab' ), the 'metric' key is not used here.
	 * @param callable      $formatter Formats the numeric value to a display string.
	 * @param string|null   $badge     Pre-rendered badge HTML, or null when no threshold applies (e.g. TBT has none of Google's three official CWV bands).
	 */
	private function render_metric_row( $label, array $metric, $formatter, $badge ) {
		echo '<tr>';
		echo '<td>' . esc_html( $label ) . '</td>';

		if ( null === $metric['value'] ) {
			echo '<td colspan="2">' . esc_html__( 'Not available for this page.', 'perdita-core' ) . '</td>';
			echo '</tr>';
			return;
		}

		$value_text = call_user_func( $formatter, $metric['value'] );
		echo '<td>' . esc_html( $value_text ) . '</td>';

		echo '<td>';
		if ( null !== $badge ) {
			echo wp_kses( $badge, array( 'span' => array( 'style' => array() ) ) );
			echo ' ';
		}
		$source_text = ( 'field' === $metric['source'] )
			? __( 'Real-world data', 'perdita-core' )
			: __( 'Lab data (simulated)', 'perdita-core' );
		echo '<span class="description">' . esc_html( $source_text ) . '</span>';
		echo '</td>';

		echo '</tr>';
	}

	/**
	 * A green/yellow/red inline-styled badge.
	 *
	 * @param string $level 'good', 'needs-improvement', or 'poor'.
	 * @return string HTML.
	 */
	private function badge( $level ) {
		$colors = array(
			'good'              => array(
				'bg'   => '#edfaef',
				'text' => '#005c12',
				'label' => __( 'Good', 'perdita-core' ),
			),
			'needs-improvement' => array(
				'bg'   => '#fcf9e8',
				'text' => '#7a6001',
				'label' => __( 'Needs improvement', 'perdita-core' ),
			),
			'poor'              => array(
				'bg'   => '#fcf0f1',
				'text' => '#8a1f1f',
				'label' => __( 'Poor', 'perdita-core' ),
			),
		);
		$c = isset( $colors[ $level ] ) ? $colors[ $level ] : $colors['poor'];

		return sprintf(
			'<span style="display:inline-block;padding:2px 8px;border-radius:3px;font-size:11px;font-weight:600;background:%1$s;color:%2$s;">%3$s</span>',
			esc_attr( $c['bg'] ),
			esc_attr( $c['text'] ),
			esc_html( $c['label'] )
		);
	}

	/**
	 * LCP thresholds: good <=2.5s, needs improvement <=4s, else poor.
	 *
	 * @param float|null $seconds LCP value in seconds.
	 * @return string|null
	 */
	private function lcp_badge( $seconds ) {
		if ( null === $seconds ) {
			return null;
		}
		if ( $seconds <= 2.5 ) {
			return $this->badge( 'good' );
		}
		if ( $seconds <= 4.0 ) {
			return $this->badge( 'needs-improvement' );
		}
		return $this->badge( 'poor' );
	}

	/**
	 * CLS thresholds: good <=0.1, needs improvement <=0.25, else poor.
	 *
	 * @param float|null $score CLS score.
	 * @return string|null
	 */
	private function cls_badge( $score ) {
		if ( null === $score ) {
			return null;
		}
		if ( $score <= 0.1 ) {
			return $this->badge( 'good' );
		}
		if ( $score <= 0.25 ) {
			return $this->badge( 'needs-improvement' );
		}
		return $this->badge( 'poor' );
	}

	/**
	 * INP thresholds: good <=200ms, needs improvement <=500ms, else poor.
	 * Total Blocking Time has no equivalent official Core Web Vitals bands,
	 * so it is shown without a color badge.
	 *
	 * @param float|null $ms INP value in milliseconds.
	 * @return string|null
	 */
	private function inp_badge( $ms ) {
		if ( null === $ms ) {
			return null;
		}
		if ( $ms <= 200 ) {
			return $this->badge( 'good' );
		}
		if ( $ms <= 500 ) {
			return $this->badge( 'needs-improvement' );
		}
		return $this->badge( 'poor' );
	}
}
