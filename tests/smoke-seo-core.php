<?php
/**
 * Perdita Core SEO layer.
 *
 * A focused fragment of the smoke suite covering the free SEO layer end to
 * end: the template variables, the per post type and per taxonomy templates,
 * per-term overrides, robots through core's wp_robots filter, the Open Graph
 * and JSON-LD extension filters Perdita Pro codes against, the author Person
 * node, webmaster verification, the robots.txt override, the feed footers,
 * and llms.txt.
 *
 * tests/smoke.php requires every tests/smoke-*.php right before it prints its
 * summary, in the same scope, so $ok() and the $pass/$fail counters below are
 * the ones defined there.
 *
 * Everything here restores what it touched: the SEO option, blog_public,
 * show_on_front, the query globals, REQUEST_URI, the user meta, both llms.txt
 * transients, and every post, term, and post type it creates.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

if ( Perdita_SEO::other_seo_active() ) {
	echo "SKIP  seo-core: another SEO plugin is active, so the whole layer is silenced by design\n";
	return;
}

$seoc_store = perdita_core()->seo;
if ( ! $seoc_store->get( 'enabled', true ) ) {
	echo "SKIP  seo-core: Perdita SEO is switched off on this site, so the layer prints nothing\n";
	return;
}

$seoc = Perdita_SEO::instance();
$ok( $seoc instanceof Perdita_SEO, 'seo: the SEO layer booted and exposes its instance' );
if ( ! $seoc instanceof Perdita_SEO ) {
	return;
}

$ok( $seoc->author instanceof Perdita_SEO_Author, 'seo: the author subsystem is built' );
$ok( $seoc->terms instanceof Perdita_SEO_Terms, 'seo: the term meta subsystem is built' );
$ok( $seoc->feeds instanceof Perdita_SEO_Feeds, 'seo: the feed subsystem is built' );
$ok( $seoc->llms instanceof Perdita_SEO_Llms, 'seo: the llms.txt subsystem is built' );
$ok( false === has_action( 'wp_head', 'rel_canonical' ), 'seo: core rel_canonical is unhooked, so a page carries exactly one canonical' );
$ok( 10 === has_filter( 'document_title_parts', array( $seoc, 'title_parts' ) ), 'seo: title templates run on document_title_parts at priority 10' );
$ok( false !== has_filter( 'wp_robots', array( $seoc, 'robots' ) ), 'seo: robots directives go through core wp_robots, not a hand-printed tag' );

/* ---------- capture what these tests change ---------- */

$seoc_orig_option       = get_option( 'perdita_seo' );
$seoc_orig_public       = get_option( 'blog_public' );
$seoc_orig_front        = get_option( 'show_on_front' );
$seoc_orig_query        = isset( $GLOBALS['wp_query'] ) ? $GLOBALS['wp_query'] : null;
$seoc_orig_the_query    = isset( $GLOBALS['wp_the_query'] ) ? $GLOBALS['wp_the_query'] : null;
$seoc_orig_post         = isset( $GLOBALS['post'] ) ? $GLOBALS['post'] : null;
$seoc_orig_req_uri      = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- captured to put back at the end, never read as input.
$_SERVER['REQUEST_URI'] = '/';

update_option( 'blog_public', 1 );

/* ---------- fixtures ---------- */

register_post_type(
	'perdita_probe',
	array(
		'public'      => true,
		'has_archive' => false,
		'supports'    => array( 'title', 'editor', 'excerpt', 'author' ),
		'taxonomies'  => array( 'category', 'post_tag' ),
		'rewrite'     => array( 'slug' => 'perdita-probe' ),
		'labels'      => array(
			'name'          => 'Perdita Probes',
			'singular_name' => 'Perdita Probe',
		),
	)
);

$seoc_author_id = (int) current( (array) get_users( array( 'number' => 1, 'fields' => 'ID' ) ) );
$ok( $seoc_author_id > 0, 'seo: a user exists to hang the author fixtures on' );

$seoc_meta_keys = array(
	Perdita_SEO_Author::META_JOB_TITLE,
	Perdita_SEO_Author::META_CREDENTIALS,
	Perdita_SEO_Author::META_KNOWS_ABOUT,
	Perdita_SEO_Author::META_SAME_AS,
	Perdita_SEO_Author::META_TWITTER,
	Perdita_SEO_Author::META_NOINDEX,
);
$seoc_orig_meta = array();
foreach ( $seoc_meta_keys as $seoc_meta_key ) {
	$seoc_orig_meta[ $seoc_meta_key ] = get_user_meta( $seoc_author_id, $seoc_meta_key, true );
}

$seoc_cat    = wp_insert_term( 'Perdita SEO Probe Cat', 'category', array( 'description' => 'Probe category description for the SEO smoke tests.' ) );
$seoc_cat_id = is_array( $seoc_cat ) ? (int) $seoc_cat['term_id'] : 0;
$seoc_tag    = wp_insert_term( 'Perdita SEO Probe Tag', 'post_tag' );
$seoc_tag_id = is_array( $seoc_tag ) ? (int) $seoc_tag['term_id'] : 0;
$ok( $seoc_cat_id > 0 && $seoc_tag_id > 0, 'seo: probe category and tag created' );

$seoc_post_id = wp_insert_post(
	array(
		'post_type'    => 'perdita_probe',
		'post_status'  => 'publish',
		'post_title'   => 'Perdita SEO Probe Post',
		'post_excerpt' => 'A short probe excerpt that the description path should pick up verbatim.',
		'post_content' => 'One two three four five six seven eight nine ten words of probe body copy.',
		'post_author'  => $seoc_author_id,
	)
);
$ok( $seoc_post_id > 0, 'seo: probe post created' );
wp_set_object_terms( $seoc_post_id, array( $seoc_cat_id ), 'category' );
wp_set_object_terms( $seoc_post_id, array( $seoc_tag_id ), 'post_tag' );

$seoc_post      = get_post( $seoc_post_id );
$seoc_permalink = (string) get_permalink( $seoc_post_id );
$seoc_home      = home_url( '/' );

/* ---------- helpers ---------- */

/**
 * Install a query as the main query for the assertions that follow.
 */
$seoc_use = function ( $query, $post = null ) {
	$GLOBALS['wp_query']     = $query;
	$GLOBALS['wp_the_query'] = $query;
	$GLOBALS['post']         = $post;
};

/**
 * The first node in a graph with a given @type.
 */
$seoc_node = function ( $graph, $type ) {
	foreach ( (array) $graph as $node ) {
		if ( is_array( $node ) && isset( $node['@type'] ) && $type === $node['@type'] ) {
			return $node;
		}
	}
	return null;
};

$seoc_singular_query = new WP_Query(
	array(
		'p'         => $seoc_post_id,
		'post_type' => 'perdita_probe',
	)
);
$seoc_use( $seoc_singular_query, $seoc_post );
$ok( is_singular() && get_queried_object_id() === $seoc_post_id, 'seo: the probe post is the queried object for the singular assertions' );

$seoc_ctx = $seoc->context();
$ok(
	'singular' === $seoc_ctx['type'] && $seoc_ctx['post'] instanceof WP_Post && $seoc_post_id === $seoc_ctx['post']->ID && 'perdita_probe' === $seoc_ctx['post_type'] && null === $seoc_ctx['term'],
	'seo: context() reports type, post, post_type, and a null term on a singular view'
);

/* ---------- 1. template variables ---------- */

$seoc_sep      = (string) $seoc_store->get( 'separator', '-' );
$seoc_sitename = (string) get_bloginfo( 'name' );
$seoc_r        = function ( $tpl ) use ( $seoc ) {
	return Perdita_SEO_Variables::resolve( $tpl, $seoc->context() );
};

$ok( get_the_title( $seoc_post ) === $seoc_r( '%title%' ), 'vars: %title% is the post title on a singular view' );
$ok( $seoc_sitename === $seoc_r( '%sitename%' ), 'vars: %sitename% is the site name' );
$ok( 'x ' . $seoc_sep . ' y' === $seoc_r( 'x %sep% y' ), 'vars: %sep% is the stored separator' );
$ok( (string) get_bloginfo( 'description' ) === $seoc_r( '%tagline%' ), 'vars: %tagline% is the site tagline' );
$ok( 0 === strpos( $seoc_r( '%excerpt%' ), 'A short probe excerpt' ), 'vars: %excerpt% is the manual excerpt, plain text' );
$ok( 'Perdita SEO Probe Cat' === $seoc_r( '%category%' ), 'vars: %category% is the primary term' );
$ok( 'Perdita SEO Probe Tag' === $seoc_r( '%tag%' ), 'vars: %tag% is the tag list' );
$ok( get_the_author_meta( 'display_name', $seoc_author_id ) === $seoc_r( '%author%' ), 'vars: %author% is the post author' );
$ok( wp_date( 'Y' ) === $seoc_r( '%currentyear%' ), 'vars: %currentyear% is this year' );
$ok( wp_date( 'F' ) === $seoc_r( '%currentmonth%' ), 'vars: %currentmonth% is this month' );
$ok( '' !== $seoc_r( '%currentdate%' ), 'vars: %currentdate% resolves to a date' );
$ok( 'Perdita Probe' === $seoc_r( '%post_type_singular%' ), 'vars: %post_type_singular% is the singular label' );
$ok( 'Perdita Probes' === $seoc_r( '%post_type_plural%' ), 'vars: %post_type_plural% is the plural label' );
$ok( '' === $seoc_r( '%page%' ), 'vars: %page% is empty on page one' );
$ok( 'Probe' === $seoc_r( 'Probe %sep% %page%' ), 'vars: an empty %page% takes the separator it would have doubled with it' );
$ok( '%not_a_token%' === $seoc_r( '%not_a_token%' ), 'vars: an unknown token is left exactly as typed' );

$seoc_var_filter = function ( $vars ) {
	$vars['probe_token'] = 'probe-token-value';
	return $vars;
};
add_filter( 'perdita_seo_variables', $seoc_var_filter );
$ok( 'probe-token-value' === $seoc_r( '%probe_token%' ), 'vars: perdita_seo_variables can add a token' );
remove_filter( 'perdita_seo_variables', $seoc_var_filter );

$seoc_paged_query = new WP_Query( array( 'post_type' => 'perdita_probe' ) );
$seoc_paged_query->set( 'paged', 2 );
$seoc_paged_query->is_paged = true;
$seoc_use( $seoc_paged_query );
$ok( 'Page 2' === Perdita_SEO_Variables::resolve( '%page%', $seoc->context() ), 'vars: %page% is "Page 2" past page one' );

/* ---------- 2. per post type and per taxonomy templates ---------- */

$seoc_store->save(
	array(
		'post_types' => array(
			'perdita_probe' => array(
				'title'       => 'PROBE %title%',
				'description' => 'PROBE DESC %excerpt%',
			),
		),
		'taxonomies' => array(
			'category' => array(
				'title'       => 'TAXTPL %term_title%',
				'description' => 'TAXDESC %term_description%',
			),
		),
	)
);

$seoc_use( $seoc_singular_query, $seoc_post );
$seoc_parts = apply_filters( 'document_title_parts', array( 'title' => get_the_title( $seoc_post ), 'site' => $seoc_sitename ) );
$ok( isset( $seoc_parts['title'] ) && 'PROBE Perdita SEO Probe Post' === $seoc_parts['title'], 'titles: the per post type template wins in document_title_parts' );
$ok( ! isset( $seoc_parts['site'] ), 'titles: the template owns the whole title, so core does not append the site name a second time' );

$seoc_cat_query = new WP_Query( array( 'cat' => $seoc_cat_id ) );
$seoc_use( $seoc_cat_query );
$ok( is_category() && get_queried_object() instanceof WP_Term, 'titles: the probe category is the queried object' );
$seoc_cat_ctx = $seoc->context();
$ok( $seoc_cat_ctx['term'] instanceof WP_Term && 'archive' === $seoc_cat_ctx['type'], 'seo: context() reports a term on a taxonomy archive' );
$ok( 'Perdita SEO Probe Cat' === Perdita_SEO_Variables::resolve( '%term_title%', $seoc_cat_ctx ), 'vars: %term_title% is the term name' );
$ok( 0 === strpos( Perdita_SEO_Variables::resolve( '%term_description%', $seoc_cat_ctx ), 'Probe category description' ), 'vars: %term_description% is the term description' );
$ok( 'Perdita SEO Probe Cat' === Perdita_SEO_Variables::resolve( '%archive_title%', $seoc_cat_ctx ), 'vars: %archive_title% is the plain archive name, no "Category:" prefix' );

$seoc_tax_parts = apply_filters( 'document_title_parts', array( 'title' => 'Perdita SEO Probe Cat' ) );
$ok( isset( $seoc_tax_parts['title'] ) && 'TAXTPL Perdita SEO Probe Cat' === $seoc_tax_parts['title'], 'titles: the taxonomy template applies on a term archive' );

update_term_meta( $seoc_cat_id, Perdita_SEO_Terms::META_TITLE, 'TERMOWN %term_title%' );
update_term_meta( $seoc_cat_id, Perdita_SEO_Terms::META_DESCRIPTION, 'TERMOWN DESC' );
$ok( 'TERMOWN %term_title%' === Perdita_SEO_Terms::title( $seoc_cat_id ), 'terms: the stored term title reads back' );
$seoc_term_parts = apply_filters( 'document_title_parts', array( 'title' => 'Perdita SEO Probe Cat' ) );
$ok( isset( $seoc_term_parts['title'] ) && 'TERMOWN Perdita SEO Probe Cat' === $seoc_term_parts['title'], 'terms: a term with its own SEO title beats the taxonomy template' );
$ok( in_array( 'category', Perdita_SEO_Terms::taxonomies(), true ) && ! in_array( 'post_format', Perdita_SEO_Terms::taxonomies(), true ), 'terms: every public taxonomy gets the fields, post formats do not' );
$seoc_registered_meta = get_registered_meta_keys( 'term', 'category' );
$ok(
	isset( $seoc_registered_meta[ Perdita_SEO_Terms::META_TITLE ] ) && ! empty( $seoc_registered_meta[ Perdita_SEO_Terms::META_TITLE ]['show_in_rest'] ) && ! empty( $seoc_registered_meta[ Perdita_SEO_Terms::META_TITLE ]['single'] ),
	'terms: _perdita_seo_title is registered term meta, single and REST visible'
);

$seoc_search_query = new WP_Query( array( 's' => 'probe widgets' ) );
$seoc_use( $seoc_search_query );
$ok( 'probe widgets' === Perdita_SEO_Variables::resolve( '%search_term%', $seoc->context() ), 'vars: %search_term% is the bare query on a search view' );
$ok( 'search' === $seoc->context()['type'], 'seo: context() reports a search view' );

/* ---------- 3. robots through core ---------- */

$seoc_use( $seoc_singular_query, $seoc_post );
$seoc_store->save( array( 'max_image_preview' => 'standard', 'max_snippet' => 40, 'max_video_preview' => 15 ) );
$seoc_robots = apply_filters( 'wp_robots', array() );
$ok( empty( $seoc_robots['noindex'] ), 'robots: a plain singular view is indexable' );
$ok( 'standard' === ( $seoc_robots['max-image-preview'] ?? '' ), 'robots: max-image-preview comes from the store, overriding core default' );
$ok( '40' === (string) ( $seoc_robots['max-snippet'] ?? '' ), 'robots: max-snippet comes from the store' );
$ok( '15' === (string) ( $seoc_robots['max-video-preview'] ?? '' ), 'robots: max-video-preview comes from the store' );

$seoc_store->save( array( 'post_types' => array( 'perdita_probe' => array( 'noindex' => true ) ) ) );
$seoc_robots_pt = apply_filters( 'wp_robots', array() );
$ok( ! empty( $seoc_robots_pt['noindex'] ) && ! empty( $seoc_robots_pt['follow'] ), 'robots: a per post type noindex hides the post but keeps follow' );
$ok( ! isset( $seoc_robots_pt['max-image-preview'] ), 'robots: preview limits are dropped on a noindexed view, where they mean nothing' );
$seoc_store->save( array( 'post_types' => array( 'perdita_probe' => array( 'noindex' => false ) ) ) );

$seoc_store->save( array( 'noindex_paginated' => false ) );
$seoc_use( $seoc_paged_query );
$ok( empty( apply_filters( 'wp_robots', array() )['noindex'] ), 'robots: page 2 is indexable while noindex_paginated is off' );
$seoc_store->save( array( 'noindex_paginated' => true ) );
$ok( ! empty( apply_filters( 'wp_robots', array() )['noindex'] ), 'robots: noindex_paginated hides page 2 and later' );
$seoc_store->save( array( 'noindex_paginated' => false ) );

$seoc_use( $seoc_cat_query );
$seoc_store->save( array( 'taxonomies' => array( 'category' => array( 'noindex' => true ) ) ) );
$ok( ! empty( apply_filters( 'wp_robots', array() )['noindex'] ), 'robots: a per taxonomy noindex hides that term archive' );
$seoc_store->save( array( 'taxonomies' => array( 'category' => array( 'noindex' => false ) ) ) );

$ok( $seoc_home === $seoc->attachment_target( null ), 'robots: an attachment with no usable parent redirects home' );
$ok( 'redirect' === $seoc_store->get( 'attachments', 'redirect' ), 'robots: attachment pages redirect by default rather than being served' );

/* ---------- 4. head tags and the extension filters ---------- */

$seoc_store->save(
	array(
		'schema_type'      => 'Organization',
		'default_image_id' => 0,
		'facebook_app_id'  => '1234567890',
		'verify_google'    => '<meta name="google-site-verification" content="probe-google-token" />',
		'verify_bing'      => 'probe-bing-token',
	)
);
update_user_meta( $seoc_author_id, Perdita_SEO_Author::META_JOB_TITLE, 'Probe Editor' );
update_user_meta( $seoc_author_id, Perdita_SEO_Author::META_CREDENTIALS, 'CFP' );
update_user_meta( $seoc_author_id, Perdita_SEO_Author::META_KNOWS_ABOUT, 'probe testing, schema' );
update_user_meta( $seoc_author_id, Perdita_SEO_Author::META_SAME_AS, "https://example.com/probe-author\nhttps://example.org/probe-author" );
update_user_meta( $seoc_author_id, Perdita_SEO_Author::META_TWITTER, '@probeauthor' );

$seoc_og_seen    = null;
$seoc_og_ctx     = null;
$seoc_graph_seen = null;
$seoc_graph_ctx  = null;

$seoc_og_filter   = function ( $tags, $ctx ) use ( &$seoc_og_seen, &$seoc_og_ctx ) {
	$seoc_og_seen          = $tags;
	$seoc_og_ctx           = $ctx;
	$tags['og:title']      = 'PROBE OG TITLE';
	$tags['perdita:probe'] = 'probe-og-value';
	return $tags;
};
$seoc_ld_filter   = function ( $graph, $ctx ) use ( &$seoc_graph_seen, &$seoc_graph_ctx ) {
	$seoc_graph_seen = $graph;
	$seoc_graph_ctx  = $ctx;
	$graph[]         = array(
		'@type' => 'Thing',
		'@id'   => 'perdita-probe-extra-node',
	);
	return $graph;
};
$seoc_desc_filter = function () {
	return 'PROBE DESCRIPTION OVERRIDE';
};

add_filter( 'perdita_seo_og_tags', $seoc_og_filter, 10, 2 );
add_filter( 'perdita_seo_json_ld', $seoc_ld_filter, 10, 2 );

$seoc_use( $seoc_singular_query, $seoc_post );
ob_start();
$seoc->head();
$seoc_head = (string) ob_get_clean();

$ok( is_array( $seoc_og_seen ) && is_array( $seoc_og_ctx ) && 'singular' === $seoc_og_ctx['type'], 'filters: perdita_seo_og_tags receives the tag array and the view context' );
$ok( 'article' === ( $seoc_og_seen['og:type'] ?? '' ), 'og: og:type is article on a singular view' );
$ok( ! empty( $seoc_og_seen['og:locale'] ) && ! empty( $seoc_og_seen['og:site_name'] ), 'og: og:locale and og:site_name are set' );
$ok( ! empty( $seoc_og_seen['article:published_time'] ) && ! empty( $seoc_og_seen['article:modified_time'] ), 'og: article published and modified times are set' );
$ok( get_author_posts_url( $seoc_author_id ) === ( $seoc_og_seen['article:author'] ?? '' ), 'og: article:author is the author archive URL' );
$ok( 'Perdita SEO Probe Cat' === ( $seoc_og_seen['article:section'] ?? '' ), 'og: article:section is the primary term' );
$ok( is_array( $seoc_og_seen['article:tag'] ?? null ) && in_array( 'Perdita SEO Probe Tag', $seoc_og_seen['article:tag'], true ), 'og: article:tag carries one value per tag' );
$ok( '1234567890' === ( $seoc_og_seen['fb:app_id'] ?? '' ), 'og: fb:app_id prints when the app id is set' );
$ok( ! empty( $seoc_og_seen['twitter:title'] ) && ! empty( $seoc_og_seen['twitter:description'] ), 'og: twitter title and description are set' );
$ok( '@probeauthor' === ( $seoc_og_seen['twitter:creator'] ?? '' ), 'og: twitter:creator comes from the author profile handle' );

$ok( false !== strpos( $seoc_head, '<meta property="og:title" content="PROBE OG TITLE"' ), 'filters: a value changed in perdita_seo_og_tags is what actually prints' );
$ok( false !== strpos( $seoc_head, '<meta name="perdita:probe" content="probe-og-value"' ), 'filters: a non og/article/fb key prints as a name attribute' );
$ok( false !== strpos( $seoc_head, '<meta property="fb:app_id"' ), 'og: fb: keys print as property attributes' );
$ok( false !== strpos( $seoc_head, '<link rel="canonical" href="' . esc_url( $seoc_permalink ) . '"' ), 'head: the canonical is the post permalink' );
$ok( 1 === substr_count( $seoc_head, 'rel="canonical"' ), 'head: exactly one canonical' );
$ok( 1 === substr_count( $seoc_head, 'application/ld+json' ), 'head: exactly one JSON-LD block' );
$ok( false === strpos( $seoc_head, 'google-site-verification' ), 'verification: nothing prints on a singular view' );

$ok( is_array( $seoc_graph_seen ) && is_array( $seoc_graph_ctx ) && 'singular' === $seoc_graph_ctx['type'], 'filters: perdita_seo_json_ld receives the node list and the view context' );
$ok( false !== strpos( $seoc_head, 'perdita-probe-extra-node' ), 'filters: a node added in perdita_seo_json_ld is in the printed graph' );

$seoc_webpage  = $seoc_node( $seoc_graph_seen, 'WebPage' );
$seoc_article  = $seoc_node( $seoc_graph_seen, 'Article' );
$seoc_website  = $seoc_node( $seoc_graph_seen, 'WebSite' );
$seoc_person   = $seoc_node( $seoc_graph_seen, 'Person' );
$seoc_identity = $seoc_node( $seoc_graph_seen, 'Organization' );

$ok( is_array( $seoc_webpage ) && $seoc_permalink . '#webpage' === $seoc_webpage['@id'], 'schema: the WebPage node is @id permalink#webpage' );
$ok( is_array( $seoc_article ) && $seoc_permalink . '#article' === $seoc_article['@id'], 'schema: a public CPT gets an Article node at permalink#article' );
$ok( is_array( $seoc_website ) && $seoc_home . '#website' === $seoc_website['@id'], 'schema: the WebSite node is @id home#website' );
$ok( is_array( $seoc_identity ) && $seoc_home . '#identity' === $seoc_identity['@id'], 'schema: the identity node is @id home#identity' );
$ok( is_array( $seoc_webpage ) && $seoc_home . '#website' === ( $seoc_webpage['isPartOf']['@id'] ?? '' ), 'schema: WebPage isPartOf points at the WebSite @id' );
$ok( is_array( $seoc_article ) && $seoc_permalink . '#webpage' === ( $seoc_article['mainEntityOfPage']['@id'] ?? '' ), 'schema: Article mainEntityOfPage points at the WebPage @id' );
$ok( is_array( $seoc_article ) && $seoc_permalink . '#webpage' === ( $seoc_article['isPartOf']['@id'] ?? '' ), 'schema: Article isPartOf points at the WebPage @id' );
$ok( is_array( $seoc_article ) && $seoc_home . '#identity' === ( $seoc_article['publisher']['@id'] ?? '' ), 'schema: Article publisher points at the identity @id' );
$ok( is_array( $seoc_article ) && isset( $seoc_article['wordCount'] ) && $seoc_article['wordCount'] > 0, 'schema: Article carries a wordCount' );
$ok( is_array( $seoc_article ) && 'Perdita SEO Probe Cat' === ( $seoc_article['articleSection'] ?? '' ), 'schema: Article carries articleSection' );
$ok( is_array( $seoc_article ) && false !== strpos( (string) ( $seoc_article['keywords'] ?? '' ), 'Perdita SEO Probe Tag' ), 'schema: Article carries keywords from the tags' );
$ok( is_array( $seoc_webpage ) && ! empty( $seoc_webpage['datePublished'] ) && ! empty( $seoc_webpage['dateModified'] ), 'schema: WebPage carries the published and modified dates on a singular view' );
$ok( is_array( $seoc_webpage ) && ! empty( $seoc_webpage['inLanguage'] ), 'schema: WebPage carries inLanguage' );

$ok( is_array( $seoc_person ) && Perdita_SEO_Author::schema_id( $seoc_author_id ) === $seoc_person['@id'], 'schema: the author Person node is @id author-archive#author' );
$ok( is_array( $seoc_article ) && is_array( $seoc_person ) && $seoc_person['@id'] === ( $seoc_article['author']['@id'] ?? '' ), 'schema: Article author references the Person node by @id' );
$ok( is_array( $seoc_person ) && 'Probe Editor' === ( $seoc_person['jobTitle'] ?? '' ), 'schema: the Person node carries jobTitle from the profile' );
$ok( is_array( $seoc_person ) && 'CFP' === ( $seoc_person['honorificSuffix'] ?? '' ), 'schema: the Person node carries honorificSuffix from the credentials field' );
$ok( is_array( $seoc_person ) && in_array( 'schema', (array) ( $seoc_person['knowsAbout'] ?? array() ), true ), 'schema: the Person node carries knowsAbout, split on commas' );
$ok( is_array( $seoc_person ) && in_array( 'https://example.com/probe-author', (array) ( $seoc_person['sameAs'] ?? array() ), true ), 'schema: the Person node carries sameAs from the profile URLs' );
$ok( is_array( $seoc_person ) && $seoc_home . '#identity' === ( $seoc_person['worksFor']['@id'] ?? '' ), 'schema: the Person node worksFor the site identity' );

add_filter( 'perdita_seo_description', $seoc_desc_filter );
ob_start();
$seoc->head();
$seoc_head_desc = (string) ob_get_clean();
remove_filter( 'perdita_seo_description', $seoc_desc_filter );
$ok( false !== strpos( $seoc_head_desc, '<meta name="description" content="PROBE DESCRIPTION OVERRIDE"' ), 'filters: perdita_seo_description is what prints' );

$seoc_type_filter = function () {
	return 'BlogPosting';
};
add_filter( 'perdita_seo_article_type', $seoc_type_filter );
$seoc_graph_seen = null;
ob_start();
$seoc->head();
ob_end_clean();
remove_filter( 'perdita_seo_article_type', $seoc_type_filter );
$ok( null !== $seoc_node( $seoc_graph_seen, 'BlogPosting' ), 'filters: perdita_seo_article_type changes the Article node type' );

$seoc_canonical_filter = function () {
	return 'https://example.com/probe-canonical/';
};
add_filter( 'perdita_seo_canonical', $seoc_canonical_filter );
ob_start();
$seoc->head();
$seoc_head_canon = (string) ob_get_clean();
remove_filter( 'perdita_seo_canonical', $seoc_canonical_filter );
$ok( false !== strpos( $seoc_head_canon, 'href="https://example.com/probe-canonical/"' ), 'filters: perdita_seo_canonical is what prints' );

/* ---------- 5. webmaster verification, front page only ---------- */

update_option( 'show_on_front', 'posts' );
$seoc_front_query                       = new WP_Query( array( 'post_type' => 'perdita_probe' ) );
$seoc_front_query->is_home              = true;
$seoc_front_query->is_archive           = false;
$seoc_front_query->is_singular          = false;
$seoc_front_query->is_post_type_archive = false;
$seoc_front_query->queried_object       = null;
$seoc_front_query->queried_object_id    = 0;
$seoc_use( $seoc_front_query );
$ok( is_front_page(), 'verification: the front page query is in place' );
ob_start();
$seoc->head();
$seoc_head_front = (string) ob_get_clean();
$ok( false !== strpos( $seoc_head_front, '<meta name="google-site-verification" content="probe-google-token"' ), 'verification: a pasted full meta tag is reduced to its token and printed on the front page' );
$ok( false !== strpos( $seoc_head_front, '<meta name="msvalidate.01" content="probe-bing-token"' ), 'verification: a bare token prints as msvalidate.01' );
$ok( 'front' === $seoc->context()['type'], 'seo: context() reports the front page' );

remove_filter( 'perdita_seo_og_tags', $seoc_og_filter, 10 );
remove_filter( 'perdita_seo_json_ld', $seoc_ld_filter, 10 );

/* ---------- 6. robots.txt override ---------- */

$seoc_core_robots = "User-agent: *\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\n\nSitemap: https://example.com/wp-sitemap.xml\n";
$ok( $seoc_core_robots === $seoc->robots_txt( $seoc_core_robots, true ), 'robots.txt: an empty override leaves core output alone' );

$seoc_store->save( array( 'robots_txt' => "User-agent: *\nDisallow: /probe-private/" ) );
$seoc_robots_txt = $seoc->robots_txt( $seoc_core_robots, true );
$ok( false !== strpos( $seoc_robots_txt, 'Disallow: /probe-private/' ), 'robots.txt: the stored text is served verbatim' );
$ok( false === strpos( $seoc_robots_txt, 'wp-admin' ), 'robots.txt: the override replaces core rules rather than appending to them' );
$ok( false !== strpos( $seoc_robots_txt, 'Sitemap: https://example.com/wp-sitemap.xml' ), 'robots.txt: the sitemap line is kept when the override does not name one' );

$seoc_store->save( array( 'robots_txt' => "User-agent: *\nSitemap: https://example.com/mine.xml" ) );
$seoc_robots_own = $seoc->robots_txt( $seoc_core_robots, true );
$ok( 1 === substr_count( strtolower( $seoc_robots_own ), 'sitemap:' ), 'robots.txt: an override that names its own sitemap does not get a second one' );
$ok( false !== strpos( Perdita_SEO::default_robots_txt(), 'wp-admin' ), 'robots.txt: default_robots_txt() shows core rules, with our own filter held off' );
$seoc_store->save( array( 'robots_txt' => '' ) );

/* ---------- 7. feed footers ---------- */

$seoc_store->save(
	array(
		'rss_before' => 'BEFORE %%BLOGTITLE%%',
		'rss_after'  => 'AFTER %%POSTLINK%%',
	)
);
$GLOBALS['post'] = $seoc_post;
$seoc_feed       = (string) apply_filters( 'the_content_feed', 'PROBE BODY' );
$ok( false !== strpos( $seoc_feed, 'BEFORE ' . esc_html( $seoc_sitename ) ), 'feeds: rss_before is prepended with its tokens filled' );
$ok( false !== strpos( $seoc_feed, 'PROBE BODY' ), 'feeds: the item content survives' );
$ok( false !== strpos( $seoc_feed, 'AFTER <a href="' . esc_url( $seoc_permalink ) . '">' ), 'feeds: rss_after is appended and %%POSTLINK%% becomes a link to the item' );
$ok( strpos( $seoc_feed, 'BEFORE' ) < strpos( $seoc_feed, 'PROBE BODY' ) && strpos( $seoc_feed, 'PROBE BODY' ) < strpos( $seoc_feed, 'AFTER' ), 'feeds: before comes first and after comes last' );
$seoc_excerpt_feed = (string) apply_filters( 'the_excerpt_rss', 'PROBE EXCERPT' );
$ok( false !== strpos( $seoc_excerpt_feed, 'AFTER' ), 'feeds: the excerpt feed gets the same treatment' );

$seoc_store->save( array( 'rss_disable_all' => true ) );
$ok( false === $seoc->feeds->show_posts_feed_links( true ), 'feeds: the posts feed link is dropped from the head when every feed is off' );
$seoc_store->save( array( 'rss_disable_all' => false, 'rss_disable_comments_feed' => true ) );
$ok( false === $seoc->feeds->show_comments_feed_links( true ), 'feeds: the comments feed link is dropped when that feed is off' );
$ok( true === $seoc->feeds->show_posts_feed_links( true ), 'feeds: turning off the comments feed leaves the posts feed alone' );
$seoc_store->save( array( 'rss_disable_comments_feed' => false, 'rss_before' => '', 'rss_after' => '' ) );

/* ---------- 8. llms.txt ---------- */

$seoc->llms->invalidate();
$ok( false === get_transient( Perdita_SEO_Llms::TRANSIENT ), 'llms: invalidate() drops the cache' );
$seoc_llms = $seoc->llms->content( false );
$ok( is_string( $seoc_llms ) && 0 === strpos( $seoc_llms, '# ' ), 'llms: the body opens with the site name as an H1' );
$ok( false !== strpos( $seoc_llms, '## Sitemaps' ), 'llms: the body ends with a Sitemaps section' );
$ok( false !== strpos( $seoc_llms, '## Perdita Probes' ), 'llms: every public post type gets its own section' );
$ok( false !== strpos( $seoc_llms, '- [Perdita SEO Probe Post](' ), 'llms: each item is a Markdown link to its permalink' );
$ok( false !== strpos( $seoc_llms, '): A short probe excerpt' ), 'llms: each item carries a short summary after the link' );
$ok( $seoc_llms === get_transient( Perdita_SEO_Llms::TRANSIENT ), 'llms: the body is cached in the transient it reads back from' );
$seoc->llms->invalidate();
$ok( false === get_transient( Perdita_SEO_Llms::TRANSIENT ), 'llms: save_post-style invalidation clears it again' );

/* ---------- 9. posts with no author ---------- */

// post_author 0 (an import or a direct database write can leave it) printed
// article:author as a bare /author/ URL and filled %%AUTHORLINK%% with an
// empty link to it. The same data made the theme's entry meta read
// "by Donations" on nonprofitmanager.app, 2026-09-22.
$seoc_orphan_id = wp_insert_post(
	array(
		'post_type'    => 'perdita_probe',
		'post_status'  => 'publish',
		'post_title'   => 'Perdita SEO Probe Orphan',
		'post_content' => 'Probe body copy for a post that has no author.',
		'post_author'  => 0,
	)
);
$ok( $seoc_orphan_id > 0 && 0 === (int) get_post_field( 'post_author', $seoc_orphan_id ), 'no author: a probe post with post_author 0 exists' );
$seoc_orphan      = get_post( $seoc_orphan_id );
$seoc_orphan_tags = null;
$seoc_orphan_ld   = null;
$seoc_orphan_og_f = function ( $tags ) use ( &$seoc_orphan_tags ) {
	$seoc_orphan_tags = $tags;
	return $tags;
};
$seoc_orphan_ld_f = function ( $graph ) use ( &$seoc_orphan_ld ) {
	$seoc_orphan_ld = $graph;
	return $graph;
};
add_filter( 'perdita_seo_og_tags', $seoc_orphan_og_f );
add_filter( 'perdita_seo_json_ld', $seoc_orphan_ld_f );
$seoc_use(
	new WP_Query(
		array(
			'p'         => $seoc_orphan_id,
			'post_type' => 'perdita_probe',
		)
	),
	$seoc_orphan
);
ob_start();
$seoc->head();
$seoc_orphan_head = (string) ob_get_clean();
remove_filter( 'perdita_seo_og_tags', $seoc_orphan_og_f );
remove_filter( 'perdita_seo_json_ld', $seoc_orphan_ld_f );

$seoc_orphan_article = $seoc_node( $seoc_orphan_ld, 'Article' );
$seoc_orphan_authors = array_filter(
	(array) $seoc_orphan_ld,
	function ( $node ) {
		return is_array( $node ) && '#author' === substr( (string) ( $node['@id'] ?? '' ), -7 );
	}
);
$ok( is_array( $seoc_orphan_tags ) && ! isset( $seoc_orphan_tags['article:author'] ) && false === strpos( $seoc_orphan_head, 'article:author' ), 'no author: no article:author tag, rather than a bare /author/ URL' );
$ok( is_array( $seoc_orphan_tags ) && ! empty( $seoc_orphan_tags['article:published_time'] ) && ! empty( $seoc_orphan_tags['article:modified_time'] ), 'no author: the other article tags still print' );
$ok( is_array( $seoc_orphan_article ) && ! isset( $seoc_orphan_article['author'] ) && ! $seoc_orphan_authors, 'no author: the Article node has no author and the graph has no author Person' );
$ok( '' === $seoc->feeds->fill( '%%AUTHORLINK%%', $seoc_orphan ), 'no author: %%AUTHORLINK%% in a feed footer stays empty instead of an empty link' );
$ok( '<a href="' . esc_url( get_author_posts_url( $seoc_author_id ) ) . '">' . esc_html( get_the_author_meta( 'display_name', $seoc_author_id ) ) . '</a>' === $seoc->feeds->fill( '%%AUTHORLINK%%', $seoc_post ), 'feeds: %%AUTHORLINK%% links a real author\'s name to their archive' );

// A user whose display name is blank is no better: a Person with no name is
// invalid schema, and the feed token would be an empty link again.
require_once ABSPATH . 'wp-admin/includes/user.php';
$seoc_blank_id = wp_insert_user(
	array(
		'user_login'   => 'perdita_seo_probe_blank_' . wp_generate_password( 6, false ),
		'user_pass'    => wp_generate_password( 24 ),
		'display_name' => 'Perdita SEO Probe Blank',
		'role'         => 'author',
	)
);
$seoc_blank_id = is_wp_error( $seoc_blank_id ) ? 0 : (int) $seoc_blank_id;
$ok( $seoc_blank_id > 0 && array() !== Perdita_SEO_Author::person_node( $seoc_blank_id ), 'no author: a probe user with a name gets a Person node' );
global $wpdb; // wp eval-file runs this file inside a function, so $wpdb is not in scope by default.
$wpdb->update( $wpdb->users, array( 'display_name' => ' ' ), array( 'ID' => $seoc_blank_id ) ); // phpcs:ignore WordPress.DB
clean_user_cache( $seoc_blank_id );
$seoc_blank_post              = clone $seoc_orphan;
$seoc_blank_post->post_author = (string) $seoc_blank_id;
$ok( array() === Perdita_SEO_Author::person_node( $seoc_blank_id ), 'no author: a user with a blank display name gets no Person node' );
$ok( '' === $seoc->feeds->fill( '%%AUTHORLINK%%', $seoc_blank_post ), 'no author: %%AUTHORLINK%% stays empty for a blank display name too' );
if ( $seoc_blank_id ) {
	wp_delete_user( $seoc_blank_id );
}
if ( $seoc_orphan_id ) {
	wp_delete_post( $seoc_orphan_id, true );
}

/* ---------- cleanup ---------- */

foreach ( $seoc_meta_keys as $seoc_meta_key ) {
	if ( '' === $seoc_orig_meta[ $seoc_meta_key ] ) {
		delete_user_meta( $seoc_author_id, $seoc_meta_key );
	} else {
		update_user_meta( $seoc_author_id, $seoc_meta_key, $seoc_orig_meta[ $seoc_meta_key ] );
	}
}

delete_term_meta( $seoc_cat_id, Perdita_SEO_Terms::META_TITLE );
delete_term_meta( $seoc_cat_id, Perdita_SEO_Terms::META_DESCRIPTION );

if ( $seoc_post_id ) {
	wp_delete_post( $seoc_post_id, true );
}
if ( $seoc_cat_id ) {
	wp_delete_term( $seoc_cat_id, 'category' );
}
if ( $seoc_tag_id ) {
	wp_delete_term( $seoc_tag_id, 'post_tag' );
}
unregister_post_type( 'perdita_probe' );

// Creating and deleting the probe post already dropped both llms.txt caches
// through save_post and deleted_post, so the only correct end state is a cold
// cache, not a restored one.
delete_transient( Perdita_SEO_Llms::TRANSIENT );
delete_transient( Perdita_SEO_Llms::TRANSIENT_FULL );

// reset() clears the store's in-memory document as well as the row, so the
// restored option is what any later read sees.
$seoc_store->reset();
if ( false !== $seoc_orig_option ) {
	update_option( 'perdita_seo', $seoc_orig_option );
}
if ( false !== $seoc_orig_public ) {
	update_option( 'blog_public', $seoc_orig_public );
}
if ( false !== $seoc_orig_front ) {
	update_option( 'show_on_front', $seoc_orig_front );
}

if ( null !== $seoc_orig_query ) {
	$GLOBALS['wp_query'] = $seoc_orig_query;
}
if ( null !== $seoc_orig_the_query ) {
	$GLOBALS['wp_the_query'] = $seoc_orig_the_query;
}
$GLOBALS['post'] = $seoc_orig_post;
if ( null === $seoc_orig_req_uri ) {
	unset( $_SERVER['REQUEST_URI'] );
} else {
	$_SERVER['REQUEST_URI'] = $seoc_orig_req_uri;
}
