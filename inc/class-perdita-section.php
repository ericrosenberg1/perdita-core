<?php
/**
 * "Describe a section" AI feature.
 *
 * Adds a block-editor sidebar where you describe a page section in plain words
 * and Perdita generates it as core blocks (using the same section helper classes
 * as the pattern library) and inserts it. Works whenever any AI provider is
 * connected: the free managed relay, or a Pro key.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

class Perdita_Section {

	/**
	 * Block names the generator is allowed to return, matching the system
	 * prompt's own list. Anything else (core/html above all) is rejected
	 * rather than inserted, since AI output here is untrusted input that
	 * reaches the block editor canvas and, if published, the front end.
	 *
	 * @var string[]
	 */
	const ALLOWED_BLOCKS = array(
		'core/group',
		'core/columns',
		'core/column',
		'core/heading',
		'core/paragraph',
		'core/buttons',
		'core/button',
		'core/list',
		'core/list-item',
		'core/quote',
		'core/details',
		'core/image',
		'core/spacer',
	);

	/**
	 * AI provider router.
	 *
	 * @var Perdita_AI
	 */
	private $ai;

	/**
	 * Constructor.
	 *
	 * @param Perdita_AI $ai Router.
	 */
	public function __construct( Perdita_AI $ai ) {
		$this->ai = $ai;
		add_action( 'rest_api_init', array( $this, 'routes' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'editor_assets' ) );
	}

	/**
	 * Register the generate route.
	 */
	public function routes() {
		register_rest_route(
			'perdita/v1',
			'/section',
			array(
				'methods'             => 'POST',
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
				'args'                => array(
					'description' => array(
						'required'          => true,
						'type'              => 'string',
						// A section brief is a sentence or two. Without a cap a
						// Contributor could send megabytes per request and spend
						// the site's AI budget on input tokens, 30 times an hour.
						'maxLength'         => 2000,
						'validate_callback' => 'rest_validate_request_arg',
						'sanitize_callback' => 'sanitize_textarea_field',
					),
				),
				'callback'            => array( $this, 'generate' ),
			)
		);
	}

	/**
	 * Generate block markup for a section.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response
	 */
	public function generate( WP_REST_Request $req ) {
		// Throttle per user: this spends the site's AI budget, and the route is
		// open to contributors. Filterable cap (default 30) per hour.
		$max = (int) apply_filters( 'perdita_section_rate_limit', 30 );
		if ( $max > 0 ) {
			$key   = 'perdita_sec_' . get_current_user_id();
			$count = (int) get_transient( $key );
			if ( $count >= $max ) {
				return new WP_REST_Response( array( 'error' => __( 'You have reached the hourly limit for AI sections. Please try again later.', 'perdita-core' ) ), 429 );
			}
			set_transient( $key, $count + 1, HOUR_IN_SECONDS );
		}

		$description = (string) $req->get_param( 'description' );
		$system      = "You build one WordPress page section as Gutenberg block markup.\n"
			. "Return ONLY block markup using the <!-- wp:... --> comment syntax. No explanations, no code fences, no <html>.\n"
			. "Use core blocks only: group, columns, column, heading, paragraph, buttons, button, list, quote, details, image, spacer.\n"
			. "Wrap the whole section in a group with className \"perdita-section\" and a constrained layout. "
			. "For a tinted background add \"perdita-section--surface\"; for a bold accent background add \"perdita-section--primary\". "
			. "Use \"perdita-card\" on columns that should look like cards.\n"
			. "Keep copy short and placeholder-like so the user can edit it. Output valid, self-closing block markup.";

		$out = $this->ai->complete(
			array( array( 'role' => 'user', 'content' => 'Build a section: ' . $description ) ),
			$system,
			false
		);

		if ( is_wp_error( $out ) ) {
			return new WP_REST_Response( array( 'error' => $out->get_error_message() ), 502 );
		}

		$markup = self::strip_fences( trim( (string) $out ) );
		if ( '' === $markup || false === strpos( $markup, '<!-- wp:' ) ) {
			return new WP_REST_Response( array( 'error' => __( 'The model did not return usable blocks. Try a more specific description.', 'perdita-core' ) ), 422 );
		}

		// The system prompt asks for "core blocks only," but that's a request,
		// not an enforcement: reject anything outside the allow-list (raw HTML
		// above all) rather than pass it through to the editor.
		$blocks = parse_blocks( $markup );
		if ( empty( $blocks ) || ! self::blocks_allowed( $blocks ) ) {
			return new WP_REST_Response( array( 'error' => __( 'The model returned unsupported content. Try a different description.', 'perdita-core' ) ), 422 );
		}

		// Defense-in-depth: strip anything KSES wouldn't allow in ordinary post
		// content (script tags, event-handler attributes, javascript: URLs)
		// while preserving the wp: block-comment structure.
		$markup = wp_kses_post( $markup );

		return new WP_REST_Response( array( 'markup' => $markup ), 200 );
	}

	/**
	 * Recursively confirm every block (and nested block) is on the allow-list.
	 * Rejects unparsed/freeform content (blockName === null with real content,
	 * e.g. a core/html block or raw HTML the parser couldn't attribute to a
	 * block) and anything not explicitly listed.
	 *
	 * Public static (not private) so a companion plugin extending this
	 * feature (e.g. Perdita Pro's edit-existing-section and whole-page-
	 * from-a-brief modes) can validate AI-returned markup through the
	 * exact same allow-list rather than duplicating it.
	 *
	 * @param array $blocks Parsed blocks (parse_blocks() output).
	 * @return bool
	 */
	public static function blocks_allowed( array $blocks ) {
		foreach ( $blocks as $block ) {
			if ( empty( $block['blockName'] ) ) {
				// parse_blocks() emits whitespace-only "freeform" blocks between
				// block comments; that's a harmless parser artifact, not content.
				if ( '' === trim( (string) $block['innerHTML'] ) ) {
					continue;
				}
				return false;
			}
			if ( ! in_array( $block['blockName'], self::ALLOWED_BLOCKS, true ) ) {
				return false;
			}
			if ( ! empty( $block['innerBlocks'] ) && ! self::blocks_allowed( $block['innerBlocks'] ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Strip Markdown code fences a model may wrap around the markup.
	 * Public static for the same reason as blocks_allowed() above.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function strip_fences( $text ) {
		if ( 0 === strpos( $text, '```' ) ) {
			$text = preg_replace( '/^```[a-zA-Z]*\n?/', '', $text );
			$text = preg_replace( '/\n?```\s*$/', '', $text );
		}
		return trim( (string) $text );
	}

	/**
	 * Enqueue the editor sidebar script.
	 */
	public function editor_assets() {
		wp_enqueue_script(
			'perdita-section-editor',
			PERDITA_CORE_URL . 'assets/js/section-editor.js',
			array( 'wp-plugins', 'wp-edit-post', 'wp-element', 'wp-components', 'wp-blocks', 'wp-data', 'wp-api-fetch', 'wp-i18n' ),
			PERDITA_CORE_VERSION,
			true
		);
		wp_localize_script(
			'perdita-section-editor',
			'PerditaSection',
			array(
				'ready' => $this->ai->is_ready(),
				'setup' => esc_url_raw( admin_url( 'admin.php?page=perdita' ) ),
			)
		);
	}
}
