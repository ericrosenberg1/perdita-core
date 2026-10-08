<?php
/**
 * IndexNow module checks.
 *
 * A fragment of the smoke suite: tests/smoke.php requires every
 * tests/smoke-*.php in its own scope right before printing the summary, so
 * $ok() and the $pass/$fail counters below are the ones defined there.
 *
 * Nothing here reaches the network. The one place that would, submit(), runs
 * behind a pre_http_request stub that records the payload and hands back a
 * canned 200, so the assertions are about what this plugin sends rather than
 * what Bing happens to answer today.
 *
 * Section 5b drives the module's own post hooks through wp_update_post(), the path
 * WP-CLI's `wp post update` and any PHP caller take, and asserts one
 * submission for a published post and none for a draft, a revision, or an
 * autosave. Section 6 also covers the WP-CLI shape, where the queue submits
 * at shutdown instead of scheduling a cron event. Section 6b creates and
 * updates a post through the REST route, which sets categories after the
 * post hooks fire, and asserts the category archives in the submitted set.
 * Section 6c trashes a published post and asserts that its old permalink is
 * submitted, never the __trashed slug WordPress renames it to.
 *
 * Everything is restored: the option, blog_public, the ping throttle
 * transient, every cron event scheduled, and the temporary post and terms.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

require_once PERDITA_CORE_DIR . 'inc/modules/indexnow/class-perdita-indexnow.php';

// The module is on by default and boots on a front-end request, so a live
// instance is already listening to transition_post_status and shutdown. Every
// post this whole suite published and deleted is sitting in its queue, and its
// shutdown handler would schedule a cron event for them after the run ends.
// Unhook it here, once, so the suite leaves nothing on the cron queue and the
// assertions below are about the instance this file drives on purpose.
foreach ( array( 'transition_post_status', 'post_updated', 'save_post', 'pre_post_update', 'before_delete_post', 'deleted_post', 'trashed_post', 'shutdown', 'template_redirect', Perdita_IndexNow::CRON_HOOK ) as $__in_hook ) {
	if ( empty( $GLOBALS['wp_filter'][ $__in_hook ] ) ) {
		continue;
	}
	foreach ( $GLOBALS['wp_filter'][ $__in_hook ]->callbacks as $__in_priority => $__in_callbacks ) {
		foreach ( $__in_callbacks as $__in_registered ) {
			$__in_fn = isset( $__in_registered['function'] ) ? $__in_registered['function'] : null;
			if ( is_array( $__in_fn ) && isset( $__in_fn[0] ) && is_object( $__in_fn[0] ) && $__in_fn[0] instanceof Perdita_IndexNow ) {
				remove_action( $__in_hook, $__in_fn, $__in_priority );
			}
		}
	}
}

/**
 * Every submission event on the cron queue right now, as "timestamp:args"
 * strings. The cleanup at the bottom unschedules anything that appears later
 * and is not in here, so the suite can never leave one of its own fixtures
 * queued for a real submission.
 *
 * @return string[]
 */
$__in_cron_snapshot = function () {
	$out = array();
	foreach ( (array) _get_cron_array() as $ts => $hooks ) {
		if ( empty( $hooks[ Perdita_IndexNow::CRON_HOOK ] ) ) {
			continue;
		}
		foreach ( $hooks[ Perdita_IndexNow::CRON_HOOK ] as $event ) {
			$out[ (string) $ts . ':' . md5( (string) wp_json_encode( $event['args'] ) ) ] = array( (int) $ts, (array) $event['args'] );
		}
	}
	return $out;
};
$__in_cron_before = $__in_cron_snapshot();

$__in_orig_option = get_option( Perdita_IndexNow::OPTION );
$__in_orig_public = get_option( 'blog_public' );
$__in_orig_ping   = get_transient( Perdita_IndexNow::PING_TRANSIENT );

delete_option( Perdita_IndexNow::OPTION );
delete_transient( Perdita_IndexNow::PING_TRANSIENT );
update_option( 'blog_public', 1 );

/* ------------------------------------------------------------------ */
/* 1. Descriptor                                                       */
/* ------------------------------------------------------------------ */

$__in_desc = include PERDITA_CORE_DIR . 'inc/modules/indexnow/module.php';
$ok( is_array( $__in_desc ) && 'indexnow' === $__in_desc['id'], 'indexnow: module.php returns a descriptor with id indexnow' );
$ok( 'seo' === $__in_desc['group'] && true === $__in_desc['default'], 'indexnow: it lands in the seo group and is on out of the box' );
$ok(
	array() === array_diff( array( 'front', 'admin', 'rest', 'cron' ), (array) $__in_desc['contexts'] ),
	'indexnow: it runs in all four contexts, so a WP-CLI publish, a block editor save, and the debounced cron all reach it'
);
$ok( file_exists( PERDITA_CORE_DIR . 'inc/modules/indexnow/class-perdita-indexnow-cli.php' ), 'indexnow: the WP-CLI class the constructor requires under WP_CLI is actually on disk' );

/* ------------------------------------------------------------------ */
/* 2. Key and key file                                                 */
/* ------------------------------------------------------------------ */

$__in_key = Perdita_IndexNow::key();
$ok( 1 === preg_match( '/^[a-f0-9]{32}$/', $__in_key ), 'indexnow: key() generates 32 hex characters on first use' );
$ok( $__in_key === Perdita_IndexNow::key(), 'indexnow: the key is generated once and then read back, not regenerated per call' );
$ok( Perdita_IndexNow::is_valid_key( $__in_key ), 'indexnow: is_valid_key() accepts a key this module generated' );
$ok( ! Perdita_IndexNow::is_valid_key( 'nope' ) && ! Perdita_IndexNow::is_valid_key( strtoupper( $__in_key ) ), 'indexnow: is_valid_key() rejects a wrong shape and an upper-case key' );

$__in_new_key = Perdita_IndexNow::regenerate_key();
$ok( $__in_new_key !== $__in_key && Perdita_IndexNow::is_valid_key( $__in_new_key ), 'indexnow: regenerate_key() replaces the stored key with a different valid one' );
$__in_key = $__in_new_key;

$__in_base = rtrim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
$ok( Perdita_IndexNow::key_file_requested( $__in_base . '/' . $__in_key . '.txt' ), 'indexnow: key_file_requested() matches the key file path' );
$ok( Perdita_IndexNow::key_file_requested( $__in_base . '/' . $__in_key . '.txt?cache=0' ), 'indexnow: a query string does not stop the key file from being served' );
$ok( ! Perdita_IndexNow::key_file_requested( $__in_base . '/' . $__in_key ), 'indexnow: the path without .txt is not the key file' );
$ok( ! Perdita_IndexNow::key_file_requested( $__in_base . '/deadbeefdeadbeefdeadbeefdeadbeef.txt' ), 'indexnow: some other 32-hex .txt file is not the key file' );
$ok( Perdita_IndexNow::key_file_url() === home_url( '/' . $__in_key . '.txt' ), 'indexnow: key_file_url() is the key file at the site root' );

/* ------------------------------------------------------------------ */
/* 3. What may be submitted                                            */
/* ------------------------------------------------------------------ */

$__in_parent_cat = wp_insert_term( 'Perdita IndexNow Parent', 'category' );
$__in_parent_cat = is_array( $__in_parent_cat ) ? (int) $__in_parent_cat['term_id'] : 0;
$__in_post_id    = wp_insert_post(
	array(
		'post_title'   => 'Perdita IndexNow Smoke Post',
		'post_content' => 'x',
		'post_status'  => 'publish',
		'post_type'    => 'post',
	)
);
if ( $__in_parent_cat ) {
	wp_set_post_terms( $__in_post_id, array( $__in_parent_cat ), 'category' );
}

$ok( Perdita_IndexNow::should_submit( $__in_post_id ), 'indexnow: should_submit() allows a published public post' );

update_post_meta( $__in_post_id, Perdita_IndexNow::NOINDEX_META, '1' );
$ok( ! Perdita_IndexNow::should_submit( $__in_post_id ), 'indexnow: should_submit() refuses a post Perdita Pro marked noindex' );
delete_post_meta( $__in_post_id, Perdita_IndexNow::NOINDEX_META );
$ok( Perdita_IndexNow::should_submit( $__in_post_id ), 'indexnow: clearing the noindex meta allows it again' );

$__in_veto = function () {
	return false;
};
add_filter( 'perdita_indexnow_should_submit', $__in_veto );
$ok( ! Perdita_IndexNow::should_submit( $__in_post_id ), 'indexnow: the perdita_indexnow_should_submit filter can veto a post' );
remove_filter( 'perdita_indexnow_should_submit', $__in_veto );

update_option( 'blog_public', 0 );
$ok( ! Perdita_IndexNow::should_submit( $__in_post_id ), 'indexnow: nothing is submitted while the site discourages search engines' );
update_option( 'blog_public', 1 );

Perdita_IndexNow::save( array( 'enabled' => false ) );
$ok( ! Perdita_IndexNow::should_submit( $__in_post_id ), 'indexnow: nothing is submitted while automatic submission is switched off' );
Perdita_IndexNow::save( array( 'enabled' => true ) );

$ok( ! Perdita_IndexNow::should_submit( 0 ) && ! Perdita_IndexNow::should_submit( -1 ), 'indexnow: should_submit() says no to an id that is not a post' );

/* ------------------------------------------------------------------ */
/* 4. URL collection and normalization                                 */
/* ------------------------------------------------------------------ */

$__in_urls = Perdita_IndexNow::urls_for_post( $__in_post_id );
$ok( in_array( get_permalink( $__in_post_id ), $__in_urls, true ), 'indexnow: urls_for_post() includes the post permalink' );
$ok( in_array( home_url( '/' ), $__in_urls, true ), 'indexnow: urls_for_post() includes the home page, which the new post changed' );
if ( $__in_parent_cat ) {
	$ok( in_array( (string) get_term_link( $__in_parent_cat, 'category' ), $__in_urls, true ), 'indexnow: urls_for_post() includes the term archives the post sits in' );
} else {
	$ok( true, 'indexnow: term archive URLs SKIPPED (the fixture category could not be created)' );
}
$ok( count( $__in_urls ) === count( array_unique( $__in_urls ) ), 'indexnow: urls_for_post() never repeats a URL' );

$__in_norm = Perdita_IndexNow::normalize_urls(
	array(
		home_url( '/a/' ),
		home_url( '/a/' ),
		'https://example.net/somewhere-else/',
		'/relative/',
		'ftp://' . (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) . '/x',
		'   ',
	)
);
$ok( array( home_url( '/a/' ) ) === $__in_norm, 'indexnow: normalize_urls() keeps one copy of an on-site http(s) URL and drops foreign hosts, relative paths, other schemes, and blanks' );

/* ------------------------------------------------------------------ */
/* 5. Debounced cron scheduling                                        */
/* ------------------------------------------------------------------ */

$__in_test_url = home_url( '/perdita-indexnow-smoke/' );
$__in_args     = Perdita_IndexNow::event_args( array( $__in_test_url ) );
$ok( is_array( $__in_args ) && array( $__in_test_url ) === $__in_args[0] && false === $__in_args[1], 'indexnow: event_args() normalizes and sorts the URL list so the same change always looks up the same event' );
$ok( null === Perdita_IndexNow::event_args( array( 'https://example.net/x/' ) ), 'indexnow: event_args() is null when nothing submittable is left' );

$ok( ! wp_next_scheduled( Perdita_IndexNow::CRON_HOOK, $__in_args ), 'indexnow: nothing is scheduled for this URL set before the test schedules it' );
$ok( true === Perdita_IndexNow::schedule( array( $__in_test_url ) ), 'indexnow: schedule() reports success' );
$__in_ts = wp_next_scheduled( Perdita_IndexNow::CRON_HOOK, $__in_args );
$ok( $__in_ts > 0, 'indexnow: schedule() puts a one-off event on the cron queue' );
$ok( $__in_ts > time() + ( Perdita_IndexNow::DEBOUNCE / 2 ), 'indexnow: the event is debounced into the future rather than run inline on the save request' );
$ok( true === Perdita_IndexNow::schedule( array( $__in_test_url ) ) && $__in_ts === wp_next_scheduled( Perdita_IndexNow::CRON_HOOK, $__in_args ), 'indexnow: scheduling the identical URL set again reuses the waiting event instead of queueing a second one' );
if ( $__in_ts ) {
	wp_unschedule_event( $__in_ts, Perdita_IndexNow::CRON_HOOK, $__in_args );
}
$ok( ! wp_next_scheduled( Perdita_IndexNow::CRON_HOOK, $__in_args ), 'indexnow: the test event is off the queue again' );
$ok( false === Perdita_IndexNow::schedule( array( 'https://example.net/x/' ) ), 'indexnow: a URL set with nothing on this site schedules nothing' );

// The hook path. The instance is built and immediately unhooked, so driving
// its handlers directly here cannot leave a second set of post hooks behind
// for the rest of the suite (which creates and deletes posts of its own).
$__in_instance = new Perdita_IndexNow( perdita_core() );
remove_action( 'template_redirect', array( $__in_instance, 'maybe_serve_key_file' ), 0 );
remove_action( 'pre_post_update', array( $__in_instance, 'stash_before_update' ), 10 );
remove_action( 'transition_post_status', array( $__in_instance, 'on_transition' ), 10 );
remove_action( 'post_updated', array( $__in_instance, 'on_post_updated' ), 10 );
remove_action( 'save_post', array( $__in_instance, 'on_save_post' ), 20 );
remove_action( 'before_delete_post', array( $__in_instance, 'stash_before_delete' ), 10 );
remove_action( 'deleted_post', array( $__in_instance, 'on_deleted' ), 10 );
remove_action( 'trashed_post', array( $__in_instance, 'on_trashed' ) );
remove_action( Perdita_IndexNow::CRON_HOOK, array( $__in_instance, 'run_scheduled' ), 10 );
remove_action( 'shutdown', array( $__in_instance, 'flush_queue' ) );

$__in_instance->on_transition( 'publish', 'draft', get_post( $__in_post_id ) );
$ok( true === $__in_instance->flush_queue(), 'indexnow: a draft-to-publish transition queues URLs and flush_queue() schedules them' );
$__in_pub_args = Perdita_IndexNow::event_args( Perdita_IndexNow::urls_for_post( $__in_post_id ), true );
$__in_pub_ts   = wp_next_scheduled( Perdita_IndexNow::CRON_HOOK, $__in_pub_args );
$ok( $__in_pub_ts > 0, 'indexnow: publishing schedules the post URL, its term archives, and the home page, with the sitemap ping flag set' );
if ( $__in_pub_ts ) {
	wp_unschedule_event( $__in_pub_ts, Perdita_IndexNow::CRON_HOOK, $__in_pub_args );
}

$__in_instance->on_post_updated( $__in_post_id, get_post( $__in_post_id ), get_post( $__in_post_id ) );
$__in_upd_args = Perdita_IndexNow::event_args( Perdita_IndexNow::urls_for_post( $__in_post_id ), false );
$__in_instance->flush_queue();
$__in_upd_ts = wp_next_scheduled( Perdita_IndexNow::CRON_HOOK, $__in_upd_args );
$ok( $__in_upd_ts > 0, 'indexnow: re-saving an already published post schedules a submission without the sitemap ping' );
if ( $__in_upd_ts ) {
	wp_unschedule_event( $__in_upd_ts, Perdita_IndexNow::CRON_HOOK, $__in_upd_args );
}

$ok( false === $__in_instance->flush_queue(), 'indexnow: an empty queue schedules nothing' );

/* ------------------------------------------------------------------ */
/* 5b. Every save path, through the module's own hooks                */
/* ------------------------------------------------------------------ */

// This instance keeps its post hooks for the rest of the file, so the saves
// below reach it the way a WP-CLI `wp post update`, a PHP wp_update_post(),
// a REST save, or the editor would. Only shutdown, the key file, and the cron
// handler are unhooked: the queue is flushed by hand so each step can assert
// exactly what it scheduled.
$__in_live = new Perdita_IndexNow( perdita_core() );
remove_action( 'template_redirect', array( $__in_live, 'maybe_serve_key_file' ), 0 );
remove_action( Perdita_IndexNow::CRON_HOOK, array( $__in_live, 'run_scheduled' ), 10 );
remove_action( 'shutdown', array( $__in_live, 'flush_queue' ) );

$ok( has_action( 'save_post', array( $__in_live, 'on_save_post' ) ) === 20, 'indexnow: the module listens to save_post, after the save handlers other plugins register at the default priority' );

/**
 * The submission events added to the cron queue since a snapshot.
 *
 * @param array $before Snapshot from $__in_cron_snapshot.
 * @return array[] Each as array( timestamp, args ).
 */
$__in_new_events = function ( array $before ) use ( $__in_cron_snapshot ) {
	return array_values( array_diff_key( $__in_cron_snapshot(), $before ) );
};

// A programmatic update of a published post: pre_post_update, a
// publish-to-publish transition, post_updated (which also stores a revision),
// and save_post all fire. One submission comes out.
$__in_snap = $__in_cron_snapshot();
$__in_ret  = wp_update_post(
	array(
		'ID'           => $__in_post_id,
		'post_content' => 'x, updated the way wp post update does it',
	),
	true
);
$ok( $__in_ret === $__in_post_id, 'indexnow: wp_update_post() on the published fixture succeeds with the module hooked' );
$ok( true === $__in_live->flush_queue(), 'indexnow: a wp_update_post() on a published post leaves URLs in the queue and flush_queue() schedules them' );
$__in_added = $__in_new_events( $__in_snap );
$__in_want  = Perdita_IndexNow::event_args( Perdita_IndexNow::urls_for_post( $__in_post_id ), false );
$ok( 1 === count( $__in_added ), 'indexnow: exactly one submission event is queued for that save, however many hooks saw it' );
$ok( isset( $__in_added[0][1] ) && $__in_want === $__in_added[0][1], 'indexnow: that event carries the post URL, its term archives, and the home page, with no sitemap ping flag' );
foreach ( $__in_added as $__in_ev ) {
	wp_unschedule_event( $__in_ev[0], Perdita_IndexNow::CRON_HOOK, $__in_ev[1] );
}
$ok( false === $__in_live->flush_queue(), 'indexnow: the queue is empty again after that flush' );

// A revision and an autosave of the published post submit nothing.
$__in_snap   = $__in_cron_snapshot();
$__in_rev_id = _wp_put_post_revision( get_post( $__in_post_id ) );
$ok( is_int( $__in_rev_id ) && $__in_rev_id > 0 && wp_is_post_revision( $__in_rev_id ), 'indexnow: a revision of the published post was stored' );
$ok( false === $__in_live->flush_queue() && array() === $__in_new_events( $__in_snap ), 'indexnow: storing a revision queues nothing' );
$__in_auto_id = _wp_put_post_revision( get_post( $__in_post_id ), true );
$ok( is_int( $__in_auto_id ) && $__in_auto_id > 0 && wp_is_post_autosave( $__in_auto_id ), 'indexnow: an autosave of the published post was stored' );
$ok( false === $__in_live->flush_queue() && array() === $__in_new_events( $__in_snap ), 'indexnow: storing an autosave queues nothing' );
$ok( ! Perdita_IndexNow::should_submit( $__in_rev_id ) && ! Perdita_IndexNow::should_submit( $__in_auto_id ), 'indexnow: should_submit() refuses a revision and an autosave outright' );

// A draft, created and then re-saved, submits nothing either.
$__in_snap     = $__in_cron_snapshot();
$__in_draft_id = wp_insert_post(
	array(
		'post_title'   => 'Perdita IndexNow Smoke Draft',
		'post_content' => 'x',
		'post_status'  => 'draft',
		'post_type'    => 'post',
	)
);
wp_update_post(
	array(
		'ID'         => $__in_draft_id,
		'post_title' => 'Perdita IndexNow Smoke Draft, edited',
	)
);
$ok( $__in_draft_id > 0 && false === $__in_live->flush_queue() && array() === $__in_new_events( $__in_snap ), 'indexnow: creating and re-saving a draft queues nothing' );

// Code that writes a post itself and then fires save_post by hand reaches
// only the catch-all. It still queues the published post once.
$__in_snap = $__in_cron_snapshot();
do_action( 'save_post', $__in_post_id, get_post( $__in_post_id ), true );
$ok( true === $__in_live->flush_queue(), 'indexnow: a bare save_post for a published post queues its URLs' );
$__in_added = $__in_new_events( $__in_snap );
$ok( 1 === count( $__in_added ) && $__in_want === $__in_added[0][1], 'indexnow: that path schedules the same single event as a full save' );
foreach ( $__in_added as $__in_ev ) {
	wp_unschedule_event( $__in_ev[0], Perdita_IndexNow::CRON_HOOK, $__in_ev[1] );
}

// The same bare save_post for the draft, a revision, and under DOING_AUTOSAVE
// (the block editor's autosave request) queues nothing.
do_action( 'save_post', $__in_draft_id, get_post( $__in_draft_id ), true );
do_action( 'save_post', $__in_rev_id, get_post( $__in_rev_id ), false );
$ok( false === $__in_live->flush_queue(), 'indexnow: a bare save_post for a draft or a revision queues nothing' );
$__in_live->on_save_post( $__in_post_id, null, true );
$ok( true === $__in_live->flush_queue(), 'indexnow: on_save_post() fetches the post itself when the hook hands it only an id' );
foreach ( $__in_new_events( $__in_snap ) as $__in_ev ) {
	wp_unschedule_event( $__in_ev[0], Perdita_IndexNow::CRON_HOOK, $__in_ev[1] );
}

/* ------------------------------------------------------------------ */
/* 6. Submission, with the HTTP call stubbed                           */
/* ------------------------------------------------------------------ */

$__in_requests = array();
$__in_stub     = function ( $pre, $args, $url ) use ( &$__in_requests ) {
	unset( $pre );
	$__in_requests[] = array(
		'url'  => (string) $url,
		'body' => isset( $args['body'] ) ? (string) $args['body'] : '',
	);
	return array(
		'headers'  => array(),
		'body'     => '',
		'response' => array(
			'code'    => 200,
			'message' => 'OK',
		),
		'cookies'  => array(),
		'filename' => null,
	);
};
add_filter( 'pre_http_request', $__in_stub, 10, 3 );

Perdita_IndexNow::save( array( 'log' => array() ) );
$__in_results = Perdita_IndexNow::submit( array( $__in_test_url, 'https://example.net/not-ours/' ) );

$ok( 1 === count( $__in_results ) && 200 === $__in_results[0]['status'], 'indexnow: submit() posts once per engine and records the HTTP status' );
$ok( 1 === count( $__in_requests ) && 'https://api.indexnow.org/indexnow' === $__in_requests[0]['url'], 'indexnow: the default engine is the shared api.indexnow.org endpoint' );

$__in_payload = json_decode( $__in_requests[0]['body'], true );
$ok( is_array( $__in_payload ), 'indexnow: the request body is JSON' );
$ok( isset( $__in_payload['host'] ) && $__in_payload['host'] === (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ), 'indexnow: the payload carries this site host' );
$ok( isset( $__in_payload['key'] ) && $__in_payload['key'] === Perdita_IndexNow::key(), 'indexnow: the payload carries the site key' );
$ok( isset( $__in_payload['keyLocation'] ) && $__in_payload['keyLocation'] === Perdita_IndexNow::key_file_url(), 'indexnow: the payload points at the key file so the engine can verify it' );
$ok( isset( $__in_payload['urlList'] ) && array( $__in_test_url ) === $__in_payload['urlList'], 'indexnow: the foreign URL is dropped before the request, because IndexNow rejects a batch that mixes hosts' );

$__in_log = Perdita_IndexNow::settings()['log'];
$ok( ! empty( $__in_log ) && 200 === (int) $__in_log[0]['status'] && 1 === (int) $__in_log[0]['count'], 'indexnow: the submission is written to the log, newest first' );

$ok( array() === Perdita_IndexNow::submit( array( 'https://example.net/x/' ) ) && 1 === count( $__in_requests ), 'indexnow: submit() with nothing on this site makes no HTTP request at all' );

// Log cap.
$__in_rows = array();
for ( $__in_i = 0; $__in_i < 70; $__in_i++ ) {
	$__in_rows[] = array(
		'time'   => time(),
		'engine' => 'api.indexnow.org',
		'count'  => 1,
		'status' => 200,
		'error'  => '',
		'urls'   => array( $__in_test_url ),
	);
}
Perdita_IndexNow::log( $__in_rows );
$ok( Perdita_IndexNow::LOG_MAX === count( Perdita_IndexNow::settings()['log'] ), 'indexnow: the log is capped at ' . Perdita_IndexNow::LOG_MAX . ' rows however many land at once' );

// The retired Google and Bing sitemap pings make no request at all.
$__in_requests = array();
$ok( array() === Perdita_IndexNow::ping_sitemaps( true ) && 0 === count( $__in_requests ), 'indexnow: the retired sitemap ping sends nothing' );
( new ReflectionClass( 'Perdita_IndexNow' ) )->newInstanceWithoutConstructor()->run_scheduled( array(), true );
$ok( 0 === count( $__in_requests ), 'indexnow: an old queued event that asked for a ping sends nothing' );

// The WP-CLI shape. This harness is not WP-CLI, so the default is the cron
// path, and the filter switches the queue to submit at shutdown instead: a
// wp_update_post() on the published post then makes exactly one request,
// writes one log row, and schedules nothing.
$ok( false === Perdita_IndexNow::submits_inline(), 'indexnow: on a web request the queue goes through the debounced cron event' );
add_filter( 'perdita_indexnow_submit_inline', '__return_true' );
$ok( true === Perdita_IndexNow::submits_inline(), 'indexnow: perdita_indexnow_submit_inline switches the queue to submit at shutdown, which is the WP-CLI default' );
Perdita_IndexNow::save( array( 'log' => array() ) );
$__in_requests = array();
$__in_snap     = $__in_cron_snapshot();
wp_update_post(
	array(
		'ID'           => $__in_post_id,
		'post_content' => 'x, updated again, submitted inline',
	)
);
$ok( true === $__in_live->flush_queue(), 'indexnow: flushing inline reports the submission' );
$ok( 1 === count( $__in_requests ) && 'https://api.indexnow.org/indexnow' === $__in_requests[0]['url'], 'indexnow: a wp_update_post() submitted inline makes exactly one IndexNow request' );
$__in_payload = json_decode( isset( $__in_requests[0]['body'] ) ? $__in_requests[0]['body'] : '', true );
$ok( is_array( $__in_payload ) && isset( $__in_payload['urlList'] ) && in_array( get_permalink( $__in_post_id ), $__in_payload['urlList'], true ) && in_array( home_url( '/' ), $__in_payload['urlList'], true ), 'indexnow: the inline request carries the post URL and the home page' );
$__in_log = Perdita_IndexNow::settings()['log'];
$ok( 1 === count( $__in_log ) && 200 === (int) $__in_log[0]['status'], 'indexnow: the inline submission is in the log before the request ends, which is what a wp post update should leave behind' );
$ok( array() === $__in_new_events( $__in_snap ), 'indexnow: the inline path schedules no cron event' );
$__in_requests = array();
$ok( false === $__in_live->flush_queue() && 0 === count( $__in_requests ), 'indexnow: an empty queue submits nothing inline either' );
remove_filter( 'perdita_indexnow_submit_inline', '__return_true' );
$ok( false === Perdita_IndexNow::submits_inline(), 'indexnow: removing the filter restores the cron path' );

/* ------------------------------------------------------------------ */
/* 6b. A post saved through the REST API                               */
/* ------------------------------------------------------------------ */

// The posts controller calls wp_insert_post() first and sets the request's
// categories, tags and meta afterwards, so every post hook fires while a new
// post still sits in the default category. The URL list is built when the
// queue flushes, from the post as it ends up, so the category the request
// asked for is submitted and the default one it sat in for a moment is not.
// An update through the same route that moves the post to another category
// submits the new archive and the one it left, and a draft published by an
// update that carries its categories (how the block editor publishes) submits
// the archive set in that save. Submitted inline, so the assertions read the
// payload the stubbed engine received.
$__in_rest_id       = 0;
$__in_rest_draft_id = 0;
$__in_rest_cat      = wp_insert_term( 'Perdita IndexNow REST Category', 'category' );
$__in_rest_cat      = is_array( $__in_rest_cat ) ? (int) $__in_rest_cat['term_id'] : 0;
$__in_rest_cat2     = wp_insert_term( 'Perdita IndexNow REST Category Two', 'category' );
$__in_rest_cat2     = is_array( $__in_rest_cat2 ) ? (int) $__in_rest_cat2['term_id'] : 0;
$__in_rest_admin    = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
		'fields' => 'ID',
	)
);

/**
 * The URL list of the one IndexNow request the stub recorded, or an empty
 * array when there was not exactly one.
 *
 * @return string[]
 */
$__in_sent_urls = function () use ( &$__in_requests ) {
	if ( 1 !== count( $__in_requests ) ) {
		return array();
	}
	$payload = json_decode( (string) $__in_requests[0]['body'], true );
	return is_array( $payload ) && isset( $payload['urlList'] ) ? (array) $payload['urlList'] : array();
};

if ( empty( $__in_rest_admin ) || ! $__in_rest_cat || ! $__in_rest_cat2 ) {
	$ok( true, 'indexnow: REST saves SKIPPED (no administrator, or the fixture categories could not be created)' );
} else {
	$__in_orig_user = get_current_user_id();
	wp_set_current_user( (int) $__in_rest_admin[0] );
	add_filter( 'perdita_indexnow_submit_inline', '__return_true' );
	$__in_snap     = $__in_cron_snapshot();
	$__in_cat_url  = (string) get_term_link( $__in_rest_cat, 'category' );
	$__in_cat2_url = (string) get_term_link( $__in_rest_cat2, 'category' );

	$__in_requests = array();
	$__in_req      = new WP_REST_Request( 'POST', '/wp/v2/posts' );
	$__in_req->set_body_params(
		array(
			'title'      => 'Perdita IndexNow Smoke REST Post',
			'content'    => 'x',
			'status'     => 'publish',
			'categories' => array( $__in_rest_cat ),
		)
	);
	$__in_res     = rest_do_request( $__in_req );
	$__in_data    = $__in_res->get_data();
	$__in_rest_id = 201 === $__in_res->get_status() && isset( $__in_data['id'] ) ? (int) $__in_data['id'] : 0;
	$ok( $__in_rest_id > 0 && array( $__in_rest_cat ) === array_map( 'intval', wp_get_post_categories( $__in_rest_id ) ), 'indexnow: POST /wp/v2/posts creates a published post in the requested category' );
	$ok( true === $__in_live->flush_queue() && 1 === count( $__in_requests ), 'indexnow: the REST create makes exactly one IndexNow request' );
	$__in_list = $__in_sent_urls();
	$ok( in_array( $__in_cat_url, $__in_list, true ), 'indexnow: a post created through the REST API submits the archive of the category the request set' );
	$ok( $__in_rest_id > 0 && in_array( get_permalink( $__in_rest_id ), $__in_list, true ) && in_array( home_url( '/' ), $__in_list, true ), 'indexnow: the REST create also submits the post URL and the home page' );
	$__in_default_cat = (int) get_option( 'default_category' );
	if ( $__in_default_cat && $__in_default_cat !== $__in_rest_cat && term_exists( $__in_default_cat, 'category' ) ) {
		$ok( ! in_array( (string) get_term_link( $__in_default_cat, 'category' ), $__in_list, true ), 'indexnow: the default category the REST post sat in before its terms were set is not submitted' );
	}

	$__in_requests = array();
	$__in_req      = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $__in_rest_id );
	$__in_req->set_body_params( array( 'categories' => array( $__in_rest_cat2 ) ) );
	$__in_res = rest_do_request( $__in_req );
	$ok( 200 === $__in_res->get_status() && array( $__in_rest_cat2 ) === array_map( 'intval', wp_get_post_categories( $__in_rest_id ) ), 'indexnow: POST /wp/v2/posts/<id> moves the post to another category' );
	$ok( true === $__in_live->flush_queue() && 1 === count( $__in_requests ), 'indexnow: the REST update makes exactly one IndexNow request' );
	$__in_list = $__in_sent_urls();
	$ok( in_array( $__in_cat2_url, $__in_list, true ), 'indexnow: a REST update submits the archive of the category the post moved into' );
	$ok( in_array( $__in_cat_url, $__in_list, true ), 'indexnow: a REST update still submits the archive the post left' );

	// The block editor's publish: a draft that already exists is published
	// by an update that carries the status and the categories together.
	$__in_requests = array();
	$__in_req      = new WP_REST_Request( 'POST', '/wp/v2/posts' );
	$__in_req->set_body_params(
		array(
			'title'   => 'Perdita IndexNow Smoke REST Draft',
			'content' => 'x',
			'status'  => 'draft',
		)
	);
	$__in_res           = rest_do_request( $__in_req );
	$__in_data          = $__in_res->get_data();
	$__in_rest_draft_id = 201 === $__in_res->get_status() && isset( $__in_data['id'] ) ? (int) $__in_data['id'] : 0;
	$ok( $__in_rest_draft_id > 0 && false === $__in_live->flush_queue() && 0 === count( $__in_requests ), 'indexnow: a draft created through the REST API submits nothing' );
	$__in_req = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $__in_rest_draft_id );
	$__in_req->set_body_params(
		array(
			'status'     => 'publish',
			'categories' => array( $__in_rest_cat2 ),
		)
	);
	$__in_res = rest_do_request( $__in_req );
	$ok( 200 === $__in_res->get_status() && 'publish' === get_post_status( $__in_rest_draft_id ), 'indexnow: POST /wp/v2/posts/<id> publishes the draft with a category, the way the block editor does' );
	$ok( true === $__in_live->flush_queue() && 1 === count( $__in_requests ), 'indexnow: that publish makes exactly one IndexNow request' );
	$__in_list = $__in_sent_urls();
	$ok( in_array( $__in_cat2_url, $__in_list, true ) && in_array( get_permalink( $__in_rest_draft_id ), $__in_list, true ), 'indexnow: a draft published from the block editor submits its URL and the archive of the category set in the same save' );

	$ok( array() === $__in_new_events( $__in_snap ), 'indexnow: the REST saves submitted inline schedule no cron event' );

	remove_filter( 'perdita_indexnow_submit_inline', '__return_true' );
	wp_set_current_user( $__in_orig_user );
}

/* ------------------------------------------------------------------ */
/* 6c. A published post moved to the trash                             */
/* ------------------------------------------------------------------ */

// wp_trash_post() renames the post to <slug>__trashed in the database before
// wp_insert_post() fires pre_post_update, so a URL list built from the post
// at that moment points at a slug that never existed while it was public.
// The engine is told about the permalink the post had while published, and
// never about a __trashed URL. Pretty permalinks are switched on for the
// section, since a plain ?p= link carries no slug to get wrong.
$__in_orig_permalinks = (string) get_option( 'permalink_structure' );
if ( '' === $__in_orig_permalinks ) {
	$GLOBALS['wp_rewrite']->set_permalink_structure( '/%postname%/' );
}
add_filter( 'perdita_indexnow_submit_inline', '__return_true' );
$__in_snap     = $__in_cron_snapshot();
$__in_trash_id = wp_insert_post(
	array(
		'post_title'   => 'Perdita IndexNow Smoke Trash',
		'post_name'    => 'perdita-indexnow-smoke-trash',
		'post_content' => 'x',
		'post_status'  => 'publish',
		'post_type'    => 'post',
	)
);
if ( $__in_parent_cat ) {
	wp_set_post_terms( $__in_trash_id, array( $__in_parent_cat ), 'category' );
}
$__in_live->flush_queue();
$__in_trash_url = (string) get_permalink( $__in_trash_id );
$__in_no_trash  = function ( array $urls ) {
	return array() === preg_grep( '/__trashed/', $urls );
};

$__in_requests = array();
$ok( $__in_trash_id > 0 && false === strpos( $__in_trash_url, '?p=' ) && false !== wp_trash_post( $__in_trash_id ), 'indexnow: a published post with a pretty permalink moves to the trash' );
$ok( '__trashed' === substr( (string) get_post_field( 'post_name', $__in_trash_id ), -9 ), 'indexnow: WordPress gave the trashed post its __trashed slug' );
$ok( true === $__in_live->flush_queue() && 1 === count( $__in_requests ), 'indexnow: trashing a published post makes exactly one IndexNow request' );
$__in_list = $__in_sent_urls();
$ok( in_array( $__in_trash_url, $__in_list, true ), 'indexnow: a trashed post submits the permalink it had while published, so the engine recrawls it and drops it' );
$ok( ! empty( $__in_list ) && $__in_no_trash( $__in_list ), 'indexnow: no URL submitted for a trashed post carries the __trashed slug' );
$ok( in_array( home_url( '/' ), $__in_list, true ) && ( ! $__in_parent_cat || in_array( (string) get_term_link( $__in_parent_cat, 'category' ), $__in_list, true ) ), 'indexnow: the trash also submits the home page and the archives the post sat in' );

// The fallback for a trash that reaches transition_post_status with nothing
// stashed (no pre_post_update before it), with and without the desired slug
// WordPress stores beside the __trashed one.
$__in_requests = array();
$__in_live->on_transition( 'trash', 'publish', get_post( $__in_trash_id ) );
$ok( true === $__in_live->flush_queue() && in_array( $__in_trash_url, $__in_sent_urls(), true ) && $__in_no_trash( $__in_sent_urls() ), 'indexnow: with nothing stashed, a publish-to-trash transition still submits the old permalink and no __trashed URL' );
delete_post_meta( $__in_trash_id, '_wp_desired_post_slug' );
$__in_requests = array();
$__in_live->on_transition( 'trash', 'publish', get_post( $__in_trash_id ) );
$ok( true === $__in_live->flush_queue() && in_array( $__in_trash_url, $__in_sent_urls(), true ) && $__in_no_trash( $__in_sent_urls() ), 'indexnow: without the stored desired slug, the __trashed suffix is stripped to rebuild the old permalink' );

// Deleting it for good from the trash adds nothing: the engine already has
// the old URL from the trash.
$__in_requests = array();
wp_delete_post( $__in_trash_id, true );
$ok( null === get_post( $__in_trash_id ) && false === $__in_live->flush_queue() && 0 === count( $__in_requests ), 'indexnow: permanently deleting a post from the trash submits nothing more' );
$ok( array() === $__in_new_events( $__in_snap ), 'indexnow: the trash checks submitted inline schedule no cron event' );

remove_filter( 'perdita_indexnow_submit_inline', '__return_true' );
if ( '' === $__in_orig_permalinks ) {
	$GLOBALS['wp_rewrite']->set_permalink_structure( '' );
}

remove_filter( 'pre_http_request', $__in_stub, 10 );

/* ------------------------------------------------------------------ */
/* 7. The key file body                                                */
/* ------------------------------------------------------------------ */

ob_start();
$__in_instance->print_key_file();
$__in_body = ob_get_clean();
$ok( Perdita_IndexNow::key() === $__in_body, 'indexnow: the key file body is the bare key and nothing else' );

/* ------------------------------------------------------------------ */
/* Cleanup                                                             */
/* ------------------------------------------------------------------ */

foreach ( array( 'pre_post_update', 'transition_post_status', 'post_updated', 'save_post', 'before_delete_post', 'deleted_post', 'trashed_post' ) as $__in_hook ) {
	if ( empty( $GLOBALS['wp_filter'][ $__in_hook ] ) ) {
		continue;
	}
	foreach ( $GLOBALS['wp_filter'][ $__in_hook ]->callbacks as $__in_priority => $__in_callbacks ) {
		foreach ( $__in_callbacks as $__in_registered ) {
			$__in_fn = isset( $__in_registered['function'] ) ? $__in_registered['function'] : null;
			if ( is_array( $__in_fn ) && isset( $__in_fn[0] ) && $__in_fn[0] === $__in_live ) {
				remove_action( $__in_hook, $__in_fn, $__in_priority );
			}
		}
	}
}
$ok( false === has_action( 'save_post', array( $__in_live, 'on_save_post' ) ), 'indexnow: the hooked test instance is detached before the fixtures are deleted' );

wp_delete_post( $__in_draft_id, true );
wp_delete_post( $__in_post_id, true );
foreach ( array( $__in_rest_id, $__in_rest_draft_id, $__in_trash_id ) as $__in_rest_post ) {
	if ( $__in_rest_post && get_post( $__in_rest_post ) ) {
		wp_delete_post( $__in_rest_post, true );
	}
}
foreach ( array( $__in_parent_cat, $__in_rest_cat, $__in_rest_cat2 ) as $__in_term_id ) {
	if ( $__in_term_id ) {
		wp_delete_term( $__in_term_id, 'category' );
	}
}

foreach ( array_diff_key( $__in_cron_snapshot(), $__in_cron_before ) as $__in_leftover ) {
	wp_unschedule_event( $__in_leftover[0], Perdita_IndexNow::CRON_HOOK, $__in_leftover[1] );
}
$ok( array() === array_diff_key( $__in_cron_snapshot(), $__in_cron_before ), 'indexnow: the suite leaves no submission events of its own on the cron queue' );

delete_transient( Perdita_IndexNow::PING_TRANSIENT );
if ( false !== $__in_orig_ping ) {
	set_transient( Perdita_IndexNow::PING_TRANSIENT, $__in_orig_ping, Perdita_IndexNow::PING_THROTTLE );
}
if ( false === $__in_orig_option ) {
	delete_option( Perdita_IndexNow::OPTION );
} else {
	update_option( Perdita_IndexNow::OPTION, $__in_orig_option );
}
if ( false === $__in_orig_public ) {
	delete_option( 'blog_public' );
} else {
	update_option( 'blog_public', $__in_orig_public );
}
