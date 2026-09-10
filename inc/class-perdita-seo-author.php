<?php
/**
 * Author E-E-A-T: profile fields (job title, expertise, profiles, X handle,
 * credentials, archive noindex) and the schema Person node built from them.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Author profile fields and the schema Person node built from them.
 */
class Perdita_SEO_Author {

	const META_JOB_TITLE   = 'perdita_seo_job_title';
	const META_KNOWS_ABOUT = 'perdita_seo_knows_about';
	const META_SAME_AS     = 'perdita_seo_same_as';
	const META_TWITTER     = 'perdita_seo_twitter';
	const META_CREDENTIALS = 'perdita_seo_credentials';
	const META_NOINDEX     = 'perdita_seo_noindex_author_archive';

	/**
	 * Hook the profile screen. The schema helpers below are static so the
	 * front end never needs an instance.
	 */
	public function __construct() {
		if ( ! is_admin() ) {
			return;
		}
		add_action( 'show_user_profile', array( $this, 'fields' ) );
		add_action( 'edit_user_profile', array( $this, 'fields' ) );
		add_action( 'personal_options_update', array( $this, 'save' ) );
		add_action( 'edit_user_profile_update', array( $this, 'save' ) );
	}

	/* ---------- profile screen ---------- */

	/**
	 * Render the Perdita SEO section on the profile screen.
	 *
	 * @param WP_User $user User being edited.
	 */
	public function fields( $user ) {
		if ( ! $user instanceof WP_User || ! current_user_can( 'edit_user', $user->ID ) ) {
			return;
		}
		wp_nonce_field( 'perdita_seo_author_' . $user->ID, 'perdita_seo_author_nonce' );
		echo '<h2>' . esc_html__( 'Perdita SEO', 'perdita-core' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'These feed the author schema search engines use to judge expertise. Leave blank what does not apply.', 'perdita-core' ) . '</p>';
		echo '<table class="form-table" role="presentation">';
		$this->text_row( __( 'Job title', 'perdita-core' ), self::META_JOB_TITLE, (string) get_user_meta( $user->ID, self::META_JOB_TITLE, true ), __( 'For example: Senior Editor.', 'perdita-core' ) );
		$this->text_row( __( 'Credentials', 'perdita-core' ), self::META_CREDENTIALS, (string) get_user_meta( $user->ID, self::META_CREDENTIALS, true ), __( 'Short, after the name: CFP, MD, PhD.', 'perdita-core' ) );
		$this->text_row( __( 'Knows about', 'perdita-core' ), self::META_KNOWS_ABOUT, (string) get_user_meta( $user->ID, self::META_KNOWS_ABOUT, true ), __( 'Comma separated topics: personal finance, credit cards, small business.', 'perdita-core' ) );
		$this->text_row( __( 'X / Twitter handle', 'perdita-core' ), self::META_TWITTER, (string) get_user_meta( $user->ID, self::META_TWITTER, true ), __( '@handle, used for the twitter:creator card tag.', 'perdita-core' ) );
		$same_as = (string) get_user_meta( $user->ID, self::META_SAME_AS, true );
		echo '<tr><th scope="row"><label for="f_' . esc_attr( self::META_SAME_AS ) . '">' . esc_html__( 'Profile URLs', 'perdita-core' ) . '</label></th><td>';
		echo '<textarea class="large-text code" rows="4" id="f_' . esc_attr( self::META_SAME_AS ) . '" name="' . esc_attr( self::META_SAME_AS ) . '">' . esc_textarea( $same_as ) . '</textarea>';
		echo '<p class="description">' . esc_html__( 'One URL per line: LinkedIn, Wikipedia, a personal site, other bylines. Becomes the schema sameAs list.', 'perdita-core' ) . '</p></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Author archive', 'perdita-core' ) . '</th><td><label>';
		echo '<input type="checkbox" name="' . esc_attr( self::META_NOINDEX ) . '" value="1" ' . checked( (bool) get_user_meta( $user->ID, self::META_NOINDEX, true ), true, false ) . ' /> ';
		echo esc_html__( 'Hide this author\'s archive from search engines (noindex)', 'perdita-core' ) . '</label></td></tr>';
		echo '</table>';
	}

	/**
	 * Save the profile fields.
	 *
	 * @param int $user_id User being saved.
	 */
	public function save( $user_id ) {
		$user_id = (int) $user_id;
		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}
		$nonce = isset( $_POST['perdita_seo_author_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['perdita_seo_author_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'perdita_seo_author_' . $user_id ) ) {
			return;
		}
		$text = array( self::META_JOB_TITLE, self::META_CREDENTIALS, self::META_KNOWS_ABOUT );
		foreach ( $text as $key ) {
			self::write( $user_id, $key, sanitize_text_field( wp_unslash( $_POST[ $key ] ?? '' ) ) );
		}
		$twitter = sanitize_text_field( wp_unslash( $_POST[ self::META_TWITTER ] ?? '' ) );
		self::write( $user_id, self::META_TWITTER, self::normalize_handle( $twitter ) );

		$raw_urls = sanitize_textarea_field( wp_unslash( $_POST[ self::META_SAME_AS ] ?? '' ) );
		$urls     = array();
		foreach ( preg_split( '/[\r\n]+/', $raw_urls ) as $line ) {
			$line = esc_url_raw( trim( (string) $line ) );
			if ( '' !== $line ) {
				$urls[] = $line;
			}
		}
		self::write( $user_id, self::META_SAME_AS, implode( "\n", array_unique( $urls ) ) );

		if ( ! empty( $_POST[ self::META_NOINDEX ] ) ) {
			update_user_meta( $user_id, self::META_NOINDEX, 1 );
		} else {
			delete_user_meta( $user_id, self::META_NOINDEX );
		}
	}

	/* ---------- read helpers ---------- */

	/**
	 * Whether the author asked for a noindex on their archive.
	 *
	 * @param int $user_id User id.
	 * @return bool
	 */
	public static function noindex_archive( $user_id ) {
		return (bool) get_user_meta( (int) $user_id, self::META_NOINDEX, true );
	}

	/**
	 * The author's X handle without the @, or empty.
	 *
	 * @param int $user_id User id.
	 * @return string
	 */
	public static function twitter_handle( $user_id ) {
		return self::normalize_handle( (string) get_user_meta( (int) $user_id, self::META_TWITTER, true ) );
	}

	/**
	 * The author's sameAs URLs.
	 *
	 * @param int $user_id User id.
	 * @return string[]
	 */
	public static function same_as( $user_id ) {
		$raw  = (string) get_user_meta( (int) $user_id, self::META_SAME_AS, true );
		$urls = array();
		foreach ( preg_split( '/[\r\n]+/', $raw ) as $line ) {
			$line = trim( (string) $line );
			if ( '' !== $line && 0 === strpos( $line, 'http' ) ) {
				$urls[] = $line;
			}
		}
		$handle = self::twitter_handle( $user_id );
		if ( '' !== $handle ) {
			$urls[] = 'https://x.com/' . $handle;
		}
		$website = (string) get_the_author_meta( 'user_url', (int) $user_id );
		if ( '' !== $website && 0 === strpos( $website, 'http' ) ) {
			$urls[] = $website;
		}
		return array_values( array_unique( $urls ) );
	}

	/**
	 * The schema @id every reference to this author uses.
	 *
	 * @param int $user_id User id.
	 * @return string
	 */
	public static function schema_id( $user_id ) {
		return get_author_posts_url( (int) $user_id ) . '#author';
	}

	/**
	 * A full Person node for an author. Empty array when the user is gone.
	 *
	 * @param int    $user_id     User id.
	 * @param string $identity_id The site identity node @id, for worksFor.
	 * @return array
	 */
	public static function person_node( $user_id, $identity_id = '' ) {
		$user = get_userdata( (int) $user_id );
		if ( ! $user instanceof WP_User ) {
			return array();
		}
		$node   = array(
			'@type' => 'Person',
			'@id'   => self::schema_id( $user->ID ),
			'name'  => (string) $user->display_name,
			'url'   => get_author_posts_url( $user->ID ),
		);
		$avatar = get_avatar_url( $user->ID, array( 'size' => 256 ) );
		if ( $avatar ) {
			$node['image'] = array(
				'@type'   => 'ImageObject',
				'url'     => $avatar,
				'caption' => (string) $user->display_name,
			);
		}
		$bio = Perdita_SEO_Variables::plain( (string) $user->description );
		if ( '' !== $bio ) {
			$node['description'] = $bio;
		}
		$job = trim( (string) get_user_meta( $user->ID, self::META_JOB_TITLE, true ) );
		if ( '' !== $job ) {
			$node['jobTitle'] = $job;
		}
		$creds = trim( (string) get_user_meta( $user->ID, self::META_CREDENTIALS, true ) );
		if ( '' !== $creds ) {
			$node['honorificSuffix'] = $creds;
		}
		$knows = array_values( array_filter( array_map( 'trim', explode( ',', (string) get_user_meta( $user->ID, self::META_KNOWS_ABOUT, true ) ) ) ) );
		if ( $knows ) {
			$node['knowsAbout'] = $knows;
		}
		$same = self::same_as( $user->ID );
		if ( $same ) {
			$node['sameAs'] = $same;
		}
		if ( '' !== (string) $identity_id ) {
			$node['worksFor'] = array( '@id' => (string) $identity_id );
		}

		/**
		 * Adjust the author Person node before it joins the graph.
		 *
		 * @param array   $node Person node.
		 * @param WP_User $user Author.
		 */
		return (array) apply_filters( 'perdita_seo_author_node', $node, $user );
	}

	/* ---------- private ---------- */

	/**
	 * Reduce a handle or profile URL to the bare handle.
	 *
	 * @param string $value Handle or URL.
	 * @return string
	 */
	private static function normalize_handle( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}
		if ( 0 === strpos( $value, 'http' ) ) {
			$value = (string) wp_parse_url( $value, PHP_URL_PATH );
		}
		$value = ltrim( trim( $value, '/ ' ), '@' );
		return (string) preg_replace( '/[^A-Za-z0-9_]/', '', $value );
	}

	/**
	 * Store a value, deleting the row when it is empty so the meta table
	 * does not fill with blanks.
	 *
	 * @param int    $user_id User id.
	 * @param string $key     Meta key.
	 * @param string $value   Value.
	 */
	private static function write( $user_id, $key, $value ) {
		if ( '' === trim( (string) $value ) ) {
			delete_user_meta( $user_id, $key );
		} else {
			update_user_meta( $user_id, $key, $value );
		}
	}

	/**
	 * One text input row.
	 *
	 * @param string $label Label.
	 * @param string $name  Field name.
	 * @param string $value Value.
	 * @param string $desc  Help text.
	 */
	private function text_row( $label, $name, $value, $desc = '' ) {
		echo '<tr><th scope="row"><label for="f_' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label></th><td>';
		echo '<input type="text" class="regular-text" id="f_' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" />';
		if ( '' !== $desc ) {
			echo '<p class="description">' . esc_html( $desc ) . '</p>';
		}
		echo '</td></tr>';
	}
}
