<?php
/**
 * Perdita Core smoke tests: Switch to Perdita's Contact Form 7 source, the
 * [contact-form-7] fallback, and the catalog of plugins only Perdita Pro
 * imports from.
 *
 * The Contact Form 7 fixtures copy what Contact Form 7 6.2.5 writes: a
 * wpcf7_contact_form post with _form, _mail, _mail_2, _messages and a
 * 64-hex _hash (dumped from a real install on the release rig). Loaded by
 * tests/smoke.php. Everything it adds is removed at the end.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

echo "\n--- Switch to Perdita: Contact Form 7 and the Pro catalog (tests/smoke-switch-cf7.php) ---\n";

require_once PERDITA_CORE_DIR . 'inc/switch/class-perdita-switch-source.php';
require_once PERDITA_CORE_DIR . 'inc/switch/class-perdita-switch-target.php';
require_once PERDITA_CORE_DIR . 'inc/switch/class-perdita-switch-php-config.php';
require_once PERDITA_CORE_DIR . 'inc/switch/class-perdita-switch-backups.php';
require_once PERDITA_CORE_DIR . 'inc/switch/class-perdita-switch-leftovers.php';
require_once PERDITA_CORE_DIR . 'inc/switch/class-perdita-switch.php';
require_once PERDITA_CORE_DIR . 'inc/switch/class-perdita-switch-catalog.php';
require_once PERDITA_CORE_DIR . 'inc/switch/class-perdita-switch-admin.php';
require_once PERDITA_CORE_DIR . 'inc/class-perdita-forms.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
Perdita_Switch::sources( true ); // Loads the source classes.

$pdcf_missing = new stdClass();
$pdcf_saved   = array();
foreach ( array( 'perdita_switch_state', 'perdita_switch_snapshots', 'perdita_forms_cf7_map' ) as $pdcf_n ) {
	$pdcf_saved[ $pdcf_n ] = get_option( $pdcf_n, $pdcf_missing );
}
$pdcf_posts   = array();
$pdcf_max_id  = (int) $GLOBALS['wpdb']->get_var( "SELECT MAX(ID) FROM {$GLOBALS['wpdb']->posts}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$pdcf_cleanup = static function () use ( &$pdcf_posts, $pdcf_saved, $pdcf_missing, $pdcf_max_id ) {
	global $wpdb;
	foreach ( $pdcf_posts as $pid ) {
		wp_delete_post( $pid, true );
	}
	// Perdita forms this run's imports made carry the source mark.
	$made = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_perdita_switch_from' AND meta_value LIKE %s AND post_id > %d", 'contact-form-7:%', $pdcf_max_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	foreach ( $made as $pid ) {
		wp_delete_post( (int) $pid, true );
	}
	foreach ( $pdcf_saved as $n => $was ) {
		if ( $was === $pdcf_missing ) {
			delete_option( $n );
		} else {
			update_option( $n, $was );
		}
	}
};
register_shutdown_function( $pdcf_cleanup );

$pdcf_src = static function () {
	Perdita_Switch::sources( true );
	return Perdita_Switch::get( 'contact-form-7' );
};
$pdcf_cf7 = static function ( $title, $form, array $mail, array $mail_2, array $messages, $hash ) use ( &$pdcf_posts ) {
	$id = wp_insert_post(
		array(
			'post_type'   => 'wpcf7_contact_form',
			'post_status' => 'publish',
			'post_title'  => $title,
		)
	);
	update_post_meta( $id, '_form', $form );
	update_post_meta( $id, '_mail', $mail );
	update_post_meta( $id, '_mail_2', $mail_2 );
	update_post_meta( $id, '_messages', $messages );
	update_post_meta( $id, '_additional_settings', '' );
	update_post_meta( $id, '_locale', 'en_US' );
	add_post_meta( $id, '_hash', $hash, true );
	$pdcf_posts[] = $id;
	return $id;
};

/* ---------- the parser, against Contact Form 7's own default form ---------- */

$pdcf_default = "<label> Your name\n    [text* your-name autocomplete:name] </label>\n\n<label> Your email\n    [email* your-email autocomplete:email] </label>\n\n<label> Subject\n    [text* your-subject] </label>\n\n<label> Your message (optional)\n    [textarea your-message] </label>\n\n[submit \"Submit\"]";
$pdcf_tags    = Perdita_Switch_Source_Contact_Form_7::parse_form( $pdcf_default );
$ok( 5 === count( $pdcf_tags ) && 'your-name' === $pdcf_tags[0]['name'] && $pdcf_tags[0]['required'] && 'Your name' === $pdcf_tags[0]['label'], 'cf7 parse: the default form gives five tags, the first a required your-name labelled "Your name"' );
$ok( 'Your message' === $pdcf_tags[3]['label'] && ! $pdcf_tags[3]['required'] && 'submit' === $pdcf_tags[4]['basetype'] && '' === $pdcf_tags[4]['name'], 'cf7 parse: "(optional)" comes out of the label, and submit has no name' );
$ok( array( 'autocomplete:name' ) === $pdcf_tags[0]['options'], 'cf7 parse: options after the name stay options' );
$ok( 'Your email' === Perdita_Switch_Source_Contact_Form_7::label_from( '<label> Your email (required)' ), 'cf7 parse: "(required)" comes out of the label too, since Perdita marks required fields itself' );
$pdcf_tags = Perdita_Switch_Source_Contact_Form_7::parse_form( "Phone<br />\n[tel your-phone]\n[[text escaped]]\n[select* service first_as_label \"Pick one\" \"Design|design\" \"Hosting\"]\n[acceptance terms] I agree. [/acceptance]" );
$ok( 3 === count( $pdcf_tags ) && 'Phone' === $pdcf_tags[0]['label'], 'cf7 parse: a <br> line labels the tag under it, and [[text escaped]] is skipped as Contact Form 7 skips it' );
$ok( array( 'Pick one', 'Design|design', 'Hosting' ) === $pdcf_tags[1]['values'] && 'I agree.' === $pdcf_tags[2]['content'], 'cf7 parse: quoted values and an acceptance tag\'s text come through' );

/* ---------- one tag to one field ---------- */

$pdcf_f = Perdita_Switch_Source_Contact_Form_7::field_for( $pdcf_tags[1] );
$ok( 'import' === $pdcf_f['status'] && 'select' === $pdcf_f['field']['type'] && array( 'Design', 'Hosting' ) === $pdcf_f['field']['options'] && $pdcf_f['field']['required'], 'cf7 field: first_as_label drops the first choice and a pipe shows the label part' );
$pdcf_f = Perdita_Switch_Source_Contact_Form_7::field_for( $pdcf_tags[2] );
$ok( 'checkbox' === $pdcf_f['field']['type'] && array( 'I agree.' ) === $pdcf_f['field']['options'] && $pdcf_f['field']['required'], 'cf7 field: acceptance becomes a required one-choice checkbox' );
$pdcf_one = static function ( $src ) {
	$t = Perdita_Switch_Source_Contact_Form_7::parse_form( $src );
	return Perdita_Switch_Source_Contact_Form_7::field_for( $t[0] );
};
$ok( 'Jane' === $pdcf_one( 'Name [text n placeholder "Jane"]' )['field']['placeholder'], 'cf7 field: a placeholder value becomes the placeholder' );
$ok( '' === $pdcf_one( 'Name [text n "Jane"]' )['field']['placeholder'], 'cf7 field: a plain value is a default, not a placeholder, and stays out' );
$ok( 'checkbox' === $pdcf_one( '[select n multiple "A" "B"]' )['field']['type'], 'cf7 field: a multiple select becomes checkboxes' );
$ok( 'number' === $pdcf_one( '[range r min:1 max:9]' )['field']['type'] && $pdcf_one( '[radio r "A" "B"]' )['field']['required'], 'cf7 field: range is a number, and a radio group is always required' );
$ok( 'none' === $pdcf_one( '[file f]' )['status'] && 'none' === $pdcf_one( '[quiz q "1+1?|2"]' )['status'] && 'none' === $pdcf_one( '[hidden h "x"]' )['status'], 'cf7 field: file, quiz and hidden have no match' );
$ok( 'skip' === $pdcf_one( '[select n "A, B" "C"]' )['status'], 'cf7 field: a choice with a comma is refused, since the editor splits choices at commas' );

/* ---------- mail-tags ---------- */

$pdcf_m = Perdita_Switch_Source_Contact_Form_7::mail_text( "From: [your-name] <[your-email]>\n[other] -- [_site_title]", array( 'your-name', 'your-email' ), true );
$ok( "From: {your-name} {your-email}\n -- " . get_bloginfo( 'name' ) === $pdcf_m['text'] && array( '[other]' ) === $pdcf_m['dropped'], 'cf7 mail: field tags become {field} in the body, "<[email]>" keeps the address, the site title fills in, and an unknown tag drops out' );
$pdcf_m = Perdita_Switch_Source_Contact_Form_7::mail_text( '[_site_title] "[your-subject]"', array( 'your-subject' ), false );
$ok( get_bloginfo( 'name' ) === $pdcf_m['text'] && array( '[your-subject]' ) === $pdcf_m['dropped'], 'cf7 mail: a subject loses what a visitor typed, and the empty quotes go with it' );
$pdcf_m = Perdita_Switch_Source_Contact_Form_7::mail_text( 'sales@example.com, [_site_admin_email]', array(), false );
$ok( 'sales@example.com, ' . get_option( 'admin_email' ) === $pdcf_m['text'], 'cf7 mail: [_site_admin_email] becomes the address' );

/* ---------- plan, import, undo, on fixtures in Contact Form 7's shape ---------- */

$pdcf_mail   = array(
	'subject'            => '[_site_title] "[your-subject]"',
	'sender'             => '[_site_title] <wordpress@example.test>',
	'body'               => "From: [your-name] <[your-email]>\nService: [service]\nBrief: [brief]\n-- [_site_title]",
	// [brief] is a file field that doesn't come over, so its tag drops out
	// and the recipient list must not end on a stray comma.
	'recipient'          => 'sales@example.com, [_site_admin_email], [brief]',
	'additional_headers' => 'Reply-To: [your-email]',
	'attachments'        => '',
	'use_html'           => 0,
	'exclude_blank'      => 0,
);
$pdcf_mail_2 = array_merge( $pdcf_mail, array( 'active' => true, 'recipient' => '[your-email]' ) );
$pdcf_msgs   = array(
	'mail_sent_ok'     => 'Thanks, we got your request.',
	'validation_error' => 'One or more fields have an error. Please check and try again.',
);
$pdcf_form   = "<label> Your name\n    [text* your-name placeholder \"Jane Doe\"] </label>\n\n<label> Email\n    [email* your-email] </label>\n\n<label> Service\n    [select* service first_as_label \"Pick one\" \"Design|design\" \"Hosting\"] </label>\n\n<label> Brief [file brief limit:1mb filetypes:pdf] </label>\n\n[acceptance terms] I agree to the terms. [/acceptance]\n\n[submit \"Send it\"]";
$pdcf_hash   = 'c0ffee79e7ab5c86c4d55face069781c30ac4fdba7a4cf5c54ebf2e0cf7ac19a';
$pdcf_id     = $pdcf_cf7( 'Fixture quote', $pdcf_form, $pdcf_mail, $pdcf_mail_2, $pdcf_msgs, $pdcf_hash );
$pdcf_before = array( get_post_meta( $pdcf_id, '_form', true ), get_post_meta( $pdcf_id, '_mail', true ), get_post_field( 'post_modified_gmt', $pdcf_id ) );

delete_option( 'perdita_forms_cf7_map' );
$pdcf_all = get_option( 'perdita_switch_snapshots', array() );
unset( $pdcf_all['contact-form-7'] );
update_option( 'perdita_switch_snapshots', $pdcf_all, false );

$pdcf_s = $pdcf_src();
$ok( $pdcf_s instanceof Perdita_Switch_Source_Contact_Form_7 && 'forms' === $pdcf_s->module() && $pdcf_s->has_settings(), 'cf7: the source is registered, targets Perdita Forms, and sees the form' );
$pdcf_plan = $pdcf_s->plan();
$pdcf_row  = isset( $pdcf_plan['patch']['forms'][ $pdcf_id ] ) ? $pdcf_plan['patch']['forms'][ $pdcf_id ] : null;
$ok( $pdcf_row && 4 === count( $pdcf_row['cfg']['fields'] ) && $pdcf_hash === $pdcf_row['hash'], 'cf7 plan: name, email, service and the consent box import, and the hash comes along' );
$ok( $pdcf_row && Perdita_Forms::sanitize_config( $pdcf_row['cfg'] ) === $pdcf_row['cfg'], 'cf7 plan: the planned form is exactly what the form editor\'s rules store' );
$pdcf_none = array_filter(
	$pdcf_plan['rows'],
	static function ( $r ) {
		return 'none' === $r['status'];
	}
);
$pdcf_none_txt = implode( ' | ', wp_list_pluck( $pdcf_none, 'setting' ) );
$ok( false !== strpos( $pdcf_none_txt, '[file brief]' ) && false !== strpos( $pdcf_none_txt, 'Mail (2)' ) && false !== strpos( $pdcf_none_txt, 'Mail From' ), 'cf7 plan: the file field, the second mail and the sender show as no match' );
$ok( $pdcf_row && 'sales@example.com, ' . get_option( 'admin_email' ) === $pdcf_row['cfg']['email']['to'] && "From: {your-name} {your-email}\nService: {service}\nBrief: \n-- " . get_bloginfo( 'name' ) === $pdcf_row['cfg']['email']['body'], 'cf7 plan: recipient and body come across with Perdita\'s {field} tags' );
$ok( $pdcf_row && 'Thanks, we got your request.' === $pdcf_row['cfg']['messages']['success'], 'cf7 plan: the sent message comes across' );
$ok( '' !== $pdcf_s->pro_note() && '' !== $pdcf_s->conflict_note(), 'cf7: the card names what Pro adds and what happens while both run' );

$pdcf_res = Perdita_Switch::import( 'contact-form-7' );
$pdcf_map = Perdita_Forms::cf7_map();
$pdcf_new = isset( $pdcf_map[ $pdcf_id ]['id'] ) ? (int) $pdcf_map[ $pdcf_id ]['id'] : 0;
$ok( $pdcf_res['ok'] && $pdcf_new && 'perdita_form' === get_post_type( $pdcf_new ) && 'publish' === get_post_status( $pdcf_new ) && 'Fixture quote' === get_the_title( $pdcf_new ), 'cf7 import: a published Perdita form with the same title' );
$pdcf_cfg = get_post_meta( $pdcf_new, Perdita_Forms::META, true );
$ok( is_array( $pdcf_cfg ) && $pdcf_cfg === $pdcf_row['cfg'], 'cf7 import: the stored form is the previewed one' );
$ok( array( get_post_meta( $pdcf_id, '_form', true ), get_post_meta( $pdcf_id, '_mail', true ), get_post_field( 'post_modified_gmt', $pdcf_id ) ) === $pdcf_before, 'cf7 import: Contact Form 7\'s own form is untouched' );
$pdcf_again = $pdcf_src()->plan();
$ok( empty( $pdcf_again['patch'] ), 'cf7 import: a second import makes no second copy' );
$ok( false === Perdita_Switch::import( 'contact-form-7' )['ok'], 'cf7 import: import again says there\'s nothing new' );

/* ---------- the [contact-form-7] fallback ---------- */

$pdcf_h7 = substr( $pdcf_hash, 0, 7 );
$ok( $pdcf_new === Perdita_Forms::cf7_target( array( 'id' => $pdcf_h7 ), $pdcf_map ), 'cf7 fallback: the 7-character hash the block saves finds the form' );
$ok( $pdcf_new === Perdita_Forms::cf7_target( array( 'id' => (string) $pdcf_id ), $pdcf_map ), 'cf7 fallback: the old numeric id finds the form' );
$ok( $pdcf_new === Perdita_Forms::cf7_target( array( 'id' => 'ffffff1', 'title' => 'Fixture quote' ), $pdcf_map ), 'cf7 fallback: the title finds the form when the id doesn\'t' );
$ok( 0 === Perdita_Forms::cf7_target( array( 'id' => 'abc' ), $pdcf_map ) && 0 === Perdita_Forms::cf7_target( 'junk', $pdcf_map ), 'cf7 fallback: an unknown id finds nothing' );
$pdcf_forms = new Perdita_Forms();
$pdcf_had   = shortcode_exists( 'contact-form-7' ) ? $GLOBALS['shortcode_tags']['contact-form-7'] : null;
add_shortcode( 'contact-form-7', '__return_empty_string' );
$pdcf_forms->register_cf7_fallback();
$ok( '__return_empty_string' === $GLOBALS['shortcode_tags']['contact-form-7'], 'cf7 fallback: while Contact Form 7 owns the shortcode, Perdita leaves it alone' );
remove_shortcode( 'contact-form-7' );
$pdcf_forms->register_cf7_fallback();
$pdcf_tags = array();
$pdcf_spy  = function ( $output, $tag ) use ( &$pdcf_tags ) {
	$pdcf_tags[] = $tag;
	return $output;
};
add_filter( 'do_shortcode_tag', $pdcf_spy, 10, 2 );
$pdcf_html = do_shortcode( '<div class="wp-block-contact-form-7-contact-form-selector">[contact-form-7 id="' . $pdcf_h7 . '" title="Fixture quote"]</div>' );
remove_filter( 'do_shortcode_tag', $pdcf_spy, 10 );
$ok( in_array( 'perdita_form', $pdcf_tags, true ), 'cf7 fallback: the old shortcode renders through [perdita_form], so Forms Pro and spam filters see it' );
$ok( false !== strpos( $pdcf_html, 'data-form="' . $pdcf_new . '"' ) && false !== strpos( $pdcf_html, 'name="fields[service]"' ), 'cf7 fallback: with Contact Form 7 off, its block markup renders the Perdita form' );
remove_shortcode( 'contact-form-7' );
if ( $pdcf_had ) {
	add_shortcode( 'contact-form-7', $pdcf_had );
}

// Two imported forms on one page, and one of them twice: every HTML id is
// unique, and each label points at its own form's field.
$pdcf_two  = wp_insert_post( array( 'post_type' => 'perdita_form', 'post_status' => 'publish', 'post_title' => 'pdcf second' ) );
update_post_meta( $pdcf_two, '_perdita_form', get_post_meta( $pdcf_new, '_perdita_form', true ) );
$pdcf_page = do_shortcode( '[perdita_form id="' . $pdcf_new . '"][perdita_form id="' . $pdcf_two . '"][perdita_form id="' . $pdcf_new . '"]' );
preg_match_all( '/\sid="([^"]+)"/', $pdcf_page, $pdcf_ids );
preg_match_all( '/<label for="([^"]+)"/', $pdcf_page, $pdcf_fors );
$ok( 3 === substr_count( $pdcf_page, '<form ' ) && count( $pdcf_ids[1] ) > 3 && count( $pdcf_ids[1] ) === count( array_unique( $pdcf_ids[1] ) ), 'cf7 forms: three forms on one page share no HTML id' );
preg_match_all( '/aria-labelledby="([^"]+)"/', $pdcf_page, $pdcf_lbl );
$ok( $pdcf_fors[1] && ! array_diff( $pdcf_fors[1], $pdcf_ids[1] ) && ! array_diff( $pdcf_lbl[1], $pdcf_ids[1] ), 'cf7 forms: every label points at a field or a choice group on the page' );
$ok( 3 === substr_count( $pdcf_page, 'name="perdita_nonce"' ) + substr_count( $pdcf_page, 'name="perdita_pro_nonce"' ) && ! preg_match( '/id="perdita_(pro_)?nonce"/', $pdcf_page ), 'cf7 forms: each form keeps its nonce field, with no shared id' );
wp_delete_post( $pdcf_two, true );

/* ---------- undo ---------- */

$pdcf_undo = Perdita_Switch::undo( 'contact-form-7' );
$ok( $pdcf_undo['ok'] && 'trash' === get_post_status( $pdcf_new ) && false === get_option( 'perdita_forms_cf7_map', false ), 'cf7 undo: the imported form goes to the trash and the map goes back to missing' );
$ok( 'publish' === get_post_status( $pdcf_id ), 'cf7 undo: Contact Form 7\'s form stays published' );

/* ---------- the Pro catalog ---------- */

$pdcf_cat    = Perdita_Switch_Catalog::pro_sources();
$pdcf_shapes = true;
foreach ( $pdcf_cat as $pdcf_cid => $pdcf_e ) {
	if ( sanitize_key( $pdcf_cid ) !== $pdcf_cid || '' === $pdcf_e['label'] || ! $pdcf_e['plugins'] || '' === $pdcf_e['covers'] || ! isset( Perdita_Switch_Catalog::module_labels()[ $pdcf_e['module'] ] ) ) {
		$pdcf_shapes = false;
	}
}
$ok( 31 === count( $pdcf_cat ) && $pdcf_shapes, 'catalog: 31 Pro-only sources, each with a label, plugins, a Pro module and what Pro brings over' );
$pdcf_core_ids = array();
foreach ( Perdita_Switch::sources( true ) as $pdcf_sid => $pdcf_sobj ) {
	if ( 0 === strpos( get_class( $pdcf_sobj ), 'Perdita_Switch_Source_' ) ) {
		$pdcf_core_ids[ $pdcf_sid ] = true;
	}
}
$ok( ! array_intersect_key( $pdcf_cat, $pdcf_core_ids ), 'catalog: no Pro-only id collides with a Core source' );
$pdcf_fake = (array) get_plugins();
$pdcf_fake['wp-rocket/wp-rocket.php'] = array( 'Name' => 'WP Rocket' );
wp_cache_set( 'plugins', array( '' => $pdcf_fake ), 'plugins' );
$pdcf_miss = Perdita_Switch_Catalog::missing( array() );
$ok( $pdcf_miss['wp-rocket']['installed'] && ! $pdcf_miss['wp-rocket']['active'] && ! $pdcf_miss['ewww']['installed'], 'catalog: an installed Pro-only plugin shows as installed, a missing one doesn\'t' );
$pdcf_reg = Perdita_Switch::sources( true );
$pdcf_reg['wp-rocket'] = reset( $pdcf_reg );
$ok( ! isset( Perdita_Switch_Catalog::missing( $pdcf_reg )['wp-rocket'] ), 'catalog: once Pro registers a source, its catalog entry steps aside' );
$pdcf_act = Perdita_Switch_Catalog::pro_action( 'missing' );
$ok( '' !== $pdcf_act['url'] && Perdita_Switch_Catalog::pro_action( 'unlicensed' )['url'] === admin_url( 'admin.php?page=perdita-pro' ), 'catalog: each Pro state has its own next step' );

// The screen: a locked card for the installed Pro-only plugin, the rest named below.
$pdcf_user = get_current_user_id();
wp_set_current_user( 1 );
ob_start();
( new Perdita_Switch_Admin() )->page();
$pdcf_page = ob_get_clean();
wp_set_current_user( $pdcf_user );
$ok( false !== strpos( $pdcf_page, 'class="pds-card pds-locked" id="pds-wp-rocket"' ) && false !== strpos( $pdcf_page, 'Speed (Pro)' ), 'screen: an installed Pro-only plugin gets a locked card under its Pro heading' );
$ok( false === strpos( $pdcf_page, 'id="pds-ewww"' ) && false !== strpos( $pdcf_page, 'With Perdita Pro, you can also switch from' ) && false !== strpos( $pdcf_page, 'EWWW Image Optimizer' ), 'screen: a Pro-only plugin you don\'t have is named on the list instead' );
$ok( false === strpos( substr( $pdcf_page, (int) strpos( $pdcf_page, 'id="pds-wp-rocket"' ), 600 ), '<form' ), 'screen: a locked card has no import button' );
wp_cache_delete( 'plugins', 'plugins' );

/* ---------- restore ---------- */

$pdcf_cleanup();
$pdcf_same = true;
foreach ( $pdcf_saved as $pdcf_n => $pdcf_was ) {
	if ( get_option( $pdcf_n, $pdcf_missing ) !== $pdcf_was ) {
		$pdcf_same = false;
		echo "      changed: $pdcf_n\n";
	}
}
$ok( $pdcf_same, 'cf7: every option is back the way it was' );
