<?php
/**
 * Per-term SEO title and description. Registered as term meta on every
 * public taxonomy (REST-visible, so the block editor and Pro can write it),
 * edited on the add and edit term screens, and read by Perdita_SEO ahead of
 * the taxonomy template.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Per-term SEO title and description meta, and the term screens that edit it.
 */
class Perdita_SEO_Terms {

	const META_TITLE       = '_perdita_seo_title';
	const META_DESCRIPTION = '_perdita_seo_description';

	/**
	 * Register late on init so taxonomies other plugins add at the default
	 * priority are included.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'register' ), 99 );
	}

	/**
	 * Register the meta and hook the term screens for every public taxonomy.
	 */
	public function register() {
		foreach ( self::taxonomies() as $taxonomy ) {
			foreach ( array( self::META_TITLE, self::META_DESCRIPTION ) as $key ) {
				register_term_meta(
					$taxonomy,
					$key,
					array(
						'type'              => 'string',
						'single'            => true,
						'show_in_rest'      => true,
						'sanitize_callback' => 'sanitize_text_field',
						'auth_callback'     => function ( $allowed, $meta_key, $term_id ) {
							unset( $allowed, $meta_key );
							return current_user_can( 'edit_term', (int) $term_id );
						},
					)
				);
			}
			if ( is_admin() ) {
				add_action( $taxonomy . '_add_form_fields', array( $this, 'add_fields' ) );
				add_action( $taxonomy . '_edit_form_fields', array( $this, 'edit_fields' ) );
				add_action( 'created_' . $taxonomy, array( $this, 'save' ) );
				add_action( 'edited_' . $taxonomy, array( $this, 'save' ) );
			}
		}
	}

	/**
	 * Public taxonomies that get the fields. Post formats are public but
	 * never carry a meaningful archive title, so they are skipped.
	 *
	 * @return string[]
	 */
	public static function taxonomies() {
		$all = get_taxonomies( array( 'public' => true ), 'names' );
		unset( $all['post_format'] );
		return array_values( $all );
	}

	/**
	 * Stored SEO title for a term, or empty.
	 *
	 * @param int $term_id Term id.
	 * @return string
	 */
	public static function title( $term_id ) {
		return trim( (string) get_term_meta( (int) $term_id, self::META_TITLE, true ) );
	}

	/**
	 * Stored SEO description for a term, or empty.
	 *
	 * @param int $term_id Term id.
	 * @return string
	 */
	public static function description( $term_id ) {
		return trim( (string) get_term_meta( (int) $term_id, self::META_DESCRIPTION, true ) );
	}

	/* ---------- screens ---------- */

	/**
	 * Fields on the add-term form.
	 */
	public function add_fields() {
		wp_nonce_field( 'perdita_seo_term', 'perdita_seo_term_nonce' );
		echo '<div class="form-field"><label for="perdita_seo_title">' . esc_html__( 'SEO title', 'perdita-core' ) . '</label>';
		echo '<input type="text" id="perdita_seo_title" name="perdita_seo_title" value="" />';
		echo '<p>' . esc_html__( 'Overrides the taxonomy title template for this term. Template tokens work here too.', 'perdita-core' ) . '</p></div>';
		echo '<div class="form-field"><label for="perdita_seo_description">' . esc_html__( 'Meta description', 'perdita-core' ) . '</label>';
		echo '<textarea id="perdita_seo_description" name="perdita_seo_description" rows="3"></textarea>';
		echo '<p>' . esc_html__( 'Overrides the taxonomy description template for this term.', 'perdita-core' ) . '</p></div>';
	}

	/**
	 * Fields on the edit-term form.
	 *
	 * @param WP_Term $term Term being edited.
	 */
	public function edit_fields( $term ) {
		if ( ! $term instanceof WP_Term ) {
			return;
		}
		wp_nonce_field( 'perdita_seo_term', 'perdita_seo_term_nonce' );
		echo '<tr class="form-field"><th scope="row"><label for="perdita_seo_title">' . esc_html__( 'SEO title', 'perdita-core' ) . '</label></th><td>';
		echo '<input type="text" id="perdita_seo_title" name="perdita_seo_title" value="' . esc_attr( self::title( $term->term_id ) ) . '" />';
		echo '<p class="description">' . esc_html__( 'Overrides the taxonomy title template for this term. Template tokens work here too.', 'perdita-core' ) . '</p></td></tr>';
		echo '<tr class="form-field"><th scope="row"><label for="perdita_seo_description">' . esc_html__( 'Meta description', 'perdita-core' ) . '</label></th><td>';
		echo '<textarea id="perdita_seo_description" name="perdita_seo_description" rows="3">' . esc_textarea( self::description( $term->term_id ) ) . '</textarea>';
		echo '<p class="description">' . esc_html__( 'Overrides the taxonomy description template for this term.', 'perdita-core' ) . '</p></td></tr>';
	}

	/**
	 * Save both fields when the term is created or edited.
	 *
	 * @param int $term_id Term id.
	 */
	public function save( $term_id ) {
		$term_id = (int) $term_id;
		$nonce   = isset( $_POST['perdita_seo_term_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['perdita_seo_term_nonce'] ) ) : '';
		if ( '' === $nonce || ! wp_verify_nonce( $nonce, 'perdita_seo_term' ) || ! current_user_can( 'edit_term', $term_id ) ) {
			return;
		}
		if ( ! array_key_exists( 'perdita_seo_title', $_POST ) && ! array_key_exists( 'perdita_seo_description', $_POST ) ) {
			return;
		}
		$title = Perdita_SEO_Store::sanitize_template( wp_unslash( $_POST['perdita_seo_title'] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Perdita_SEO_Store::sanitize_template() is the sanitizer.
		$desc  = Perdita_SEO_Store::sanitize_template( wp_unslash( $_POST['perdita_seo_description'] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Perdita_SEO_Store::sanitize_template() is the sanitizer.
		self::write( $term_id, self::META_TITLE, $title );
		self::write( $term_id, self::META_DESCRIPTION, $desc );
	}

	/**
	 * Store or clear one meta value.
	 *
	 * @param int    $term_id Term id.
	 * @param string $key     Meta key.
	 * @param string $value   Value.
	 */
	private static function write( $term_id, $key, $value ) {
		if ( '' === $value ) {
			delete_term_meta( $term_id, $key );
		} else {
			update_term_meta( $term_id, $key, $value );
		}
	}
}
