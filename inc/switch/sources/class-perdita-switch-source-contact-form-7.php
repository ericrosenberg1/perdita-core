<?php
/**
 * Contact Form 7, into Perdita Forms.
 *
 * Contact Form 7 keeps each form as a post of type wpcf7_contact_form with
 * the template in post meta _form (form-tags such as [text* your-name]),
 * the notification in _mail, the optional second mail in _mail_2, the
 * messages in _messages, and a 32-hex _hash whose first 7 characters its
 * shortcode and block use as the id. Checked against Contact Form 7 6.2.5.
 *
 * Each form becomes a Perdita form (post type perdita_form) whose fields,
 * recipient, subject, body, and messages run through
 * Perdita_Forms::sanitize_config(), the same rules as the form editor. A
 * field goes in only when that keeps it unchanged. The import records each
 * form in the option perdita_forms_cf7_map, and once Contact Form 7 is off,
 * Perdita answers [contact-form-7] with the imported form, so pages and
 * blocks that embed one keep working.
 *
 * Read only toward Contact Form 7: its posts and meta are never changed.
 * Undo moves the Perdita forms this import made to the trash, since a form
 * may have taken entries since.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

class Perdita_Switch_Source_Contact_Form_7 extends Perdita_Switch_Source {

	const POST_TYPE = 'wpcf7_contact_form';

	/**
	 * Forms one import reads.
	 */
	const MAX_FORMS = 200;

	/**
	 * Contact Form 7's form-tag types, from its modules.
	 *
	 * @var string[]
	 */
	const TAG_TYPES = array( 'text', 'email', 'url', 'tel', 'number', 'range', 'date', 'textarea', 'select', 'checkbox', 'radio', 'acceptance', 'quiz', 'file', 'submit', 'hidden', 'captchac', 'captchar', 'recaptcha', 'turnstile', 'stripe', 'count', 'response', 'reflection', 'output' );

	public function id() {
		return 'contact-form-7';
	}

	public function label() {
		return 'Contact Form 7';
	}

	public function plugin_files() {
		return array( 'contact-form-7/wp-contact-form-7.php' );
	}

	public function module() {
		return 'forms';
	}

	/**
	 * Load Perdita_Forms whether or not the module is on.
	 *
	 * @return bool
	 */
	private static function forms_loaded() {
		if ( ! class_exists( 'Perdita_Forms' ) && defined( 'PERDITA_CORE_DIR' ) && is_readable( PERDITA_CORE_DIR . 'inc/class-perdita-forms.php' ) ) {
			require_once PERDITA_CORE_DIR . 'inc/class-perdita-forms.php';
		}
		return class_exists( 'Perdita_Forms' );
	}

	/**
	 * Contact Form 7's forms, oldest first. Read straight from the table,
	 * since the post type isn't registered while the plugin is off.
	 *
	 * @return object[] ID, post_title.
	 */
	public static function forms() {
		global $wpdb;
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_title FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish' ORDER BY ID ASC LIMIT %d", self::POST_TYPE, self::MAX_FORMS ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	public function has_settings() {
		return (bool) self::forms();
	}

	public function snapshot_options() {
		return array( 'perdita_forms_cf7_map' );
	}

	public function conflict_note() {
		return __( 'While Contact Form 7 is active, your pages keep showing its forms. Once you deactivate it, Perdita shows the imported form wherever a page or block has a Contact Form 7 shortcode.', 'perdita-core' );
	}

	public function pro_note() {
		return __( 'Perdita Pro also brings over file upload fields and the second mail, Contact Form 7\'s autoresponder.', 'perdita-core' );
	}

	/* ---------- the form template ---------- */

	/**
	 * Parse a form-tag's attribute text the way Contact Form 7 does:
	 * unquoted options first, then quoted values.
	 *
	 * @param string $text Text after the tag type.
	 * @return array options, values.
	 */
	public static function parse_atts( $text ) {
		$atts = array(
			'options' => array(),
			'values'  => array(),
		);
		$text = trim( preg_replace( '/[\x{00a0}\x{200b}]+/u', ' ', (string) $text ) );
		if ( preg_match( '%^([-+*=0-9a-zA-Z:.!?#$&@_/|\%\s]*?)((?:\s*"[^"]*"|\s*\'[^\']*\')*)$%u', $text, $m ) ) {
			if ( '' !== trim( $m[1] ) ) {
				$atts['options'] = preg_split( '/\s+/', trim( $m[1] ) );
			}
			if ( '' !== $m[2] && preg_match_all( '/"[^"]*"|\'[^\']*\'/', $m[2], $vals ) ) {
				foreach ( $vals[0] as $v ) {
					$atts['values'][] = substr( $v, 1, -1 );
				}
			}
		}
		return $atts;
	}

	/**
	 * Every form-tag in a template, in order, with the text that labels it.
	 *
	 * @param string $form The _form template.
	 * @return array[] type, basetype, required, name, options, values, content, label.
	 */
	public static function parse_form( $form ) {
		$form  = (string) $form;
		$types = implode( '|', array_map( 'preg_quote', self::TAG_TYPES ) );
		$re    = '/(\[?)\[((?:' . $types . ')\*?)(?:[\r\n\t ]+(.*?))?(?:[\r\n\t ]+(\/))?\](?:([^[]*?)\[\/\2\])?(\]?)/s';
		if ( ! preg_match_all( $re, $form, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			return array();
		}
		$tags = array();
		$prev = 0;
		foreach ( $all as $m ) {
			$whole  = $m[0][0];
			$offset = $m[0][1];
			if ( '[' === $m[1][0] && ']' === $m[6][0] ) {
				continue; // [[tag]] is Contact Form 7's escape for a literal tag.
			}
			$before = substr( $form, $prev, $offset - $prev );
			$prev   = $offset + strlen( $whole );
			$type   = $m[2][0];
			$base   = rtrim( $type, '*' );
			$atts   = self::parse_atts( isset( $m[3] ) ? $m[3][0] : '' );
			$name   = '';
			if ( ! in_array( $base, array( 'submit', 'recaptcha', 'turnstile', 'stripe', 'response' ), true ) && $atts['options'] ) {
				$name = (string) array_shift( $atts['options'] );
			}
			$tags[] = array(
				'type'     => $type,
				'basetype' => $base,
				'required' => '*' === substr( $type, -1 ),
				'name'     => $name,
				'options'  => $atts['options'],
				'values'   => $atts['values'],
				'content'  => isset( $m[5] ) ? trim( wp_strip_all_tags( $m[5][0] ) ) : '',
				'label'    => self::label_from( $before ),
			);
		}
		return $tags;
	}

	/**
	 * The words right before a tag, as its label: the last line of text
	 * between the previous tag and this one, without markup or "(optional)".
	 *
	 * @param string $before Template text before the tag.
	 * @return string
	 */
	public static function label_from( $before ) {
		$text  = wp_strip_all_tags( preg_replace( '/<br\s*\/?>/i', "\n", (string) $before ) );
		$lines = array_filter( array_map( 'trim', preg_split( '/\R/', $text ) ), 'strlen' );
		if ( ! $lines ) {
			return '';
		}
		$label = (string) end( $lines );
		$label = trim( preg_replace( '/\(\s*optional\s*\)/i', '', $label ) );
		return trim( $label, " \t:" );
	}

	/**
	 * A name as words, for a field with no label in the template.
	 *
	 * @param string $name Field name, such as your-name.
	 * @return string
	 */
	private static function humanize( $name ) {
		$words = trim( preg_replace( '/[-_]+/', ' ', (string) $name ) );
		return '' === $words ? '' : ucfirst( $words );
	}

	/**
	 * A choice's visible text: "Label|value" pipes show the part before the bar.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private static function choice( $value ) {
		$value = (string) $value;
		$bar   = strpos( $value, '|' );
		return false === $bar ? $value : substr( $value, 0, $bar );
	}

	/**
	 * One tag as a Perdita field, or why not.
	 *
	 * @param array $tag From parse_form().
	 * @return array field (array|null), status (import, none), reason.
	 */
	public static function field_for( array $tag ) {
		$map  = array(
			'text'       => 'text',
			'email'      => 'email',
			'url'        => 'url',
			'tel'        => 'tel',
			'number'     => 'number',
			'range'      => 'number',
			'date'       => 'date',
			'textarea'   => 'textarea',
			'select'     => 'select',
			'checkbox'   => 'checkbox',
			'radio'      => 'radio',
			'acceptance' => 'checkbox',
		);
		$base = $tag['basetype'];
		$out  = array(
			'field'  => null,
			'status' => 'none',
			'reason' => '',
		);
		if ( ! isset( $map[ $base ] ) ) {
			$reasons = array(
				'file'      => __( 'Perdita Forms has no file upload field. Perdita Pro\'s Forms Pro does.', 'perdita-core' ),
				'quiz'      => __( 'Perdita Forms has no quiz field. It stops spam with a hidden field and Cloudflare Turnstile.', 'perdita-core' ),
				'hidden'    => __( 'Perdita Forms has no hidden field.', 'perdita-core' ),
				'submit'    => __( 'Every Perdita form ends with its own Send button.', 'perdita-core' ),
				'captchac'  => __( 'Perdita Forms stops spam with a hidden field and Cloudflare Turnstile instead.', 'perdita-core' ),
				'captchar'  => __( 'Perdita Forms stops spam with a hidden field and Cloudflare Turnstile instead.', 'perdita-core' ),
				'recaptcha' => __( 'Perdita Forms stops spam with a hidden field and Cloudflare Turnstile instead.', 'perdita-core' ),
				'turnstile' => __( 'Add your Turnstile keys under Perdita Forms, Settings.', 'perdita-core' ),
			);
			$out['reason'] = isset( $reasons[ $base ] ) ? $reasons[ $base ] : __( 'Perdita Forms has no field like this one.', 'perdita-core' );
			return $out;
		}
		$opts     = $tag['options'];
		$type     = $map[ $base ];
		$required = $tag['required'];
		$values   = $tag['values'];
		$ph       = '';
		$options  = array();
		if ( in_array( $base, array( 'select', 'checkbox', 'radio' ), true ) ) {
			if ( 'select' === $base && in_array( 'multiple', $opts, true ) ) {
				$type = 'checkbox';
			}
			if ( 'select' === $base && in_array( 'first_as_label', $opts, true ) && $values ) {
				array_shift( $values );
			}
			foreach ( $values as $v ) {
				$options[] = self::choice( $v );
			}
			if ( ! $options ) {
				$out['reason'] = __( 'This field has no choices to copy.', 'perdita-core' );
				return $out;
			}
			foreach ( $options as $o ) {
				if ( false !== strpos( $o, ',' ) ) {
					$out['status'] = 'skip';
					$out['reason'] = __( 'A choice has a comma in it, and Perdita\'s form editor splits choices at commas, so this field stays out.', 'perdita-core' );
					return $out;
				}
			}
			if ( 'radio' === $base ) {
				$required = true; // Contact Form 7 always sends one radio choice.
			}
		} elseif ( 'acceptance' === $base ) {
			$text = '' !== $tag['content'] ? $tag['content'] : $tag['label'];
			if ( '' === $text || false !== strpos( $text, ',' ) ) {
				$out['status'] = 'skip';
				$out['reason'] = __( 'This consent box has no text Perdita can use as its one choice.', 'perdita-core' );
				return $out;
			}
			$options  = array( $text );
			$required = ! in_array( 'optional', $opts, true );
		} elseif ( $values && ( in_array( 'placeholder', $opts, true ) || in_array( 'watermark', $opts, true ) ) ) {
			$ph = (string) $values[0];
		}
		$label = '' !== $tag['label'] ? $tag['label'] : self::humanize( $tag['name'] );
		if ( 'acceptance' === $base && '' === $tag['label'] ) {
			$label = __( 'Consent', 'perdita-core' );
		}
		$out['field']  = array(
			'type'        => $type,
			'label'       => $label,
			'name'        => (string) $tag['name'],
			'required'    => (bool) $required,
			'placeholder' => $ph,
			'options'     => $options,
		);
		$out['status'] = 'import';
		return $out;
	}

	/**
	 * Swap Contact Form 7's mail-tags for what Perdita can send: [field]
	 * becomes {field} in the body, and the site tags become their values.
	 *
	 * @param string   $text   Text.
	 * @param string[] $fields Field names Perdita imports.
	 * @param bool     $body   In the body, field tags become {field}. Elsewhere they drop out.
	 * @return array text, dropped (string[] tags taken out).
	 */
	public static function mail_text( $text, array $fields, $body ) {
		$site    = array(
			'_site_title'       => get_bloginfo( 'name' ),
			'_site_description' => get_bloginfo( 'description' ),
			'_site_url'         => home_url(),
			'_site_admin_email' => get_option( 'admin_email' ),
		);
		$dropped = array();
		// "Name <[your-email]>" would lose the address to the plain-text
		// sanitizer, so the brackets around a lone mail-tag go first.
		$text = preg_replace( '/<(\[[^\[\]<>]+\])>/', '$1', (string) $text );
		$text = preg_replace_callback(
			'/\[(_[a-z_]+|[a-zA-Z][0-9a-zA-Z:._-]*)\]/',
			function ( $m ) use ( $site, $fields, $body, &$dropped ) {
				if ( isset( $site[ $m[1] ] ) ) {
					return (string) $site[ $m[1] ];
				}
				if ( $body && in_array( $m[1], $fields, true ) ) {
					return '{' . $m[1] . '}';
				}
				$dropped[] = $m[0];
				return '';
			},
			(string) $text
		);
		// A tag that came out of quotes leaves the empty quotes behind.
		$text = preg_replace( '/""|\'\'|\x{201C}\s*\x{201D}/u', '', $text );
		return array(
			'text'    => trim( preg_replace( '/[ \t]{2,}/', ' ', $text ) ),
			'dropped' => $dropped,
		);
	}

	/* ---------- plan ---------- */

	public function plan() {
		$this->begin();
		if ( ! self::forms_loaded() ) {
			$this->note( __( 'Perdita Forms isn\'t available, so you have nothing to import into.', 'perdita-core' ) );
			return $this->finish();
		}
		$map = Perdita_Forms::cf7_map();
		foreach ( self::forms() as $post ) {
			$cf7_id = (int) $post->ID;
			$title  = (string) $post->post_title;
			/* translators: 1: form title, 2: its Contact Form 7 id. */
			$where = sprintf( __( 'Form "%1$s" (#%2$d)', 'perdita-core' ), $title, $cf7_id );
			if ( isset( $map[ $cf7_id ]['id'] ) && get_post( (int) $map[ $cf7_id ]['id'] ) ) {
				/* translators: %d: Perdita form id. */
				$this->unsupported( $where, '', sprintf( __( 'You imported this form already, as Perdita form %d.', 'perdita-core' ), (int) $map[ $cf7_id ]['id'] ) );
				continue;
			}
			$this->plan_form( $cf7_id, $title, $where );
		}
		return $this->finish();
	}

	/**
	 * Rows and patch for one form.
	 *
	 * @param int    $cf7_id Contact Form 7 post id.
	 * @param string $title  Its title.
	 * @param string $where  Row prefix.
	 */
	private function plan_form( $cf7_id, $title, $where ) {
		$template = (string) get_post_meta( $cf7_id, '_form', true );
		$mail     = get_post_meta( $cf7_id, '_mail', true );
		$mail     = is_array( $mail ) ? $mail : array();
		$messages = get_post_meta( $cf7_id, '_messages', true );
		$messages = is_array( $messages ) ? $messages : array();
		$fields   = array();
		$names    = array();
		$skipped  = array();

		foreach ( self::parse_form( $template ) as $tag ) {
			$res   = self::field_for( $tag );
			$shown = '[' . $tag['type'] . ( '' !== $tag['name'] ? ' ' . $tag['name'] : '' ) . ']';
			if ( 'import' !== $res['status'] ) {
				$row = array(
					'setting' => $where . ': ' . $shown,
					'found'   => $shown,
					'target'  => '',
					'value'   => '',
					'status'  => $res['status'],
					'reason'  => $res['reason'],
				);
				$this->building['rows'][] = $row;
				$skipped[]                = $tag['name'];
				continue;
			}
			$field = $res['field'];
			$clean = Perdita_Forms::sanitize_config( array( 'fields' => array( $field ) ) );
			$kept  = $clean['fields'][0];
			if ( $kept !== $field || in_array( $field['name'], $names, true ) ) {
				$this->skip( $where . ': ' . $shown, $shown, __( 'Perdita\'s form editor would change this field\'s name or text, so it stays out.', 'perdita-core' ), __( 'Field', 'perdita-core' ) );
				$skipped[] = $tag['name'];
				continue;
			}
			$fields[] = $field;
			$names[]  = $field['name'];
			$this->building['rows'][] = array(
				'setting' => $where . ': ' . $shown,
				'found'   => $shown,
				'target'  => __( 'Field', 'perdita-core' ),
				'value'   => $field['label'] . ' (' . $field['type'] . ( $field['required'] ? ', ' . __( 'required', 'perdita-core' ) : '' ) . ')',
				'status'  => 'import',
				'reason'  => '',
			);
		}

		if ( ! $fields ) {
			$this->unsupported( $where, '', __( 'This form has no fields Perdita Forms can show, so it stays out.', 'perdita-core' ) );
			return;
		}

		$to      = self::mail_text( isset( $mail['recipient'] ) ? $mail['recipient'] : '', $names, false );
		$subject = self::mail_text( isset( $mail['subject'] ) ? $mail['subject'] : '', $names, false );
		$body    = self::mail_text( isset( $mail['body'] ) ? $mail['body'] : '', $names, true );
		$cfg     = array(
			'fields'   => $fields,
			'email'    => array(
				'to'      => '' !== $to['text'] ? $to['text'] : get_option( 'admin_email' ),
				'subject' => $subject['text'],
				'body'    => $body['text'],
			),
			'messages' => array(
				'success' => isset( $messages['mail_sent_ok'] ) ? (string) $messages['mail_sent_ok'] : '',
				'error'   => isset( $messages['validation_error'] ) ? (string) $messages['validation_error'] : '',
			),
		);
		$clean = Perdita_Forms::sanitize_config( $cfg );
		foreach ( array( 'to', 'subject', 'body' ) as $k ) {
			if ( $clean['email'][ $k ] !== $cfg['email'][ $k ] ) {
				$cfg['email'][ $k ] = $clean['email'][ $k ];
			}
		}
		foreach ( array( 'success', 'error' ) as $k ) {
			$cfg['messages'][ $k ] = $clean['messages'][ $k ];
		}

		if ( ! empty( $to['dropped'] ) ) {
			/* translators: %s: mail-tags. */
			$this->skip( $where . ': ' . __( 'Mail To', 'perdita-core' ), isset( $mail['recipient'] ) ? $mail['recipient'] : '', sprintf( __( 'Perdita sends to fixed addresses, so %s stays out.', 'perdita-core' ), implode( ' ', $to['dropped'] ) ), __( 'Send to', 'perdita-core' ) );
		}
		$this->building['rows'][] = array(
			'setting' => $where . ': ' . __( 'Mail To', 'perdita-core' ),
			'found'   => self::show( isset( $mail['recipient'] ) ? $mail['recipient'] : '' ),
			'target'  => __( 'Send to', 'perdita-core' ),
			'value'   => self::show( $cfg['email']['to'] ),
			'status'  => 'import',
			'reason'  => '',
		);
		$this->building['rows'][] = array(
			'setting' => $where . ': ' . __( 'Mail Subject', 'perdita-core' ),
			'found'   => self::show( isset( $mail['subject'] ) ? $mail['subject'] : '' ),
			'target'  => __( 'Subject', 'perdita-core' ),
			'value'   => self::show( $cfg['email']['subject'] ),
			'status'  => 'import',
			/* translators: %s: mail-tags. */
			'reason'  => $subject['dropped'] ? sprintf( __( 'Perdita\'s subject line can\'t hold what a visitor typed, so %s comes out of it.', 'perdita-core' ), implode( ' ', $subject['dropped'] ) ) : '',
		);
		$this->building['rows'][] = array(
			'setting' => $where . ': ' . __( 'Mail Message Body', 'perdita-core' ),
			'found'   => self::show( isset( $mail['body'] ) ? $mail['body'] : '' ),
			'target'  => __( 'Email body', 'perdita-core' ),
			'value'   => self::show( $cfg['email']['body'] ),
			'status'  => 'import',
			/* translators: %s: mail-tags. */
			'reason'  => $body['dropped'] ? sprintf( __( 'Perdita has nothing to put in for %s, so it comes out.', 'perdita-core' ), implode( ' ', array_unique( $body['dropped'] ) ) ) : '',
		);
		if ( ! empty( $mail['sender'] ) ) {
			$this->unsupported( $where . ': ' . __( 'Mail From', 'perdita-core' ), $mail['sender'], __( 'Perdita sends from your site\'s own address. Set it on Perdita, Email.', 'perdita-core' ) );
		}
		$mail_2 = get_post_meta( $cf7_id, '_mail_2', true );
		if ( is_array( $mail_2 ) && ! empty( $mail_2['active'] ) ) {
			$this->unsupported( $where . ': ' . __( 'Mail (2)', 'perdita-core' ), __( 'on', 'perdita-core' ), __( 'Perdita Forms sends one email. Perdita Pro\'s Forms Pro sends the autoresponder.', 'perdita-core' ) );
		}

		$hash = (string) get_post_meta( $cf7_id, '_hash', true );
		$this->building['patch']['forms'][ $cf7_id ] = array(
			'title' => $title,
			'hash'  => preg_match( '/^[0-9a-f]+$/', $hash ) ? $hash : '',
			'cfg'   => $cfg,
		);
	}

	/* ---------- write and undo ---------- */

	/**
	 * Make one Perdita form per planned form and record it.
	 *
	 * @param array $patch From plan().
	 * @return bool
	 */
	public function apply( array $patch ) {
		if ( empty( $patch['forms'] ) || ! is_array( $patch['forms'] ) || ! self::forms_loaded() ) {
			return false;
		}
		$map   = Perdita_Forms::cf7_map();
		$wrote = false;
		foreach ( $patch['forms'] as $cf7_id => $row ) {
			$id = wp_insert_post(
				array(
					'post_type'   => Perdita_Forms::CPT,
					'post_status' => 'publish',
					'post_title'  => sanitize_text_field( (string) $row['title'] ),
				),
				true
			);
			if ( is_wp_error( $id ) || ! $id ) {
				continue;
			}
			update_post_meta( $id, Perdita_Forms::META, Perdita_Forms::sanitize_config( (array) $row['cfg'] ) );
			update_post_meta( $id, '_perdita_switch_from', $this->id() . ':' . (int) $cf7_id );
			$map[ (int) $cf7_id ] = array(
				'id'    => (int) $id,
				'hash'  => (string) $row['hash'],
				'title' => (string) $row['title'],
			);
			$wrote = true;
		}
		if ( $wrote ) {
			update_option( Perdita_Forms::CF7_MAP_OPTION, $map );
		}
		return $wrote;
	}

	/**
	 * Undo: trash the Perdita forms this import made that the snapshot
	 * didn't already list. Core puts the map back right after.
	 *
	 * @param array $snapshot The snapshot Undo restores.
	 */
	public function before_undo( array $snapshot ) {
		if ( ! self::forms_loaded() ) {
			return;
		}
		$before = array();
		$name   = Perdita_Forms::CF7_MAP_OPTION;
		if ( ! empty( $snapshot['options'][ $name ]['exists'] ) && is_array( $snapshot['options'][ $name ]['value'] ) ) {
			$before = $snapshot['options'][ $name ]['value'];
		}
		foreach ( Perdita_Forms::cf7_map() as $cf7_id => $row ) {
			if ( isset( $before[ $cf7_id ] ) || empty( $row['id'] ) ) {
				continue;
			}
			$post = get_post( (int) $row['id'] );
			if ( $post && Perdita_Forms::CPT === $post->post_type && $this->id() . ':' . (int) $cf7_id === get_post_meta( $post->ID, '_perdita_switch_from', true ) ) {
				wp_trash_post( $post->ID );
			}
		}
	}
}
