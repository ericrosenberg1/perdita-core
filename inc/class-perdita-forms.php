<?php
/**
 * Perdita Forms: a built-in contact-form feature covering the core of plugins
 * like Contact Form 7, with a simpler structured field builder (no tag syntax),
 * email delivery, spam protection, and stored entries.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

class Perdita_Forms {

	const CPT        = 'perdita_form';
	const META       = '_perdita_form';
	const DB_VERSION = '2';

	/**
	 * Field types the builder offers.
	 *
	 * @return array
	 */
	public static function field_types() {
		return array(
			'text'     => __( 'Text', 'perdita-core' ),
			'email'    => __( 'Email', 'perdita-core' ),
			'textarea' => __( 'Paragraph', 'perdita-core' ),
			'tel'      => __( 'Phone', 'perdita-core' ),
			'url'      => __( 'URL', 'perdita-core' ),
			'number'   => __( 'Number', 'perdita-core' ),
			'date'     => __( 'Date', 'perdita-core' ),
			'select'   => __( 'Dropdown', 'perdita-core' ),
			'radio'    => __( 'Radio buttons', 'perdita-core' ),
			'checkbox' => __( 'Checkboxes', 'perdita-core' ),
		);
	}

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'register_cpt' ) );
		add_action( 'init', array( $this, 'maybe_install' ) );
		add_action( 'rest_api_init', array( $this, 'routes' ) );
		add_shortcode( 'perdita_form', array( $this, 'shortcode' ) );
		add_filter( 'perdita_cache_exclude', array( $this, 'exclude_from_cache_if_has_form' ) );

		if ( is_admin() ) {
			add_action( 'admin_notices', array( __CLASS__, 'proxy_ip_notice' ) );
			add_action( 'admin_init', array( __CLASS__, 'maybe_dismiss_proxy_ip_notice' ) );
		}
	}

	/**
	 * Set by shortcode() when a form is actually rendered into the page.
	 * Request-scoped: the only thing that reads it is the cache-exclusion
	 * filter, which the caching module re-applies after the render.
	 *
	 * @var bool
	 */
	private static $form_rendered = false;

	/**
	 * Option flag: a forwarded-for/CF-Connecting-IP header was seen while no
	 * 'perdita_form_client_ip' filter was hooked, so the per-IP throttle was
	 * skipped. Value 1 means "show the notice", 'dismissed' means the owner
	 * closed it.
	 */
	const PROXY_IP_NOTICE_OPTION = 'perdita_forms_proxy_ip_notice';

	/**
	 * A page embedding [perdita_form] must never be served from the page
	 * cache: the form's hidden nonce field is only correct for as long as
	 * the WordPress nonce it was rendered with is valid. A cache HIT reads
	 * the FIRST visitor's captured HTML back verbatim, so every subsequent
	 * visitor gets that same, aging nonce; once it expires (WordPress
	 * nonces last a day or two), every submission on that page starts
	 * failing with "nonce expired" until the cache entry happens to be
	 * purged and rebuilt.
	 *
	 * The singular post_content check below only sees a shortcode written
	 * into the post being viewed. It misses a form rendered from a widget,
	 * a reusable block, a template part, a term description, or any archive
	 * or 404 view, all of which cache the same stale nonce. So the render
	 * itself also raises a flag, and the caching module re-applies this
	 * filter after the buffer is built (see write_buffer() over in the
	 * caching module) specifically so a page that only turns out to be
	 * personalized mid-render can still opt out.
	 *
	 * @param bool $exclude Whether the caching module already wants to
	 *                      exclude this request.
	 * @return bool
	 */
	public function exclude_from_cache_if_has_form( $exclude ) {
		if ( $exclude ) {
			return $exclude;
		}
		if ( self::$form_rendered ) {
			return true;
		}
		if ( ! is_singular() ) {
			return $exclude;
		}
		$post = get_post();
		return ( $post && has_shortcode( (string) $post->post_content, 'perdita_form' ) ) ? true : $exclude;
	}

	/**
	 * Default config for a new form.
	 *
	 * @return array
	 */
	public static function default_config() {
		return array(
			'fields'   => array(
				array( 'type' => 'text', 'label' => 'Name', 'name' => 'name', 'required' => true, 'placeholder' => '', 'options' => array() ),
				array( 'type' => 'email', 'label' => 'Email', 'name' => 'email', 'required' => true, 'placeholder' => '', 'options' => array() ),
				array( 'type' => 'textarea', 'label' => 'Message', 'name' => 'message', 'required' => true, 'placeholder' => '', 'options' => array() ),
			),
			'email'    => array(
				'to'      => get_option( 'admin_email' ),
				'subject' => sprintf( /* translators: site name */ __( 'New message from %s', 'perdita-core' ), get_bloginfo( 'name' ) ),
				'body'    => '',
			),
			'messages' => array(
				'success' => __( 'Thanks, your message has been sent.', 'perdita-core' ),
				'error'   => __( 'Something went wrong. Please check the form and try again.', 'perdita-core' ),
			),
		);
	}

	/**
	 * Read a form's config merged over defaults.
	 *
	 * @param int $id Form ID.
	 * @return array
	 */
	public function config( $id ) {
		$saved = get_post_meta( $id, self::META, true );
		$cfg   = self::default_config();
		if ( is_array( $saved ) ) {
			$cfg = array_merge( $cfg, $saved );
			if ( empty( $cfg['fields'] ) || ! is_array( $cfg['fields'] ) ) {
				$cfg['fields'] = self::default_config()['fields'];
			}
		}
		return $cfg;
	}

	/**
	 * Register the form post type.
	 */
	public function register_cpt() {
		register_post_type(
			self::CPT,
			array(
				'labels'       => array(
					'name'          => __( 'Perdita Forms', 'perdita-core' ),
					'singular_name' => __( 'Form', 'perdita-core' ),
					'menu_name'     => __( 'Perdita Forms', 'perdita-core' ),
					'add_new'       => __( 'Add Form', 'perdita-core' ),
					'add_new_item'  => __( 'Add Form', 'perdita-core' ),
					'edit_item'     => __( 'Edit Form', 'perdita-core' ),
					'all_items'     => __( 'All Forms', 'perdita-core' ),
				),
				'public'       => false,
				'show_ui'      => true,
				'show_in_menu' => true,
				'menu_icon'    => 'dashicons-feedback',
				// Position 58: sorts just below Perdita (56) and Perdita SEO (57).
				'menu_position' => 58,
				'supports'     => array( 'title' ),
				'capability_type' => 'page',
			)
		);
	}

	/**
	 * Create the entries table when the schema version changes. Hooked to
	 * `init` so an ordinary page load repairs a site whose schema is behind.
	 */
	public function maybe_install() {
		if ( get_option( 'perdita_forms_db_version' ) === self::DB_VERSION ) {
			return;
		}
		self::install();
	}

	/**
	 * Create the entries table, unconditionally.
	 *
	 * Static and unguarded so plugin activation can call it the same way the
	 * folder modules' descriptors call their own install(): dbDelta is
	 * idempotent, and running it without the version check is what repairs a
	 * site whose table was dropped while the stored schema version stayed put.
	 */
	public static function install() {
		global $wpdb;
		$table   = $wpdb->prefix . 'perdita_entries';
		$charset = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta(
			"CREATE TABLE $table (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				form_id BIGINT UNSIGNED NOT NULL,
				data LONGTEXT NOT NULL,
				ip VARCHAR(100) DEFAULT '',
				created DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY form_created (form_id, created)
			) $charset;"
		);
		// The entries viewer filters by form_id and orders by created, so the
		// composite key above covers it. Drop the old single-column index a
		// prior version created; dbDelta adds indexes but never removes them.
		$has_old = $wpdb->get_results( "SHOW INDEX FROM $table WHERE Key_name = 'form_id'" ); // phpcs:ignore WordPress.DB
		if ( $has_old ) {
			$wpdb->query( "ALTER TABLE $table DROP INDEX form_id" ); // phpcs:ignore WordPress.DB
		}
		update_option( 'perdita_forms_db_version', self::DB_VERSION );
	}

	/**
	 * Entries table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'perdita_entries';
	}

	/* ---------- front end ---------- */

	/**
	 * Shortcode: [perdita_form id="123"].
	 *
	 * @param array $atts Attributes.
	 * @return string
	 */
	public function shortcode( $atts ) {
		$atts = shortcode_atts( array( 'id' => 0 ), $atts, 'perdita_form' );
		$id   = (int) $atts['id'];
		if ( ! $id || self::CPT !== get_post_type( $id ) || 'publish' !== get_post_status( $id ) ) {
			return '';
		}
		// A form is going into this response, nonce and all, so the page
		// must not be stored in the page cache no matter how it was reached
		// (widget, reusable block, archive, 404 template, anywhere).
		self::$form_rendered = true;

		wp_enqueue_style( 'perdita-forms', PERDITA_CORE_URL . 'assets/css/forms.css', array(), PERDITA_CORE_VERSION );
		wp_enqueue_script( 'perdita-forms', PERDITA_CORE_URL . 'assets/js/forms.js', array(), PERDITA_CORE_VERSION, true );
		wp_localize_script(
			'perdita-forms',
			'PerditaForms',
			array(
				'rest'    => esc_url_raw( rest_url( 'perdita/v1/form/' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'strings' => array(
					'networkError' => __( 'Network error. Please try again.', 'perdita-core' ),
				),
			)
		);

		$turnstile_site = $this->turnstile_site_key();
		if ( '' !== $turnstile_site ) {
			// Cloudflare's own script. It finds the .cf-turnstile div, solves
			// the challenge, and injects a hidden cf-turnstile-response field
			// that FormData submits with the rest of the form.
			wp_enqueue_script( 'perdita-turnstile', 'https://challenges.cloudflare.com/turnstile/v0/api.js', array(), null, true ); // phpcs:ignore PluginCheck.CodeAnalysis.EnqueuedResourceOffloading.OffloadedContent, WordPress.WP.EnqueuedResourceParameters.MissingVersion -- third-party endpoint, versionless by design.
		}

		$cfg = $this->config( $id );
		ob_start();
		?>
		<form class="perdita-form" data-form="<?php echo esc_attr( $id ); ?>" novalidate>
			<?php wp_nonce_field( 'perdita_form_' . $id, 'perdita_nonce' ); ?>
			<div class="perdita-form__hp" aria-hidden="true" style="position:absolute;left:-9999px;">
				<label><?php esc_html_e( 'Leave this field empty', 'perdita-core' ); ?><input type="text" name="perdita_hp" tabindex="-1" autocomplete="off" /></label>
			</div>
			<?php foreach ( $cfg['fields'] as $field ) : ?>
				<?php echo $this->render_field( $field ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped within. ?>
			<?php endforeach; ?>
			<?php if ( '' !== $turnstile_site ) : ?>
				<div class="cf-turnstile" data-sitekey="<?php echo esc_attr( $turnstile_site ); ?>"></div>
			<?php endif; ?>
			<div class="perdita-form__actions">
				<button type="submit" class="perdita-form__submit"><?php esc_html_e( 'Send', 'perdita-core' ); ?></button>
				<span class="perdita-form__spinner" hidden>…</span>
			</div>
			<div class="perdita-form__message" role="status" aria-live="polite"></div>
		</form>
		<?php
		return ob_get_clean();
	}

	/**
	 * Render a single field.
	 *
	 * @param array $f Field.
	 * @return string
	 */
	private function render_field( $f ) {
		$type  = isset( $f['type'] ) ? $f['type'] : 'text';
		$name  = isset( $f['name'] ) ? $f['name'] : '';
		$label = isset( $f['label'] ) ? $f['label'] : '';
		$req   = ! empty( $f['required'] );
		$ph    = isset( $f['placeholder'] ) ? $f['placeholder'] : '';
		$opts  = ! empty( $f['options'] ) && is_array( $f['options'] ) ? $f['options'] : array();
		if ( '' === $name ) {
			return '';
		}
		$id    = 'df-' . $name;
		$rmark = $req ? ' <span class="perdita-form__req">*</span>' : '';
		$rattr = $req ? ' required' : '';

		ob_start();
		echo '<p class="perdita-form__field perdita-form__field--' . esc_attr( $type ) . '">';
		echo '<label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . $rmark . '</label>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $rmark static.

		if ( in_array( $type, array( 'text', 'email', 'tel', 'url', 'number', 'date' ), true ) ) {
			printf(
				'<input type="%s" id="%s" name="fields[%s]" placeholder="%s"%s />',
				esc_attr( $type ),
				esc_attr( $id ),
				esc_attr( $name ),
				esc_attr( $ph ),
				esc_attr( $rattr ) // phpcs:ignore
			);
		} elseif ( 'textarea' === $type ) {
			printf( '<textarea id="%s" name="fields[%s]" rows="5" placeholder="%s"%s></textarea>', esc_attr( $id ), esc_attr( $name ), esc_attr( $ph ), esc_attr( $rattr ) );
		} elseif ( 'select' === $type ) {
			echo '<select id="' . esc_attr( $id ) . '" name="fields[' . esc_attr( $name ) . ']"' . ( $req ? ' required' : '' ) . '>';
			echo '<option value="">' . esc_html__( 'Choose…', 'perdita-core' ) . '</option>';
			foreach ( $opts as $opt ) {
				echo '<option value="' . esc_attr( $opt ) . '">' . esc_html( $opt ) . '</option>';
			}
			echo '</select>';
		} elseif ( 'radio' === $type || 'checkbox' === $type ) {
			$input_type = 'radio' === $type ? 'radio' : 'checkbox';
			$fname      = 'checkbox' === $type ? 'fields[' . $name . '][]' : 'fields[' . $name . ']';
			echo '<span class="perdita-form__choices">';
			foreach ( $opts as $opt ) {
				echo '<label class="perdita-form__choice"><input type="' . esc_attr( $input_type ) . '" name="' . esc_attr( $fname ) . '" value="' . esc_attr( $opt ) . '" /> ' . esc_html( $opt ) . '</label>';
			}
			echo '</span>';
		}
		echo '</p>';
		return ob_get_clean();
	}

	/* ---------- REST submit ---------- */

	/**
	 * Register the submit route.
	 */
	public function routes() {
		register_rest_route(
			'perdita/v1',
			'/form/(?P<id>\d+)',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => array( $this, 'submit' ),
			)
		);
	}

	/**
	 * Handle a submission.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response
	 */
	public function submit( WP_REST_Request $req ) {
		$id  = (int) $req['id'];
		$cfg = $this->config( $id );
		if ( self::CPT !== get_post_type( $id ) || 'publish' !== get_post_status( $id ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'message' => __( 'Form not found.', 'perdita-core' ) ), 404 );
		}

		// Spam: honeypot + nonce.
		if ( ! empty( $req->get_param( 'perdita_hp' ) ) ) {
			return new WP_REST_Response( array( 'ok' => true, 'message' => $cfg['messages']['success'] ), 200 ); // Silently accept bots.
		}
		$nonce = $req->get_param( 'perdita_nonce' );
		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'perdita_form_' . $id ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'message' => __( 'Your session expired. Reload and try again.', 'perdita-core' ) ), 403 );
		}

		// CAPTCHA (Cloudflare Turnstile) when configured, plus an extension
		// point so other spam add-ons can gate a submission. A non-empty
		// string is treated as the rejection message.
		$spam = $this->check_turnstile( $req );
		$spam = (string) apply_filters( 'perdita_form_spam_check', $spam, $req, $id );
		if ( '' !== $spam ) {
			return new WP_REST_Response( array( 'ok' => false, 'message' => $spam ), 422 );
		}

		// Throttle: cap submissions per IP so a harvested nonce can't be scripted
		// into DB and mailbox flooding.
		if ( $this->is_rate_limited( $id ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'message' => __( 'Too many submissions. Please try again later.', 'perdita-core' ) ), 429 );
		}

		$raw    = (array) $req->get_param( 'fields' );
		$errors = array();
		$clean  = array();
		foreach ( $cfg['fields'] as $f ) {
			$name = $f['name'];
			$val  = isset( $raw[ $name ] ) ? $raw[ $name ] : '';
			$val  = $this->sanitize_value( $f['type'], $val );
			// Choice fields accept only their own configured options, so a
			// crafted POST can't store or email an arbitrary value.
			if ( in_array( $f['type'], array( 'select', 'radio', 'checkbox' ), true ) ) {
				$opts      = ! empty( $f['options'] ) && is_array( $f['options'] ) ? $f['options'] : array();
				$had_value = is_array( $val ) ? ! empty( $val ) : ( '' !== $val );
				if ( is_array( $val ) ) {
					$val = array_values( array_intersect( $val, $opts ) );
				} elseif ( '' !== $val && ! in_array( $val, $opts, true ) ) {
					$val = '';
				}
				$now_empty = is_array( $val ) ? empty( $val ) : ( '' === $val );
				if ( $had_value && $now_empty ) {
					// Submitted something, but it no longer matches this
					// field's configured options (e.g. the form was edited
					// after the page was loaded) -- distinct from having
					// left the field genuinely blank.
					$errors[ $name ] = __( 'That option is no longer available. Please choose again.', 'perdita-core' );
				}
			}
			if ( ! empty( $f['required'] ) && ( '' === $val || array() === $val ) && ! isset( $errors[ $name ] ) ) {
				$errors[ $name ] = __( 'Required.', 'perdita-core' );
			}
			if ( 'email' === $f['type'] && '' !== $val && ! is_email( $val ) ) {
				$errors[ $name ] = __( 'Enter a valid email.', 'perdita-core' );
			}
			if ( 'number' === $f['type'] && '' !== $val && ! is_numeric( $val ) ) {
				$errors[ $name ] = __( 'Enter a valid number.', 'perdita-core' );
			}
			$clean[ $name ] = $val;
		}
		if ( $errors ) {
			return new WP_REST_Response( array( 'ok' => false, 'message' => $cfg['messages']['error'], 'errors' => $errors ), 422 );
		}

		$this->store_entry( $id, $clean );
		$this->send_mail( $cfg, $clean );

		return new WP_REST_Response( array( 'ok' => true, 'message' => $cfg['messages']['success'] ), 200 );
	}

	/**
	 * Sanitize a submitted value by field type.
	 *
	 * @param string $type Type.
	 * @param mixed  $val  Value.
	 * @return mixed
	 */
	private function sanitize_value( $type, $val ) {
		if ( is_array( $val ) ) {
			return array_map( 'sanitize_text_field', array_map( 'wp_unslash', $val ) );
		}
		$val = wp_unslash( $val );
		switch ( $type ) {
			case 'email':
				return sanitize_email( $val );
			case 'url':
				return esc_url_raw( $val );
			case 'textarea':
				return sanitize_textarea_field( $val );
			default:
				return sanitize_text_field( $val );
		}
	}

	/**
	 * Site-wide forms settings (currently the Turnstile keys), stored outside
	 * the design-token document.
	 *
	 * @return array
	 */
	private function forms_settings() {
		$o = get_option( 'perdita_forms_settings', array() );
		return is_array( $o ) ? $o : array();
	}

	/**
	 * The configured Turnstile site key, or '' if not set. The site key is
	 * public (it's rendered in the widget), so it isn't encrypted.
	 *
	 * @return string
	 */
	private function turnstile_site_key() {
		$s = $this->forms_settings();
		return isset( $s['turnstile_site_key'] ) ? trim( (string) $s['turnstile_site_key'] ) : '';
	}

	/**
	 * Verify a Cloudflare Turnstile response, if Turnstile is configured.
	 * Returns '' when the check passes or Turnstile isn't set up, or a
	 * user-facing rejection message when it fails. Fails OPEN on a network
	 * error to the verify endpoint, so a Cloudflare outage doesn't block every
	 * legitimate visitor (the honeypot, nonce, and rate limit still apply).
	 *
	 * It does NOT fail open on an unreadable secret. The secret is encrypted
	 * with a key derived from the wp-config salts, so rotating those salts
	 * makes it undecryptable, and decrypt()'s '' return is indistinguishable
	 * from "the owner never configured Turnstile". That combination silently
	 * turned anti-spam off on sites that thought it was on: the widget still
	 * rendered (the site key is stored in the clear) and every submission
	 * sailed through unverified. A site key with an unreadable secret is a
	 * broken configuration, not an absent one, so reject the submission and
	 * tell the owner what to fix.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return string
	 */
	private function check_turnstile( $req ) {
		$s      = $this->forms_settings();
		$site   = isset( $s['turnstile_site_key'] ) ? trim( (string) $s['turnstile_site_key'] ) : '';
		$stored = isset( $s['turnstile_secret_key'] ) ? (string) $s['turnstile_secret_key'] : '';
		$secret = Perdita_Crypto::decrypt_strict( $stored );

		if ( false === $secret ) {
			// Record the failure so the owner gets the admin notice too,
			// not just visitors getting turned away.
			Perdita_Crypto::decrypt( $stored, __( 'Turnstile secret key (contact forms)', 'perdita-core' ) );
			if ( '' !== $site ) {
				return __( 'The anti-spam check is not working right now, so this form cannot accept messages. Please contact the site owner.', 'perdita-core' );
			}
			$secret = '';
		}

		if ( '' === $site || '' === $secret ) {
			return '';
		}
		$token = (string) $req->get_param( 'cf-turnstile-response' );
		if ( '' === $token ) {
			return __( 'Please complete the anti-spam check and try again.', 'perdita-core' );
		}
		$resp = wp_remote_post(
			'https://challenges.cloudflare.com/turnstile/v0/siteverify',
			array(
				'timeout' => 10,
				'body'    => array(
					'secret'   => $secret,
					'response' => $token,
					'remoteip' => $this->client_ip(),
				),
			)
		);
		if ( is_wp_error( $resp ) ) {
			return ''; // Fail open on a verify-endpoint outage.
		}
		$data = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( is_array( $data ) && ! empty( $data['success'] ) ) {
			return '';
		}
		return __( 'The anti-spam check did not pass. Please try again.', 'perdita-core' );
	}

	/**
	 * Resolve the client IP used for rate limiting and entry storage.
	 * Defaults to REMOTE_ADDR, which is the proxy's own address (not the real
	 * visitor) on any site behind Cloudflare or a load balancer -- pooling the
	 * rate limit across every visitor sharing that hop. Filterable so a site
	 * owner in that situation can wire in the real client IP (e.g. from
	 * CF-Connecting-IP), which this class doesn't trust by default since an
	 * unvetted header would let a client just spoof its own rate limit away.
	 *
	 * @return string
	 */
	private function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return (string) apply_filters( 'perdita_form_client_ip', $ip );
	}

	/**
	 * Whether this request arrived through something that rewrote the
	 * client address, i.e. REMOTE_ADDR is a proxy hop rather than a visitor.
	 * Only used to decide whether the per-IP throttle can mean anything;
	 * the header's VALUE is still never trusted.
	 *
	 * @return bool
	 */
	public static function behind_forwarding_proxy() {
		return ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) || ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- presence check only, the value is never read or trusted.
	}

	/**
	 * Note that the per-IP throttle had to be skipped, so the owner can see
	 * one dismissible notice explaining how to switch it back on. add_option()
	 * is a no-op once the row exists, so a dismissed notice never comes back
	 * and a busy endpoint never rewrites the row.
	 */
	private static function flag_proxy_ip_detected() {
		add_option( self::PROXY_IP_NOTICE_OPTION, 1, '', false );
	}

	/**
	 * Admin notice: the form throttle is off because every request looks
	 * like it comes from the same proxy address.
	 */
	public static function proxy_ip_notice() {
		if ( ! current_user_can( 'manage_options' ) || 1 !== (int) get_option( self::PROXY_IP_NOTICE_OPTION ) ) {
			return;
		}
		$dismiss = wp_nonce_url( add_query_arg( 'perdita_dismiss_proxy_ip_notice', 'forms' ), 'perdita_dismiss_proxy_ip_notice' );
		echo '<div class="notice notice-warning"><p><strong>';
		esc_html_e( 'Perdita Forms: the per-visitor submission limit is switched off.', 'perdita-core' );
		echo '</strong> ';
		esc_html_e( 'This site is behind Cloudflare or another proxy, so every submission arrives from the same address and a per-IP limit would either throttle all visitors at once or nothing at all. Spam protection still runs (honeypot, nonce, and Turnstile if configured). To switch the limit back on, hook the perdita_form_client_ip filter and return the real visitor IP your proxy sends.', 'perdita-core' );
		echo ' <a href="' . esc_url( $dismiss ) . '">' . esc_html__( 'Dismiss', 'perdita-core' ) . '</a>';
		echo '</p></div>';
	}

	/**
	 * Handle the notice's dismiss link.
	 */
	public static function maybe_dismiss_proxy_ip_notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the nonce is verified immediately below, once we know this request is the dismissal.
		$which = isset( $_GET['perdita_dismiss_proxy_ip_notice'] ) ? sanitize_key( wp_unslash( $_GET['perdita_dismiss_proxy_ip_notice'] ) ) : '';
		if ( 'forms' !== $which ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'perdita_dismiss_proxy_ip_notice' ) ) {
			return;
		}
		update_option( self::PROXY_IP_NOTICE_OPTION, 'dismissed', false );
	}

	/**
	 * Simple per-IP submission throttle backed by a short-lived transient.
	 * Filterable cap (default 15) per hour, per form, per IP.
	 *
	 * @param int $id Form ID.
	 * @return bool True if the caller is over the cap.
	 */
	private function is_rate_limited( $id ) {
		// Behind a CDN or load balancer, REMOTE_ADDR is the proxy, so every
		// visitor lands in ONE bucket: the fifteenth legitimate submission
		// of the hour locks out the whole site, and a spammer rotating real
		// addresses is never separated out. That is worse than no throttle,
		// so skip it and say so, unless the owner has wired the real client
		// IP in through the filter (which is the only trustworthy source
		// here, since a forwarded header a client can set is a rate limit a
		// client can erase).
		if ( ! has_filter( 'perdita_form_client_ip' ) && self::behind_forwarding_proxy() ) {
			self::flag_proxy_ip_detected();
			return false;
		}

		$ip = $this->client_ip();
		if ( '' === $ip ) {
			return false;
		}
		/** Max submissions per form, per IP, per hour. */
		$max   = (int) apply_filters( 'perdita_form_rate_limit', 15, $id );
		if ( $max <= 0 ) {
			return false;
		}
		$key   = 'perdita_fl_' . $id . '_' . md5( $ip );
		$count = (int) get_transient( $key );
		if ( $count >= $max ) {
			return true;
		}
		set_transient( $key, $count + 1, HOUR_IN_SECONDS );
		return false;
	}

	/**
	 * Store an entry.
	 *
	 * @param int   $id   Form ID.
	 * @param array $data Clean field values.
	 */
	private function store_entry( $id, $data ) {
		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			array(
				'form_id' => $id,
				'data'    => wp_json_encode( $data ),
				'ip'      => $this->client_ip(),
				'created' => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s' )
		);
	}

	/**
	 * Send the notification email.
	 *
	 * @param array $cfg   Form config.
	 * @param array $data  Clean values.
	 */
	private function send_mail( $cfg, $data ) {
		$to      = ! empty( $cfg['email']['to'] ) ? $cfg['email']['to'] : get_option( 'admin_email' );
		$subject = ! empty( $cfg['email']['subject'] ) ? $cfg['email']['subject'] : __( 'New form submission', 'perdita-core' );

		$body = trim( (string) ( $cfg['email']['body'] ?? '' ) );
		if ( '' !== $body ) {
			foreach ( $data as $k => $v ) {
				$body = str_replace( '{' . $k . '}', is_array( $v ) ? implode( ', ', $v ) : (string) $v, $body );
			}
		} else {
			$lines = array();
			foreach ( $cfg['fields'] as $f ) {
				$v       = isset( $data[ $f['name'] ] ) ? $data[ $f['name'] ] : '';
				$lines[] = $f['label'] . ': ' . ( is_array( $v ) ? implode( ', ', $v ) : $v );
			}
			$body = implode( "\n", $lines );
		}

		$headers = array();
		foreach ( $cfg['fields'] as $f ) {
			if ( 'email' === $f['type'] && ! empty( $data[ $f['name'] ] ) ) {
				$headers[] = 'Reply-To: ' . $data[ $f['name'] ];
				break;
			}
		}
		wp_mail( $to, $subject, $body, $headers );
	}

	/**
	 * Entries for a form (for the admin list), newest first.
	 *
	 * @param int $form_id Form ID.
	 * @param int $limit   Max rows.
	 * @param int $offset  Rows to skip, for pagination past the newest page.
	 * @return array
	 */
	public static function entries( $form_id, $limit = 50, $offset = 0 ) {
		global $wpdb;
		$table = self::table();
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE form_id = %d ORDER BY created DESC LIMIT %d OFFSET %d", $form_id, $limit, $offset ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Total entry count for a form, for pagination.
	 *
	 * @param int $form_id Form ID.
	 * @return int
	 */
	public static function entry_count( $form_id ) {
		global $wpdb;
		$table = self::table();
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE form_id = %d", $form_id ) ); // phpcs:ignore WordPress.DB
	}
}
