<?php
/**
 * The Switch to Perdita screen (Perdita, Switch to Perdita) and the compact
 * plugin notices on the Perdita dashboard and the Plugins screen.
 *
 * Every action posts to admin-post.php with a nonce bound to the action and
 * the source, and needs manage_options. Deactivate and Reactivate also need
 * activate_plugins. Importing never deactivates, and deactivating never
 * deletes.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

class Perdita_Switch_Admin {

	const PAGE   = 'perdita-switch';
	const ACTION = 'perdita_switch';

	/**
	 * Hook up.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
		add_action( 'admin_notices', array( $this, 'notices' ) );
	}

	/**
	 * Register the screen under the Perdita menu.
	 */
	public function menu() {
		$hook = add_submenu_page(
			'perdita',
			__( 'Switch to Perdita', 'perdita-core' ),
			__( 'Switch to Perdita', 'perdita-core' ),
			'manage_options',
			self::PAGE,
			array( $this, 'page' )
		);
		if ( $hook ) {
			add_action( 'admin_print_styles-' . $hook, array( $this, 'assets' ) );
		}
	}

	/**
	 * The screen's layout sheet and its two small scripts (the deactivate
	 * confirmation and the batch's next step). Controls (buttons, badges,
	 * focus) come from the shared admin sheet.
	 */
	public function assets() {
		if ( class_exists( 'Perdita_Core_Admin_UI' ) ) {
			Perdita_Core_Admin_UI::enqueue_assets();
		}
		wp_enqueue_style( 'perdita-switch', PERDITA_CORE_URL . 'assets/css/perdita-switch.css', array(), PERDITA_CORE_VERSION );
		wp_register_script( 'perdita-switch', false, array(), PERDITA_CORE_VERSION, true );
		wp_enqueue_script( 'perdita-switch' );
		wp_add_inline_script( 'perdita-switch', self::js() );
	}

	/**
	 * @return string
	 */
	private static function js() {
		return implode(
			"\n",
			array(
				'(function(){',
				'document.addEventListener("submit",function(e){var f=e.target;if(f&&f.getAttribute&&f.getAttribute("data-confirm")&&!window.confirm(f.getAttribute("data-confirm"))){e.preventDefault();}},true);',
				'var next=document.querySelector("form[data-auto-continue]");',
				'if(next){window.setTimeout(function(){next.submit();},1500);}',
				'})();',
			)
		);
	}

	/* ---------- actions ---------- */

	/**
	 * A form that posts one action for one source.
	 *
	 * @param string $do     Action.
	 * @param string $id     Source id.
	 * @param string $inner  HTML inside the form, already escaped.
	 * @param array  $attrs  Extra attributes, escaped here.
	 * @return string
	 */
	private static function form( $do, $id, $inner, array $attrs = array() ) {
		$extra = '';
		foreach ( $attrs as $k => $v ) {
			$extra .= ' ' . esc_attr( $k ) . '="' . esc_attr( $v ) . '"';
		}
		return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"' . $extra . '>'
			. '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '" />'
			. '<input type="hidden" name="do" value="' . esc_attr( $do ) . '" />'
			. '<input type="hidden" name="source" value="' . esc_attr( $id ) . '" />'
			// No id attribute: a page holds dozens of these forms.
			. '<input type="hidden" name="_wpnonce" value="' . esc_attr( wp_create_nonce( self::nonce( $do, $id ) ) ) . '" />'
			. wp_referer_field( false )
			. $inner
			. '</form>';
	}

	/**
	 * The nonce action for one action on one source.
	 *
	 * @param string $do Action.
	 * @param string $id Source id.
	 * @return string
	 */
	public static function nonce( $do, $id ) {
		return 'perdita_switch_' . $do . '_' . $id;
	}

	/**
	 * Run one action and go back to the screen.
	 */
	public function handle() {
		// phpcs:disable WordPress.Security.NonceVerification -- verified per action below, before anything runs.
		$do = isset( $_REQUEST['do'] ) ? sanitize_key( wp_unslash( $_REQUEST['do'] ) ) : '';
		$id = isset( $_REQUEST['source'] ) ? sanitize_key( wp_unslash( $_REQUEST['source'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'perdita-core' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::nonce( $do, $id ) );
		if ( 'dismiss' === $do && 'all' === $id ) {
			foreach ( array_keys( Perdita_Switch::nudges( get_current_user_id() ) ) as $nudged ) {
				Perdita_Switch::dismiss( get_current_user_id(), $nudged );
			}
			$back = wp_get_referer();
			wp_safe_redirect( $back ? $back : admin_url( 'admin.php?page=' . self::PAGE ) );
			exit;
		}
		if ( 'delete_backups' === $do && 'all' === $id ) {
			$n = Perdita_Switch_Backups::delete_all();
			/* translators: %s: number of backup copies deleted. */
			$this->back( 'backups', sprintf( _n( 'Deleted %s backup copy.', 'Deleted %s backup copies.', $n, 'perdita-core' ), number_format_i18n( $n ) ) );
		}
		$source = Perdita_Switch::get( $id );
		if ( ! $source ) {
			$this->back( '', __( 'You can\'t switch from that plugin.', 'perdita-core' ), false );
		}
		if ( in_array( $do, array( 'deactivate', 'reactivate' ), true ) && ! self::can_toggle( $source ) ) {
			wp_die( esc_html__( 'Not allowed.', 'perdita-core' ), '', array( 'response' => 403 ) );
		}
		if ( 'leftover' === $do && is_multisite() && ! current_user_can( 'manage_network_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'perdita-core' ), '', array( 'response' => 403 ) );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- check_admin_referer() above.
		switch ( $do ) {
			case 'import':
				$res = Perdita_Switch::import( $id, array( 'enable_module' => ! empty( $_POST['enable_module'] ) ) );
				$this->back( $id, $res['message'], $res['ok'] );
				break;
			case 'skip':
				Perdita_Switch::skip_import( $id );
				/* translators: %s: plugin name. */
				$this->back( $id, sprintf( __( 'Skipped the import from %s. You can still import later.', 'perdita-core' ), $source->label() ) );
				break;
			case 'undo':
				$res = Perdita_Switch::undo( $id );
				$this->back( $id, $res['message'], $res['ok'] );
				break;
			case 'deactivate':
				if ( empty( $_POST['confirm'] ) ) {
					$this->back( $id, __( 'Tick the box to confirm, then deactivate.', 'perdita-core' ), false );
				}
				$res = Perdita_Switch::deactivate( $id );
				$this->back( $id, $res['message'], $res['ok'] );
				break;
			case 'reactivate':
				$res = Perdita_Switch::reactivate( $id );
				$this->back( $id, $res['message'], $res['ok'] );
				break;
			case 'leftover':
				$item = isset( $_POST['item'] ) ? sanitize_text_field( wp_unslash( $_POST['item'] ) ) : '';
				if ( empty( $_POST['confirm'] ) ) {
					$this->back( $id, __( 'Tick the box to confirm, then try again.', 'perdita-core' ), false );
				}
				if ( ! method_exists( $source, 'fix_leftover' ) ) {
					$this->back( $id, __( 'That plugin has no leftovers to clean up.', 'perdita-core' ), false );
				}
				$res = $source->fix_leftover( $item );
				$this->back( $id, $res['message'], $res['ok'] );
				break;
			case 'batch':
				$done = Perdita_Switch::run_batch( 8 );
				$this->back( $id, $done ? __( 'Finished the term titles and descriptions.', 'perdita-core' ) : '' );
				break;
			case 'dismiss':
				Perdita_Switch::dismiss( get_current_user_id(), $id );
				$back = wp_get_referer();
				wp_safe_redirect( $back ? $back : admin_url( 'admin.php?page=' . self::PAGE ) );
				exit;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$this->back( $id, __( 'That action isn\'t available here.', 'perdita-core' ), false );
	}

	/**
	 * Whether this person may deactivate or reactivate the source plugin.
	 * activate_plugins always, and on a network, manage_network_plugins for
	 * a plugin that's network-active (or was, before this screen turned it
	 * off), since that switch reaches every site.
	 *
	 * @param Perdita_Switch_Source $source Source.
	 * @return bool
	 */
	public static function can_toggle( Perdita_Switch_Source $source ) {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return false;
		}
		if ( ! is_multisite() ) {
			return true;
		}
		$det = $source->detect();
		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$state   = Perdita_Switch::state( $source->id() );
		$network = ( '' !== $det['plugin'] && is_plugin_active_for_network( $det['plugin'] ) ) || ! empty( $state['network'] );
		return ! $network || current_user_can( 'manage_network_plugins' );
	}

	/**
	 * Redirect to the screen with a message.
	 *
	 * @param string $id      Source id for the anchor.
	 * @param string $message Message, '' for none.
	 * @param bool   $ok      Success or error.
	 */
	private function back( $id, $message, $ok = true ) {
		$url = admin_url( 'admin.php?page=' . self::PAGE );
		if ( '' !== $message ) {
			$url = add_query_arg( $ok ? 'perdita_msg' : 'perdita_err', rawurlencode( $message ), $url );
		}
		wp_safe_redirect( $url . ( '' !== $id ? '#pds-' . sanitize_key( $id ) : '' ) );
		exit;
	}

	/* ---------- the screen ---------- */

	/**
	 * Render the screen.
	 */
	public function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'perdita-core' ) );
		}
		echo '<div class="wrap perdita-switch"><h1>' . esc_html__( 'Switch to Perdita', 'perdita-core' ) . '</h1>';
		$msg = perdita_notice_text( 'perdita_msg' );
		$err = perdita_notice_text( 'perdita_err' );
		if ( '' !== $msg ) {
			echo '<div class="notice notice-success"><p>' . esc_html( $msg ) . '</p></div>';
		}
		if ( '' !== $err ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $err ) . '</p></div>';
		}
		echo '<p class="pds-intro">' . esc_html__( 'You have these plugins on your site. For each plugin, you can preview what moves to Perdita, import its settings, and then deactivate the plugin. You can undo any import, since a copy of Perdita\'s settings is saved first. When you deactivate a plugin, you keep its files and settings, so you can turn the plugin back on anytime.', 'perdita-core' ) . '</p>';

		$sources = Perdita_Switch::sources();

		/**
		 * Fires near the top of the Switch to Perdita screen, after its
		 * notices and introduction and before the running batch and the
		 * cards. For screen-level content, such as a panel of your own.
		 *
		 * @param Perdita_Switch_Source[] $sources Every registered source, keyed by id().
		 */
		do_action( 'perdita_switch_screen_before', $sources );

		$this->render_job();

		$groups = array();
		$absent = array();
		foreach ( $sources as $id => $source ) {
			$det   = $source->detect();
			$state = Perdita_Switch::state( $id );
			if ( $det['installed'] || $det['has_settings'] || $state['imported'] || Perdita_Switch::snapshot( $id ) ) {
				$groups[ $source->module() ][ $id ] = array( $source, $det, $state );
			} else {
				$absent[] = $source->label();
			}
		}
		if ( ! $groups ) {
			echo '<div class="pds-card"><p>' . esc_html__( 'You don\'t have any of the plugins you can switch from, or any settings they left, so you have nothing to switch.', 'perdita-core' ) . '</p></div>';
		}
		foreach ( $groups as $module => $cards ) {
			echo '<h2 class="pds-group">' . esc_html( Perdita_Switch_Target::module_label( $module ) ) . '</h2>';
			foreach ( $cards as $id => $card ) {
				$this->render_card( $id, $card[0], $card[1], $card[2] );
			}
		}
		if ( $absent ) {
			/* translators: %s: comma-separated plugin names. */
			echo '<p class="pds-meta">' . esc_html( sprintf( __( 'You can also switch from %s. You don\'t have any of those plugins installed.', 'perdita-core' ), implode( ', ', $absent ) ) ) . '</p>';
		}
		$this->render_backups();

		/**
		 * Fires at the bottom of the Switch to Perdita screen, after the
		 * cards and the backup copies, inside the screen's wrapper.
		 *
		 * @param Perdita_Switch_Source[] $sources Every registered source, keyed by id().
		 */
		do_action( 'perdita_switch_screen_after', $sources );
		echo '</div>';
	}

	/**
	 * The backup copies of edited files, with one button that deletes them
	 * all. Shown only while a copy exists.
	 */
	private function render_backups() {
		$items = Perdita_Switch_Backups::all();
		if ( ! $items ) {
			return;
		}
		echo '<div class="pds-card" id="pds-backups">';
		echo '<h2 class="pds-card-title">' . esc_html__( 'Backup copies', 'perdita-core' ) . '</h2>';
		/* translators: %s: backup folder, such as wp-content/uploads/perdita-switch-backups/. */
		echo '<p>' . esc_html( sprintf( __( 'You get a copy of each file before it changes on this screen, in %s. Each copy has a name nobody can guess, and on Apache and IIS, visitors can\'t open the folder at all. We never copy wp-config.php. You get a note with the one line we added instead. Delete the copies once your site works.', 'perdita-core' ), Perdita_Switch_Backups::display_dir() ) ) . '</p>';
		echo '<ul class="pds-backups">';
		foreach ( $items as $item ) {
			echo '<li><code>' . esc_html( $item['name'] . ( $item['dir'] ? '/' : '' ) ) . '</code> <span class="pds-meta perdita-num">' . esc_html( self::when( $item['time'] ) . ' · ' . size_format( $item['size'], 1 ) ) . '</span></li>';
		}
		echo '</ul>';
		/* translators: %s: number of backup copies. */
		$ask = sprintf( _n( 'Delete %s backup copy for good?', 'Delete all %s backup copies for good?', count( $items ), 'perdita-core' ), number_format_i18n( count( $items ) ) );
		echo '<div class="pds-actions">' . self::form( 'delete_backups', 'all', get_submit_button( __( 'Delete backup copies', 'perdita-core' ), 'delete', '', false ), array( 'data-confirm' => $ask ) ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		echo '</div>';
	}

	/**
	 * The running batch, with a button that runs the next step (and does so
	 * by itself every few seconds while the screen is open).
	 */
	private function render_job() {
		$job = Perdita_Switch::job();
		if ( ! $job ) {
			return;
		}
		$source = Perdita_Switch::get( $job['source'] );
		if ( ! $source ) {
			return;
		}
		$total = max( 1, (int) $job['total'] );
		$done  = min( $total, (int) $job['done'] );
		echo '<div class="pds-card" id="pds-job">';
		echo '<p><strong>' . esc_html(
			'undo' === $job['mode']
				/* translators: %s: plugin name. */
				? sprintf( __( 'Undoing the term titles and descriptions from %s', 'perdita-core' ), $source->label() )
				/* translators: %s: plugin name. */
				: sprintf( __( 'Importing term titles and descriptions from %s', 'perdita-core' ), $source->label() )
		) . '</strong></p>';
		echo '<progress max="' . esc_attr( (string) $total ) . '" value="' . esc_attr( (string) $done ) . '"></progress>';
		/* translators: 1: terms done, 2: terms in all. */
		echo '<p class="pds-meta">' . esc_html( sprintf( __( '%1$s of %2$s terms done. You can leave this screen, and you\'ll still get the rest in the background.', 'perdita-core' ), number_format_i18n( $done ), number_format_i18n( $total ) ) ) . '</p>';
		echo self::form( 'batch', $job['source'], get_submit_button( __( 'Continue now', 'perdita-core' ), 'secondary', '', false ), array( 'data-auto-continue' => '1' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts in form().
		echo '</div>';
	}

	/**
	 * One plugin's card.
	 *
	 * @param string                $id     Source id.
	 * @param Perdita_Switch_Source $source Source.
	 * @param array                 $det    detect().
	 * @param array                 $state  Stored state.
	 */
	private function render_card( $id, Perdita_Switch_Source $source, array $det, array $state ) {
		$label    = $source->label();
		$module   = $source->module();
		$snapshot = Perdita_Switch::snapshot( $id );
		$scan     = $source->scan();
		$counts   = array(
			'import' => 0,
			'skip'   => 0,
			'none'   => 0,
		);
		foreach ( $scan['rows'] as $row ) {
			if ( isset( $counts[ $row['status'] ] ) ) {
				++$counts[ $row['status'] ];
			}
		}

		echo '<div class="pds-card" id="pds-' . esc_attr( $id ) . '">';
		echo '<div class="pds-head"><h3>' . esc_html( $label ) . '</h3>';
		if ( $det['active'] ) {
			echo '<span class="perdita-badge is-warn">' . esc_html__( 'Active', 'perdita-core' ) . '</span>';
		} elseif ( $det['installed'] ) {
			echo '<span class="perdita-badge">' . esc_html__( 'Inactive', 'perdita-core' ) . '</span>';
		} else {
			echo '<span class="perdita-badge">' . esc_html__( 'Removed, settings still saved', 'perdita-core' ) . '</span>';
		}
		if ( $state['imported'] ) {
			echo '<span class="perdita-badge is-ok">' . esc_html__( 'Imported', 'perdita-core' ) . '</span>';
		} elseif ( $state['skipped'] ) {
			echo '<span class="perdita-badge">' . esc_html__( 'Import skipped', 'perdita-core' ) . '</span>';
		}
		echo '</div>';

		$meta = array();
		if ( $state['imported'] ) {
			/* translators: %s: date and time. */
			$meta[] = sprintf( __( 'Imported %s.', 'perdita-core' ), self::when( $state['imported'] ) );
			if ( isset( $state['counts']['terms'] ) && is_array( $state['counts']['terms'] ) ) {
				/* translators: 1: terms written, 2: terms that already had Perdita values. */
				$meta[] = sprintf( __( 'Term titles and descriptions: %1$s copied, %2$s already set in Perdita.', 'perdita-core' ), number_format_i18n( (int) $state['counts']['terms']['written'] ), number_format_i18n( (int) $state['counts']['terms']['kept'] ) );
			}
		}
		if ( $state['deactivated'] && ! $det['active'] ) {
			/* translators: %s: date and time. */
			$meta[] = sprintf( __( 'Deactivated here %s.', 'perdita-core' ), self::when( $state['deactivated'] ) );
		}
		if ( $meta ) {
			echo '<p class="pds-meta">' . esc_html( implode( ' ', $meta ) ) . '</p>';
		}

		if ( $det['active'] ) {
			$conflict = $source->conflict_note();
			if ( '' !== $conflict ) {
				echo '<div class="pds-note"><p>' . esc_html( $conflict ) . '</p></div>';
			}
		}
		$pro = $source->pro_note();
		if ( '' !== $pro ) {
			echo '<p>' . esc_html( $pro );
			$url = 'seo' === $module ? (string) apply_filters( 'perdita_seo_per_post_import_url', '' ) : '';
			if ( '' !== $url ) {
				echo ' <a href="' . esc_url( $url ) . '">' . esc_html__( 'Import per-post SEO', 'perdita-core' ) . '</a>';
			}
			echo '</p>';
		}

		$this->render_preview( $scan, $counts );
		$this->render_actions( $id, $source, $det, $state, $snapshot, $counts );
		if ( method_exists( $source, 'leftovers' ) ) {
			$this->render_leftovers( $id, $source->leftovers() );
		}

		/**
		 * Fires at the end of one source's card, after the card's own
		 * controls and inside its wrapper (div.pds-card#pds-{id}).
		 *
		 * @param Perdita_Switch_Source $source The card's source.
		 * @param array                 $state  Its stored state (Perdita_Switch::state()).
		 */
		do_action( 'perdita_switch_card_after', $source, $state );
		echo '</div>';
	}

	/**
	 * The dry-run table.
	 *
	 * @param array $scan   scan().
	 * @param array $counts Rows by status.
	 */
	private function render_preview( array $scan, array $counts ) {
		if ( ! $scan['rows'] && ! $scan['notes'] ) {
			echo '<p class="pds-meta">' . esc_html__( 'You have no settings here to import.', 'perdita-core' ) . '</p>';
			return;
		}
		/* translators: 1: settings to import, 2: settings Perdita refuses, 3: settings with no match. */
		$summary = sprintf( __( 'Preview: %1$s to import, %2$s refused, and %3$s with no Perdita match', 'perdita-core' ), number_format_i18n( $counts['import'] ), number_format_i18n( $counts['skip'] ), number_format_i18n( $counts['none'] ) );
		echo '<details><summary>' . esc_html( $summary ) . '</summary>';
		if ( $scan['rows'] ) {
			$heads  = array(
				'setting' => __( 'Setting', 'perdita-core' ),
				'found'   => __( 'Found', 'perdita-core' ),
				'target'  => __( 'Perdita setting', 'perdita-core' ),
				'value'   => __( 'Value in Perdita', 'perdita-core' ),
				'status'  => __( 'Result', 'perdita-core' ),
			);
			$status = array(
				'import' => __( 'To import', 'perdita-core' ),
				'skip'   => __( 'Refused', 'perdita-core' ),
				'none'   => __( 'No match', 'perdita-core' ),
			);
			echo '<table class="pds-table"><thead><tr>';
			foreach ( $heads as $head ) {
				echo '<th scope="col">' . esc_html( $head ) . '</th>';
			}
			echo '</tr></thead><tbody>';
			foreach ( $scan['rows'] as $row ) {
				echo '<tr>';
				foreach ( array( 'setting', 'found', 'target', 'value' ) as $col ) {
					echo '<td data-label="' . esc_attr( $heads[ $col ] ) . '"><div>' . esc_html( (string) $row[ $col ] ) . '</div></td>';
				}
				$st = isset( $status[ $row['status'] ] ) ? $row['status'] : 'none';
				echo '<td data-label="' . esc_attr( $heads['status'] ) . '"><div><span class="pds-st-' . esc_attr( $st ) . '">' . esc_html( $status[ $st ] ) . '</span>';
				if ( '' !== (string) $row['reason'] ) {
					echo '<br />' . esc_html( (string) $row['reason'] );
				}
				echo '</div></td></tr>';
			}
			echo '</tbody></table>';
		}
		foreach ( $scan['notes'] as $note ) {
			echo '<p>' . esc_html( (string) $note ) . '</p>';
		}
		echo '</details>';
	}

	/**
	 * Import, skip, undo, deactivate and reactivate.
	 *
	 * @param string                $id       Source id.
	 * @param Perdita_Switch_Source $source   Source.
	 * @param array                 $det      detect().
	 * @param array                 $state    Stored state.
	 * @param array|null            $snapshot Snapshot.
	 * @param array                 $counts   Rows by status.
	 */
	private function render_actions( $id, Perdita_Switch_Source $source, array $det, array $state, $snapshot, array $counts ) {
		$label  = $source->label();
		$module = $source->module();
		$batch  = $source->batch_keys();
		$terms  = ! empty( $batch['keys'] ) && Perdita_Switch::batch_total( $source ) > 0;
		echo '<div class="pds-actions">';

		if ( $counts['import'] > 0 || $terms ) {
			$inner = '';
			if ( ! Perdita_Switch::module_on( $module ) ) {
				/* translators: %s: module name. */
				$inner .= '<label><input type="checkbox" name="enable_module" value="1" checked="checked" /> ' . esc_html( sprintf( __( 'Turn on %s too', 'perdita-core' ), Perdita_Switch_Target::module_label( $module ) ) ) . '</label>';
			}
			$inner .= get_submit_button( $state['imported'] ? __( 'Import again', 'perdita-core' ) : __( 'Import settings', 'perdita-core' ), 'primary', '', false );
			echo self::form( 'import', $id, $inner ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		}
		if ( $det['active'] && ! $state['imported'] && ! $state['skipped'] ) {
			echo self::form( 'skip', $id, get_submit_button( __( 'Skip the import', 'perdita-core' ), 'secondary', '', false ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		}
		if ( $snapshot ) {
			/* translators: %s: plugin name. */
			$ask = sprintf( __( 'Put Perdita\'s settings back to how they were before the import from %s?', 'perdita-core' ), $label );
			if ( Perdita_Switch::changed_since_import( $id ) ) {
				/* translators: %s: plugin name. */
				$ask = sprintf( __( 'You changed these Perdita settings after the import from %s. Undo puts back the settings from before the import, so you lose those later changes too. Undo anyway?', 'perdita-core' ), $label );
				echo '<p class="pds-meta">' . esc_html__( 'You changed these settings after the import. Undo also takes out those later changes.', 'perdita-core' ) . '</p>';
			}
			echo self::form( 'undo', $id, get_submit_button( __( 'Undo this import', 'perdita-core' ), 'secondary', '', false ), array( 'data-confirm' => $ask ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		}
		echo '</div>';

		if ( $det['active'] && self::can_toggle( $source ) ) {
			$allowed = Perdita_Switch::deactivate_allowed( $id );
			if ( true === $allowed ) {
				/* translators: %s: plugin name. */
				$ask   = sprintf( __( 'Deactivate %s now?', 'perdita-core' ), $label );
				$inner = '<label><input type="checkbox" name="confirm" value="1" required="required" /> '
					/* translators: %s: plugin name. */
					. esc_html( sprintf( __( 'I checked Perdita\'s settings and want to deactivate %s. I\'ll keep its files and settings.', 'perdita-core' ), $label ) ) . '</label>'
					/* translators: %s: plugin name. */
					. get_submit_button( sprintf( __( 'Deactivate %s', 'perdita-core' ), $label ), 'secondary', '', false );
				echo '<div class="pds-actions">' . self::form( 'deactivate', $id, $inner, array( 'data-confirm' => $ask ) ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
			} else {
				echo '<p class="pds-meta">' . esc_html( $allowed ) . '</p>';
			}
		}
		if ( ! $det['active'] && $det['installed'] && $state['deactivated'] && self::can_toggle( $source ) ) {
			/* translators: %s: plugin name. */
			echo '<div class="pds-actions">' . self::form( 'reactivate', $id, get_submit_button( sprintf( __( 'Reactivate %s', 'perdita-core' ), $label ), 'secondary', '', false ) ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		}
	}

	/**
	 * Files the plugin left behind, each with its own confirmed fix.
	 *
	 * @param string  $id    Source id.
	 * @param array[] $items From leftovers().
	 */
	private function render_leftovers( $id, array $items ) {
		if ( ! $items ) {
			return;
		}
		echo '<div class="pds-left"><p><strong>' . esc_html__( 'Left behind', 'perdita-core' ) . '</strong></p>';
		$locked = ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) || ( is_multisite() && ! current_user_can( 'manage_network_options' ) );
		foreach ( $items as $item ) {
			echo '<p><code>' . esc_html( $item['label'] ) . '</code> ' . esc_html( $item['detail'] ) . '</p>';
			if ( '' !== $item['line'] ) {
				echo '<p><code>' . esc_html( $item['line'] ) . '</code></p>';
			}
			if ( empty( $item['removable'] ) || $locked ) {
				echo '<p class="pds-meta">' . esc_html__( 'Make this change yourself. We can\'t safely change the file here, this site has file changes from plugins turned off, or the file covers a whole network.', 'perdita-core' ) . '</p>';
				continue;
			}
			$is_add  = 'wp_cache' === $item['id'];
			$confirm = isset( $item['confirm'] ) && '' !== (string) $item['confirm'] ? (string) $item['confirm'] : __( 'Remove this leftover. You\'ll get a backup copy first.', 'perdita-core' );
			$inner   = '<input type="hidden" name="item" value="' . esc_attr( $item['id'] ) . '" />'
				. '<label><input type="checkbox" name="confirm" value="1" required="required" /> '
				. esc_html( $confirm ) . '</label>'
				. get_submit_button( $is_add ? __( 'Add the line', 'perdita-core' ) : __( 'Remove', 'perdita-core' ), 'secondary', '', false );
			echo '<div class="pds-actions">' . self::form( 'leftover', $id, $inner ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		}
		echo '</div>';
	}

	/**
	 * A stored time for the screen.
	 *
	 * @param int $time Unix time.
	 * @return string
	 */
	private static function when( $time ) {
		return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $time );
	}

	/* ---------- notices ---------- */

	/**
	 * One compact line per plugin Perdita can import from, on the Perdita
	 * dashboard and the Plugins screen. Dismissing one hides it for good.
	 */
	public function notices() {
		if ( ! current_user_can( 'manage_options' ) || ! function_exists( 'get_current_screen' ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->id, array( 'toplevel_page_perdita', 'plugins' ), true ) ) {
			return;
		}
		$nudges = Perdita_Switch::nudges( get_current_user_id() );
		if ( ! $nudges ) {
			return;
		}
		// One notice for all of them, so the screen never stacks a row of nudges.
		$names = array();
		foreach ( $nudges as $source ) {
			$names[] = $source->label();
		}
		$last    = array_pop( $names );
		$list    = $names ? sprintf(
			/* translators: 1: comma-separated plugin names, 2: the last plugin name. */
			_n( '%1$s and %2$s', '%1$s, and %2$s', count( $names ) + 1, 'perdita-core' ),
			implode( ', ', $names ),
			$last
		) : $last;
		$first   = array_keys( $nudges )[0];
		$review  = admin_url( 'admin.php?page=' . self::PAGE ) . ( 1 === count( $nudges ) ? '#pds-' . $first : '' );
		$dismiss = wp_nonce_url(
			add_query_arg(
				array(
					'action' => self::ACTION,
					'do'     => 'dismiss',
					'source' => 'all',
				),
				admin_url( 'admin-post.php' )
			),
			self::nonce( 'dismiss', 'all' )
		);
		echo '<div class="notice notice-info"><p>';
		/* translators: %s: one or more plugin names. */
		echo esc_html( sprintf( __( 'You can bring your settings over to Perdita from %s.', 'perdita-core' ), $list ) );
		echo ' <a href="' . esc_url( $review ) . '">' . esc_html__( 'Review the switch', 'perdita-core' ) . '</a>';
		echo ' &middot; <a href="' . esc_url( $dismiss ) . '">' . esc_html__( 'Dismiss', 'perdita-core' ) . '</a></p></div>';
	}
}
