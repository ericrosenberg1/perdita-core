<?php
/**
 * Perdita Forms admin: the form editor (structured field builder, email, and
 * messages) and a submissions viewer.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

class Perdita_Forms_Admin {

	/**
	 * Forms core.
	 *
	 * @var Perdita_Forms
	 */
	private $forms;

	/**
	 * Constructor.
	 *
	 * @param Perdita_Forms $forms Forms core.
	 */
	public function __construct( Perdita_Forms $forms ) {
		$this->forms = $forms;
		add_action( 'add_meta_boxes', array( $this, 'meta_boxes' ) );
		add_action( 'save_post_' . Perdita_Forms::CPT, array( $this, 'save' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_menu', array( $this, 'entries_menu' ) );
		add_action( 'admin_menu', array( $this, 'settings_menu' ) );
		add_action( 'admin_post_perdita_forms_settings', array( $this, 'save_settings' ) );
	}

	/**
	 * Enqueue the builder script on the form editor.
	 *
	 * @param string $hook Hook.
	 */
	public function assets( $hook ) {
		$screen = get_current_screen();
		if ( $screen && Perdita_Forms::CPT === $screen->post_type && in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			wp_enqueue_script( 'perdita-forms-admin', PERDITA_CORE_URL . 'assets/js/forms-admin.js', array( 'jquery' ), PERDITA_CORE_VERSION, true );
		}
	}

	/**
	 * Register the editor meta boxes.
	 */
	public function meta_boxes() {
		add_meta_box( 'perdita-form-shortcode', __( 'Embed', 'perdita-core' ), array( $this, 'box_shortcode' ), Perdita_Forms::CPT, 'side' );
		add_meta_box( 'perdita-form-spam', __( 'Spam protection', 'perdita-core' ), array( $this, 'box_spam' ), Perdita_Forms::CPT, 'side' );
		add_meta_box( 'perdita-form-fields', __( 'Fields', 'perdita-core' ), array( $this, 'box_fields' ), Perdita_Forms::CPT, 'normal', 'high' );
		add_meta_box( 'perdita-form-email', __( 'Email & messages', 'perdita-core' ), array( $this, 'box_email' ), Perdita_Forms::CPT, 'normal' );
	}

	/**
	 * Shortcode box.
	 *
	 * @param WP_Post $post Post.
	 */
	public function box_shortcode( $post ) {
		echo '<p>' . esc_html__( 'Paste this where you want the form:', 'perdita-core' ) . '</p>';
		echo '<input type="text" readonly class="widefat" onclick="this.select()" aria-label="' . esc_attr__( 'Shortcode to embed this form', 'perdita-core' ) . '" value="' . esc_attr( '[perdita_form id="' . $post->ID . '"]' ) . '" />';
	}

	/**
	 * Spam-protection status box: surfaces the Cloudflare Turnstile connect
	 * state right on the form editor, instead of only under a
	 * generically-labeled "Settings" screen tucked under a different admin
	 * menu that a site owner building a form has no reason to visit.
	 */
	public function box_spam() {
		$s          = get_option( 'perdita_forms_settings', array() );
		$s          = is_array( $s ) ? $s : array();
		$connected  = ! empty( $s['turnstile_site_key'] ) && ! empty( $s['turnstile_secret_key'] );
		$settings_url = admin_url( 'edit.php?post_type=' . Perdita_Forms::CPT . '&page=perdita-forms-settings' );
		if ( $connected ) {
			echo '<p style="color:#008a20;">&#10003; ' . esc_html__( 'Cloudflare Turnstile is connected.', 'perdita-core' ) . '</p>';
		} else {
			echo '<p>' . esc_html__( 'Cloudflare Turnstile (a free CAPTCHA) is not connected yet. Every form is still protected by a honeypot, a nonce, and rate limiting.', 'perdita-core' ) . '</p>';
		}
		echo '<p><a href="' . esc_url( $settings_url ) . '" class="button">' . esc_html__( 'Manage spam protection', 'perdita-core' ) . '</a></p>';
		echo '<p class="description">' . esc_html__( 'Google reCAPTCHA is a planned Pro feature.', 'perdita-core' ) . '</p>';
	}

	/**
	 * Fields builder box.
	 *
	 * @param WP_Post $post Post.
	 */
	public function box_fields( $post ) {
		wp_nonce_field( 'perdita_form_save', 'perdita_form_nonce' );
		$cfg    = $this->forms->config( $post->ID );
		$types  = Perdita_Forms::field_types();
		?>
		<p class="description"><?php esc_html_e( 'Add the fields people fill in. The field name is used in emails and must be unique (lowercase, no spaces).', 'perdita-core' ); ?></p>
		<table class="widefat perdita-fields" style="margin-bottom:10px;">
			<thead><tr>
				<th><?php esc_html_e( 'Type', 'perdita-core' ); ?></th>
				<th><?php esc_html_e( 'Label', 'perdita-core' ); ?></th>
				<th><?php esc_html_e( 'Name', 'perdita-core' ); ?></th>
				<th><?php esc_html_e( 'Options (comma-separated)', 'perdita-core' ); ?></th>
				<th><?php esc_html_e( 'Required', 'perdita-core' ); ?></th>
				<th></th>
			</tr></thead>
			<tbody class="perdita-fields__rows">
				<?php foreach ( $cfg['fields'] as $i => $f ) : ?>
					<?php $this->field_row( $i, $f, $types ); ?>
				<?php endforeach; ?>
			</tbody>
		</table>
		<button type="button" class="button perdita-fields__add"><?php esc_html_e( 'Add field', 'perdita-core' ); ?></button>

		<script type="text/template" id="perdita-field-row-tpl">
			<?php $this->field_row( '__i__', array( 'type' => 'text', 'label' => '', 'name' => '', 'required' => false, 'placeholder' => '', 'options' => array() ), $types ); ?>
		</script>
		<?php
	}

	/**
	 * One field row.
	 *
	 * @param int|string $i     Index.
	 * @param array      $f     Field.
	 * @param array      $types Type choices.
	 */
	private function field_row( $i, $f, $types ) {
		$base = 'df[fields][' . $i . ']';
		?>
		<tr class="perdita-fields__row">
			<td>
				<select name="<?php echo esc_attr( $base . '[type]' ); ?>">
					<?php foreach ( $types as $val => $label ) : ?>
						<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $f['type'], $val ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
			<td><input type="text" class="perdita-field-label" name="<?php echo esc_attr( $base . '[label]' ); ?>" value="<?php echo esc_attr( $f['label'] ); ?>" /></td>
			<td><input type="text" class="perdita-field-name" name="<?php echo esc_attr( $base . '[name]' ); ?>" value="<?php echo esc_attr( $f['name'] ); ?>" /></td>
			<td><input type="text" class="widefat" name="<?php echo esc_attr( $base . '[options]' ); ?>" value="<?php echo esc_attr( implode( ', ', (array) $f['options'] ) ); ?>" placeholder="<?php esc_attr_e( 'For dropdown / radio / checkboxes', 'perdita-core' ); ?>" /></td>
			<td style="text-align:center;"><input type="checkbox" name="<?php echo esc_attr( $base . '[required]' ); ?>" value="1" <?php checked( ! empty( $f['required'] ) ); ?> /></td>
			<td><button type="button" class="button-link perdita-fields__remove" aria-label="<?php esc_attr_e( 'Remove', 'perdita-core' ); ?>">&times;</button></td>
		</tr>
		<?php
	}

	/**
	 * Email + messages box.
	 *
	 * @param WP_Post $post Post.
	 */
	public function box_email( $post ) {
		$cfg = $this->forms->config( $post->ID );
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="df-to"><?php esc_html_e( 'Send to', 'perdita-core' ); ?></label></th>
				<td><input type="text" id="df-to" class="regular-text" name="df[email][to]" value="<?php echo esc_attr( $cfg['email']['to'] ); ?>" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="df-subject"><?php esc_html_e( 'Subject', 'perdita-core' ); ?></label></th>
				<td><input type="text" id="df-subject" class="regular-text" name="df[email][subject]" value="<?php echo esc_attr( $cfg['email']['subject'] ); ?>" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="df-body"><?php esc_html_e( 'Email body', 'perdita-core' ); ?></label></th>
				<td>
					<textarea id="df-body" class="large-text" rows="5" name="df[email][body]"><?php echo esc_textarea( $cfg['email']['body'] ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Use {field_name} placeholders. Leave blank to email every field automatically.', 'perdita-core' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="df-success"><?php esc_html_e( 'Success message', 'perdita-core' ); ?></label></th>
				<td><input type="text" id="df-success" class="large-text" name="df[messages][success]" value="<?php echo esc_attr( $cfg['messages']['success'] ); ?>" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="df-error"><?php esc_html_e( 'Error message', 'perdita-core' ); ?></label></th>
				<td><input type="text" id="df-error" class="large-text" name="df[messages][error]" value="<?php echo esc_attr( $cfg['messages']['error'] ); ?>" /></td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Persist the form config.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post.
	 */
	public function save( $post_id, $post ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! isset( $_POST['perdita_form_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['perdita_form_nonce'] ) ), 'perdita_form_save' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$in     = isset( $_POST['df'] ) ? wp_unslash( $_POST['df'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized field by field below.
		$fields = array();
		$used   = array();
		if ( ! empty( $in['fields'] ) && is_array( $in['fields'] ) ) {
			foreach ( $in['fields'] as $f ) {
				$label = sanitize_text_field( $f['label'] ?? '' );
				$type  = array_key_exists( $f['type'] ?? '', Perdita_Forms::field_types() ) ? $f['type'] : 'text';
				if ( '' === $label ) {
					continue;
				}
				$name = sanitize_key( $f['name'] ?? '' );
				if ( '' === $name ) {
					$name = sanitize_key( str_replace( '-', '_', sanitize_title( $label ) ) );
				}
				while ( '' === $name || in_array( $name, $used, true ) ) {
					$name .= '_' . ( count( $used ) + 1 );
				}
				$used[]   = $name;
				$opts_raw = isset( $f['options'] ) ? (string) $f['options'] : '';
				$options  = array_values( array_filter( array_map( 'trim', explode( ',', $opts_raw ) ), 'strlen' ) );
				$fields[] = array(
					'type'        => $type,
					'label'       => $label,
					'name'        => $name,
					'required'    => ! empty( $f['required'] ),
					'placeholder' => sanitize_text_field( $f['placeholder'] ?? '' ),
					'options'     => array_map( 'sanitize_text_field', $options ),
				);
			}
		}

		$cfg = array(
			'fields'   => $fields ? $fields : Perdita_Forms::default_config()['fields'],
			'email'    => array(
				'to'      => sanitize_text_field( $in['email']['to'] ?? get_option( 'admin_email' ) ),
				'subject' => sanitize_text_field( $in['email']['subject'] ?? '' ),
				'body'    => sanitize_textarea_field( $in['email']['body'] ?? '' ),
			),
			'messages' => array(
				'success' => sanitize_text_field( $in['messages']['success'] ?? '' ),
				'error'   => sanitize_text_field( $in['messages']['error'] ?? '' ),
			),
		);
		update_post_meta( $post_id, Perdita_Forms::META, $cfg );
	}

	/* ---------- entries ---------- */

	/**
	 * Entries submenu under the Forms menu.
	 */
	public function entries_menu() {
		add_submenu_page( 'edit.php?post_type=' . Perdita_Forms::CPT, __( 'Entries', 'perdita-core' ), __( 'Entries', 'perdita-core' ), 'edit_pages', 'perdita-entries', array( $this, 'entries_page' ) );
	}

	/**
	 * Settings submenu (spam protection / Turnstile) under the Forms menu.
	 */
	public function settings_menu() {
		// Menu label is explicitly "Spam Protection", not a generic
		// "Settings", so it's findable by name instead of by guessing which
		// menu might hide the CAPTCHA option.
		add_submenu_page( 'edit.php?post_type=' . Perdita_Forms::CPT, __( 'Spam Protection', 'perdita-core' ), __( 'Spam Protection', 'perdita-core' ), 'manage_options', 'perdita-forms-settings', array( $this, 'settings_page' ) );
	}

	/**
	 * Render the forms settings screen. Cloudflare Turnstile is the free
	 * CAPTCHA. The secret key is stored encrypted at rest.
	 */
	public function settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s        = get_option( 'perdita_forms_settings', array() );
		$s        = is_array( $s ) ? $s : array();
		$site     = isset( $s['turnstile_site_key'] ) ? (string) $s['turnstile_site_key'] : '';
		$has_secret = ! empty( $s['turnstile_secret_key'] );
		echo '<div class="wrap"><h1>' . esc_html__( 'Spam Protection', 'perdita-core' ) . '</h1>';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['perdita_msg'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['perdita_msg'] ) ) ) . '</p></div>';
		}
		echo '<h2>' . esc_html__( 'Spam protection: Cloudflare Turnstile', 'perdita-core' ) . '</h2>';
		echo '<p>' . esc_html__( 'Turnstile is a free, privacy-friendly CAPTCHA that stops bots without puzzles. Create a free widget at Cloudflare, then paste the two keys here. Leave both blank to turn it off. Your forms are always protected by a honeypot, a nonce, and rate limiting even without Turnstile.', 'perdita-core' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="perdita_forms_settings" />';
		wp_nonce_field( 'perdita_forms_settings' );
		echo '<table class="form-table" role="presentation">';
		echo '<tr><th scope="row"><label for="ts_site">' . esc_html__( 'Turnstile site key', 'perdita-core' ) . '</label></th><td><input type="text" class="regular-text" id="ts_site" name="turnstile_site_key" value="' . esc_attr( $site ) . '" autocomplete="off" /></td></tr>';
		echo '<tr><th scope="row"><label for="ts_secret">' . esc_html__( 'Turnstile secret key', 'perdita-core' ) . '</label></th><td><input type="password" class="regular-text" id="ts_secret" name="turnstile_secret_key" autocomplete="off" placeholder="' . ( $has_secret ? esc_attr__( 'saved (leave blank to keep)', 'perdita-core' ) : '' ) . '" />';
		if ( $has_secret ) {
			echo ' <label style="margin-left:8px;"><input type="checkbox" name="turnstile_secret_clear" value="1" /> ' . esc_html__( 'Remove', 'perdita-core' ) . '</label>';
		}
		echo '<p class="description">' . esc_html__( 'The secret key is encrypted before it is saved, so it is not readable in a database export.', 'perdita-core' ) . '</p></td></tr>';
		echo '</table>';
		submit_button( __( 'Save settings', 'perdita-core' ) );
		echo '</form></div>';
	}

	/**
	 * Save the forms settings. The secret key is encrypted before storage.
	 */
	public function save_settings() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'perdita_forms_settings' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'perdita-core' ) );
		}
		$s = get_option( 'perdita_forms_settings', array() );
		$s = is_array( $s ) ? $s : array();

		$s['turnstile_site_key'] = isset( $_POST['turnstile_site_key'] ) ? sanitize_text_field( wp_unslash( $_POST['turnstile_site_key'] ) ) : '';

		if ( ! empty( $_POST['turnstile_secret_clear'] ) ) {
			unset( $s['turnstile_secret_key'] );
		} else {
			$secret = isset( $_POST['turnstile_secret_key'] ) ? trim( (string) wp_unslash( $_POST['turnstile_secret_key'] ) ) : '';
			if ( '' !== $secret ) {
				$s['turnstile_secret_key'] = Perdita_Crypto::encrypt( $secret );
			}
		}

		update_option( 'perdita_forms_settings', $s );
		wp_safe_redirect( add_query_arg( 'perdita_msg', rawurlencode( __( 'Settings saved.', 'perdita-core' ) ), admin_url( 'edit.php?post_type=' . Perdita_Forms::CPT . '&page=perdita-forms-settings' ) ) );
		exit;
	}

	/**
	 * Render the entries viewer.
	 */
	public function entries_page() {
		if ( ! current_user_can( 'edit_pages' ) ) {
			return;
		}
		$forms = get_posts( array( 'post_type' => Perdita_Forms::CPT, 'numberposts' => -1, 'post_status' => 'publish' ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$fid   = isset( $_GET['form'] ) ? (int) $_GET['form'] : ( $forms ? $forms[0]->ID : 0 );
		echo '<div class="wrap"><h1>' . esc_html__( 'Form entries', 'perdita-core' ) . '</h1>';
		if ( ! $forms ) {
			echo '<p>' . esc_html__( 'No forms yet.', 'perdita-core' ) . '</p></div>';
			return;
		}
		echo '<form method="get"><input type="hidden" name="post_type" value="' . esc_attr( Perdita_Forms::CPT ) . '" /><input type="hidden" name="page" value="perdita-entries" />';
		echo '<select name="form" onchange="this.form.submit()">';
		foreach ( $forms as $f ) {
			printf( '<option value="%d" %s>%s</option>', (int) $f->ID, selected( $fid, $f->ID, false ), esc_html( $f->post_title ) );
		}
		echo '</select> <button class="button">' . esc_html__( 'View', 'perdita-core' ) . '</button></form><br />';

		$per_page = 50;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$paged    = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
		$total    = $fid ? Perdita_Forms::entry_count( $fid ) : 0;
		$entries  = $fid ? Perdita_Forms::entries( $fid, $per_page, ( $paged - 1 ) * $per_page ) : array();
		if ( ! $entries ) {
			echo '<p>' . esc_html__( 'No entries yet.', 'perdita-core' ) . '</p></div>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Date', 'perdita-core' ) . '</th><th>' . esc_html__( 'Submission', 'perdita-core' ) . '</th></tr></thead><tbody>';
		foreach ( $entries as $e ) {
			$data = json_decode( $e->data, true );
			echo '<tr><td style="white-space:nowrap;">' . esc_html( $e->created ) . '</td><td>';
			if ( is_array( $data ) ) {
				foreach ( $data as $k => $v ) {
					$value_html = esc_html( is_array( $v ) ? implode( ', ', $v ) : $v );

					/**
					 * Filters the rendered HTML for one submitted field value on
					 * the entries screen, so an add-on can display a value that
					 * needs more than plain text. Forms Pro uses this to turn a
					 * stored file-upload value (an array, which would otherwise
					 * just be imploded into unusable text) into a nonce-protected
					 * download link to its own admin-post handler.
					 *
					 * A filter callback owns escaping its own return value. The
					 * result is still run through wp_kses_post() before it is
					 * printed, so a misbehaving add-on can't inject a script tag
					 * into wp-admin. With nothing hooked, the default is the same
					 * esc_html()'d text this screen has always shown.
					 *
					 * @param string   $value_html Default rendered HTML (escaped plain text).
					 * @param mixed    $v          Raw stored value for this field.
					 * @param string   $k          Field name.
					 * @param stdClass $e          The entry row (id, form_id, data, ip, created).
					 */
					$value_html = apply_filters( 'perdita_form_entry_value_html', $value_html, $v, $k, $e );

					echo '<strong>' . esc_html( $k ) . ':</strong> ' . wp_kses_post( $value_html ) . '<br />';
				}
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';

		$total_pages = (int) ceil( $total / $per_page );
		if ( $total_pages > 1 ) {
			$links = paginate_links(
				array(
					'base'      => add_query_arg( 'paged', '%#%' ),
					'format'    => '',
					'current'   => $paged,
					'total'     => $total_pages,
					'prev_text' => __( '&laquo; Newer', 'perdita-core' ),
					'next_text' => __( 'Older &raquo;', 'perdita-core' ),
				)
			);
			if ( $links ) {
				echo '<p class="perdita-entries__pagination" style="margin-top:12px;">' . wp_kses_post( $links ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_kses_post above.
			}
		}
		echo '</div>';
	}
}
