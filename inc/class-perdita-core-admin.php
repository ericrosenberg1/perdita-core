<?php
/**
 * The "Perdita" top-level admin menu and its Dashboard screen.
 *
 * The menu slug is 'perdita' and has to stay that way. Every module admin
 * screen in inc/modules/*, and all twelve of Perdita Pro's, attach themselves
 * with add_submenu_page( 'perdita', ... ). Renaming the slug orphans every one
 * of them.
 *
 * The Modules screen itself came from the theme's inc/class-perdita-admin.php,
 * which no longer carries it: the toggles now govern plugin code and write to
 * a plugin option, so the screen belongs with them.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

class Perdita_Core_Admin {

	const PAGE   = 'perdita';
	const ACTION = 'perdita_core_save_modules';

	/**
	 * Plugin bootstrap.
	 *
	 * @var Perdita_Core
	 */
	private $core;

	/**
	 * Constructor.
	 *
	 * @param Perdita_Core $core Bootstrap.
	 */
	public function __construct( Perdita_Core $core ) {
		$this->core = $core;

		// Priority 9, deliberately BEFORE the default 10. Every module admin
		// screen here and in Perdita Pro registers with add_submenu_page(
		// 'perdita', ... ) at the default priority, and WordPress derives a
		// submenu's hook name from $admin_page_hooks['perdita'], which only
		// exists once add_menu_page() has actually run. A submenu registered
		// before its parent gets the hook name 'admin_page_<slug>', while
		// user_can_access_admin_page() later recomputes it as
		// 'perdita_page_<slug>'; the two disagree and WordPress answers the
		// screen with "Sorry, you are not allowed to access this page". Pro's
		// license screen carries the same note from the other direction (it
		// uses priority 20 to land after this one).
		add_action( 'admin_menu', array( $this, 'menu' ), 9 );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'save_modules' ) );
	}

	/**
	 * Register the top-level menu and its Dashboard submenu.
	 *
	 * Position 59 sits below the theme's own design menu and above Perdita
	 * SEO and Perdita Forms, so the group reads in a sensible order.
	 * add_menu_page() creates a first submenu with the parent's own slug;
	 * re-registering it here is what renames that entry to "Dashboard".
	 */
	public function menu() {
		add_menu_page(
			__( 'Perdita', 'perdita-core' ),
			__( 'Perdita', 'perdita-core' ),
			'edit_theme_options',
			self::PAGE,
			array( $this, 'page_dashboard' ),
			'dashicons-layout',
			59
		);
		add_submenu_page(
			self::PAGE,
			__( 'Dashboard', 'perdita-core' ),
			__( 'Dashboard', 'perdita-core' ),
			'edit_theme_options',
			self::PAGE,
			array( $this, 'page_dashboard' )
		);
	}

	/**
	 * The Dashboard: what this plugin is, and the module switchboard.
	 */
	public function page_dashboard() {
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'perdita-core' ) );
		}

		echo '<div class="wrap"><h1>' . esc_html__( 'Perdita', 'perdita-core' ) . ' <span style="font-size:12px;color:#787c82;">' . esc_html( PERDITA_CORE_VERSION ) . '</span></h1>';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display of a message this plugin put in the URL itself.
		if ( '' !== perdita_notice_text( 'perdita_msg' ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( perdita_notice_text( 'perdita_msg' ) ) . '</p></div>';
		}
		?>
		<h2><?php esc_html_e( 'Modules', 'perdita-core' ); ?></h2>
		<p><?php esc_html_e( 'Turn Perdita\'s features on or off. Each module only loads its code when it is on, and only on the requests that need it, so a site stays light no matter how many you enable. When a module is off, its menu and its code do not load at all.', 'perdita-core' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>" />
			<?php wp_nonce_field( self::ACTION ); ?>
			<?php $this->render_module_toggles(); ?>
			<?php submit_button( __( 'Save modules', 'perdita-core' ) ); ?>
		</form>
		</div>
		<?php
	}

	/**
	 * Modules whose toggle needs manage_options: they change who can sign
	 * in, what an outside client can do, or what is written to disk, and
	 * many sites give Editors edit_theme_options, which opens this screen.
	 *
	 * @param string $id Module id.
	 * @return bool
	 */
	public static function can_toggle( $id ) {
		$restricted = array( 'security', 'security-pro', 'backups', 'backups-pro', 'mcp', 'smtp', 'smtp-pro' );
		return ! in_array( (string) $id, $restricted, true ) || current_user_can( 'manage_options' );
	}

	/**
	 * Print every registered module as a checkbox, grouped so the screen
	 * reads in a sensible order rather than registration order.
	 */
	private function render_module_toggles() {
		$modules = $this->core->modules->all();

		$group_order = array(
			'content'        => __( 'Content', 'perdita-core' ),
			'seo'            => __( 'SEO and search', 'perdita-core' ),
			'performance'    => __( 'Performance', 'perdita-core' ),
			'infrastructure' => __( 'Site infrastructure', 'perdita-core' ),
			'commerce'       => __( 'Selling', 'perdita-core' ),
			'security'       => __( 'Security', 'perdita-core' ),
			'design'         => __( 'Design', 'perdita-core' ),
			'general'        => __( 'Other', 'perdita-core' ),
		);

		$by_group = array();
		foreach ( $modules as $id => $module ) {
			$group                    = isset( $group_order[ $module['group'] ] ) ? $module['group'] : 'general';
			$by_group[ $group ][ $id ] = $module;
		}

		foreach ( $group_order as $group => $group_label ) {
			if ( empty( $by_group[ $group ] ) ) {
				continue;
			}
			echo '<h3 style="margin:14px 0 4px;">' . esc_html( $group_label ) . '</h3>';
			foreach ( $by_group[ $group ] as $id => $module ) {
				printf(
					'<p style="margin:2px 0;"><label><input type="checkbox" name="module_%1$s" value="1" %2$s /> <strong>%3$s</strong>%4$s</label></p>',
					esc_attr( $id ),
					checked( $this->core->modules->is_enabled( $id ), true, false ) . ( self::can_toggle( $id ) ? '' : ' disabled="disabled"' ),
					esc_html( $module['label'] ),
					$module['description'] ? ' &middot; ' . esc_html( $module['description'] ) : ''
				);
			}
		}
	}

	/**
	 * Persist each registered module's on/off state. Turning a module on runs
	 * its activate callback (create tables, directories, generated secrets)
	 * so a freshly enabled module is ready to use on the next page load.
	 */
	public function save_modules() {
		if ( ! current_user_can( 'edit_theme_options' ) || ! check_admin_referer( self::ACTION ) ) {
			wp_die( esc_html__( 'Not allowed.', 'perdita-core' ) );
		}

		// The modules that change who can sign in, what an outside client
		// can do, or what is written to disk need manage_options. Many sites
		// give Editors edit_theme_options, which opens this screen.
		$modules = $this->core->modules;
		foreach ( array_keys( $modules->all() ) as $id ) {
			if ( ! self::can_toggle( $id ) ) {
				continue; // Left as it is.
			}
			$was = $modules->is_enabled( $id );
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_admin_referer() above.
			$now = ! empty( $_POST[ 'module_' . $id ] );
			$modules->set_module_state( $id, $now );
			if ( $now && ! $was ) {
				$modules->activate( $id );
			}
		}

		wp_safe_redirect(
			add_query_arg(
				'perdita_msg',
				rawurlencode( __( 'Modules updated. Reload to see any new menus.', 'perdita-core' ) ),
				admin_url( 'admin.php?page=' . self::PAGE )
			)
		);
		exit;
	}
}
