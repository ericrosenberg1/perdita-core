<?php
/**
 * Caching module admin: a "Caching" screen under the Perdita menu.
 *
 * Toggles the page cache, sets the TTL and per-path exclusions, toggles the
 * browser-cache/GZIP .htaccess rules, shows current cache size and file count,
 * and offers a one-click "Clear cache now". Every write goes through an
 * admin_post handler that checks manage_options and a nonce.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

class Perdita_Caching_Admin {

	/**
	 * Admin page slug.
	 */
	const PAGE = 'perdita-caching';

	/**
	 * The main caching class instance.
	 *
	 * @var Perdita_Caching
	 */
	private $main;

	/**
	 * The Perdita core singleton.
	 *
	 * @var Perdita
	 */
	private $core;

	/**
	 * Constructor.
	 *
	 * @param Perdita_Caching $main Main module instance.
	 * @param Perdita         $core Core.
	 */
	public function __construct( $main, $core ) {
		$this->main = $main;
		$this->core = $core;

		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_perdita_caching_save', array( $this, 'handle_save' ) );
		add_action( 'admin_post_perdita_caching_clear', array( $this, 'handle_clear' ) );
	}

	/**
	 * Add the Caching submenu under the Perdita top-level menu.
	 */
	public function menu() {
		add_submenu_page(
			'perdita',
			__( 'Caching', 'perdita-core' ),
			__( 'Caching', 'perdita-core' ),
			'manage_options',
			self::PAGE,
			array( $this, 'render' )
		);
	}

	/* ---------- handlers ---------- */

	/**
	 * Shared guard for every write: capability + nonce, or die.
	 *
	 * @param string $action Nonce action.
	 */
	private function guard( $action ) {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( $action ) ) {
			wp_die( esc_html__( 'Not allowed.', 'perdita-core' ) );
		}
	}

	/**
	 * Redirect back to the settings screen with a short status message.
	 *
	 * @param string $msg Message to show.
	 */
	private function redirect( $msg ) {
		wp_safe_redirect( add_query_arg( 'perdita_msg', rawurlencode( $msg ), admin_url( 'admin.php?page=' . self::PAGE ) ) );
		exit;
	}

	/**
	 * Save the settings form.
	 */
	public function handle_save() {
		$this->guard( 'perdita_caching_save' );

		$raw = array(
			'enabled'       => isset( $_POST['enabled'] ) ? 1 : 0,
			'ttl_hours'     => isset( $_POST['ttl_hours'] ) ? (int) $_POST['ttl_hours'] : 10,
			'exclude_paths' => isset( $_POST['exclude_paths'] ) ? sanitize_textarea_field( wp_unslash( $_POST['exclude_paths'] ) ) : '',
			'browser_cache' => isset( $_POST['browser_cache'] ) ? 1 : 0,
		);

		$clean = Perdita_Caching::sanitize( $raw );

		// Read the previous browser-cache state so we only rewrite .htaccess when
		// the toggle actually changed. update_option below fires the purge hook.
		$before = Perdita_Caching::settings();
		update_option( Perdita_Caching::OPTION, $clean );

		// Apply the browser-cache rules to .htaccess to match the new toggle.
		$msg = __( 'Settings saved.', 'perdita-core' );
		if ( $clean['browser_cache'] && ! $before['browser_cache'] ) {
			if ( Perdita_Caching::server_is_apache() ) {
				$msg = $this->main->write_htaccess_rules()
					? __( 'Settings saved and browser-cache rules added to .htaccess.', 'perdita-core' )
					: __( 'Settings saved, but the .htaccess file could not be written. Check its permissions or add the rules by hand.', 'perdita-core' );
			} else {
				$msg = __( 'Settings saved. This server is not Apache, so copy the nginx snippet below into your server config.', 'perdita-core' );
			}
		} elseif ( ! $clean['browser_cache'] && $before['browser_cache'] ) {
			$this->main->remove_htaccess_rules();
			$msg = __( 'Settings saved and browser-cache rules removed from .htaccess.', 'perdita-core' );
		}

		$this->redirect( $msg );
	}

	/**
	 * Clear the whole page cache.
	 */
	public function handle_clear() {
		$this->guard( 'perdita_caching_clear' );
		$removed = (int) $this->main->purge_all();
		/* translators: %d: number of cached pages removed. */
		$this->redirect( sprintf( _n( 'Cache cleared. %d file removed.', 'Cache cleared. %d files removed.', $removed, 'perdita-core' ), $removed ) );
	}

	/* ---------- render ---------- */

	/**
	 * Human-readable size string for a byte count.
	 *
	 * @param int $bytes Bytes.
	 * @return string
	 */
	private function human_size( $bytes ) {
		return size_format( max( 0, (int) $bytes ), 1 ) ?: '0 B';
	}

	/**
	 * Render the Caching settings screen.
	 */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$s      = Perdita_Caching::settings();
		$stats  = Perdita_Caching::stats();
		$apache = Perdita_Caching::server_is_apache();
		?>
		<style>.perdita-recommended-flag{display:inline-block;padding:2px 8px;border-radius:3px;font-size:11px;font-weight:600;background:#edfaef;color:#005c12;}</style>
		<div class="wrap">
			<h1><?php esc_html_e( 'Caching', 'perdita-core' ); ?></h1>

			<?php
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only status message echoed after a redirect.
			if ( isset( $_GET['perdita_msg'] ) ) {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['perdita_msg'] ) ) ) . '</p></div>';
			}
			?>

			<p style="max-width:760px;">
				<?php esc_html_e( 'This is a lightweight page cache. It saves each guest page as a static file and serves it on the next visit, which cuts server work for anonymous visitors. It runs inside WordPress, so a host-level cache or a CDN is still faster and can layer on top of it. Logged-in users, carts, forms, searches, and any page with a query string are never cached.', 'perdita-core' ); ?>
			</p>

			<div class="card" style="max-width:760px;margin-bottom:16px;">
				<h2 style="margin-top:0;"><?php esc_html_e( 'Cache status', 'perdita-core' ); ?></h2>
				<p>
					<strong><?php esc_html_e( 'Cached pages:', 'perdita-core' ); ?></strong>
					<?php echo esc_html( number_format_i18n( (int) $stats['files'] ) ); ?>
					&nbsp;·&nbsp;
					<strong><?php esc_html_e( 'Cache size:', 'perdita-core' ); ?></strong>
					<?php echo esc_html( $this->human_size( $stats['bytes'] ) ); ?>
				</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0;">
					<input type="hidden" name="action" value="perdita_caching_clear" />
					<?php wp_nonce_field( 'perdita_caching_clear' ); ?>
					<?php submit_button( __( 'Clear cache now', 'perdita-core' ), 'secondary', 'submit', false ); ?>
				</form>
			</div>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="perdita_caching_save" />
				<?php wp_nonce_field( 'perdita_caching_save' ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Page cache', 'perdita-core' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="enabled" value="1" <?php checked( $s['enabled'] ); ?> />
								<?php esc_html_e( 'Save and serve static copies of guest pages', 'perdita-core' ); ?>
							</label> <span class="perdita-recommended-flag"><?php esc_html_e( '(Recommended)', 'perdita-core' ); ?></span>
							<p class="description"><?php esc_html_e( 'When on, the cache is cleared automatically when you publish or edit content, change the design, or switch themes.', 'perdita-core' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="perdita-cache-ttl"><?php esc_html_e( 'Cache lifetime', 'perdita-core' ); ?></label></th>
						<td>
							<input type="number" id="perdita-cache-ttl" name="ttl_hours" min="1" max="8760" step="1" value="<?php echo esc_attr( (string) $s['ttl_hours'] ); ?>" class="small-text" />
							<?php esc_html_e( 'hours', 'perdita-core' ); ?>
							<p class="description"><?php esc_html_e( 'How long a saved page stays fresh before it is rebuilt. A shorter time means fresher pages and less benefit. 10 hours is a sensible default.', 'perdita-core' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="perdita-cache-exclude"><?php esc_html_e( 'Never cache these paths', 'perdita-core' ); ?></label></th>
						<td>
							<textarea id="perdita-cache-exclude" name="exclude_paths" rows="4" class="large-text code" placeholder="/cart&#10;/checkout&#10;/my-account"><?php echo esc_textarea( implode( "\n", (array) $s['exclude_paths'] ) ); ?></textarea>
							<p class="description"><?php esc_html_e( 'One URL path per line, starting with a slash. A path also covers everything under it, so /account also excludes /account/orders.', 'perdita-core' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Browser caching and GZIP', 'perdita-core' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="browser_cache" value="1" <?php checked( $s['browser_cache'] ); ?> />
								<?php esc_html_e( 'Add browser-cache and GZIP rules', 'perdita-core' ); ?>
							</label> <span class="perdita-recommended-flag"><?php esc_html_e( '(Recommended)', 'perdita-core' ); ?></span>
							<?php if ( $apache ) : ?>
								<p class="description"><?php esc_html_e( 'On this Apache server, saving writes a "Perdita Cache" block into your .htaccess with mod_expires and mod_deflate rules. Turning it off removes that block and leaves the rest of the file alone.', 'perdita-core' ); ?></p>
							<?php else : ?>
								<p class="description"><?php esc_html_e( 'This server is not Apache, so Perdita cannot edit a config file for you. Turn this on to keep the setting, then copy the nginx snippet below into your server block.', 'perdita-core' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save changes', 'perdita-core' ) ); ?>
			</form>

			<?php if ( ! $apache ) : ?>
				<div class="card" style="max-width:760px;">
					<h2 id="perdita-cache-nginx-heading" style="margin-top:0;"><?php esc_html_e( 'nginx snippet', 'perdita-core' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Add this inside your server block, then reload nginx. It sets long-lived browser caching for static assets and turns on GZIP.', 'perdita-core' ); ?></p>
					<textarea readonly rows="12" class="large-text code" onclick="this.select()" aria-labelledby="perdita-cache-nginx-heading"><?php echo esc_textarea( Perdita_Caching::nginx_snippet() ); ?></textarea>
				</div>
			<?php endif; ?>

			<div class="card" style="max-width:760px;border-left:4px solid #2563eb;">
				<h2 style="margin-top:0;"><?php esc_html_e( 'What is and is not here', 'perdita-core' ); ?></h2>
				<p><?php esc_html_e( 'These simple settings should work well for the majority of small WordPress sites. It saves each guest page as a static file and serves it right back on the next visit, which is exactly the boost a small site on modest hosting benefits from most. It never touches wp-config.php, so it can never break how your site boots.', 'perdita-core' ); ?></p>
				<p class="description">
					<?php
					printf(
						/* translators: %s: link to Perdita Premium */
						esc_html__( 'Want more advanced features like CSS/JS minification and combining? Check out %s.', 'perdita-core' ),
						'<a href="' . esc_url( 'https://perdita.ericrosenberg.com' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Perdita Premium', 'perdita-core' ) . '</a>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- href and link text both escaped above.
					);
					?>
				</p>
			</div>
		</div>
		<?php
	}
}
