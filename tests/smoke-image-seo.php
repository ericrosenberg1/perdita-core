<?php
/**
 * Image SEO module checks.
 *
 * A fragment of the smoke suite: tests/smoke.php requires every
 * tests/smoke-*.php in its own scope right before printing the summary, so
 * $ok() and the $pass/$fail counters below are the ones defined there.
 *
 * The batch filler writes to real attachments, so it is only run when the
 * library has nothing missing alt text of its own. On a site that does, the
 * pieces the batch is built from are still asserted against this file's own
 * fixtures, and the batch itself reports SKIPPED rather than quietly editing
 * somebody's media library.
 *
 * Everything is restored: the settings option, the sitemap transient, and
 * every temporary post and attachment.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

require_once PERDITA_CORE_DIR . 'inc/modules/image-seo/class-perdita-image-seo.php';

// On a site that already has this module switched on, a live instance is
// listening to add_attachment and would fill in the alt text of the fixtures
// below before they were ever asserted on. Unhook it for the run, so the
// assertions are about the instance this file drives on purpose.
foreach ( array( 'add_attachment', 'template_redirect', 'robots_txt', 'save_post', 'deleted_post' ) as $__is_hook ) {
	if ( empty( $GLOBALS['wp_filter'][ $__is_hook ] ) ) {
		continue;
	}
	foreach ( $GLOBALS['wp_filter'][ $__is_hook ]->callbacks as $__is_priority => $__is_callbacks ) {
		foreach ( $__is_callbacks as $__is_registered ) {
			$__is_fn = isset( $__is_registered['function'] ) ? $__is_registered['function'] : null;
			if ( is_array( $__is_fn ) && isset( $__is_fn[0] ) && is_object( $__is_fn[0] ) && $__is_fn[0] instanceof Perdita_Image_SEO ) {
				remove_filter( $__is_hook, $__is_fn, $__is_priority );
			}
		}
	}
}

$__is_orig_option  = get_option( Perdita_Image_SEO::OPTION );
$__is_orig_sitemap = get_transient( Perdita_Image_SEO::SITEMAP_TRANSIENT );
delete_option( Perdita_Image_SEO::OPTION );
delete_transient( Perdita_Image_SEO::SITEMAP_TRANSIENT );

/* ------------------------------------------------------------------ */
/* 1. Descriptor                                                       */
/* ------------------------------------------------------------------ */

$__is_desc = include PERDITA_CORE_DIR . 'inc/modules/image-seo/module.php';
$ok( is_array( $__is_desc ) && 'image-seo' === $__is_desc['id'], 'image seo: module.php returns a descriptor with id image-seo' );
$ok( 'seo' === $__is_desc['group'] && false === $__is_desc['default'], 'image seo: it lands in the seo group and is off until it is switched on, because it writes to the media library' );
$ok(
	array() === array_diff( array( 'admin', 'rest', 'front' ), (array) $__is_desc['contexts'] ),
	'image seo: rest is in the contexts, because a block editor upload never touches is_admin()'
);

/* ------------------------------------------------------------------ */
/* 2. Filename to words                                                */
/* ------------------------------------------------------------------ */

$ok( 'red barn at sunset' === Perdita_Image_SEO::words_from_filename( 'red-barn_at-sunset.jpg' ), 'image seo: dashes and underscores come back as spaces and the extension goes' );
$ok( 'red barn' === Perdita_Image_SEO::words_from_filename( '2024/07/red-barn-1024.jpg' ), 'image seo: the upload directory and a digit-only size segment are both dropped' );
$ok( 'DSC' === Perdita_Image_SEO::words_from_filename( 'DSC_0421.JPG' ), 'image seo: a camera counter is dropped, upper case extension and all' );
$ok( '20240711 1200' === Perdita_Image_SEO::words_from_filename( '20240711-1200.jpg' ), 'image seo: a name that is nothing but digits falls back to the digits rather than to an empty alt' );
$ok( 'photo' === Perdita_Image_SEO::words_from_filename( 'photo.png' ), 'image seo: a one-word name is left alone' );
$ok( '' === Perdita_Image_SEO::words_from_filename( '' ), 'image seo: an empty name produces an empty string, not a warning' );

/* ------------------------------------------------------------------ */
/* 3. Fixtures                                                         */
/* ------------------------------------------------------------------ */

$__is_post_id = wp_insert_post(
	array(
		'post_title'   => 'Perdita Image SEO Smoke Post',
		'post_content' => '<p>Before</p><img src="' . esc_url( home_url( '/wp-content/uploads/perdita-in-content.jpg' ) ) . '" alt="" /><p>After</p>',
		'post_status'  => 'publish',
		'post_type'    => 'post',
	)
);

$__is_attach_id = wp_insert_attachment(
	array(
		'post_title'     => 'perdita-red-barn-1024',
		'post_status'    => 'inherit',
		'post_type'      => 'attachment',
		'post_mime_type' => 'image/jpeg',
	),
	false,
	$__is_post_id
);
update_post_meta( $__is_attach_id, '_wp_attached_file', '2026/09/perdita-red-barn-1024.jpg' );

$__is_pdf_id = wp_insert_attachment(
	array(
		'post_title'     => 'perdita-brochure',
		'post_status'    => 'inherit',
		'post_type'      => 'attachment',
		'post_mime_type' => 'application/pdf',
	),
	false,
	$__is_post_id
);
update_post_meta( $__is_pdf_id, '_wp_attached_file', '2026/09/perdita-brochure.pdf' );

$ok( Perdita_Image_SEO::is_image( $__is_attach_id ), 'image seo: an image attachment is recognized' );
$ok( ! Perdita_Image_SEO::is_image( $__is_pdf_id ) && ! Perdita_Image_SEO::is_image( $__is_post_id ), 'image seo: a PDF and an ordinary post are not' );

/* ------------------------------------------------------------------ */
/* 4. Alt text from the template                                       */
/* ------------------------------------------------------------------ */

$ok( 'perdita red barn' === Perdita_Image_SEO::generate_alt( $__is_attach_id ), 'image seo: the default %filename% template turns the stored file path into words' );
$ok( Perdita_Image_SEO::alt_is_missing( $__is_attach_id ), 'image seo: a fresh attachment has no alt text' );
$ok( 'perdita red barn' === Perdita_Image_SEO::fill_alt( $__is_attach_id ), 'image seo: fill_alt() writes the generated text' );
$ok( 'perdita red barn' === get_post_meta( $__is_attach_id, Perdita_Image_SEO::ALT_META, true ), 'image seo: it lands in _wp_attachment_image_alt, the key WordPress itself reads' );
$ok( ! Perdita_Image_SEO::alt_is_missing( $__is_attach_id ), 'image seo: and the attachment stops counting as missing' );
$ok( '' === Perdita_Image_SEO::fill_alt( $__is_attach_id ), 'image seo: fill_alt() never overwrites alt text that is already there' );
$ok( '' === Perdita_Image_SEO::fill_alt( $__is_pdf_id ), 'image seo: fill_alt() leaves anything that is not an image alone' );

update_post_meta( $__is_attach_id, Perdita_Image_SEO::ALT_META, '' );
$ok( 'perdita red barn' === Perdita_Image_SEO::fill_alt( $__is_attach_id ), 'image seo: an alt attribute stored as an empty string still counts as missing' );

$ok( true === Perdita_Image_SEO::defaults()['strip_punctuation'] && false === Perdita_Image_SEO::defaults()['lowercase'], 'image seo: punctuation is stripped out of the box, case is left alone' );

update_option( Perdita_Image_SEO::OPTION, Perdita_Image_SEO::sanitize( array( 'alt_format' => '%post_title% :: %sitename%', 'strip_punctuation' => true ) ) );
$__is_vars = Perdita_Image_SEO::generate_alt( $__is_attach_id );
$ok( 0 === strpos( $__is_vars, 'Perdita Image SEO Smoke Post' ), 'image seo: %post_title% resolves to the post the image is attached to' );
$ok( false === strpos( $__is_vars, '::' ) && false === strpos( $__is_vars, '  ' ), 'image seo: strip_punctuation removes the punctuation and the leftover spaces collapse' );

update_option( Perdita_Image_SEO::OPTION, Perdita_Image_SEO::sanitize( array( 'alt_format' => '%sitename%', 'strip_punctuation' => false ) ) );
$ok( wp_strip_all_tags( (string) get_bloginfo( 'name' ) ) === Perdita_Image_SEO::generate_alt( $__is_attach_id ), 'image seo: %sitename% resolves to the site title, left intact with punctuation stripping off' );

update_option( Perdita_Image_SEO::OPTION, Perdita_Image_SEO::sanitize( array( 'alt_format' => '%filename%', 'strip_punctuation' => false, 'lowercase' => true ) ) );
$ok( 'DSC' === Perdita_Image_SEO::words_from_filename( 'DSC_0421.JPG' ), 'image seo: words_from_filename() is pure and never applies the case option itself' );
update_post_meta( $__is_attach_id, '_wp_attached_file', '2026/09/Perdita-RED-Barn.jpg' );
$ok( 'perdita red barn' === Perdita_Image_SEO::generate_alt( $__is_attach_id ), 'image seo: the lowercase option applies to the finished text' );
update_post_meta( $__is_attach_id, '_wp_attached_file', '2026/09/perdita-red-barn-1024.jpg' );

delete_option( Perdita_Image_SEO::OPTION );

/* ------------------------------------------------------------------ */
/* 5. Upload hook and the title rule                                   */
/* ------------------------------------------------------------------ */

$__is_instance = new Perdita_Image_SEO( perdita_core() );
// Built to drive its handlers directly, so unhook it again rather than
// leaving a second set of upload and sitemap hooks behind for the rest of
// the suite.
remove_action( 'add_attachment', array( $__is_instance, 'on_add_attachment' ) );
remove_action( 'template_redirect', array( $__is_instance, 'maybe_serve_sitemap' ), 0 );
remove_filter( 'robots_txt', array( $__is_instance, 'robots_txt' ), 10 );
remove_action( 'save_post', array( $__is_instance, 'flush_sitemap' ) );
remove_action( 'deleted_post', array( $__is_instance, 'flush_sitemap' ) );

$__is_upload_id = wp_insert_attachment(
	array(
		'post_title'     => 'perdita-blue-door',
		'post_status'    => 'inherit',
		'post_type'      => 'attachment',
		'post_mime_type' => 'image/png',
	),
	false,
	$__is_post_id
);
update_post_meta( $__is_upload_id, '_wp_attached_file', '2026/09/perdita-blue-door.png' );

update_option( Perdita_Image_SEO::OPTION, Perdita_Image_SEO::sanitize( array( 'auto_alt' => true, 'auto_title' => true, 'title_format' => '%filename%' ) ) );
$__is_instance->on_add_attachment( $__is_upload_id );
$ok( 'perdita blue door' === get_post_meta( $__is_upload_id, Perdita_Image_SEO::ALT_META, true ), 'image seo: an upload with no alt gets one from the filename' );
$ok( 'perdita blue door' === get_post_field( 'post_title', $__is_upload_id ), 'image seo: the media title is rewritten while it is still the raw filename' );

wp_update_post( array( 'ID' => $__is_upload_id, 'post_title' => 'A title somebody actually wrote' ) );
delete_post_meta( $__is_upload_id, Perdita_Image_SEO::ALT_META );
$__is_instance->on_add_attachment( $__is_upload_id );
$ok( 'A title somebody actually wrote' === get_post_field( 'post_title', $__is_upload_id ), 'image seo: a title a person wrote is never overwritten' );
$ok( '' !== get_post_meta( $__is_upload_id, Perdita_Image_SEO::ALT_META, true ), 'image seo: the alt text is still filled in even when the title is left alone' );

update_option( Perdita_Image_SEO::OPTION, Perdita_Image_SEO::sanitize( array( 'auto_alt' => false, 'auto_title' => false ) ) );
delete_post_meta( $__is_upload_id, Perdita_Image_SEO::ALT_META );
$__is_instance->on_add_attachment( $__is_upload_id );
$ok( '' === get_post_meta( $__is_upload_id, Perdita_Image_SEO::ALT_META, true ), 'image seo: with auto alt switched off an upload is left exactly as it came in' );
delete_option( Perdita_Image_SEO::OPTION );

/* ------------------------------------------------------------------ */
/* 6. The batch, only where it cannot touch anybody else's media       */
/* ------------------------------------------------------------------ */

delete_post_meta( $__is_attach_id, Perdita_Image_SEO::ALT_META );
delete_post_meta( $__is_upload_id, Perdita_Image_SEO::ALT_META );

$__is_missing_ids = Perdita_Image_SEO::ids_missing_alt( Perdita_Image_SEO::BATCH_SIZE );
$ok( in_array( (int) $__is_attach_id, $__is_missing_ids, true ), 'image seo: ids_missing_alt() finds an image with no alt text' );
$ok( ! in_array( (int) $__is_pdf_id, $__is_missing_ids, true ), 'image seo: it never returns anything that is not an image' );
$ok( Perdita_Image_SEO::count_missing_alt() >= 2, 'image seo: count_missing_alt() counts them' );

if ( 2 === Perdita_Image_SEO::count_missing_alt() ) {
	$__is_batch = Perdita_Image_SEO::fill_missing_alt( Perdita_Image_SEO::BATCH_SIZE );
	$ok( 2 === $__is_batch['filled'] && 0 === $__is_batch['remaining'], 'image seo: fill_missing_alt() fills the images with no alt text and reports what is left' );
	$ok( '' !== get_post_meta( $__is_attach_id, Perdita_Image_SEO::ALT_META, true ), 'image seo: the batch wrote real alt text, not a placeholder' );
} else {
	Perdita_Image_SEO::fill_alt( $__is_attach_id );
	Perdita_Image_SEO::fill_alt( $__is_upload_id );
	$ok( true, 'image seo: fill_missing_alt() SKIPPED (this library already has images with no alt text, and the batch writes to real attachments)' );
	$ok( '' !== get_post_meta( $__is_attach_id, Perdita_Image_SEO::ALT_META, true ), 'image seo: fill_alt(), the step the batch is built from, still wrote real alt text' );
}
$ok( 100 === Perdita_Image_SEO::BATCH_SIZE, 'image seo: the batch does 100 per round, so one admin-post request never runs long' );

/* ------------------------------------------------------------------ */
/* 7. Image sitemap                                                    */
/* ------------------------------------------------------------------ */

$__is_base = rtrim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
$ok( Perdita_Image_SEO::sitemap_requested( $__is_base . '/image-sitemap.xml' ), 'image seo: sitemap_requested() matches the sitemap path' );
$ok( Perdita_Image_SEO::sitemap_requested( $__is_base . '/image-sitemap.xml?v=2' ), 'image seo: a query string does not stop it being served' );
$ok( ! Perdita_Image_SEO::sitemap_requested( $__is_base . '/sitemap.xml' ), 'image seo: the core sitemap is left alone' );

$__is_srcs = Perdita_Image_SEO::img_srcs(
	'<img src="' . esc_url( home_url( '/wp-content/uploads/a.jpg' ) ) . '" />'
	. '<img src="/wp-content/uploads/b.jpg" />'
	. '<img src="https://cdn.example.com/c.jpg" />'
	. '<img src="data:image/gif;base64,R0lGOD" />'
	. '<img src="' . esc_url( home_url( '/wp-content/uploads/a.jpg' ) ) . '" />'
);
$ok( 3 === count( $__is_srcs ), 'image seo: img_srcs() keeps absolute, root-relative and off-site images, drops a data URI, and never lists the same src twice' );
$ok( in_array( home_url( '/wp-content/uploads/b.jpg' ), $__is_srcs, true ), 'image seo: a root-relative src is resolved against the site url' );
$ok( array() === Perdita_Image_SEO::img_srcs( '<p>no pictures here</p>' ), 'image seo: content with no images costs no regex pass' );

$__is_images = Perdita_Image_SEO::images_for_post( get_post( $__is_post_id ) );
$ok( 1 === count( $__is_images ) && home_url( '/wp-content/uploads/perdita-in-content.jpg' ) === $__is_images[0]['url'], 'image seo: images_for_post() picks up an in-content image' );

set_post_thumbnail( $__is_post_id, $__is_attach_id );
$__is_images = Perdita_Image_SEO::images_for_post( get_post( $__is_post_id ) );
$ok( 2 === count( $__is_images ), 'image seo: the featured image is listed alongside the in-content ones' );

$__is_xml = Perdita_Image_SEO::build_sitemap_xml();
$ok( 0 === strpos( $__is_xml, '<?xml version="1.0" encoding="UTF-8"?>' ), 'image seo: the sitemap is a real XML document' );
$ok( false !== strpos( $__is_xml, 'http://www.google.com/schemas/sitemap-image/1.1' ), 'image seo: it declares the image namespace' );
$ok( false !== strpos( $__is_xml, '<image:loc>' ) && false !== strpos( $__is_xml, '</urlset>' ), 'image seo: it carries image entries and closes properly' );
$ok( false !== strpos( $__is_xml, esc_url( (string) get_permalink( $__is_post_id ) ) ), 'image seo: the fixture post with images is in it' );
$ok( false === strpos( $__is_xml, '<loc>' . esc_url( (string) get_permalink( $__is_pdf_id ) ) . '</loc>' ), 'image seo: attachment pages are not listed as pages of their own' );

delete_transient( Perdita_Image_SEO::SITEMAP_TRANSIENT );
$__is_cached = Perdita_Image_SEO::sitemap_xml();
$ok( is_string( get_transient( Perdita_Image_SEO::SITEMAP_TRANSIENT ) ), 'image seo: rendering the sitemap warms the 12 hour transient' );
$ok( $__is_cached === Perdita_Image_SEO::sitemap_xml(), 'image seo: the second read comes back identical' );
$__is_instance->flush_sitemap();
$ok( false === get_transient( Perdita_Image_SEO::SITEMAP_TRANSIENT ), 'image seo: saving a post drops the cached sitemap' );

$__is_robots = $__is_instance->robots_txt( "User-agent: *\nDisallow:\n", true );
$ok( false !== strpos( $__is_robots, 'Sitemap: ' . Perdita_Image_SEO::sitemap_url() ), 'image seo: the image sitemap is announced in robots.txt' );
$ok( $__is_robots === $__is_instance->robots_txt( $__is_robots, true ), 'image seo: it is only added once, however many times the filter runs' );
$ok( "x\n" === $__is_instance->robots_txt( "x\n", false ), 'image seo: nothing is announced while the site discourages search engines' );

/* ------------------------------------------------------------------ */
/* Cleanup                                                             */
/* ------------------------------------------------------------------ */

delete_post_thumbnail( $__is_post_id );
wp_delete_attachment( $__is_upload_id, true );
wp_delete_attachment( $__is_pdf_id, true );
wp_delete_attachment( $__is_attach_id, true );
wp_delete_post( $__is_post_id, true );

delete_transient( Perdita_Image_SEO::SITEMAP_TRANSIENT );
if ( is_string( $__is_orig_sitemap ) ) {
	set_transient( Perdita_Image_SEO::SITEMAP_TRANSIENT, $__is_orig_sitemap, Perdita_Image_SEO::SITEMAP_TTL );
}
if ( false === $__is_orig_option ) {
	delete_option( Perdita_Image_SEO::OPTION );
} else {
	update_option( Perdita_Image_SEO::OPTION, $__is_orig_option );
}
