<?php
/**
 * wp perdita switch: the Switch to Perdita screen from the command line.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Move settings from other plugins into Perdita.
 */
class Perdita_Switch_CLI {

	/**
	 * List the plugins Perdita can switch you from.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table, csv, json or yaml.
	 * ---
	 * default: table
	 * ---
	 *
	 * @subcommand list
	 *
	 * @param array $args  Positional.
	 * @param array $assoc Named.
	 */
	public function list_( $args, $assoc ) {
		unset( $args );
		$rows = array();
		foreach ( Perdita_Switch::sources() as $id => $source ) {
			$det    = $source->detect();
			$state  = Perdita_Switch::state( $id );
			$rows[] = array(
				'source'       => $id,
				'plugin'       => $source->label(),
				'module'       => $source->module(),
				'installed'    => $det['installed'] ? 'yes' : 'no',
				'active'       => $det['active'] ? 'yes' : 'no',
				'has_settings' => $det['has_settings'] ? 'yes' : 'no',
				'imported'     => $state['imported'] ? gmdate( 'Y-m-d H:i', (int) $state['imported'] ) : '',
				'undo'         => Perdita_Switch::snapshot( $id ) ? 'yes' : 'no',
			);
		}
		WP_CLI\Utils\format_items( isset( $assoc['format'] ) ? $assoc['format'] : 'table', $rows, array_keys( $rows[0] ) );
	}

	/**
	 * Show what an import would do, without changing anything.
	 *
	 * ## OPTIONS
	 *
	 * <source>
	 * : Source id, from `wp perdita switch list`.
	 *
	 * [--format=<format>]
	 * : table, csv, json or yaml.
	 * ---
	 * default: table
	 * ---
	 *
	 * @param array $args  Positional.
	 * @param array $assoc Named.
	 */
	public function preview( $args, $assoc ) {
		$source = self::source( $args );
		$scan   = $source->scan();
		if ( $scan['rows'] ) {
			WP_CLI\Utils\format_items( isset( $assoc['format'] ) ? $assoc['format'] : 'table', $scan['rows'], array( 'setting', 'found', 'target', 'value', 'status', 'reason' ) );
		} else {
			WP_CLI::log( __( 'You have no settings here to import.', 'perdita-core' ) );
		}
		$terms = Perdita_Switch::batch_total( $source );
		if ( $terms ) {
			/* translators: %d: number of terms. */
			WP_CLI::log( sprintf( __( 'Terms with their own SEO title or description: %d', 'perdita-core' ), $terms ) );
		}
		foreach ( $scan['notes'] as $note ) {
			WP_CLI::log( $note );
		}
	}

	/**
	 * Import a plugin's settings, and optionally deactivate it afterward.
	 *
	 * ## OPTIONS
	 *
	 * <source>
	 * : Source id, from `wp perdita switch list`.
	 *
	 * [--enable-module]
	 * : Turn the Perdita module on too.
	 *
	 * [--deactivate]
	 * : Deactivate the plugin once the import succeeds.
	 *
	 * @param array $args  Positional.
	 * @param array $assoc Named.
	 */
	public function import( $args, $assoc ) {
		$source = self::source( $args );
		$res    = Perdita_Switch::import( $source->id(), array( 'enable_module' => ! empty( $assoc['enable-module'] ), 'batch_seconds' => 0 ) );
		if ( ! $res['ok'] ) {
			WP_CLI::error( $res['message'] );
		}
		self::finish_batch();
		WP_CLI::success( $res['message'] );
		if ( ! empty( $assoc['deactivate'] ) ) {
			$off = Perdita_Switch::deactivate( $source->id() );
			if ( ! $off['ok'] ) {
				WP_CLI::error( $off['message'] );
			}
			WP_CLI::success( $off['message'] );
		}
	}

	/**
	 * Undo an import: put Perdita's settings back to how they were before it.
	 *
	 * ## OPTIONS
	 *
	 * <source>
	 * : Source id, from `wp perdita switch list`.
	 *
	 * @param array $args  Positional.
	 * @param array $assoc Named.
	 */
	public function undo( $args, $assoc ) {
		unset( $assoc );
		$source = self::source( $args );
		$res    = Perdita_Switch::undo( $source->id(), 0 );
		if ( ! $res['ok'] ) {
			WP_CLI::error( $res['message'] );
		}
		self::finish_batch();
		WP_CLI::success( $res['message'] );
	}

	/**
	 * The source named on the command line, or an error.
	 *
	 * @param array $args Positional.
	 * @return Perdita_Switch_Source
	 */
	private static function source( array $args ) {
		$id     = isset( $args[0] ) ? sanitize_key( $args[0] ) : '';
		$source = Perdita_Switch::get( $id );
		if ( ! $source ) {
			/* translators: %s: source id. */
			WP_CLI::error( sprintf( __( 'You can\'t switch from "%s". Run wp perdita switch list for the ids.', 'perdita-core' ), $id ) );
		}
		return $source;
	}

	/**
	 * Run the term batch to the end, here, instead of on cron.
	 */
	private static function finish_batch() {
		while ( Perdita_Switch::job() ) {
			if ( Perdita_Switch::run_batch( 30 ) ) {
				break;
			}
			$job = Perdita_Switch::job();
			if ( $job ) {
				/* translators: 1: terms done, 2: terms in all. */
				WP_CLI::log( sprintf( __( '%1$d of %2$d terms', 'perdita-core' ), (int) $job['done'], (int) $job['total'] ) );
			}
		}
	}
}
