<?php
/**
 * Perdita Core smoke tests: Switch to Perdita, the SEO, mail, analytics and
 * security sources.
 *
 * Loaded by tests/smoke.php. Every option, term and cron event this touches
 * is put back at the end, and by a shutdown function too if a test dies on
 * the way.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

echo "\n--- Switch to Perdita: SEO, mail, analytics and security sources (tests/smoke-switch-sources.php) ---\n";

require_once PERDITA_CORE_DIR . 'inc/switch/class-perdita-switch-source.php';
require_once PERDITA_CORE_DIR . 'inc/switch/class-perdita-switch-target.php';
require_once PERDITA_CORE_DIR . 'inc/switch/class-perdita-switch-php-config.php';
require_once PERDITA_CORE_DIR . 'inc/switch/class-perdita-switch-backups.php';
require_once PERDITA_CORE_DIR . 'inc/switch/class-perdita-switch-leftovers.php';
require_once PERDITA_CORE_DIR . 'inc/switch/class-perdita-switch.php';
if ( ! class_exists( 'Perdita_SEO_Terms' ) ) {
	require_once PERDITA_CORE_DIR . 'inc/class-perdita-seo-terms.php';
}
foreach ( array( 'smtp', 'analytics', 'security', 'caching' ) as $pdss_m ) {
	Perdita_Switch_Target::load( $pdss_m );
}

/* ---------- snapshot and restore ---------- */

$pdss_missing = '__pdss_missing__';
$pdss_names   = array(
	'perdita_core',
	'perdita_settings',
	'perdita_seo',
	'perdita_smtp_settings',
	'perdita_analytics_settings',
	'perdita_security_settings',
	'perdita_caching_settings',
	'perdita_switch_state',
	'perdita_switch_snapshots',
	'perdita_crypto_failures',
	'cron',
	'show_on_front',
	'rank-math-options-titles',
	'rank-math-options-general',
	'seopress_titles_option_name',
	'seopress_social_option_name',
	'seopress_advanced_option_name',
	'wpseo_titles',
	'wpseo_social',
	'wp_mail_smtp',
	'wp_mail_smtp_mail_key',
	'fluentmail-settings',
	'postman_options',
	'googlesitekit_analytics-4_settings',
	'monsterinsights_site_profile',
	'monsterinsights_settings',
	'gap_options',
	'limit_login_allowed_retries',
	'limit_login_lockout_duration',
	'limit_login_valid_duration',
	'limit_login_whitelist',
	'limit_login_whitelist_usernames',
	'limit_login_allowed_lockouts',
	'limit_login_active_app',
	'loginizer_options',
	'loginizer_whitelist',
	'loginizer_disable_brute',
);
$pdss_saved   = array();
foreach ( $pdss_names as $pdss_n ) {
	$pdss_saved[ $pdss_n ] = get_option( $pdss_n, $pdss_missing );
}
$pdss_terms                   = array();
$GLOBALS['pdss_cleanup_done'] = false;
$pdss_cleanup                 = static function () use ( $pdss_saved, $pdss_missing, &$pdss_terms ) {
	if ( $GLOBALS['pdss_cleanup_done'] ) {
		return;
	}
	$GLOBALS['pdss_cleanup_done'] = true;
	delete_transient( Perdita_Switch::JOB );
	foreach ( $pdss_saved as $name => $value ) {
		if ( $pdss_missing === $value ) {
			delete_option( $name );
		} else {
			update_option( $name, $value );
		}
	}
	foreach ( $pdss_terms as $term_id ) {
		wp_delete_term( $term_id, 'category' );
	}
	if ( function_exists( 'perdita_core' ) && isset( perdita_core()->seo ) ) {
		perdita_core()->seo->refresh();
	}
	Perdita_Switch::sources( true );
};
register_shutdown_function( $pdss_cleanup );

$pdss_rows = static function ( array $plan, $target = null, $status = null ) {
	$out = array();
	foreach ( $plan['rows'] as $row ) {
		if ( ( null === $target || $row['target'] === $target ) && ( null === $status || $row['status'] === $status ) ) {
			$out[] = $row;
		}
	}
	return $out;
};
$pdss_plan = static function ( $id ) {
	Perdita_Switch::sources( true );
	return Perdita_Switch::get( $id )->plan();
};

/* =============================================================
 * Regressions in code this feature touched.
 * ============================================================= */

// Yoast's "sc-mdash" separator is an em dash. The importer mapped it to a
// middle dot, so a site moving from Yoast got a different title separator.
update_option( 'wpseo_titles', array( 'separator' => 'sc-mdash' ) );
delete_option( 'wpseo_social' );
list( $pdss_y ) = perdita_core()->seo->yoast_patch();
$ok( isset( $pdss_y['separator'] ) && "\u{2014}" === $pdss_y['separator'], 'switch: Yoast sc-mdash imports as an em dash, not a middle dot' );

// SEOPress and Perdita SEO used to print two sets of tags: other_seo_active()
// didn't know SEOPress. Checked in a child PHP process, since a constant
// can't be undefined here.
$pdss_php = defined( 'PHP_BINARY' ) && is_executable( PHP_BINARY ) && 'cli' === PHP_SAPI ? PHP_BINARY : '';
if ( $pdss_php && function_exists( 'shell_exec' ) ) {
	$pdss_child = static function ( $define ) use ( $pdss_php ) {
		$tmp = tempnam( get_temp_dir(), 'pdss' );
		$src = array(
			'<?php',
			$define ? "define( 'SEOPRESS_VERSION', '9.0' );" : '',
			'$_SERVER["HTTP_HOST"] = "localhost";',
			'require ' . var_export( ABSPATH . 'wp-load.php', true ) . ';',
			"if ( ! class_exists( 'Perdita_SEO' ) ) { require_once PERDITA_CORE_DIR . 'inc/class-perdita-seo.php'; }",
			"echo Perdita_SEO::other_seo_active() ? 'YES' : 'NO';",
		);
		file_put_contents( $tmp, implode( "\n", $src ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$out = (string) shell_exec( escapeshellarg( $pdss_php ) . ' ' . escapeshellarg( $tmp ) . ' 2>/dev/null' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.shell_exec_shell_exec
		unlink( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		return substr( trim( $out ), -3 );
	};
	$ok( 'YES' === $pdss_child( true ), 'switch: Perdita SEO stands down while SEOPress is active (SEOPRESS_VERSION)' );
	$ok( 'NO' === $pdss_child( false ), 'switch: and only because of SEOPress in that check' );
} else {
	echo "SKIP  SEOPress other_seo_active (no CLI PHP)\n";
}

// The SMTP screen's save now runs through Perdita_SMTP::sanitize(). Its rules
// are the ones handle_save() had inline.
$pdss_cur = array( 'password' => 'p1:blob' );
$pdss_s   = Perdita_SMTP::sanitize(
	array(
		'encryption' => 'starttls',
		'port'       => '70000',
		'from_email' => 'not an email',
		'host'       => ' <b>smtp.x</b> ',
		'auth'       => '1',
	),
	$pdss_cur
);
$ok( '' === $pdss_s['encryption'] && 587 === $pdss_s['port'] && '' === $pdss_s['from_email'] && 'smtp.x' === $pdss_s['host'] && true === $pdss_s['auth'], 'switch: SMTP sanitize() refuses a bad encryption, port and address, and strips tags' );
$ok( 'p1:blob' === $pdss_s['password'], 'switch: SMTP sanitize() keeps the stored password when none is typed' );
$pdss_s = Perdita_SMTP::sanitize( array( 'remove_password' => '1', 'password_plain' => 'x' ), $pdss_cur );
$ok( '' === $pdss_s['password'], 'switch: SMTP sanitize() lets "remove" win over a typed password' );
$pdss_s = Perdita_SMTP::sanitize( array( 'password_plain' => 'n3w-Secret' ), $pdss_cur );
$ok( 'n3w-Secret' !== $pdss_s['password'] && 'n3w-Secret' === Perdita_Crypto::decrypt( $pdss_s['password'] ), 'switch: SMTP sanitize() encrypts a typed password' );

// The security screen's save now runs through sanitize_login().
$pdss_l = Perdita_Security::sanitize_login(
	array(
		'login_limit'   => '1',
		'login_max'     => '500',
		'login_window'  => '0',
		'login_lockout' => '99999',
		'ip_allowlist'  => "1.2.3.4\n10.0.0.0/8\n::1\n1.2.3.4\nnope",
	)
);
$ok( true === $pdss_l['login_limit'] && 100 === $pdss_l['login_max'] && 1 === $pdss_l['login_window'] && 1440 === $pdss_l['login_lockout'], 'switch: sanitize_login() clamps the attempt count and both durations' );
$ok( array( '1.2.3.4', '::1' ) === $pdss_l['ip_allowlist'], 'switch: sanitize_login() keeps exact, unique IPs only' );

/* =============================================================
 * Rank Math.
 * ============================================================= */

add_filter( 'pre_option_show_on_front', $pdss_posts = static function () {
	return 'posts';
} );
update_option(
	'rank-math-options-titles',
	array(
		'title_separator'          => '&#8211;',
		'homepage_title'           => '%sitename% %page% %sep% %sitedesc%',
		'homepage_description'     => 'Plain words about the site.',
		'pt_post_title'            => '%title% %sep% %sitename%',
		'pt_post_description'      => '%excerpt%',
		'pt_post_custom_robots'    => 'on',
		'pt_post_robots'           => array( 'noindex' ),
		'pt_page_title'            => '%title% %customfield(price)%',
		'pt_page_custom_robots'    => 'off',
		'pt_page_robots'           => array( 'noindex' ),
		'tax_category_title'       => '%term% Archives %sep% %sitename%',
		'author_custom_robots'     => 'on',
		'author_robots'            => array( 'noindex', 'nofollow' ),
		'date_archive_robots'      => array( 'noindex' ),
		'disable_date_archives'    => 'on',
		'noindex_search'           => 'on',
		'noindex_archive_subpages' => 'off',
		'advanced_robots_global'   => array(
			'max-snippet'       => '-1',
			'max-video-preview' => '-1',
			'max-image-preview' => 'large',
		),
		'knowledgegraph_type'      => 'company',
		'knowledgegraph_name'      => 'Acme <b>Co</b>',
		'knowledgegraph_logo_id'   => 987654321,
		'twitter_card_type'        => 'summary_card',
		'facebook_app_id'          => '12345',
		'social_url_facebook'      => 'https://facebook.com/acme',
		'twitter_author_names'     => 'acme',
		'social_additional_profiles' => "https://www.instagram.com/acme\nhttps://mastodon.social/@acme",
		'search_title'             => 'Search: %search_query% %sep% %sitename%',
		'404_title'                => 'Not found %sep% %sitename%',
		'oversize'                 => str_repeat( 'x', 70000 ),
		'obj'                      => new ArrayObject( array( 1 ) ),
	)
);
update_option(
	'rank-math-options-general',
	array(
		'google_verify'            => 'gv-123',
		'attachment_redirect_urls' => 'on',
		'rss_before_content'       => 'From %BLOGLINK%',
		'rss_after_content'        => 'See %FEATUREDIMAGE%',
		'robots_txt_content'       => "User-agent: *\nDisallow: /private/",
	)
);
$pdss_p = $pdss_plan( 'rank-math' );
$pdss_patch = $pdss_p['patch'];
$ok( "\u{2013}" === $pdss_patch['separator'], 'rank math: the entity-encoded separator imports as the character' );
$ok( '%sitename% %page% %sep% %tagline%' === $pdss_patch['home_title'], 'rank math: %sitedesc% becomes %tagline% in the home title' );
$ok( '%title% %sep% %sitename%' === $pdss_patch['post_types']['post']['title'] && true === $pdss_patch['post_types']['post']['noindex'], 'rank math: a post type title and its custom noindex import' );
$ok( ! isset( $pdss_patch['post_types']['page']['title'] ) && ! isset( $pdss_patch['post_types']['page']['noindex'] ), 'rank math: a template with an unknown variable and robots without the custom switch stay out' );
$ok( (bool) $pdss_rows( $pdss_p, 'Pages: title', 'skip' ), 'rank math: and the unknown variable shows as refused with a reason' );
$pdss_names = wp_list_pluck( $pdss_p['rows'], 'setting' );
$ok( in_array( 'Posts: Single title', $pdss_names, true ) && in_array( 'Google verification', $pdss_names, true ) && ! preg_grep( '/^(pt|tax)_|_verify$/', $pdss_names ), 'rank math: rows carry names a person reads, never option keys' );
$ok( (bool) $pdss_rows( $pdss_p, 'Noindex: author archives', 'import' ), 'rank math: noindex targets read as plain words' );
$ok( '%term_title% Archives %sep% %sitename%' === $pdss_patch['taxonomies']['category']['title'], 'rank math: %term% becomes %term_title%' );
$ok( true === $pdss_patch['noindex']['archive_author'] && true === $pdss_patch['noindex']['archive_date'] && true === $pdss_patch['noindex']['search'], 'rank math: author, date and search noindex import' );
$ok( false === $pdss_patch['noindex_paginated'] && -1 === $pdss_patch['max_snippet'] && 'large' === $pdss_patch['max_image_preview'], 'rank math: paginated noindex and the robots limits import' );
$ok( 'Organization' === $pdss_patch['schema_type'] && ! isset( $pdss_patch['org_name'] ), 'rank math: company maps to Organization, and a name the SEO check would change stays out' );
$ok( ! isset( $pdss_patch['org_logo_id'] ) && (bool) $pdss_rows( $pdss_p, 'Logo', 'skip' ), 'rank math: a logo no longer in the media library is refused' );
$ok( 'summary' === $pdss_patch['twitter_card'] && '12345' === $pdss_patch['facebook_app_id'], 'rank math: the card type and app ID import' );
$ok( 'https://www.instagram.com/acme' === $pdss_patch['social']['instagram'] && 'acme' === $pdss_patch['social']['twitter'], 'rank math: Instagram comes from the additional profiles' );
$ok( (bool) $pdss_rows( $pdss_p, '', 'none' ), 'rank math: a Mastodon profile shows as having no match' );
$ok( 'gv-123' === $pdss_patch['verify_google'] && 'redirect' === $pdss_patch['attachments'], 'rank math: verification and the attachment redirect import' );
$ok( 'From %%BLOGLINK%%' === $pdss_patch['rss_before'] && ! isset( $pdss_patch['rss_after'] ), 'rank math: feed tokens translate, and an unknown one keeps the field out' );
$ok( "User-agent: *\nDisallow: /private/" === $pdss_patch['robots_txt'], 'rank math: robots.txt imports' );
$pdss_json = wp_json_encode( $pdss_p );
$ok( false === strpos( $pdss_json, str_repeat( 'x', 1000 ) ) && false === strpos( $pdss_json, 'ArrayObject' ), 'rank math: an oversize value and a stored object never reach the preview' );

// Terms: one with Rank Math values, one where Perdita already has its own.
$pdss_t1 = wp_insert_term( 'PDSS Rank Term One', 'category' );
$pdss_t2 = wp_insert_term( 'PDSS Rank Term Two', 'category' );
$pdss_t1 = is_array( $pdss_t1 ) ? (int) $pdss_t1['term_id'] : 0;
$pdss_t2 = is_array( $pdss_t2 ) ? (int) $pdss_t2['term_id'] : 0;
$pdss_terms[] = $pdss_t1;
$pdss_terms[] = $pdss_t2;
update_term_meta( $pdss_t1, 'rank_math_title', '%term% guide %sep% %sitename%' );
update_term_meta( $pdss_t1, 'rank_math_description', 'All about it.' );
update_term_meta( $pdss_t2, 'rank_math_title', 'Rank title two' );
update_term_meta( $pdss_t2, Perdita_SEO_Terms::META_TITLE, 'Perdita already set this' );

$pdss_seo_before = get_option( 'perdita_seo', $pdss_missing );
$pdss_res        = Perdita_Switch::import( 'rank-math', array( 'batch_seconds' => 5 ) );
perdita_core()->seo->refresh();
$ok( $pdss_res['ok'] && ! $pdss_res['batch'], 'rank math: import runs, and the term batch finishes in the request' );
$ok( "\u{2013}" === perdita_core()->seo->get( 'separator' ) && '%sitename% %page% %sep% %tagline%' === perdita_core()->seo->get( 'home_title' ), 'rank math: the import lands in perdita_seo' );
$ok( '%term_title% guide %sep% %sitename%' === get_term_meta( $pdss_t1, Perdita_SEO_Terms::META_TITLE, true ) && 'All about it.' === get_term_meta( $pdss_t1, Perdita_SEO_Terms::META_DESCRIPTION, true ), 'rank math: a term\'s title and description import, translated' );
$ok( 'Perdita already set this' === get_term_meta( $pdss_t2, Perdita_SEO_Terms::META_TITLE, true ), 'rank math: a term title Perdita already had stays' );
$ok( 'All about it.' === get_term_meta( $pdss_t1, 'rank_math_description', true ) && 'Rank title two' === get_term_meta( $pdss_t2, 'rank_math_title', true ), 'rank math: Rank Math\'s own term meta is untouched' );
$pdss_state = Perdita_Switch::state( 'rank-math' );
$ok( 1 === $pdss_state['counts']['terms']['written'] && 1 === $pdss_state['counts']['terms']['kept'], 'rank math: the state counts written and kept terms' );

// Someone edits one imported term field after the import: Undo keeps it.
update_term_meta( $pdss_t1, Perdita_SEO_Terms::META_DESCRIPTION, 'Edited by hand' );
$pdss_res = Perdita_Switch::undo( 'rank-math', 5 );
perdita_core()->seo->refresh();
$ok( $pdss_res['ok'] && get_option( 'perdita_seo', $pdss_missing ) === $pdss_seo_before, 'rank math: undo puts perdita_seo back exactly' );
$ok( '' === get_term_meta( $pdss_t1, Perdita_SEO_Terms::META_TITLE, true ) && 'Edited by hand' === get_term_meta( $pdss_t1, Perdita_SEO_Terms::META_DESCRIPTION, true ), 'rank math: undo removes the imported term title and keeps a field edited since' );
$ok( '' === get_term_meta( $pdss_t1, Perdita_Switch::TERM_FROM, true ) && 'Perdita already set this' === get_term_meta( $pdss_t2, Perdita_SEO_Terms::META_TITLE, true ), 'rank math: undo drops its marks and never touches what it didn\'t write' );
$ok( null === Perdita_Switch::snapshot( 'rank-math' ) && ! Perdita_Switch::job(), 'rank math: undo leaves no snapshot and no running batch' );
remove_filter( 'pre_option_show_on_front', $pdss_posts );

// A batch longer than one step resumes on cron.
for ( $pdss_i = 0; $pdss_i < Perdita_Switch::BATCH_SIZE + 5; $pdss_i++ ) {
	$pdss_tt = wp_insert_term( 'PDSS Batch ' . $pdss_i, 'category' );
	if ( is_array( $pdss_tt ) ) {
		$pdss_terms[] = (int) $pdss_tt['term_id'];
		update_term_meta( (int) $pdss_tt['term_id'], 'rank_math_title', 'Batch ' . $pdss_i );
	}
}
$pdss_res = Perdita_Switch::import( 'rank-math', array( 'batch_seconds' => 0 ) );
$pdss_job = Perdita_Switch::job();
$ok( $pdss_res['batch'] && $pdss_job && Perdita_Switch::BATCH_SIZE === $pdss_job['done'] && wp_next_scheduled( Perdita_Switch::CRON ), 'batch: one step of 100, the rest booked on cron' );
$ok( Perdita_Switch::run_batch( 5 ) && ! Perdita_Switch::job() && ! wp_next_scheduled( Perdita_Switch::CRON ), 'batch: the next run finishes and clears its cron event' );
$pdss_res = Perdita_Switch::undo( 'rank-math', 5 );
$ok( $pdss_res['ok'] && ! Perdita_Switch::job(), 'batch: undo takes the batch back out' );

/* =============================================================
 * SEOPress.
 * ============================================================= */

update_option(
	'seopress_titles_option_name',
	array(
		'seopress_titles_sep'                     => '|',
		'seopress_titles_home_site_title'         => '%%sitetitle%% %%sep%% %%tagline%%',
		'seopress_titles_home_site_desc'          => 'SEOPress home',
		'seopress_titles_single_titles'           => array(
			'post' => array( 'title' => '%%post_title%% %%sep%% %%sitetitle%%', 'description' => '%%post_excerpt%%' ),
			'page' => array( 'title' => '%%post_title%%', 'noindex' => '1' ),
			'nope' => array( 'title' => 'unregistered post type' ),
		),
		'seopress_titles_tax_titles'              => array(
			'category' => array( 'title' => '%%_category_title%% %%sep%% %%sitetitle%%', 'description' => '%%wc_whatever%%' ),
		),
		'seopress_titles_archives_author_noindex' => '1',
		'seopress_titles_archives_404_title'      => 'Gone %%sep%% %%sitetitle%%',
		'seopress_titles_paged_noindex'           => '1',
	)
);
update_option(
	'seopress_social_option_name',
	array(
		'seopress_social_knowledge_type'        => 'Organization',
		'seopress_social_knowledge_name'        => 'Press Co',
		'seopress_social_knowledge_img'         => 'https://elsewhere.example/logo.png',
		'seopress_social_twitter_card_img_size' => 'large',
		'seopress_social_accounts_facebook'     => 'https://facebook.com/pressco',
	)
);
update_option(
	'seopress_advanced_option_name',
	array(
		'seopress_advanced_advanced_google'      => 'sp-google',
		'seopress_advanced_advanced_attachments' => '1',
	)
);
$pdss_p     = $pdss_plan( 'seopress' );
$pdss_patch = $pdss_p['patch'];
$ok( '|' === $pdss_patch['separator'] && '%sitename% %sep% %tagline%' === $pdss_patch['home_title'], 'seopress: separator and home title, with %%x%% variables translated' );
$ok( '%title% %sep% %sitename%' === $pdss_patch['post_types']['post']['title'] && '%excerpt%' === $pdss_patch['post_types']['post']['description'] && true === $pdss_patch['post_types']['page']['noindex'], 'seopress: post type templates and noindex' );
$ok( ! isset( $pdss_patch['post_types']['nope'] ), 'seopress: a post type that isn\'t registered stays out' );
$ok( '%term_title% %sep% %sitename%' === $pdss_patch['taxonomies']['category']['title'] && ! isset( $pdss_patch['taxonomies']['category']['description'] ), 'seopress: a term template imports and an unknown variable keeps its field out' );
$ok( true === $pdss_patch['noindex']['archive_author'] && false === $pdss_patch['noindex']['archive_date'] && true === $pdss_patch['noindex_paginated'], 'seopress: archive and paginated noindex' );
$ok( 'Organization' === $pdss_patch['schema_type'] && 'Press Co' === $pdss_patch['org_name'] && ! isset( $pdss_patch['org_logo_id'] ), 'seopress: knowledge graph, with a logo from outside the media library refused' );
$ok( 'summary_large_image' === $pdss_patch['twitter_card'] && 'redirect' === $pdss_patch['attachments'] && 'sp-google' === $pdss_patch['verify_google'], 'seopress: card size, attachment redirect and verification' );
$ok( (bool) array_filter( $pdss_p['notes'], function ( $n ) {
	return false !== strpos( $n, 'SEOPress' );
} ), 'seopress: the card says per-post fields stay behind' );

/* =============================================================
 * WP Mail SMTP, FluentSMTP and Post SMTP: secrets.
 * ============================================================= */

$pdss_secret = 'Sup3r-Secret-Passw0rd-Q7x!';
$pdss_key    = random_bytes( SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
$pdss_nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
update_option( 'wp_mail_smtp_mail_key', base64_encode( $pdss_key ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
update_option(
	'wp_mail_smtp',
	array(
		'mail' => array(
			'mailer'     => 'smtp',
			'from_email' => 'site@example.com',
			'from_name'  => 'Example Site',
		),
		'smtp' => array(
			'host'       => 'smtp.example.com',
			'port'       => 465,
			'encryption' => 'ssl',
			'autotls'    => true,
			'auth'       => true,
			'user'       => 'mailer@example.com',
			'pass'       => base64_encode( $pdss_nonce . sodium_crypto_secretbox( $pdss_secret, $pdss_nonce, $pdss_key ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		),
	)
);
delete_option( 'perdita_smtp_settings' );
$pdss_p = $pdss_plan( 'wp-mail-smtp' );
$ok( $pdss_secret === $pdss_p['patch']['password_plain'] && 'smtp.example.com' === $pdss_p['patch']['host'] && 465 === $pdss_p['patch']['port'] && 'ssl' === $pdss_p['patch']['encryption'], 'wp mail smtp: the sodium-encrypted password decrypts, with host, port and encryption' );
$pdss_pw = $pdss_rows( $pdss_p, 'Password' );
$ok( $pdss_pw && '••••Q7x!' === $pdss_pw[0]['found'] && '••••Q7x!' === $pdss_pw[0]['value'], 'wp mail smtp: the preview shows the last 4 characters of the password only' );
$ok( false === strpos( wp_json_encode( Perdita_Switch::get( 'wp-mail-smtp' )->scan() ), 'Sup3r' ), 'wp mail smtp: the password appears nowhere in the preview' );
$pdss_log_size = is_file( WP_CONTENT_DIR . '/debug.log' ) ? filesize( WP_CONTENT_DIR . '/debug.log' ) : 0;
$pdss_res      = Perdita_Switch::import( 'wp-mail-smtp' );
$pdss_stored   = get_option( 'perdita_smtp_settings' );
$ok( $pdss_res['ok'] && is_array( $pdss_stored ) && $pdss_secret !== $pdss_stored['password'] && $pdss_secret === Perdita_Crypto::decrypt( $pdss_stored['password'] ), 'wp mail smtp: the password is stored encrypted, the way the Email screen stores it' );
$ok( false === strpos( (string) wp_json_encode( $pdss_stored ), 'Sup3r' ) && false === strpos( (string) wp_json_encode( get_option( Perdita_Switch::SNAPSHOTS ) ), 'Sup3r' ) && false === strpos( (string) wp_json_encode( get_option( Perdita_Switch::STATE ) ), 'Sup3r' ), 'wp mail smtp: no plain password in Perdita\'s options' );
clearstatcache();
$pdss_log_new = is_file( WP_CONTENT_DIR . '/debug.log' ) ? (string) file_get_contents( WP_CONTENT_DIR . '/debug.log', false, null, $pdss_log_size ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$ok( false === strpos( $pdss_log_new, 'Sup3r' ), 'wp mail smtp: the password never reaches debug.log' );
$ok( 'mailer@example.com' === $pdss_stored['username'] && 'site@example.com' === $pdss_stored['from_email'] && true === $pdss_stored['auth'], 'wp mail smtp: username, From and auth import' );
$pdss_res = Perdita_Switch::undo( 'wp-mail-smtp' );
$ok( $pdss_res['ok'] && false === get_option( 'perdita_smtp_settings', false ), 'wp mail smtp: undo deletes the option the import created' );

update_option( 'wp_mail_smtp_mail_key', base64_encode( random_bytes( SODIUM_CRYPTO_SECRETBOX_KEYBYTES ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
$pdss_p = $pdss_plan( 'wp-mail-smtp' );
$ok( ! isset( $pdss_p['patch']['password_plain'] ) && (bool) $pdss_rows( $pdss_p, 'Password', 'skip' ), 'wp mail smtp: a password that won\'t decrypt is refused, never imported as ciphertext' );

$pdss_wm             = get_option( 'wp_mail_smtp' );
$pdss_wm['smtp']['pass'] = str_repeat( 'a', 2000 );
update_option( 'wp_mail_smtp', $pdss_wm );
$pdss_p = $pdss_plan( 'wp-mail-smtp' );
$ok( ! isset( $pdss_p['patch']['password_plain'] ) && (bool) $pdss_rows( $pdss_p, 'Password', 'skip' ), 'wp mail smtp: a 2,000 character password is refused' );

update_option(
	'wp_mail_smtp',
	array(
		'mail'     => array( 'mailer' => 'sendgrid', 'from_email' => 'site@example.com' ),
		'sendgrid' => array( 'api_key' => 'SG.abcdefghijklmnop.WXYZ' ),
	)
);
$pdss_p = $pdss_plan( 'wp-mail-smtp' );
$ok( 'smtp.sendgrid.net' === $pdss_p['patch']['host'] && 'apikey' === $pdss_p['patch']['username'] && 'SG.abcdefghijklmnop.WXYZ' === $pdss_p['patch']['password_plain'], 'wp mail smtp: SendGrid becomes SMTP with the API key' );
update_option( 'wp_mail_smtp', array( 'mail' => array( 'mailer' => 'gmail' ) ) );
$pdss_p = $pdss_plan( 'wp-mail-smtp' );
$ok( ! isset( $pdss_p['patch']['host'] ) && (bool) $pdss_rows( $pdss_p, '', 'none' ), 'wp mail smtp: an API mailer with no SMTP twin shows as no match' );

// FluentSMTP, encrypted with LOGGED_IN_KEY and LOGGED_IN_SALT.
$pdss_fkey  = defined( 'FLUENTMAIL_ENCRYPT_KEY' ) ? FLUENTMAIL_ENCRYPT_KEY : LOGGED_IN_KEY;
$pdss_fsalt = defined( 'FLUENTMAIL_ENCRYPT_SALT' ) ? FLUENTMAIL_ENCRYPT_SALT : LOGGED_IN_SALT;
$pdss_iv    = random_bytes( openssl_cipher_iv_length( 'aes-256-ctr' ) );
$pdss_fenc  = base64_encode( $pdss_iv . openssl_encrypt( 'Flu3nt-Pass-ABCD' . $pdss_fsalt, 'aes-256-ctr', $pdss_fkey, 0, $pdss_iv ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
update_option(
	'fluentmail-settings',
	array(
		'connections' => array(
			'first'  => array( 'provider_settings' => array( 'provider' => 'smtp', 'host' => 'wrong.example.com' ) ),
			'second' => array(
				'provider_settings' => array(
					'provider'     => 'smtp',
					'sender_email' => 'fluent@example.com',
					'sender_name'  => 'Fluent',
					'host'         => 'smtp.fluent.example',
					'port'         => '587',
					'auth'         => 'yes',
					'username'     => 'fuser',
					'password'     => $pdss_fenc,
					'encryption'   => 'tls',
					'key_store'    => 'db',
				),
			),
		),
		'misc'        => array( 'default_connection' => 'second', 'use_encrypt' => 'yes' ),
	)
);
$pdss_p = $pdss_plan( 'fluent-smtp' );
$ok( 'Flu3nt-Pass-ABCD' === $pdss_p['patch']['password_plain'] && 'smtp.fluent.example' === $pdss_p['patch']['host'] && 'tls' === $pdss_p['patch']['encryption'], 'fluentsmtp: the default connection imports with its AES-encrypted password' );
$ok( '••••ABCD' === $pdss_rows( $pdss_p, 'Password' )[0]['found'] && (bool) $pdss_p['notes'], 'fluentsmtp: masked, and the card mentions the other connection' );
$pdss_f = get_option( 'fluentmail-settings' );
$pdss_f['connections']['second']['provider_settings']['password'] = base64_encode( str_repeat( 'z', 40 ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
update_option( 'fluentmail-settings', $pdss_f );
$pdss_p = $pdss_plan( 'fluent-smtp' );
$ok( ! isset( $pdss_p['patch']['password_plain'] ) && (bool) $pdss_rows( $pdss_p, 'Password', 'skip' ), 'fluentsmtp: a tampered password is refused' );

// Post SMTP keeps its password base64 encoded.
update_option(
	'postman_options',
	array(
		'transport_type'      => 'smtp',
		'hostname'            => 'smtp.post.example',
		'port'                => 2525,
		'enc_type'            => 'tls',
		'auth_type'           => 'login',
		'basic_auth_username' => 'puser',
		'basic_auth_password' => base64_encode( 'P0st-Password-9876' ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		'sender_email'        => 'post@example.com',
	)
);
$pdss_p = $pdss_plan( 'post-smtp' );
$ok( 'P0st-Password-9876' === $pdss_p['patch']['password_plain'] && 2525 === $pdss_p['patch']['port'] && 'puser' === $pdss_p['patch']['username'], 'post smtp: the base64 password and the server import' );
$pdss_o              = get_option( 'postman_options' );
$pdss_o['auth_type'] = 'oauth2';
update_option( 'postman_options', $pdss_o );
$pdss_p = $pdss_plan( 'post-smtp' );
$ok( ! isset( $pdss_p['patch']['password_plain'] ) && false === $pdss_p['patch']['auth'] && (bool) $pdss_rows( $pdss_p, '', 'none' ), 'post smtp: OAuth sign-in shows as no match, with no password' );

/* =============================================================
 * Analytics.
 * ============================================================= */

update_option( 'googlesitekit_analytics-4_settings', array( 'measurementID' => 'g-abc123', 'useSnippet' => false ) );
$pdss_p = $pdss_plan( 'site-kit' );
$ok( 'G-ABC123' === $pdss_p['patch']['measurement_id'] && count( $pdss_p['notes'] ) >= 2, 'site kit: the measurement ID imports upper-cased, with a note that Site Kit wasn\'t placing the tag' );
update_option( 'googlesitekit_analytics-4_settings', array( 'measurementID' => 'G-<script>' ) );
$pdss_p = $pdss_plan( 'site-kit' );
$ok( ! isset( $pdss_p['patch']['measurement_id'] ) && (bool) $pdss_rows( $pdss_p, null, 'skip' ), 'site kit: a measurement ID that fails Perdita\'s check is refused' );
update_option( 'gap_options', array( 'gap_id' => 'UA-12345-1', 'gap_enable' => 1 ) );
$pdss_p = $pdss_plan( 'ga-google-analytics' );
$ok( ! isset( $pdss_p['patch']['measurement_id'] ) && (bool) $pdss_rows( $pdss_p, null, 'skip' ), 'ga google analytics: a Universal Analytics ID is refused with a reason' );
update_option( 'monsterinsights_site_profile', array( 'v4' => 'G-MONSTER1' ) );
$pdss_p = $pdss_plan( 'monsterinsights' );
$ok( 'G-MONSTER1' === $pdss_p['patch']['measurement_id'], 'monsterinsights: the connected profile\'s ID imports' );

delete_option( 'perdita_analytics_settings' );
add_filter( 'perdita_analytics_scm_active', '__return_true' );
$pdss_p = $pdss_plan( 'scm' );
$ok( Perdita_Analytics::MODEL_OPT_OUT === $pdss_p['patch']['consent_model'] && true === $pdss_p['patch']['manage_without_id'] && true === $pdss_p['patch']['ad_signals'], 'scm: the card offers the same adoption Perdita Analytics runs by itself' );
update_option( 'perdita_analytics_settings', array( 'consent_model' => 'opt_in', 'manage_without_id' => false ) );
$pdss_p = $pdss_plan( 'scm' );
$ok( empty( $pdss_p['patch'] ) && (bool) $pdss_p['notes'], 'scm: nothing to import once Perdita has its own consent model' );
remove_filter( 'perdita_analytics_scm_active', '__return_true' );

/* =============================================================
 * Limit Login Attempts Reloaded and Loginizer.
 * ============================================================= */

update_option( 'limit_login_allowed_retries', 4 );
update_option( 'limit_login_lockout_duration', 1200 );
update_option( 'limit_login_valid_duration', 43200 );
update_option( 'limit_login_whitelist', array( '1.2.3.4', '10.0.0.0/8', '5.6.7.8-5.6.7.9' ) );
update_option( 'limit_login_whitelist_usernames', array( 'admin' ) );
update_option( 'limit_login_allowed_lockouts', 4 );
delete_option( 'perdita_security_settings' );
$pdss_p = $pdss_plan( 'limit-login-attempts-reloaded' );
$ok( 4 === $pdss_p['patch']['login_max'] && 20 === $pdss_p['patch']['login_lockout'] && 720 === $pdss_p['patch']['login_window'] && true === $pdss_p['patch']['login_limit'], 'llar: retries, lockout and reset window convert to Perdita\'s minutes' );
$ok( array( '1.2.3.4' ) === $pdss_p['patch']['ip_allowlist'] && 2 === count( $pdss_rows( $pdss_p, null, 'skip' ) ), 'llar: exact IPs import and each range shows as refused' );
$ok( count( $pdss_rows( $pdss_p, '', 'none' ) ) >= 2, 'llar: username safelists and growing lockouts show as no match' );
$pdss_res = Perdita_Switch::import( 'limit-login-attempts-reloaded' );
$pdss_sec = Perdita_Security::get_settings();
$ok( $pdss_res['ok'] && 4 === $pdss_sec['login_max'] && array( '1.2.3.4' ) === $pdss_sec['ip_allowlist'], 'llar: the import lands in perdita_security_settings' );
$ok( 4 === (int) get_option( 'limit_login_allowed_retries' ) && 3 === count( get_option( 'limit_login_whitelist' ) ), 'llar: its own options are untouched' );
Perdita_Switch::undo( 'limit-login-attempts-reloaded' );
$ok( false === get_option( 'perdita_security_settings', false ), 'llar: undo removes what the import created' );
update_option( 'limit_login_lockout_duration', 200000 );
$pdss_p = $pdss_plan( 'limit-login-attempts-reloaded' );
$ok( ! isset( $pdss_p['patch']['login_lockout'] ) && (bool) $pdss_rows( $pdss_p, null, 'skip' ), 'llar: a lockout longer than Perdita allows is refused' );

update_option( 'loginizer_options', array( 'max_retries' => 3, 'lockout_time' => 900, 'reset_retries' => 1230, 'max_lockouts' => 5 ) );
update_option( 'loginizer_whitelist', array( 1 => array( 'start' => '9.9.9.9', 'end' => '9.9.9.9' ), 2 => array( 'start' => '1.1.1.1', 'end' => '1.1.1.255' ) ) );
update_option( 'loginizer_disable_brute', 0 );
$pdss_p = $pdss_plan( 'loginizer' );
$ok( 3 === $pdss_p['patch']['login_max'] && 15 === $pdss_p['patch']['login_lockout'] && 21 === $pdss_p['patch']['login_window'] && array( '9.9.9.9' ) === $pdss_p['patch']['ip_allowlist'], 'loginizer: settings convert, rounding the window to whole minutes' );

/* ---------- restore ---------- */

$pdss_cleanup();
$pdss_same = true;
foreach ( $pdss_saved as $pdss_n => $pdss_was ) {
	if ( 'cron' === $pdss_n ) {
		continue;
	}
	if ( get_option( $pdss_n, $pdss_missing ) !== $pdss_was ) {
		$pdss_same = false;
		echo "      changed: $pdss_n\n";
	}
}
$ok( $pdss_same, 'switch sources: every option is back the way it was' );
