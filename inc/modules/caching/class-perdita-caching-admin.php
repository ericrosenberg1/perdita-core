<?php
/**
 * Caching module admin: a "Caching" screen under the Perdita menu.
 *
 * Holds every page cache setting (lifetime, exclusions, preload, gzip copies,
 * serve stale, the phone cache, the CDN, feeds, the debug log), shows the
 * cache's size, the preload's progress and a searchable list of cached pages,
 * and offers "Clear cache now", "Delete expired", "Preload now" and a delete
 * link per page. Every write goes through an admin_post (or admin-ajax)
 * handler that checks manage_options and a nonce.
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
		add_action( 'admin_post_perdita_caching_gc', array( $this, 'handle_gc' ) );
		add_action( 'admin_post_perdita_caching_preload', array( $this, 'handle_preload' ) );
		add_action( 'admin_post_perdita_caching_delete', array( $this, 'handle_delete' ) );
		add_action( 'admin_post_perdita_caching_log_clear', array( $this, 'handle_log_clear' ) );
		add_action( 'wp_ajax_perdita_caching_preload_status', array( $this, 'ajax_preload_status' ) );
	}

	/**
	 * Add the Caching submenu under the Perdita top-level menu.
	 */
	public function menu() {
		$hook = add_submenu_page(
			'perdita',
			__( 'Caching', 'perdita-core' ),
			__( 'Caching', 'perdita-core' ),
			'manage_options',
			self::PAGE,
			array( $this, 'render' )
		);
		if ( $hook ) {
			add_action( 'admin_print_styles-' . $hook, array( $this, 'assets' ) );
		}
	}

	/**
	 * The screen's layout sheet. Controls and badges come from the shared
	 * admin sheet.
	 */
	public function assets() {
		if ( class_exists( 'Perdita_Core_Admin_UI' ) ) {
			Perdita_Core_Admin_UI::enqueue_assets();
		}
		wp_enqueue_style( 'perdita-caching-admin', PERDITA_CORE_URL . 'assets/css/perdita-caching-admin.css', array(), PERDITA_CORE_VERSION );
	}

	/* ---------- handlers ---------- */

	/**
	 * Shared capability check for every write. Each handler then checks its
	 * own nonce with check_admin_referer(), so a bad nonce stops the request.
	 */
	private function guard() {
		if ( ! current_user_can( 'manage_options' ) ) {
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
		$this->guard();
		check_admin_referer( 'perdita_caching_save' );

		$raw = array(
			'enabled'       => isset( $_POST['enabled'] ) ? 1 : 0,
			'ttl_hours'     => isset( $_POST['ttl_hours'] ) ? (int) $_POST['ttl_hours'] : 10,
			'exclude_paths' => isset( $_POST['exclude_paths'] ) ? sanitize_textarea_field( wp_unslash( $_POST['exclude_paths'] ) ) : '',
			'browser_cache' => isset( $_POST['browser_cache'] ) ? 1 : 0,
			'early_serve'   => isset( $_POST['early_serve'] ) ? 1 : 0,
			'serve_stale'   => isset( $_POST['serve_stale'] ) ? 1 : 0,
			'gzip'          => isset( $_POST['gzip'] ) ? 1 : 0,
			'mobile_cache'  => isset( $_POST['mobile_cache'] ) ? 1 : 0,
			'cache_feeds'   => isset( $_POST['cache_feeds'] ) ? 1 : 0,
			'preload'       => isset( $_POST['preload'] ) ? 1 : 0,
			'cdn_enabled'   => isset( $_POST['cdn_enabled'] ) ? 1 : 0,
			'debug_log'     => isset( $_POST['debug_log'] ) ? 1 : 0,
			'preload_interval_hours' => isset( $_POST['preload_interval_hours'] ) ? (int) $_POST['preload_interval_hours'] : 0,
			'gc_schedule'   => isset( $_POST['gc_schedule'] ) ? sanitize_key( wp_unslash( $_POST['gc_schedule'] ) ) : 'daily',
			'max_size_mb'   => isset( $_POST['max_size_mb'] ) ? (int) $_POST['max_size_mb'] : 1024,
			'purge_on_save' => isset( $_POST['purge_on_save'] ) && 'smart' === sanitize_key( wp_unslash( $_POST['purge_on_save'] ) ) ? 'smart' : 'all',
			'cdn_host'      => isset( $_POST['cdn_host'] ) ? sanitize_text_field( wp_unslash( $_POST['cdn_host'] ) ) : '',
		);
		// Every list is a textarea, one entry per line. sanitize() cleans each line.
		foreach ( array( 'reject_user_agents', 'reject_cookies', 'cdn_dirs', 'cdn_exclude' ) as $list ) {
			$raw[ $list ] = isset( $_POST[ $list ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ $list ] ) ) : '';
		}

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
					: __( 'Settings saved, but the .htaccess file couldn\'t be written. Check its permissions, or add the rules by hand.', 'perdita-core' );
			} else {
				$msg = __( 'Settings saved. This server isn\'t Apache, so copy the nginx snippet below into your server config.', 'perdita-core' );
			}
		} elseif ( ! $clean['browser_cache'] && $before['browser_cache'] ) {
			$this->main->remove_htaccess_rules();
			$msg = __( 'Settings saved and browser-cache rules removed from .htaccess.', 'perdita-core' );
		}

		$dropin = Perdita_Caching::sync_dropin();
		if ( $clean['early_serve'] && $clean['enabled'] ) {
			$msg .= ' ' . $this->dropin_message( $dropin );
		}
		Perdita_Caching::sync_schedules();
		Perdita_Caching::log( 'SETTINGS', 'saved' );

		$this->redirect( $msg );
	}

	/**
	 * One sentence for a sync_dropin() result.
	 *
	 * @param string $status Result.
	 * @return string
	 */
	private function dropin_message( $status ) {
		switch ( $status ) {
			case 'installed':
			case 'current':
				return __( 'Visitors now get cached pages before WordPress loads.', 'perdita-core' );
			case 'other_cache':
				/* translators: %s: plugin name. */
				return sprintf( __( '%s is still active, so you won\'t get cached pages from Perdita yet. Deactivate it first.', 'perdita-core' ), Perdita_Caching::other_page_cache() );
			case 'foreign':
				return __( 'wp-content/advanced-cache.php belongs to another cache plugin, so early serving is off. Deactivate that plugin, or delete the file if the plugin is already gone, then save again.', 'perdita-core' );
			case 'no_wp_cache':
				return __( 'Early serving needs define( \'WP_CACHE\', true ); in wp-config.php, above the "That\'s all" line. Add it, then save again.', 'perdita-core' );
			case 'multisite':
				return __( 'On a multisite network, every site shares one wp-content/advanced-cache.php, so early serving stays off. You still get each site\'s cached pages, served after WordPress loads.', 'perdita-core' );
			case 'unwritable':
				return __( 'wp-content/advanced-cache.php couldn\'t be written. Check that WordPress can write to wp-content.', 'perdita-core' );
		}
		return '';
	}

	/**
	 * Clear the whole page cache.
	 */
	public function handle_clear() {
		$this->guard();
		check_admin_referer( 'perdita_caching_clear' );
		$removed = (int) $this->main->purge_all();
		/* translators: %d: number of cached pages removed. */
		$this->redirect( sprintf( _n( 'Cleared the cache and removed %d file.', 'Cleared the cache and removed %d files.', $removed, 'perdita-core' ), $removed ) );
	}

	/**
	 * Delete expired cache files now.
	 */
	public function handle_gc() {
		$this->guard();
		check_admin_referer( 'perdita_caching_gc' );
		$removed = (int) Perdita_Caching::run_gc();
		/* translators: %s: number of expired pages removed. */
		$this->redirect( sprintf( _n( 'Deleted %s expired page.', 'Deleted %s expired pages.', $removed, 'perdita-core' ), number_format_i18n( $removed ) ) );
	}

	/**
	 * Start or stop a preload.
	 */
	public function handle_preload() {
		$this->guard();
		check_admin_referer( 'perdita_caching_preload' );
		Perdita_Caching::load( 'preload' );
		if ( isset( $_POST['stop'] ) ) {
			Perdita_Caching_Preload::stop();
			$this->redirect( __( 'Stopped the preload.', 'perdita-core' ) );
		}
		if ( ! Perdita_Caching_Preload::start( 'fill' ) ) {
			$this->redirect( __( 'Turn the page cache on first, then start the preload again.', 'perdita-core' ) );
		}
		$this->redirect( __( 'Started the preload. You can leave this screen and still get every page built in the background.', 'perdita-core' ) );
	}

	/**
	 * Delete one cached page from the list.
	 */
	public function handle_delete() {
		$this->guard();
		check_admin_referer( 'perdita_caching_delete' );
		Perdita_Caching::load( 'tools' );
		// delete_page() takes nothing but a 40 to 53 character hex id, so no
		// path can ride in on this field.
		$id = isset( $_POST['page_id'] ) ? sanitize_key( wp_unslash( $_POST['page_id'] ) ) : '';
		$this->redirect(
			Perdita_Caching_Tools::delete_page( $id )
				? __( 'Deleted the cached page.', 'perdita-core' )
				: __( 'That page is no longer in the cache.', 'perdita-core' )
		);
	}

	/**
	 * Delete the debug log.
	 */
	public function handle_log_clear() {
		$this->guard();
		check_admin_referer( 'perdita_caching_log_clear' );
		Perdita_Caching::load( 'tools' );
		Perdita_Caching_Tools::log_clear();
		$this->redirect( __( 'Debug log deleted.', 'perdita-core' ) );
	}

	/**
	 * Answer the screen's progress poll with the preload's current line.
	 */
	public function ajax_preload_status() {
		if ( ! current_user_can( 'manage_options' ) || ! check_ajax_referer( 'perdita_caching_preload_status', 'nonce', false ) ) {
			wp_send_json_error( null, 403 );
		}
		Perdita_Caching::load( 'preload' );
		$state = Perdita_Caching_Preload::state( true );
		wp_send_json_success(
			array(
				'running' => in_array( $state['status'], array( 'running', 'waiting' ), true ),
				'text'    => $this->preload_line( $state ),
			)
		);
	}

	/**
	 * One line that says where the preload stands.
	 *
	 * @param array $state Preload state.
	 * @return string
	 */
	private function preload_line( $state ) {
		$done  = number_format_i18n( (int) $state['done'] );
		$total = number_format_i18n( (int) max( $state['total'], $state['done'] ) );
		switch ( $state['status'] ) {
			case 'running':
				/* translators: 1: pages done, 2: total pages. */
				return sprintf( __( 'Running. %1$s of %2$s pages done.', 'perdita-core' ), $done, $total );
			case 'waiting':
				return __( 'Starting in about a minute.', 'perdita-core' );
			case 'stopped':
				/* translators: 1: pages done, 2: total pages. */
				return sprintf( __( 'Stopped at %1$s of %2$s pages.', 'perdita-core' ), $done, $total );
			case 'done':
				return sprintf(
					/* translators: 1: pages visited, 2: pages built, 3: pages already cached, 4: pages that failed, 5: how long ago. */
					__( 'Finished %5$s ago. %1$s pages checked: %2$s built, %3$s already cached, and %4$s failed.', 'perdita-core' ),
					$done,
					number_format_i18n( (int) $state['built'] ),
					number_format_i18n( (int) $state['cached'] ),
					number_format_i18n( (int) $state['failed'] ),
					human_time_diff( (int) $state['finished'] )
				);
		}
		return __( 'Not started yet.', 'perdita-core' );
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
		$other  = Perdita_Caching::other_page_cache();
		$dropin = Perdita_Caching::dropin_state();

		Perdita_Caching::load( 'preload' );
		Perdita_Caching::load( 'tools' );
		$preload = Perdita_Caching_Preload::state( true );
		$running = in_array( $preload['status'], array( 'running', 'waiting' ), true );
		$stored  = get_option( Perdita_Caching::OPTION, array() );
		// The stored flag, not the effective one: once the 24 hours are up the
		// box shows unchecked, and the note below says why.
		$debug_expired = is_array( $stored ) && ! empty( $stored['debug_log'] ) && ! $s['debug_log'];
		$gc_labels     = array(
			'off'        => __( 'Never', 'perdita-core' ),
			'hourly'     => __( 'Every hour', 'perdita-core' ),
			'twicedaily' => __( 'Twice a day', 'perdita-core' ),
			'daily'      => __( 'Once a day', 'perdita-core' ),
		);
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- a read-only search and page number for the cached pages list.
		$list_search = isset( $_GET['cache_s'] ) ? sanitize_text_field( wp_unslash( $_GET['cache_s'] ) ) : '';
		$list_paged  = isset( $_GET['cache_paged'] ) ? max( 1, absint( $_GET['cache_paged'] ) ) : 1;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$list     = Perdita_Caching_Tools::list_pages( $list_search, $list_paged, 20 );
		$list_url = admin_url( 'admin.php?page=' . self::PAGE );
		$log_tail = ( $s['debug_log'] || is_file( Perdita_Caching::log_path() ) ) ? Perdita_Caching_Tools::log_tail( 200 ) : '';
		?>
		<div class="wrap perdita-caching">
			<h1><?php esc_html_e( 'Caching', 'perdita-core' ); ?></h1>

			<?php
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only status message echoed after a redirect.
			if ( '' !== perdita_notice_text( 'perdita_msg' ) ) {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( perdita_notice_text( 'perdita_msg' ) ) . '</p></div>';
			}
			?>

			<p class="perdita-cache-intro">
				<?php esc_html_e( 'With this page cache, you save each guest page as a static file and serve that file on the next visit, so you save server work for anonymous visitors. By default, you serve cached pages from inside WordPress. Turn on "Serve before WordPress loads" for the speed of a drop-in cache. You never cache pages for logged-in users, carts, forms, searches, or any address with a query string.', 'perdita-core' ); ?>
			</p>

			<?php if ( '' !== $other ) : ?>
				<div class="notice notice-warning inline perdita-cache-narrow">
					<p>
						<?php
						printf(
							/* translators: %1$s: plugin name such as WP Super Cache. */
							esc_html__( '%1$s is active. With two page caches on one site, visitors can get each cache\'s stale copies, so Perdita\'s cache is off while %1$s is active. Turn the settings below on, then deactivate %1$s on the Plugins screen. You get cached pages from Perdita starting with the next visit.', 'perdita-core' ),
							esc_html( $other )
						);
						?>
					</p>
				</div>
			<?php elseif ( $s['enabled'] && 'foreign' === $dropin ) : ?>
				<div class="notice notice-warning inline perdita-cache-narrow">
					<p><?php esc_html_e( 'wp-content/advanced-cache.php belongs to another cache plugin that is no longer active. While WP_CACHE is on, WordPress loads that file on every request. Delete the file, then save these settings again.', 'perdita-core' ); ?></p>
				</div>
			<?php endif; ?>

			<div class="card perdita-cache-card">
				<h2 class="perdita-cache-card-title"><?php esc_html_e( 'Cache status', 'perdita-core' ); ?></h2>
				<p>
					<strong><?php esc_html_e( 'Cached pages:', 'perdita-core' ); ?></strong>
					<span class="perdita-cache-num"><?php echo esc_html( number_format_i18n( (int) $stats['files'] ) ); ?></span>
					&nbsp;·&nbsp;
					<strong><?php esc_html_e( 'Cache size:', 'perdita-core' ); ?></strong>
					<span class="perdita-cache-num"><?php echo esc_html( $this->human_size( $stats['bytes'] ) ); ?></span>
				</p>
				<div class="perdita-cache-actions">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="perdita_caching_clear" />
						<?php wp_nonce_field( 'perdita_caching_clear' ); ?>
						<?php submit_button( __( 'Clear cache now', 'perdita-core' ), 'secondary', 'submit', false ); ?>
					</form>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="perdita_caching_gc" />
						<?php wp_nonce_field( 'perdita_caching_gc' ); ?>
						<?php submit_button( __( 'Delete expired', 'perdita-core' ), 'secondary', 'submit', false ); ?>
					</form>
				</div>
			</div>

			<div class="card perdita-cache-card">
				<h2 class="perdita-cache-card-title"><?php esc_html_e( 'Preload', 'perdita-core' ); ?></h2>
				<p><?php esc_html_e( 'You can build every published post, page, and archive into the cache before your first visitor arrives. You build pages a few at a time in the background, so you can leave this screen.', 'perdita-core' ); ?></p>
				<p>
					<strong><?php esc_html_e( 'Status:', 'perdita-core' ); ?></strong>
					<span id="perdita-cache-preload-line" class="perdita-cache-num" aria-live="polite"><?php echo esc_html( $this->preload_line( $preload ) ); ?></span>
				</p>
				<?php if ( ! $s['enabled'] ) : ?>
					<p class="description"><?php esc_html_e( 'Turn the page cache on below, and you can preload it here.', 'perdita-core' ); ?></p>
				<?php else : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="perdita-cache-flush">
						<input type="hidden" name="action" value="perdita_caching_preload" />
						<?php wp_nonce_field( 'perdita_caching_preload' ); ?>
						<?php
						if ( $running ) {
							submit_button( __( 'Stop', 'perdita-core' ), 'secondary', 'stop', false );
						} else {
							submit_button( __( 'Preload now', 'perdita-core' ), 'secondary', 'start', false );
						}
						?>
					</form>
				<?php endif; ?>
			</div>
			<?php
			if ( $running ) {
				// The poll keeps the line current, and each request also gives
				// WP-Cron its chance to run the next batch.
				wp_print_inline_script_tag(
					'(function(){var el=document.getElementById("perdita-cache-preload-line");if(!el||!window.fetch){return;}var body=new URLSearchParams({action:"perdita_caching_preload_status",nonce:' . wp_json_encode( wp_create_nonce( 'perdita_caching_preload_status' ) ) . '});var tick=function(){fetch(' . wp_json_encode( admin_url( 'admin-ajax.php' ) ) . ',{method:"POST",credentials:"same-origin",body:body}).then(function(r){return r.json();}).then(function(j){if(j&&j.success){el.textContent=j.data.text;if(j.data.running){setTimeout(tick,5000);}}}).catch(function(){});};setTimeout(tick,5000);})();'
				);
			}
			?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="perdita_caching_save" />
				<?php wp_nonce_field( 'perdita_caching_save' ); ?>

				<h2 class="perdita-cache-section"><?php esc_html_e( 'Page cache', 'perdita-core' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Page cache', 'perdita-core' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="enabled" value="1" <?php checked( $s['enabled'] ); ?> />
								<?php esc_html_e( 'Save and serve static copies of guest pages', 'perdita-core' ); ?>
							</label> <span class="perdita-badge is-ok"><?php esc_html_e( 'Recommended', 'perdita-core' ); ?></span>
							<p class="description"><?php esc_html_e( 'When this is on, the cache is cleared whenever you publish or edit content, change a menu, a widget, a category, or a tag, change the site title, the tagline, or the permalinks, turn a plugin on or off, switch themes, save the Customizer, or update WordPress, a plugin, or a theme.', 'perdita-core' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Serve before WordPress loads', 'perdita-core' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="early_serve" value="1" <?php checked( $s['early_serve'] ); ?> />
								<?php esc_html_e( 'Answer cached pages from wp-content/advanced-cache.php', 'perdita-core' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'The fastest setting, and the way WP Super Cache and most cache plugins work. Visitors get a cached page before any plugin or theme loads. When you save, you write the drop-in, and you remove the drop-in when you turn this or the cache off. You also need define( \'WP_CACHE\', true ); in wp-config.php. Add the line yourself, or from Perdita, Switch to Perdita.', 'perdita-core' ); ?></p>
							<?php if ( $s['early_serve'] && $s['enabled'] ) : ?>
								<p class="description"><strong>
									<?php
									if ( Perdita_Caching::is_network() ) {
										esc_html_e( 'Status: off on a multisite network. Every site shares one drop-in, so you get each site\'s cached pages after WordPress loads instead.', 'perdita-core' );
									} elseif ( 'ours' === $dropin && Perdita_Caching::wp_cache_on() && '' === $other ) {
										esc_html_e( 'Status: on. Visitors get cached pages before WordPress loads.', 'perdita-core' );
									} elseif ( ! Perdita_Caching::wp_cache_on() ) {
										esc_html_e( 'Status: waiting for WP_CACHE. Add define( \'WP_CACHE\', true ); to wp-config.php, or run: wp config set WP_CACHE true --raw', 'perdita-core' );
									} else {
										esc_html_e( 'Status: not active yet. See the note above.', 'perdita-core' );
									}
									?>
								</strong></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="perdita-cache-ttl"><?php esc_html_e( 'Cache lifetime', 'perdita-core' ); ?></label></th>
						<td>
							<input type="number" id="perdita-cache-ttl" name="ttl_hours" min="1" max="8760" step="1" value="<?php echo esc_attr( (string) $s['ttl_hours'] ); ?>" class="small-text" />
							<?php esc_html_e( 'hours', 'perdita-core' ); ?>
							<p class="description"><?php esc_html_e( 'How long a saved page stays fresh. With a shorter time, you get fresher pages and fewer cache hits. Most sites do well with 10 hours.', 'perdita-core' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Serve stale while rebuilding', 'perdita-core' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="serve_stale" value="1" <?php checked( $s['serve_stale'] ); ?> />
								<?php esc_html_e( 'Keep serving the old copy during a rebuild', 'perdita-core' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'When a page expires or gets a new comment, the next visitor starts the rebuild. Everyone else keeps getting the old copy until the new one is ready, for 10 minutes at most. When you change a theme, plugin, or setting, you still clear every page at once.', 'perdita-core' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="perdita-cache-purge-on-save"><?php esc_html_e( 'When you save a post', 'perdita-core' ); ?></label></th>
						<td>
							<select id="perdita-cache-purge-on-save" name="purge_on_save">
								<option value="all" <?php selected( $s['purge_on_save'], 'all' ); ?>><?php esc_html_e( 'Clear every cached page (safest)', 'perdita-core' ); ?></option>
								<option value="smart" <?php selected( $s['purge_on_save'], 'smart' ); ?>><?php esc_html_e( 'Clear the post and the pages that list the post', 'perdita-core' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'With the second choice, when you edit a published post, you clear that post, the home and blog pages, its category, tag, author, and date archives, the list pages around the post, the posts beside the post, and the feeds. When you publish, unpublish, or change a title, slug, date, category, or tag, you still clear every page. Visitors see the old version of a post list in a widget or block elsewhere on your site until the cache lifetime runs out.', 'perdita-core' ); ?></p>
						</td>
					</tr>
				</table>

				<h2 class="perdita-cache-section"><?php esc_html_e( 'Preload', 'perdita-core' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Automatic preload', 'perdita-core' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="preload" value="1" <?php checked( $s['preload'] ); ?> />
								<?php esc_html_e( 'Rebuild pages in the background after a clear', 'perdita-core' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'After a full clear, you get every page rebuilt within a few minutes. After a purge of one page, you get that page rebuilt first.', 'perdita-core' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="perdita-cache-preload-interval"><?php esc_html_e( 'Scheduled refresh', 'perdita-core' ); ?></label></th>
						<td>
							<?php esc_html_e( 'Rebuild every page every', 'perdita-core' ); ?>
							<input type="number" id="perdita-cache-preload-interval" name="preload_interval_hours" min="0" max="720" step="1" value="<?php echo esc_attr( (string) $s['preload_interval_hours'] ); ?>" class="small-text" />
							<?php esc_html_e( 'hours', 'perdita-core' ); ?>
							<p class="description"><?php esc_html_e( 'Enter 0 to turn the scheduled refresh off. Pick a number at or under the cache lifetime, and no visitor waits for a page to build. Turn automatic preload on to use the scheduled refresh.', 'perdita-core' ); ?></p>
						</td>
					</tr>
				</table>

				<h2 class="perdita-cache-section"><?php esc_html_e( 'Compression and devices', 'perdita-core' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Pre-compressed pages', 'perdita-core' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="gzip" value="1" <?php checked( $s['gzip'] ); ?> />
								<?php esc_html_e( 'Store a gzip copy of each cached page', 'perdita-core' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Visitors whose browsers support gzip get the smaller copy, and you save the work of compressing each page on every request. You can leave this off when you already compress HTML on your server or CDN.', 'perdita-core' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Separate cache for phones', 'perdita-core' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="mobile_cache" value="1" <?php checked( $s['mobile_cache'] ); ?> />
								<?php esc_html_e( 'Keep a second copy of each page for phones', 'perdita-core' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Turn this on only when phones get different HTML from your theme or a plugin. With a responsive theme, everyone gets the same page, and you need one copy. Any device that WordPress calls mobile gets the phone copy, including Android tablets and Kindles. iPads get the desktop copy.', 'perdita-core' ); ?></p>
						</td>
					</tr>
				</table>

				<h2 class="perdita-cache-section"><?php esc_html_e( 'Who skips the cache', 'perdita-core' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="perdita-cache-exclude"><?php esc_html_e( 'Never cache these paths', 'perdita-core' ); ?></label></th>
						<td>
							<textarea id="perdita-cache-exclude" name="exclude_paths" rows="4" class="large-text code" placeholder="/cart&#10;/checkout&#10;/my-account"><?php echo esc_textarea( implode( "\n", (array) $s['exclude_paths'] ) ); ?></textarea>
							<p class="description"><?php esc_html_e( 'One URL path per line, starting with a slash. When you exclude a path, you exclude everything under the path too, so /account also excludes /account/orders.', 'perdita-core' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="perdita-cache-reject-ua"><?php esc_html_e( 'Rejected user agents', 'perdita-core' ); ?></label></th>
						<td>
							<textarea id="perdita-cache-reject-ua" name="reject_user_agents" rows="3" class="large-text code" placeholder="SomeCrawler&#10;MyMonitoringTool"><?php echo esc_textarea( implode( "\n", (array) $s['reject_user_agents'] ) ); ?></textarea>
							<p class="description"><?php esc_html_e( 'One piece of a user agent per line, upper or lower case. A visitor whose user agent contains one of these pieces gets a freshly built page every time, with no copy saved.', 'perdita-core' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="perdita-cache-reject-cookies"><?php esc_html_e( 'Rejected cookies', 'perdita-core' ); ?></label></th>
						<td>
							<textarea id="perdita-cache-reject-cookies" name="reject_cookies" rows="3" class="large-text code" placeholder="my_membership_cookie"><?php echo esc_textarea( implode( "\n", (array) $s['reject_cookies'] ) ); ?></textarea>
							<p class="description"><?php esc_html_e( 'One piece of a cookie name per line. A visitor carrying a matching cookie gets a freshly built page every time. You don\'t need to list login, comment, post password, or cart cookies.', 'perdita-core' ); ?></p>
						</td>
					</tr>
				</table>

				<h2 class="perdita-cache-section"><?php esc_html_e( 'Feeds and cleanup', 'perdita-core' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Feed cache', 'perdita-core' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="cache_feeds" value="1" <?php checked( $s['cache_feeds'] ); ?> />
								<?php esc_html_e( 'Cache RSS and Atom feeds', 'perdita-core' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'People check your feeds around the clock with feed readers. You clear cached feeds along with every cached page when you publish.', 'perdita-core' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="perdita-cache-gc"><?php esc_html_e( 'Delete expired pages', 'perdita-core' ); ?></label></th>
						<td>
							<select id="perdita-cache-gc" name="gc_schedule">
								<?php foreach ( $gc_labels as $gc_value => $gc_label ) : ?>
									<option value="<?php echo esc_attr( $gc_value ); ?>" <?php selected( $s['gc_schedule'], $gc_value ); ?>><?php echo esc_html( $gc_label ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'An expired page stays on disk until someone visits that page again. On this schedule, you delete the expired pages nobody visits and keep the cache under the size cap below.', 'perdita-core' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="perdita-cache-max-size"><?php esc_html_e( 'Size cap', 'perdita-core' ); ?></label></th>
						<td>
							<input type="number" id="perdita-cache-max-size" name="max_size_mb" min="0" max="1048576" step="1" value="<?php echo esc_attr( (string) $s['max_size_mb'] ); ?>" class="small-text" />
							<?php esc_html_e( 'MB', 'perdita-core' ); ?>
							<p class="description"><?php esc_html_e( 'Once the cached pages reach this size, you stop saving new pages and delete the oldest at the next cleanup. Enter 0 for no cap.', 'perdita-core' ); ?></p>
						</td>
					</tr>
				</table>

				<h2 class="perdita-cache-section"><?php esc_html_e( 'Browser caching', 'perdita-core' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Browser caching and GZIP', 'perdita-core' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="browser_cache" value="1" <?php checked( $s['browser_cache'] ); ?> />
								<?php esc_html_e( 'Add browser-cache and GZIP rules', 'perdita-core' ); ?>
							</label> <span class="perdita-badge is-ok"><?php esc_html_e( 'Recommended', 'perdita-core' ); ?></span>
							<?php if ( $apache ) : ?>
								<p class="description"><?php esc_html_e( 'On this Apache server, you add a "Perdita Cache" block with mod_expires and mod_deflate rules to your .htaccess when you save. When you turn this off, you remove that block, and the rest of the file stays as it was.', 'perdita-core' ); ?></p>
							<?php else : ?>
								<p class="description"><?php esc_html_e( 'This server isn\'t Apache, so you add these rules to your server config yourself. Turn this on to keep the setting, then copy the nginx snippet below into your server block.', 'perdita-core' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
				</table>

				<h2 class="perdita-cache-section"><?php esc_html_e( 'CDN', 'perdita-core' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'CDN rewriting', 'perdita-core' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="cdn_enabled" value="1" <?php checked( $s['cdn_enabled'] ); ?> />
								<?php esc_html_e( 'Load stylesheets, scripts, images, and fonts from a CDN', 'perdita-core' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Set your CDN to pull from this site first. You still get files from this site on admin screens, in previews, and in the Customizer.', 'perdita-core' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="perdita-cache-cdn-host"><?php esc_html_e( 'CDN hostname', 'perdita-core' ); ?></label></th>
						<td>
							<input type="text" id="perdita-cache-cdn-host" name="cdn_host" value="<?php echo esc_attr( $s['cdn_host'] ); ?>" class="regular-text code" placeholder="cdn.example.com" autocomplete="off" spellcheck="false" />
							<p class="description"><?php esc_html_e( 'The hostname only, such as cdn.example.com, with no https:// and no path.', 'perdita-core' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="perdita-cache-cdn-dirs"><?php esc_html_e( 'Included directories', 'perdita-core' ); ?></label></th>
						<td>
							<textarea id="perdita-cache-cdn-dirs" name="cdn_dirs" rows="3" class="large-text code"><?php echo esc_textarea( implode( "\n", (array) $s['cdn_dirs'] ) ); ?></textarea>
							<p class="description"><?php esc_html_e( 'One directory per line. You get a CDN address for static files under these directories.', 'perdita-core' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="perdita-cache-cdn-exclude"><?php esc_html_e( 'Excluded URLs', 'perdita-core' ); ?></label></th>
						<td>
							<textarea id="perdita-cache-cdn-exclude" name="cdn_exclude" rows="3" class="large-text code"><?php echo esc_textarea( implode( "\n", (array) $s['cdn_exclude'] ) ); ?></textarea>
							<p class="description"><?php esc_html_e( 'One piece of a URL per line. You serve any URL containing one of these pieces from this site.', 'perdita-core' ); ?></p>
						</td>
					</tr>
				</table>

				<h2 class="perdita-cache-section"><?php esc_html_e( 'Debugging', 'perdita-core' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Debug log', 'perdita-core' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="debug_log" value="1" <?php checked( $s['debug_log'] ); ?> />
								<?php esc_html_e( 'Write cache decisions to a log', 'perdita-core' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'You get one line per decision: hit, miss, stored, or skipped and why. You can read the log on this screen. The file sits in the cache folder under a name nobody can guess, and logging ends after 24 hours.', 'perdita-core' ); ?></p>
							<?php if ( $s['debug_log'] ) : ?>
								<p class="description"><strong>
									<?php
									/* translators: %s: how long until the debug log turns off, such as "23 hours". */
									printf( esc_html__( 'Status: on for another %s.', 'perdita-core' ), esc_html( human_time_diff( time(), (int) $s['debug_log_until'] ) ) );
									?>
								</strong></p>
							<?php elseif ( $debug_expired ) : ?>
								<p class="description"><strong><?php esc_html_e( 'Status: off. The 24 hours ran out. Check the box and save to start again.', 'perdita-core' ); ?></strong></p>
							<?php endif; ?>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save changes', 'perdita-core' ) ); ?>
			</form>

			<div class="card perdita-cache-card">
				<h2 class="perdita-cache-card-title"><?php esc_html_e( 'Cached pages', 'perdita-core' ); ?></h2>
				<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="perdita-cache-search">
					<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>" />
					<label class="screen-reader-text" for="perdita-cache-search"><?php esc_html_e( 'Search cached pages', 'perdita-core' ); ?></label>
					<input type="search" id="perdita-cache-search" name="cache_s" value="<?php echo esc_attr( $list_search ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'Part of a URL, or a full URL', 'perdita-core' ); ?>" />
					<?php submit_button( __( 'Search', 'perdita-core' ), 'secondary', '', false ); ?>
					<?php if ( '' !== $list_search ) : ?>
						<a class="perdita-cache-showall" href="<?php echo esc_url( $list_url ); ?>"><?php esc_html_e( 'Show all', 'perdita-core' ); ?></a>
					<?php endif; ?>
				</form>
				<?php if ( $list['capped'] ) : ?>
					<p class="description">
						<?php
						printf(
							/* translators: %s: the most pages the list reads, such as 2,000. */
							esc_html__( 'This cache is large, so you see %s pages at a time. To find one page, search for its full URL.', 'perdita-core' ),
							'<span class="perdita-cache-num">' . esc_html( number_format_i18n( (int) $list['cap'] ) ) . '</span>'
						);
						?>
					</p>
				<?php endif; ?>
				<?php if ( empty( $list['rows'] ) ) : ?>
					<p><?php echo '' !== $list_search ? esc_html__( 'No cached page matches that search.', 'perdita-core' ) : esc_html__( 'No pages are cached yet.', 'perdita-core' ); ?></p>
				<?php else : ?>
					<table class="widefat striped perdita-cache-list">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'URL', 'perdita-core' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Age', 'perdita-core' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Size', 'perdita-core' ); ?></th>
								<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'perdita-core' ); ?></span></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $list['rows'] as $row ) : ?>
								<tr>
									<td class="perdita-cache-url">
										<?php if ( '' !== $row['url'] ) : ?>
											<a href="<?php echo esc_url( $row['url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $row['url'] ); ?></a>
										<?php else : ?>
											<code><?php echo esc_html( $row['id'] ); ?></code>
										<?php endif; ?>
										<?php if ( 'mobile' === $row['variant'] ) : ?>
											<span class="perdita-badge"><?php esc_html_e( 'Phone', 'perdita-core' ); ?></span>
										<?php elseif ( '' !== $row['variant'] ) : ?>
											<span class="perdita-badge"><?php esc_html_e( 'Variant', 'perdita-core' ); ?></span>
										<?php endif; ?>
										<?php if ( 'feed' === $row['type'] ) : ?>
											<span class="perdita-badge"><?php esc_html_e( 'Feed', 'perdita-core' ); ?></span>
										<?php endif; ?>
										<?php if ( $row['stale'] ) : ?>
											<span class="perdita-badge"><?php esc_html_e( 'Rebuilding', 'perdita-core' ); ?></span>
										<?php endif; ?>
									</td>
									<td class="perdita-cache-num" data-label="<?php esc_attr_e( 'Age', 'perdita-core' ); ?>"><?php echo esc_html( human_time_diff( (int) $row['mtime'] ) ); ?></td>
									<td class="perdita-cache-num" data-label="<?php esc_attr_e( 'Size', 'perdita-core' ); ?>"><?php echo esc_html( $this->human_size( $row['bytes'] ) ); ?></td>
									<td>
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="perdita-cache-flush">
											<input type="hidden" name="action" value="perdita_caching_delete" />
											<input type="hidden" name="page_id" value="<?php echo esc_attr( $row['id'] ); ?>" />
											<?php wp_nonce_field( 'perdita_caching_delete' ); ?>
											<button type="submit" class="button-link button-link-delete" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: URL of a cached page. */ __( 'Delete the cached copy of %s', 'perdita-core' ), '' !== $row['url'] ? $row['url'] : $row['id'] ) ); ?>"><?php esc_html_e( 'Delete', 'perdita-core' ); ?></button>
										</form>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
					<p class="perdita-cache-pager">
						<?php
						printf(
							/* translators: 1: current page number, 2: number of pages, 3: number of cached pages listed. */
							esc_html( _n( 'Page %1$s of %2$s (%3$s cached page)', 'Page %1$s of %2$s (%3$s cached pages)', (int) $list['total'], 'perdita-core' ) ),
							esc_html( number_format_i18n( (int) $list['paged'] ) ),
							esc_html( number_format_i18n( (int) $list['pages'] ) ),
							esc_html( number_format_i18n( (int) $list['total'] ) )
						);
						$list_args = '' !== $list_search ? array( 'cache_s' => $list_search ) : array();
						if ( $list['paged'] > 1 ) {
							echo ' &nbsp; <a href="' . esc_url( add_query_arg( array_merge( $list_args, array( 'cache_paged' => $list['paged'] - 1 ) ), $list_url ) ) . '">' . esc_html__( 'Previous', 'perdita-core' ) . '</a>';
						}
						if ( $list['paged'] < $list['pages'] ) {
							echo ' &nbsp; <a href="' . esc_url( add_query_arg( array_merge( $list_args, array( 'cache_paged' => $list['paged'] + 1 ) ), $list_url ) ) . '">' . esc_html__( 'Next', 'perdita-core' ) . '</a>';
						}
						?>
					</p>
				<?php endif; ?>
			</div>

			<?php if ( '' !== $log_tail || $s['debug_log'] ) : ?>
				<div class="card perdita-cache-card">
					<h2 id="perdita-cache-log-heading" class="perdita-cache-card-title"><?php esc_html_e( 'Debug log', 'perdita-core' ); ?></h2>
					<?php if ( '' === $log_tail ) : ?>
						<p><?php esc_html_e( 'You have no log lines yet. Visit a page on your site in a private window, then reload this screen.', 'perdita-core' ); ?></p>
					<?php else : ?>
						<p class="description"><?php esc_html_e( 'The newest 200 lines, in UTC.', 'perdita-core' ); ?></p>
						<textarea readonly rows="12" class="large-text code" aria-labelledby="perdita-cache-log-heading"><?php echo esc_textarea( $log_tail ); ?></textarea>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="perdita-cache-logform">
							<input type="hidden" name="action" value="perdita_caching_log_clear" />
							<?php wp_nonce_field( 'perdita_caching_log_clear' ); ?>
							<?php submit_button( __( 'Delete log', 'perdita-core' ), 'secondary', 'submit', false ); ?>
						</form>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<?php if ( ! $apache ) : ?>
				<div class="card perdita-cache-card">
					<h2 id="perdita-cache-nginx-heading" class="perdita-cache-card-title"><?php esc_html_e( 'nginx snippet', 'perdita-core' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Add this inside your server block, then reload nginx. You get long-lived browser caching for static assets, with GZIP turned on.', 'perdita-core' ); ?></p>
					<textarea readonly rows="12" class="large-text code" onclick="this.select()" aria-labelledby="perdita-cache-nginx-heading"><?php echo esc_textarea( Perdita_Caching::nginx_snippet() ); ?></textarea>
				</div>
			<?php endif; ?>

			<div class="card perdita-cache-card perdita-cache-card--note">
				<h2 class="perdita-cache-card-title"><?php esc_html_e( 'What is and is not here', 'perdita-core' ); ?></h2>
				<p><?php esc_html_e( 'These settings fit most small WordPress sites. You save each guest page as a static file and serve that file on the next visit, which helps most on modest hosting. You never edit wp-config.php from this screen, and with the optional early-serve drop-in, visitors get only pages already in the cache.', 'perdita-core' ); ?></p>
				<p class="description">
					<?php
					printf(
						/* translators: %s: link to Perdita Pro. */
						esc_html__( 'CSS and JavaScript minification and combining are part of %s.', 'perdita-core' ),
						'<a href="' . esc_url( 'https://perdita.ericrosenberg.com' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Perdita Pro', 'perdita-core' ) . '</a>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- href and link text both escaped above.
					);
					?>
				</p>
			</div>
		</div>
		<?php
	}
}
