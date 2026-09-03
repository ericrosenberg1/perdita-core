<?php
/**
 * Related posts. Appends a "related posts" section after a single post's
 * content, matched by shared categories and/or tags. Pure local WP_Query,
 * no external service or cloud recommendation engine, so there's no data
 * leaving the site and nothing to configure beyond the options below.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

class Perdita_Related_Posts {

	const OPTION = 'perdita_related_posts_settings';

	/**
	 * Prefix for the per-post meta key that caches a resolved related-post ID
	 * list. The full key carries a short hash of the current settings, so
	 * changing the count or the match mode reads a different key and rebuilds,
	 * and the previous key is simply never read again (purge_post_cache()
	 * clears every key with this prefix, so stale ones go on the next edit).
	 */
	const META_PREFIX = '_perdita_related_';

	/**
	 * The Perdita core.
	 *
	 * @var Perdita
	 */
	private $core;

	/**
	 * Constructor.
	 *
	 * @param Perdita $core Core.
	 */
	public function __construct( $core ) {
		$this->core = $core;
		add_filter( 'the_content', array( $this, 'append' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue' ) );

		// Invalidate a post's cached list when the post itself changes, when
		// its categories or tags change (which is what the match is built
		// from), or when it is deleted.
		add_action( 'save_post', array( $this, 'purge_post_cache' ) );
		add_action( 'set_object_terms', array( $this, 'purge_post_cache' ) );
		add_action( 'deleted_post', array( $this, 'purge_post_cache' ) );
	}

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'count'    => 3,
			'match_by' => 'both', // category|tag|both
			'heading'  => __( 'Related posts', 'perdita-core' ),
		);
	}

	/**
	 * Read settings, merged over defaults.
	 *
	 * @return array
	 */
	public static function settings() {
		$saved = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
	}

	/**
	 * Sanitize a settings array by field role. Used by the admin save handler
	 * and safe to call on anything, so a bad value can never corrupt the
	 * option or the query built from it.
	 *
	 * @param array $input Raw input.
	 * @return array
	 */
	public static function sanitize( array $input ) {
		$clean          = self::defaults();
		$clean['count'] = isset( $input['count'] ) ? max( 1, min( 6, absint( $input['count'] ) ) ) : $clean['count'];
		$match_by       = isset( $input['match_by'] ) ? (string) $input['match_by'] : '';
		if ( in_array( $match_by, array( 'category', 'tag', 'both' ), true ) ) {
			$clean['match_by'] = $match_by;
		}
		if ( isset( $input['heading'] ) ) {
			$heading           = sanitize_text_field( $input['heading'] );
			$clean['heading']  = '' !== $heading ? $heading : $clean['heading'];
		}
		return $clean;
	}

	/**
	 * Only load the stylesheet on the requests that will actually render the
	 * block, so an enabled-but-rarely-hit module still costs nothing on most
	 * page views.
	 */
	public function maybe_enqueue() {
		if ( is_singular( 'post' ) && ! post_password_required() ) {
			wp_enqueue_style( 'perdita-related-posts', PERDITA_CORE_URL . 'inc/modules/related-posts/assets/related-posts.css', array(), PERDITA_CORE_VERSION );
		}
	}

	/**
	 * Append the related-posts block after the main content. Guarded to the
	 * main singular-post loop so it can't fire twice from a widget or another
	 * plugin that also calls the_content() (the classic double-append bug for
	 * anything hooked to this filter).
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	public function append( $content ) {
		if ( ! is_singular( 'post' ) || ! in_the_loop() || ! is_main_query() || post_password_required() ) {
			return $content;
		}
		$html = $this->render( get_the_ID() );
		return '' === $html ? $content : $content . $html;
	}

	/**
	 * Build the related-posts HTML for a given post, or '' if there's nothing
	 * to show (a brand-new site with only one post, for instance).
	 *
	 * @param int $post_id Current post id.
	 * @return string
	 */
	private function render( $post_id ) {
		$s     = self::settings();
		$posts = $this->find_related( $post_id, $s['count'], $s['match_by'] );
		if ( empty( $posts ) ) {
			return '';
		}

		ob_start();
		?>
		<aside class="perdita-related" aria-label="<?php echo esc_attr( $s['heading'] ); ?>">
			<h2 class="perdita-related__heading"><?php echo esc_html( $s['heading'] ); ?></h2>
			<div class="perdita-related__grid">
				<?php foreach ( $posts as $p ) : ?>
					<a class="perdita-related__card" href="<?php echo esc_url( get_permalink( $p ) ); ?>">
						<?php if ( has_post_thumbnail( $p ) ) : ?>
							<span class="perdita-related__thumb"><?php echo get_the_post_thumbnail( $p, 'medium', array( 'loading' => 'lazy', 'alt' => '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core template tag, self-escaping. ?></span>
						<?php endif; ?>
						<span class="perdita-related__title"><?php echo esc_html( get_the_title( $p ) ); ?></span>
						<span class="perdita-related__date"><?php echo esc_html( get_the_date( '', $p ) ); ?></span>
					</a>
				<?php endforeach; ?>
			</div>
		</aside>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * The post-meta key holding the cached ID list for the current settings.
	 *
	 * Keyed by a hash of the settings so a changed count or match mode reads a
	 * different key and rebuilds, instead of serving a list of the wrong shape.
	 *
	 * @return string
	 */
	private static function cache_key() {
		return self::META_PREFIX . substr( md5( (string) wp_json_encode( self::settings() ) ), 0, 8 );
	}

	/**
	 * Drop every cached related-post list for one post.
	 *
	 * Hooked to save_post, set_object_terms, and deleted_post, all of which
	 * hand the post/object id first. Clears every key with the prefix, not
	 * just the current settings hash, so old keys left behind by a settings
	 * change are cleaned up as posts are edited rather than accumulating.
	 *
	 * @param int $post_id Post id.
	 */
	public function purge_post_cache( $post_id ) {
		$post_id = (int) $post_id;
		if ( ! $post_id ) {
			return;
		}
		foreach ( array_keys( (array) get_post_meta( $post_id ) ) as $key ) {
			if ( 0 === strpos( (string) $key, self::META_PREFIX ) ) {
				delete_post_meta( $post_id, $key );
			}
		}
	}

	/**
	 * Find related posts by shared taxonomy terms, backfilled with the most
	 * recent other posts so the block is never thin or empty just because a
	 * post happens to have no categories or tags set.
	 *
	 * The result is memoized in post meta, because this ran two term lookups
	 * and up to two WP_Query passes on EVERY single-post view to produce a
	 * list that only changes when the post or its terms do. The meta read is
	 * free: WordPress primes the meta cache for the main query's posts before
	 * the template runs, so a hit costs no query at all.
	 *
	 * @param int    $post_id  Current post id.
	 * @param int    $count    How many to return.
	 * @param string $match_by category|tag|both.
	 * @return WP_Post[]
	 */
	private function find_related( $post_id, $count, $match_by ) {
		$cache_key = self::cache_key();
		$cached    = get_post_meta( $post_id, $cache_key, true );
		if ( is_array( $cached ) ) {
			return $this->hydrate( array_map( 'absint', $cached ) );
		}

		$ids = $this->query_related( $post_id, $count, $match_by );
		update_post_meta( $post_id, $cache_key, $ids );
		return $this->hydrate( $ids );
	}

	/**
	 * Turn a cached ID list back into the post objects the block renders.
	 *
	 * One primed read for the whole list, not one lookup per card. On a cache
	 * miss the posts were just loaded by query_related() and are already in the
	 * object cache, so this costs nothing; on a hit it is the only query the
	 * whole block runs.
	 *
	 * Posts that have since been deleted or unpublished are dropped rather than
	 * rendered as a card with an empty title and a dead link. The invalidation
	 * hooks fire for the post that CHANGED, not for every post whose cached
	 * list happens to mention it, so a stale entry is possible until the viewed
	 * post is next edited; the block simply shows fewer cards in the meantime.
	 *
	 * @param int[] $ids Post IDs.
	 * @return WP_Post[]
	 */
	private function hydrate( array $ids ) {
		if ( empty( $ids ) ) {
			return array();
		}
		// Term caches stay off for the same reason they are off in
		// query_related(): a card shows a title, a date, and a thumbnail.
		// Guarded because this is one of core's underscore-prefixed helpers;
		// without it get_post() below still works, just one query per card.
		if ( function_exists( '_prime_post_caches' ) ) {
			_prime_post_caches( $ids, false, true );
		}

		$posts = array();
		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( $post instanceof WP_Post && 'publish' === $post->post_status ) {
				$posts[] = $post;
			}
		}
		return $posts;
	}

	/**
	 * The uncached lookup behind find_related().
	 *
	 * @param int    $post_id  Current post id.
	 * @param int    $count    How many to return.
	 * @param string $match_by category|tag|both.
	 * @return int[] Post IDs, in display order.
	 */
	private function query_related( $post_id, $count, $match_by ) {
		$tax_query = array();
		if ( 'category' !== $match_by ) {
			$tags = wp_get_post_tags( $post_id, array( 'fields' => 'ids' ) );
			if ( $tags ) {
				$tax_query[] = array( 'taxonomy' => 'post_tag', 'field' => 'term_id', 'terms' => $tags );
			}
		}
		if ( 'tag' !== $match_by ) {
			$cats = wp_get_post_categories( $post_id );
			if ( $cats ) {
				$tax_query[] = array( 'taxonomy' => 'category', 'field' => 'term_id', 'terms' => $cats );
			}
		}

		$found = array();
		if ( $tax_query ) {
			if ( count( $tax_query ) > 1 ) {
				$tax_query['relation'] = 'OR';
			}
			$matched = get_posts(
				array(
					'post_type'      => 'post',
					'post_status'    => 'publish',
					'posts_per_page' => $count,
					'post__not_in'   => array( $post_id ),
					'tax_query'      => $tax_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- bounded by posts_per_page, runs once per cache rebuild.
					'orderby'        => 'date',
					'order'          => 'DESC',
					'no_found_rows'  => true,
					'ignore_sticky_posts' => true,
					// The cards show a title, date, and thumbnail. None of
					// that reads a term, so priming every matched post's
					// taxonomies was a query spent on nothing.
					'update_post_term_cache' => false,
				)
			);
			$found = array_map( 'absint', wp_list_pluck( $matched, 'ID' ) );
		}

		// Backfill with the most recent other posts if the taxonomy match came
		// up short, so a post with no categories/tags still shows something.
		$have = count( $found );
		if ( $have < $count ) {
			$exclude  = array_merge( array( (int) $post_id ), $found );
			$backfill = get_posts(
				array(
					'post_type'           => 'post',
					'post_status'         => 'publish',
					'posts_per_page'      => $count - $have,
					'post__not_in'        => $exclude,
					'orderby'             => 'date',
					'order'               => 'DESC',
					'no_found_rows'       => true,
					'ignore_sticky_posts' => true,
					'update_post_term_cache' => false,
				)
			);
			$found = array_merge( $found, array_map( 'absint', wp_list_pluck( $backfill, 'ID' ) ) );
		}

		return $found;
	}
}
