<?php
/**
 * Backups module admin: the Backups screen under Perdita.
 *
 * Shows a "Back up now" button, the list of existing backup sets with Download,
 * Restore, and Delete actions, the schedule and retention controls, the
 * what-to-include toggle, and the storage-location note (including the nginx
 * deny snippet). Every action posts to admin-post.php with a capability check
 * and a nonce, handled on the engine class. Values are escaped on output.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

class Perdita_Backups_Admin {

	/**
	 * Menu slug for the Backups page.
	 */
	const PAGE = 'perdita-backups';

	/**
	 * The main module instance.
	 *
	 * @var Perdita_Backups
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
	 * @param Perdita_Backups $main Main module.
	 * @param Perdita         $core Core.
	 */
	public function __construct( $main, $core = null ) {
		$this->main = $main;
		$this->core = $core;

		add_action( 'admin_menu', array( $this, 'menu' ) );
	}

	/**
	 * Register the Backups submenu under Perdita.
	 */
	public function menu() {
		add_submenu_page(
			'perdita',
			__( 'Backups', 'perdita-core' ),
			__( 'Backups', 'perdita-core' ),
			'manage_options',
			self::PAGE,
			array( $this, 'render' )
		);
	}

	/**
	 * Human-readable size.
	 *
	 * @param int $bytes Bytes.
	 * @return string
	 */
	private function human_size( $bytes ) {
		return size_format( max( 0, (int) $bytes ), 1 ) ?: '0 B';
	}

	/**
	 * Render the Backups screen.
	 */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings = Perdita_Backups::settings();
		$sets     = Perdita_Backups::list_sets();
		$dir      = Perdita_Backups::dir();

		// Read-only notice flags. No state change, so no nonce needed here.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$status = isset( $_GET['perdita_backups'] ) ? sanitize_key( wp_unslash( $_GET['perdita_backups'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$detail = get_transient( 'perdita_backups_notice_' . get_current_user_id() );
		if ( false !== $detail ) {
			delete_transient( 'perdita_backups_notice_' . get_current_user_id() );
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Backups', 'perdita-core' ); ?></h1>

			<?php $this->notice( $status, is_string( $detail ) ? $detail : '' ); ?>

			<p class="description" style="max-width:760px;">
				<?php esc_html_e( 'Take on-demand or scheduled backups of your database and files, and restore any set with one click. A backup is a full copy of your site, so treat the download like a password.', 'perdita-core' ); ?>
			</p>

			<div class="notice notice-info inline" style="max-width:760px;">
				<p style="margin:0.6em 0;">
					<strong><?php esc_html_e( 'Free backups are stored on this same server.', 'perdita-core' ); ?></strong>
					<?php esc_html_e( 'That makes them great for rolling back after a bad update, but they are not off-site disaster recovery. If the server disk fails, these backups fail with it. Off-site storage (like Amazon S3) is a planned Pro feature. For now, download a copy you care about and keep it somewhere safe.', 'perdita-core' ); ?>
				</p>
			</div>

			<h2><?php esc_html_e( 'Back up now', 'perdita-core' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="perdita_backups_run" />
				<?php wp_nonce_field( 'perdita_backups_run' ); ?>
				<p>
					<?php submit_button( __( 'Back up now', 'perdita-core' ), 'primary', 'submit', false ); ?>
					<span class="description" style="margin-left:8px;">
						<?php
						$labels = array(
							'database' => __( 'database only', 'perdita-core' ),
							'files'    => __( 'files only', 'perdita-core' ),
							'both'     => __( 'database and files', 'perdita-core' ),
						);
						$current = isset( $labels[ $settings['contents'] ] ) ? $labels[ $settings['contents'] ] : $labels['both'];
						/* translators: %s: what the backup includes. */
						echo esc_html( sprintf( __( 'This backup will include: %s.', 'perdita-core' ), $current ) );
						?>
					</span>
				</p>
			</form>

			<h2><?php esc_html_e( 'Your backups', 'perdita-core' ); ?></h2>
			<?php if ( empty( $sets ) ) : ?>
				<p><?php esc_html_e( 'No backups yet. Use "Back up now" above, or turn on a schedule below.', 'perdita-core' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Date', 'perdita-core' ); ?></th>
							<th><?php esc_html_e( 'Contents', 'perdita-core' ); ?></th>
							<th><?php esc_html_e( 'Size', 'perdita-core' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'perdita-core' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $sets as $set ) : ?>
							<?php
							$when = $set['created'] ? wp_date( 'Y-m-d H:i', $set['created'] ) : esc_html__( 'Unknown', 'perdita-core' );

							$parts = array();
							if ( in_array( 'database', $set['contents'], true ) ) {
								$parts[] = __( 'Database', 'perdita-core' );
							}
							if ( in_array( 'files', $set['contents'], true ) ) {
								$parts[] = __( 'Files', 'perdita-core' );
							}
							$contents_label = $parts ? implode( ' + ', $parts ) : __( 'Empty', 'perdita-core' );
							?>
							<tr>
								<td><?php echo esc_html( $when ); ?></td>
								<td><?php echo esc_html( $contents_label ); ?></td>
								<td><?php echo esc_html( $this->human_size( $set['size'] ) ); ?></td>
								<td>
									<?php // Download each file in the set. ?>
									<?php if ( '' !== $set['sql'] ) : ?>
										<a class="button button-small" href="<?php echo esc_url( $this->download_url( $set['sql'] ) ); ?>"><?php esc_html_e( 'Download SQL', 'perdita-core' ); ?></a>
									<?php endif; ?>
									<?php if ( '' !== $set['zip'] ) : ?>
										<a class="button button-small" href="<?php echo esc_url( $this->download_url( $set['zip'] ) ); ?>"><?php esc_html_e( 'Download files', 'perdita-core' ); ?></a>
									<?php endif; ?>

									<?php // Restore, guarded by a JS confirm. ?>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;" onsubmit="return confirm('<?php echo esc_js( __( 'This will OVERWRITE your live site with this backup. A fresh pre-restore snapshot is taken automatically first so you can roll back. Continue?', 'perdita-core' ) ); ?>');">
										<input type="hidden" name="action" value="perdita_backups_restore" />
										<input type="hidden" name="base" value="<?php echo esc_attr( $set['base'] ); ?>" />
										<?php wp_nonce_field( 'perdita_backups_restore' ); ?>
										<button type="submit" class="button button-small"><?php esc_html_e( 'Restore', 'perdita-core' ); ?></button>
									</form>

									<?php // Delete, guarded by a JS confirm. ?>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;" onsubmit="return confirm('<?php echo esc_js( __( 'Delete this backup set permanently?', 'perdita-core' ) ); ?>');">
										<input type="hidden" name="action" value="perdita_backups_delete" />
										<input type="hidden" name="base" value="<?php echo esc_attr( $set['base'] ); ?>" />
										<?php wp_nonce_field( 'perdita_backups_delete' ); ?>
										<button type="submit" class="button button-small button-link-delete"><?php esc_html_e( 'Delete', 'perdita-core' ); ?></button>
									</form>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Schedule and options', 'perdita-core' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="perdita_backups_settings" />
				<?php wp_nonce_field( 'perdita_backups_settings' ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="perdita-backups-schedule"><?php esc_html_e( 'Automatic backups', 'perdita-core' ); ?></label></th>
						<td>
							<select id="perdita-backups-schedule" name="perdita_backups[schedule]">
								<option value="off" <?php selected( $settings['schedule'], 'off' ); ?>><?php esc_html_e( 'Off', 'perdita-core' ); ?></option>
								<option value="daily" <?php selected( $settings['schedule'], 'daily' ); ?>><?php esc_html_e( 'Daily', 'perdita-core' ); ?></option>
								<option value="weekly" <?php selected( $settings['schedule'], 'weekly' ); ?>><?php esc_html_e( 'Weekly', 'perdita-core' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'Scheduled backups run through WordPress cron, so they fire when your site gets traffic near the scheduled time.', 'perdita-core' ); ?></p>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'What to include', 'perdita-core' ); ?></th>
						<td>
							<label style="margin-right:14px;"><input type="radio" name="perdita_backups[contents]" value="both" <?php checked( $settings['contents'], 'both' ); ?> /> <?php esc_html_e( 'Database and files', 'perdita-core' ); ?></label>
							<label style="margin-right:14px;"><input type="radio" name="perdita_backups[contents]" value="database" <?php checked( $settings['contents'], 'database' ); ?> /> <?php esc_html_e( 'Database only', 'perdita-core' ); ?></label>
							<label><input type="radio" name="perdita_backups[contents]" value="files" <?php checked( $settings['contents'], 'files' ); ?> /> <?php esc_html_e( 'Files only', 'perdita-core' ); ?></label>
							<p class="description"><?php esc_html_e( 'Files means your wp-content folder (themes, plugins, uploads). Caches, node_modules, and .git are skipped to keep archives small.', 'perdita-core' ); ?></p>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="perdita-backups-retention"><?php esc_html_e( 'Keep how many', 'perdita-core' ); ?></label></th>
						<td>
							<input type="number" id="perdita-backups-retention" name="perdita_backups[retention]" min="1" max="100" step="1" value="<?php echo esc_attr( (string) (int) $settings['retention'] ); ?>" class="small-text" />
							<p class="description"><?php esc_html_e( 'Older sets past this number are deleted automatically after each new backup.', 'perdita-core' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save settings', 'perdita-core' ) ); ?>
			</form>

			<h2><?php esc_html_e( 'Where backups are stored', 'perdita-core' ); ?></h2>
			<p class="description" style="max-width:760px;">
				<?php
				/* translators: %s: absolute path to the backups directory. */
				echo esc_html( sprintf( __( 'Backups are written to: %s', 'perdita-core' ), $dir ) );
				?>
			</p>
			<p class="description" style="max-width:760px;">
				<?php esc_html_e( 'That folder is locked down several ways: an .htaccess and a web.config deny direct web access, an index.php stops directory browsing, and every filename carries a per-site secret plus a random token so the paths cannot be guessed. Downloads only ever happen through this authenticated screen, never a direct URL.', 'perdita-core' ); ?>
			</p>
			<p class="description" style="max-width:760px;">
				<strong><?php esc_html_e( 'Using nginx?', 'perdita-core' ); ?></strong>
				<?php esc_html_e( 'nginx ignores .htaccess, so add this to your server block to deny direct access. Even without it, the unguessable filenames and authenticated-only download are the real protection.', 'perdita-core' ); ?>
			</p>
			<pre style="max-width:760px; overflow:auto; background:#fff; border:1px solid #dcdcde; padding:12px;"><code>location ~* /wp-content/uploads/<?php echo esc_html( Perdita_Backups::DIR_NAME ); ?>/ {
    deny all;
    return 403;
}</code></pre>
		</div>
		<?php
	}

	/**
	 * Build a nonce-protected admin-post download URL for one file.
	 *
	 * @param string $file Basename inside the backups dir.
	 * @return string
	 */
	private function download_url( $file ) {
		$url = add_query_arg(
			array(
				'action' => 'perdita_backups_download',
				'file'   => rawurlencode( $file ),
			),
			admin_url( 'admin-post.php' )
		);
		return wp_nonce_url( $url, 'perdita_backups_download' );
	}

	/**
	 * Print a status notice.
	 *
	 * @param string $status Status key from the redirect.
	 * @param string $detail Optional detail message (already plain text).
	 */
	private function notice( $status, $detail ) {
		if ( '' === $status ) {
			return;
		}

		$map = array(
			'created'  => array( 'success', __( 'Backup created.', 'perdita-core' ) ),
			'restored' => array( 'success', __( 'Restore complete. Your site was restored from the selected backup.', 'perdita-core' ) ),
			'deleted'  => array( 'success', __( 'Backup deleted.', 'perdita-core' ) ),
			'saved'    => array( 'success', __( 'Settings saved.', 'perdita-core' ) ),
			'error'    => array( 'error', __( 'Something went wrong.', 'perdita-core' ) ),
		);
		if ( ! isset( $map[ $status ] ) ) {
			return;
		}
		list( $type, $text ) = $map[ $status ];
		if ( '' !== $detail ) {
			$text = $detail;
		}
		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $type ),
			esc_html( $text )
		);
	}
}
