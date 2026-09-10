<?php
/**
 * WP-CLI commands for IndexNow.
 *
 * Loaded only under WP-CLI, from the main module class constructor, so an
 * ordinary request never parses it.
 *
 *   wp perdita indexnow key
 *   wp perdita indexnow key --regenerate
 *   wp perdita indexnow submit https://example.com/a/ https://example.com/b/
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

class Perdita_IndexNow_CLI {

	/**
	 * Show the site's IndexNow key and the URL of its key file.
	 *
	 * ## OPTIONS
	 *
	 * [--regenerate]
	 * : Replace the stored key with a fresh one first.
	 *
	 * ## EXAMPLES
	 *
	 *     wp perdita indexnow key
	 *     wp perdita indexnow key --regenerate
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Associative arguments.
	 */
	public function key( $args, $assoc_args ) {
		unset( $args );
		if ( ! empty( $assoc_args['regenerate'] ) ) {
			Perdita_IndexNow::regenerate_key();
			WP_CLI::log( __( 'A new key was generated.', 'perdita-core' ) );
		}
		WP_CLI::log( Perdita_IndexNow::key() );
		WP_CLI::log( Perdita_IndexNow::key_file_url() );
	}

	/**
	 * Submit one or more URLs to the configured IndexNow engines right now.
	 *
	 * Only URLs on this site are sent. Anything else is dropped before the
	 * request, because IndexNow rejects a batch that mixes hosts.
	 *
	 * ## OPTIONS
	 *
	 * <url>...
	 * : One or more URLs on this site.
	 *
	 * ## EXAMPLES
	 *
	 *     wp perdita indexnow submit https://example.com/hello-world/
	 *
	 * @param array $args       URLs.
	 * @param array $assoc_args Associative arguments (unused).
	 */
	public function submit( $args, $assoc_args ) {
		unset( $assoc_args );
		$results = Perdita_IndexNow::submit( (array) $args );
		if ( empty( $results ) ) {
			WP_CLI::error( __( 'Nothing sent: no valid URLs on this site were given.', 'perdita-core' ) );
		}
		$failed = 0;
		foreach ( $results as $row ) {
			$status = $row['status'] ? 'HTTP ' . $row['status'] : ( '' !== $row['error'] ? $row['error'] : 'unknown' );
			if ( ! in_array( (int) $row['status'], array( 200, 202 ), true ) ) {
				++$failed;
			}
			WP_CLI::log( sprintf( '%1$s  %2$d URL(s)  %3$s', $row['engine'], (int) $row['count'], $status ) );
		}
		if ( $failed ) {
			WP_CLI::error( sprintf(
				/* translators: %d: number of engines that did not accept the submission. */
				__( '%d engine(s) did not accept the submission.', 'perdita-core' ),
				$failed
			) );
		}
		WP_CLI::success( __( 'Submitted.', 'perdita-core' ) );
	}

	/**
	 * Ping the classic Google and Bing sitemap endpoints now, ignoring the
	 * 10 minute throttle.
	 *
	 * ## EXAMPLES
	 *
	 *     wp perdita indexnow ping
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Associative arguments (unused).
	 */
	public function ping( $args, $assoc_args ) {
		unset( $args, $assoc_args );
		$results = Perdita_IndexNow::ping_sitemaps( true );
		if ( empty( $results ) ) {
			WP_CLI::error( __( 'No sitemap URL to ping.', 'perdita-core' ) );
		}
		foreach ( $results as $engine => $status ) {
			WP_CLI::log( sprintf( '%1$s  %2$s', $engine, $status ? 'HTTP ' . (int) $status : 'error' ) );
		}
		WP_CLI::success( Perdita_IndexNow::sitemap_url() );
	}
}
