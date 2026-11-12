<?php
/**
 * WP-CLI commands for the page cache.
 *
 * Loaded only under WP-CLI, from the main module class constructor, so an
 * ordinary request never parses it.
 *
 *   wp perdita cache status
 *   wp perdita cache purge
 *   wp perdita cache purge https://example.com/about/
 *   wp perdita cache purge --page-url=https://example.com/about/
 *   wp perdita cache preload
 *   wp perdita cache preload --stop
 *   wp perdita cache gc
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

class Perdita_Caching_CLI {

	/**
	 * Show what the page cache holds and how it is set up.
	 *
	 * ## EXAMPLES
	 *
	 *     wp perdita cache status
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Associative arguments (unused).
	 */
	public function status( $args, $assoc_args ) {
		$settings = Perdita_Caching::settings();
		$stats    = Perdita_Caching::stats();
		$other    = Perdita_Caching::other_page_cache();
		Perdita_Caching::load( 'preload' );
		$preload = Perdita_Caching_Preload::state( true );

		$on_off = static function ( $value ) {
			return $value ? 'on' : 'off';
		};
		$rows   = array(
			'Page cache'         => $on_off( $settings['enabled'] ) . ( '' !== $other ? ' (waiting on ' . $other . ')' : '' ),
			'Cached pages'       => number_format( (int) $stats['files'] ),
			'Cache size'         => size_format( (int) $stats['bytes'], 1 ) ?: '0 B',
			'Cache lifetime'     => (int) $settings['ttl_hours'] . ' hours',
			'Early serve'        => $on_off( $settings['early_serve'] ) . ' (drop-in: ' . Perdita_Caching::dropin_state() . ')',
			'Gzip copies'        => $on_off( $settings['gzip'] ),
			'Serve stale'        => $on_off( $settings['serve_stale'] ),
			'Phone cache'        => $on_off( $settings['mobile_cache'] ),
			'Feed cache'         => $on_off( $settings['cache_feeds'] ),
			'CDN'                => $settings['cdn_enabled'] && '' !== $settings['cdn_host'] ? $settings['cdn_host'] : 'off',
			'Garbage collection' => $settings['gc_schedule'],
			'Preload'            => $on_off( $settings['preload'] ) . ', ' . $preload['status'] . ', ' . number_format( (int) $preload['done'] ) . ' of ' . number_format( (int) max( $preload['total'], $preload['done'] ) ),
			'Debug log'          => $on_off( $settings['debug_log'] ),
		);
		foreach ( $rows as $label => $value ) {
			WP_CLI::log( str_pad( $label . ':', 20 ) . $value );
		}
	}

	/**
	 * Clear the whole page cache, or one URL.
	 *
	 * WP-CLI's own --url picks which site the command runs on. It never
	 * names a page to purge: `wp --url=https://example.com/ perdita cache
	 * purge` clears the whole cache of that site. Name one page with the
	 * positional argument or --page-url.
	 *
	 * ## OPTIONS
	 *
	 * [<url>]
	 * : Purge only this URL. It has to be on this site.
	 *
	 * [--page-url=<url>]
	 * : The same, as a flag.
	 *
	 * ## EXAMPLES
	 *
	 *     wp perdita cache purge
	 *     wp perdita cache purge https://example.com/about/
	 *     wp perdita cache purge --page-url=https://example.com/about/
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function purge( $args, $assoc_args ) {
		$url = isset( $assoc_args['page-url'] ) ? (string) $assoc_args['page-url'] : ( isset( $args[0] ) ? (string) $args[0] : '' );
		if ( '' !== $url ) {
			$count = Perdita_Caching::purge_urls( array( esc_url_raw( $url ) ) );
			/* translators: %d: number of cached copies. */
			WP_CLI::success( sprintf( _n( 'Purged %d cached copy.', 'Purged %d cached copies.', $count, 'perdita-core' ), $count ) );
			return;
		}
		$removed = Perdita_Caching::purge_everything();
		/* translators: %d: number of cached pages removed. */
		WP_CLI::success( sprintf( _n( 'Cleared the cache and removed %d file.', 'Cleared the cache and removed %d files.', $removed, 'perdita-core' ), $removed ) );
	}

	/**
	 * Build every public page into the cache now, or stop a running preload.
	 *
	 * ## OPTIONS
	 *
	 * [--stop]
	 * : Stop the preload that is running.
	 *
	 * [--refresh]
	 * : Rebuild every page, including pages with a fresh copy.
	 *
	 * ## EXAMPLES
	 *
	 *     wp perdita cache preload
	 *     wp perdita cache preload --stop
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Associative arguments.
	 */
	public function preload( $args, $assoc_args ) {
		Perdita_Caching::load( 'preload' );
		if ( ! empty( $assoc_args['stop'] ) ) {
			Perdita_Caching_Preload::stop();
			WP_CLI::success( __( 'Stopped the preload.', 'perdita-core' ) );
			return;
		}
		if ( ! Perdita_Caching_Preload::start( ! empty( $assoc_args['refresh'] ) ? 'refresh' : 'fill' ) ) {
			WP_CLI::error( __( 'Turn the page cache on first, then start the preload again.', 'perdita-core' ) );
		}
		// The start booked a cron batch. This command does the work itself, so
		// that batch has nothing left to do when it runs. A batch that moves
		// nothing (the page cache went off, another plugin took over) three
		// times in a row ends the loop instead of spinning forever. A full
		// purge during the run asks for one more fill pass, run here too.
		$passes = 0;
		$state  = Perdita_Caching_Preload::state( true );
		while ( $passes < 2 ) {
			++$passes;
			$stuck = 0;
			do {
				$before = self::progress( $state );
				delete_transient( Perdita_Caching_Preload::LOCK );
				$state = Perdita_Caching_Preload::run_batch();
				WP_CLI::log( sprintf( '%s of %s', number_format( (int) $state['done'] ), number_format( (int) max( $state['total'], $state['done'] ) ) ) );
				$stuck = self::progress( $state ) === $before ? $stuck + 1 : 0;
			} while ( 'running' === $state['status'] && $stuck < 3 && Perdita_Caching_Preload::available() );
			if ( 'waiting' !== $state['status'] || $passes >= 2 || ! Perdita_Caching_Preload::start( 'fill' ) ) {
				break;
			}
			$state = Perdita_Caching_Preload::state( true );
		}
		wp_unschedule_hook( Perdita_Caching_Preload::HOOK_BATCH );

		if ( 'done' !== $state['status'] ) {
			WP_CLI::warning( __( 'Stopped with pages left to build. Run the command again to build the rest.', 'perdita-core' ) );
			return;
		}
		WP_CLI::success(
			sprintf(
				/* translators: 1: pages built, 2: pages already cached, 3: pages that failed. */
				__( 'Finished the preload: %1$s built, %2$s already cached, and %3$s failed.', 'perdita-core' ),
				number_format( (int) $state['built'] ),
				number_format( (int) $state['cached'] ),
				number_format( (int) $state['failed'] )
			)
		);
	}

	/**
	 * Where a preload stands, to tell a batch that moved from one that didn't.
	 *
	 * @param array $state Preload state.
	 * @return string
	 */
	private static function progress( $state ) {
		return implode( '|', array( $state['status'], $state['run'], $state['phase'], (int) $state['cursor'], (int) $state['done'], count( (array) $state['queue'] ) ) );
	}

	/**
	 * Delete expired cache files.
	 *
	 * ## EXAMPLES
	 *
	 *     wp perdita cache gc
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Associative arguments (unused).
	 */
	public function gc( $args, $assoc_args ) {
		$removed = Perdita_Caching::run_gc();
		/* translators: %d: number of expired pages removed. */
		WP_CLI::success( sprintf( _n( 'Deleted %d expired page.', 'Deleted %d expired pages.', $removed, 'perdita-core' ), $removed ) );
	}
}
