<?php
/**
 * Switch to Perdita: the source registry, import with a snapshot and undo,
 * deactivate and reactivate, and the resumable meta batch.
 *
 * Sources register through the `perdita_switch_sources` filter (see
 * class-perdita-switch-source.php for the contract). Everything here is
 * read-only toward the other plugin: Perdita reads its settings, and the
 * only things it ever does to the plugin itself are deactivate_plugins()
 * and activate_plugin(), each on an owner's confirmed click.
 *
 * Stored state:
 *   option perdita_switch_state      id => imported, skipped, deactivated,
 *                                    reactivated, undone (unix times) and the
 *                                    last import's counts.
 *   option perdita_switch_snapshots  id => the Perdita options an import
 *                                    changed, as they were before it, and the
 *                                    module's on/off state.
 *   transient perdita_switch_job     the running meta batch.
 *   term meta _perdita_switch_from   the source a term's SEO title or
 *   term meta _perdita_switch_wrote  description came from, and what it wrote,
 *                                    so Undo removes only that.
 *   user meta perdita_switch_dismissed  plugin notices this person dismissed.
 *   cron perdita_switch_batch        the next batch.
 *   option perdita_switch_batch_lock one batch runner at a time (add_option
 *                                    is an atomic insert). The _lock suffix
 *                                    keeps the page cache from purging.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

class Perdita_Switch {

	const STATE     = 'perdita_switch_state';
	const SNAPSHOTS = 'perdita_switch_snapshots';
	const JOB       = 'perdita_switch_job';
	const CRON      = 'perdita_switch_batch';
	const DISMISSED = 'perdita_switch_dismissed';
	const LOCK      = 'perdita_switch_batch_lock';
	const TERM_FROM  = '_perdita_switch_from';
	const TERM_WROTE = '_perdita_switch_wrote';

	/**
	 * Objects one batch step reads.
	 */
	const BATCH_SIZE = 100;

	/**
	 * Registered sources, built once per request.
	 *
	 * @var Perdita_Switch_Source[]|null
	 */
	private static $sources = null;

	/* ---------- registry ---------- */

	/**
	 * Core's own sources, by class name and file.
	 *
	 * @return array class => file under inc/switch/sources/.
	 */
	private static function builtin() {
		return array(
			'Perdita_Switch_Source_WP_Super_Cache'   => 'class-perdita-switch-source-wp-super-cache.php',
			'Perdita_Switch_Source_W3_Total_Cache'   => 'class-perdita-switch-source-w3-total-cache.php',
			'Perdita_Switch_Source_WP_Fastest_Cache' => 'class-perdita-switch-source-wp-fastest-cache.php',
			'Perdita_Switch_Source_LiteSpeed_Cache'  => 'class-perdita-switch-source-litespeed-cache.php',
			'Perdita_Switch_Source_Cache_Enabler'    => 'class-perdita-switch-source-cache-enabler.php',
			'Perdita_Switch_Source_Yoast'            => 'class-perdita-switch-source-yoast.php',
			'Perdita_Switch_Source_AIOSEO'           => 'class-perdita-switch-source-aioseo.php',
			'Perdita_Switch_Source_Rank_Math'        => 'class-perdita-switch-source-rank-math.php',
			'Perdita_Switch_Source_SEOPress'         => 'class-perdita-switch-source-seopress.php',
			'Perdita_Switch_Source_WP_Mail_SMTP'     => 'class-perdita-switch-source-wp-mail-smtp.php',
			'Perdita_Switch_Source_FluentSMTP'       => 'class-perdita-switch-source-fluent-smtp.php',
			'Perdita_Switch_Source_Post_SMTP'        => 'class-perdita-switch-source-post-smtp.php',
			'Perdita_Switch_Source_Site_Kit'         => 'class-perdita-switch-source-site-kit.php',
			'Perdita_Switch_Source_MonsterInsights'  => 'class-perdita-switch-source-monsterinsights.php',
			'Perdita_Switch_Source_GA_Google'        => 'class-perdita-switch-source-ga-google-analytics.php',
			'Perdita_Switch_Source_SCM'              => 'class-perdita-switch-source-scm.php',
			'Perdita_Switch_Source_LLAR'             => 'class-perdita-switch-source-llar.php',
			'Perdita_Switch_Source_Loginizer'        => 'class-perdita-switch-source-loginizer.php',
		);
	}

	/**
	 * Every registered source, keyed by id.
	 *
	 * @param bool $fresh Rebuild the list (tests, after a filter changed).
	 * @return Perdita_Switch_Source[]
	 */
	public static function sources( $fresh = false ) {
		if ( null !== self::$sources && ! $fresh ) {
			return self::$sources;
		}
		$list = array();
		require_once __DIR__ . '/sources/class-perdita-switch-source-caching.php';
		require_once __DIR__ . '/sources/class-perdita-switch-source-seo.php';
		require_once __DIR__ . '/sources/class-perdita-switch-source-smtp.php';
		require_once __DIR__ . '/sources/class-perdita-switch-source-analytics.php';
		require_once __DIR__ . '/sources/class-perdita-switch-source-security.php';
		foreach ( self::builtin() as $class => $file ) {
			if ( ! class_exists( $class ) ) {
				require_once __DIR__ . '/sources/' . $file;
			}
			$source                 = new $class();
			$list[ $source->id() ] = $source;
		}
		/**
		 * Filters the plugins the Switch to Perdita screen knows. Add a
		 * Perdita_Switch_Source keyed by its id(). See
		 * class-perdita-switch-source.php for what a source implements.
		 *
		 * @param Perdita_Switch_Source[] $list Sources keyed by id.
		 */
		$filtered = apply_filters( 'perdita_switch_sources', $list );
		$clean    = array();
		foreach ( (array) $filtered as $id => $source ) {
			if ( $source instanceof Perdita_Switch_Source && $id === $source->id() && '' !== sanitize_key( $id ) && sanitize_key( $id ) === $id ) {
				$clean[ $id ] = $source;
			}
		}
		self::$sources = $clean;
		return $clean;
	}

	/**
	 * One source, or null.
	 *
	 * @param string $id Source id.
	 * @return Perdita_Switch_Source|null
	 */
	public static function get( $id ) {
		$all = self::sources();
		return isset( $all[ $id ] ) ? $all[ $id ] : null;
	}

	/* ---------- state ---------- */

	/**
	 * Stored state for one source.
	 *
	 * @param string $id Source id.
	 * @return array
	 */
	public static function state( $id ) {
		$all = get_option( self::STATE, array() );
		$row = is_array( $all ) && isset( $all[ $id ] ) && is_array( $all[ $id ] ) ? $all[ $id ] : array();
		return array_merge(
			array(
				'imported'    => 0,
				'skipped'     => 0,
				'deactivated' => 0,
				'reactivated' => 0,
				'undone'      => 0,
				'counts'      => array(),
			),
			$row
		);
	}

	/**
	 * Merge into one source's state.
	 *
	 * @param string $id    Source id.
	 * @param array  $patch Fields.
	 */
	private static function set_state( $id, array $patch ) {
		$all        = get_option( self::STATE, array() );
		$all        = is_array( $all ) ? $all : array();
		$all[ $id ] = array_merge( self::state( $id ), $patch );
		update_option( self::STATE, $all, false );
	}

	/**
	 * The snapshot an import left, or null.
	 *
	 * @param string $id Source id.
	 * @return array|null
	 */
	public static function snapshot( $id ) {
		$all = get_option( self::SNAPSHOTS, array() );
		return is_array( $all ) && isset( $all[ $id ] ) && is_array( $all[ $id ] ) ? $all[ $id ] : null;
	}

	/**
	 * Whether a module is on.
	 *
	 * @param string $module Module id.
	 * @return bool
	 */
	public static function module_on( $module ) {
		return function_exists( 'perdita_core' ) && isset( perdita_core()->modules ) && perdita_core()->modules->is_enabled( $module );
	}

	/* ---------- import, skip, undo ---------- */

	/**
	 * Import one source's settings into its Perdita module.
	 *
	 * Copies the Perdita options the import changes first, so Undo can put
	 * them back. Never deactivates the source plugin.
	 *
	 * @param string $id   Source id.
	 * @param array  $opts enable_module (bool): turn the module on too.
	 *                     batch_seconds (int): how long to work through meta
	 *                     in this request before handing the rest to cron.
	 * @return array ok, message, imported, skipped, batch (bool, more left).
	 */
	public static function import( $id, array $opts = array() ) {
		$source = self::get( $id );
		$result = array(
			'ok'       => false,
			'message'  => '',
			'imported' => 0,
			'skipped'  => 0,
			'batch'    => false,
		);
		if ( ! $source ) {
			$result['message'] = __( 'You can\'t switch from that plugin.', 'perdita-core' );
			return $result;
		}
		$busy = self::busy_with_other( $id );
		if ( '' !== $busy ) {
			$result['message'] = $busy;
			return $result;
		}
		$plan  = $source->plan();
		$patch = isset( $plan['patch'] ) ? (array) $plan['patch'] : array();
		$rows  = isset( $plan['rows'] ) ? (array) $plan['rows'] : array();
		$batch = $source->batch_keys();
		foreach ( $rows as $row ) {
			if ( 'import' === $row['status'] ) {
				++$result['imported'];
			} else {
				++$result['skipped'];
			}
		}
		$has_meta = ! empty( $batch['keys'] ) && self::batch_total( $source ) > 0;
		if ( empty( $patch ) && ! $has_meta ) {
			/* translators: %s: plugin name. */
			$result['message'] = sprintf( __( 'You have nothing in %s to import.', 'perdita-core' ), $source->label() );
			return $result;
		}

		self::take_snapshot( $source );

		$module = $source->module();
		if ( ! empty( $opts['enable_module'] ) && ! self::module_on( $module ) && function_exists( 'perdita_core' ) ) {
			perdita_core()->modules->set_module_state( $module, true );
			perdita_core()->modules->activate( $module );
		}
		if ( $patch ) {
			$source->apply( $patch );
		}
		self::stamp_snapshot( $source );
		self::set_state(
			$id,
			array(
				'imported' => time(),
				'undone'   => 0,
				'counts'   => array(
					'imported' => $result['imported'],
					'skipped'  => $result['skipped'],
				),
			)
		);
		if ( $has_meta ) {
			self::start_batch( $id, 'import' );
			$result['batch'] = ! self::run_batch( isset( $opts['batch_seconds'] ) ? (int) $opts['batch_seconds'] : 8 );
		}
		$result['ok'] = true;
		/* translators: 1: number of settings, 2: plugin name. */
		$result['message'] = sprintf( _n( 'Imported %1$d setting from %2$s.', 'Imported %1$d settings from %2$s.', $result['imported'], 'perdita-core' ), $result['imported'], $source->label() );
		$after             = Perdita_Switch_Target::after_import_note( $source->module() );
		if ( '' !== $after && $patch ) {
			$result['message'] .= ' ' . $after;
		}
		return $result;
	}

	/**
	 * Copy the options an import is about to change.
	 *
	 * @param Perdita_Switch_Source $source Source.
	 */
	private static function take_snapshot( Perdita_Switch_Source $source ) {
		$opts = array();
		foreach ( $source->snapshot_options() as $name ) {
			$name = (string) $name;
			if ( '' === $name ) {
				continue;
			}
			$missing        = new stdClass();
			$value          = get_option( $name, $missing );
			$opts[ $name ] = $value === $missing ? array( 'exists' => false ) : array(
				'exists' => true,
				'value'  => $value,
			);
		}
		$all = get_option( self::SNAPSHOTS, array() );
		$all = is_array( $all ) ? $all : array();
		// Re-importing keeps the first snapshot, so Undo always goes back to
		// how Perdita was before the first import, not before the latest one.
		if ( isset( $all[ $source->id() ] ) ) {
			return;
		}
		$all[ $source->id() ] = array(
			'time'      => time(),
			'module'    => $source->module(),
			'module_on' => Perdita_Core::module_state( $source->module() ),
			'options'   => $opts,
		);
		update_option( self::SNAPSHOTS, $all, false );
	}

	/**
	 * A fingerprint of the options Undo would put back.
	 *
	 * @param Perdita_Switch_Source $source Source.
	 * @return string
	 */
	private static function options_hash( Perdita_Switch_Source $source ) {
		$values = array();
		foreach ( $source->snapshot_options() as $name ) {
			$values[ (string) $name ] = get_option( (string) $name, null );
		}
		return md5( maybe_serialize( $values ) );
	}

	/**
	 * Note what the options look like right after an import, so Undo can
	 * tell when the owner changed them since.
	 *
	 * @param Perdita_Switch_Source $source Source.
	 */
	private static function stamp_snapshot( Perdita_Switch_Source $source ) {
		$all = get_option( self::SNAPSHOTS, array() );
		if ( ! is_array( $all ) || ! isset( $all[ $source->id() ] ) || ! is_array( $all[ $source->id() ] ) ) {
			return;
		}
		$all[ $source->id() ]['after'] = self::options_hash( $source );
		update_option( self::SNAPSHOTS, $all, false );
	}

	/**
	 * Whether the settings Undo would put back changed after the last
	 * import, so Undo would also throw away those later changes.
	 *
	 * @param string $id Source id.
	 * @return bool
	 */
	public static function changed_since_import( $id ) {
		$source = self::get( $id );
		$snap   = self::snapshot( $id );
		if ( ! $source || ! $snap || empty( $snap['after'] ) ) {
			return false;
		}
		return self::options_hash( $source ) !== $snap['after'];
	}

	/**
	 * Why a new import or undo has to wait for another source's batch, or ''.
	 *
	 * @param string $id Source id.
	 * @return string
	 */
	public static function busy_with_other( $id ) {
		$job = self::job();
		if ( ! $job || $job['source'] === $id ) {
			return '';
		}
		$other = self::get( $job['source'] );
		if ( ! $other ) {
			self::clear_job();
			return '';
		}
		/* translators: %s: plugin name. */
		return sprintf( __( 'We\'re still working through the terms from %s. Try again once we finish.', 'perdita-core' ), $other->label() );
	}

	/**
	 * Record that the owner chose not to import, so Deactivate is offered.
	 *
	 * @param string $id Source id.
	 * @return bool
	 */
	public static function skip_import( $id ) {
		if ( ! self::get( $id ) ) {
			return false;
		}
		self::set_state( $id, array( 'skipped' => time() ) );
		return true;
	}

	/**
	 * Put the Perdita options back the way they were before the first
	 * import, switch the module back off if the import turned it on, and
	 * take out the term meta the import wrote.
	 *
	 * @param string $id            Source id.
	 * @param int    $batch_seconds Time for the meta undo in this request.
	 * @return array ok, message, batch (bool, more left).
	 */
	public static function undo( $id, $batch_seconds = 8 ) {
		$source = self::get( $id );
		$snap   = self::snapshot( $id );
		$out    = array(
			'ok'      => false,
			'message' => '',
			'batch'   => false,
		);
		if ( ! $source || ! $snap ) {
			$out['message'] = __( 'You have no import to undo.', 'perdita-core' );
			return $out;
		}
		$busy = self::busy_with_other( $id );
		if ( '' !== $busy ) {
			$out['message'] = $busy;
			return $out;
		}
		$job = self::job();
		if ( $job && $job['source'] === $id && 'import' === $job['mode'] ) {
			self::clear_job();
		}
		foreach ( (array) $snap['options'] as $name => $was ) {
			if ( ! empty( $was['exists'] ) ) {
				update_option( $name, $was['value'] );
			} else {
				delete_option( $name );
			}
		}
		$module = (string) $snap['module'];
		if ( true !== $snap['module_on'] && self::module_on( $module ) ) {
			if ( false === $snap['module_on'] ) {
				Perdita_Core::set_module_state( $module, false );
			} else {
				$state = Perdita_Core::state();
				unset( $state['modules'][ $module ] );
				Perdita_Core::save_state( $state );
				if ( 'caching' === $module ) {
					Perdita_Core::remove_cache_dropin();
				}
			}
		}
		Perdita_Switch_Target::after_restore( $module );

		$all = get_option( self::SNAPSHOTS, array() );
		unset( $all[ $id ] );
		update_option( self::SNAPSHOTS, $all, false );
		self::set_state(
			$id,
			array(
				'imported' => 0,
				'undone'   => time(),
			)
		);

		$batch = $source->batch_keys();
		if ( ! empty( $batch['keys'] ) ) {
			self::start_batch( $id, 'undo' );
			$out['batch'] = ! self::run_batch( $batch_seconds );
		}
		$out['ok'] = true;
		/* translators: %s: plugin name. */
		$out['message'] = sprintf( __( 'Undid the import from %s. You have Perdita\'s settings back the way they were before the import.', 'perdita-core' ), $source->label() );
		return $out;
	}

	/* ---------- deactivate, reactivate ---------- */

	/**
	 * Whether Deactivate may be offered: the plugin is active, the owner
	 * imported or chose to skip, and the source allows it.
	 *
	 * @param string $id Source id.
	 * @return true|string
	 */
	public static function deactivate_allowed( $id ) {
		$source = self::get( $id );
		if ( ! $source ) {
			return __( 'You can\'t switch from that plugin.', 'perdita-core' );
		}
		$det = $source->detect();
		if ( ! $det['active'] ) {
			return __( 'That plugin isn\'t active.', 'perdita-core' );
		}
		$state = self::state( $id );
		if ( empty( $state['imported'] ) && empty( $state['skipped'] ) ) {
			return __( 'Import the settings first, or choose to skip the import.', 'perdita-core' );
		}
		$can = $source->can_deactivate();
		return true === $can ? true : (string) $can;
	}

	/**
	 * Deactivate the source plugin. Its own deactivation routine runs, as
	 * it would from the Plugins screen. Nothing is deleted.
	 *
	 * @param string $id Source id.
	 * @return array ok, message.
	 */
	public static function deactivate( $id ) {
		$allowed = self::deactivate_allowed( $id );
		if ( true !== $allowed ) {
			return array(
				'ok'      => false,
				'message' => $allowed,
			);
		}
		$source = self::get( $id );
		$det    = $source->detect();
		if ( ! function_exists( 'deactivate_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$network = is_multisite() && is_plugin_active_for_network( $det['plugin'] );
		deactivate_plugins( $det['plugin'], false, $network );
		if ( Perdita_Switch_Source::plugin_active( $det['plugin'] ) ) {
			return array(
				'ok'      => false,
				/* translators: %s: plugin name. */
				'message' => sprintf( __( '%s is still active. Try again, or deactivate the plugin on the Plugins screen.', 'perdita-core' ), $source->label() ),
			);
		}
		self::set_state(
			$id,
			array(
				'deactivated' => time(),
				'reactivated' => 0,
				'network'     => $network,
			)
		);
		return array(
			'ok'      => true,
			/* translators: %1$s: plugin name. */
			'message' => sprintf( __( 'Deactivated %1$s. You still have its files and settings, so you can reactivate %1$s anytime.', 'perdita-core' ), $source->label() ),
		);
	}

	/**
	 * Reactivate a plugin this screen deactivated.
	 *
	 * @param string $id Source id.
	 * @return array ok, message.
	 */
	public static function reactivate( $id ) {
		$source = self::get( $id );
		if ( ! $source ) {
			return array(
				'ok'      => false,
				'message' => __( 'You can\'t switch from that plugin.', 'perdita-core' ),
			);
		}
		$det = $source->detect();
		if ( ! $det['installed'] || $det['active'] ) {
			return array(
				'ok'      => false,
				'message' => __( 'That plugin is already active or no longer installed.', 'perdita-core' ),
			);
		}
		if ( ! function_exists( 'activate_plugin' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$state = self::state( $id );
		$source->before_reactivate();
		$res = activate_plugin( $det['plugin'], '', is_multisite() && ! empty( $state['network'] ) );
		if ( is_wp_error( $res ) ) {
			return array(
				'ok'      => false,
				'message' => $res->get_error_message(),
			);
		}
		self::set_state(
			$id,
			array(
				'deactivated' => 0,
				'reactivated' => time(),
				'network'     => false,
			)
		);
		return array(
			'ok'      => true,
			/* translators: %s: plugin name. */
			'message' => trim( sprintf( __( 'Reactivated %s.', 'perdita-core' ), $source->label() ) . ' ' . $source->reactivate_note() ),
		);
	}

	/* ---------- the meta batch ---------- */

	/**
	 * The running job, or null.
	 *
	 * @return array|null
	 */
	public static function job() {
		$job = get_transient( self::JOB );
		return is_array( $job ) && isset( $job['source'], $job['mode'] ) ? $job : null;
	}

	/**
	 * Drop the job and its cron event.
	 */
	public static function clear_job() {
		delete_transient( self::JOB );
		wp_clear_scheduled_hook( self::CRON );
	}

	/**
	 * How many objects carry the source's batch keys.
	 *
	 * @param Perdita_Switch_Source $source Source.
	 * @return int
	 */
	public static function batch_total( Perdita_Switch_Source $source ) {
		global $wpdb;
		$batch = $source->batch_keys();
		if ( empty( $batch['keys'] ) ) {
			return 0;
		}
		$keys = array_values( array_map( 'strval', (array) $batch['keys'] ) );
		$in   = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- placeholders built to match $keys.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT term_id) FROM {$wpdb->termmeta} WHERE meta_key IN ($in)", $keys ) );
	}

	/**
	 * Start (or restart) a batch.
	 *
	 * @param string $id   Source id.
	 * @param string $mode import or undo.
	 */
	public static function start_batch( $id, $mode ) {
		$source = self::get( $id );
		if ( ! $source ) {
			return;
		}
		set_transient(
			self::JOB,
			array(
				'source'  => $id,
				'mode'    => 'undo' === $mode ? 'undo' : 'import',
				'cursor'  => 0,
				'total'   => 'undo' === $mode ? self::undo_total( $id ) : self::batch_total( $source ),
				'done'    => 0,
				'written' => 0,
				'kept'    => 0,
				'skipped' => 0,
				'started' => time(),
			),
			DAY_IN_SECONDS
		);
	}

	/**
	 * How many terms carry this source's undo mark.
	 *
	 * @param string $id Source id.
	 * @return int
	 */
	private static function undo_total( $id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->termmeta} WHERE meta_key = %s AND meta_value = %s", self::TERM_FROM, $id ) );
	}

	/**
	 * Work through the job for up to $seconds, then book the rest on cron.
	 *
	 * @param int $seconds Time budget. 0 runs one step.
	 * @return bool Whether the job is finished (or there was none).
	 */
	public static function run_batch( $seconds = 0 ) {
		if ( ! self::job() ) {
			return true;
		}
		if ( ! self::lock() ) {
			// Another request is working through the batch right now.
			return false;
		}
		try {
			return self::run_batch_locked( $seconds );
		} finally {
			delete_option( self::LOCK );
		}
	}

	/**
	 * Take the batch lock. A lock older than two minutes belongs to a
	 * request that died, so it's taken over.
	 *
	 * @return bool
	 */
	private static function lock() {
		if ( add_option( self::LOCK, time(), '', false ) ) {
			return true;
		}
		$held = (int) get_option( self::LOCK, 0 );
		if ( $held && $held < time() - 2 * MINUTE_IN_SECONDS ) {
			delete_option( self::LOCK );
			return add_option( self::LOCK, time(), '', false );
		}
		return false;
	}

	/**
	 * run_batch() under the lock.
	 *
	 * @param int $seconds Time budget.
	 * @return bool
	 */
	private static function run_batch_locked( $seconds ) {
		$job = self::job();
		if ( ! $job ) {
			return true;
		}
		$source = self::get( $job['source'] );
		if ( ! $source ) {
			self::clear_job();
			return true;
		}
		// Book the next step before working, so a request that dies midway
		// still leaves cron a way to pick the batch up.
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_single_event( time() + 60, self::CRON );
		}
		$until = microtime( true ) + max( 0, (int) $seconds );
		do {
			$ids = self::next_ids( $source, $job );
			foreach ( $ids as $object_id ) {
				$job['cursor'] = $object_id;
				++$job['done'];
				// Save the cursor first, so a term that kills the request is
				// skipped on the next run instead of retried forever.
				set_transient( self::JOB, $job, DAY_IN_SECONDS );
				$res = 'undo' === $job['mode'] ? self::undo_term( $object_id, $job['source'] ) : $source->batch_step( $object_id );
				if ( 'written' === $res || 'removed' === $res ) {
					++$job['written'];
				} elseif ( 'kept' === $res ) {
					++$job['kept'];
				} elseif ( 'skipped' === $res ) {
					++$job['skipped'];
				}
			}
			$finished = count( $ids ) < self::BATCH_SIZE;
		} while ( ! $finished && microtime( true ) < $until );

		if ( $finished ) {
			self::clear_job();
			if ( 'import' === $job['mode'] ) {
				$state           = self::state( $job['source'] );
				$counts          = (array) $state['counts'];
				$counts['terms'] = array(
					'written' => $job['written'],
					'kept'    => $job['kept'],
					'skipped' => $job['skipped'],
				);
				self::set_state( $job['source'], array( 'counts' => $counts ) );
			}
			// Term titles changed, so cached term archives are stale.
			if ( class_exists( 'Perdita_Caching' ) && $job['written'] > 0 ) {
				Perdita_Caching::purge_everything();
			}
			return true;
		}
		set_transient( self::JOB, $job, DAY_IN_SECONDS );
		wp_clear_scheduled_hook( self::CRON );
		wp_schedule_single_event( time() + 5, self::CRON );
		return false;
	}

	/**
	 * The next ids after the job's cursor.
	 *
	 * @param Perdita_Switch_Source $source Source.
	 * @param array                 $job    Job.
	 * @return int[]
	 */
	private static function next_ids( Perdita_Switch_Source $source, array $job ) {
		global $wpdb;
		if ( 'undo' === $job['mode'] ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT term_id FROM {$wpdb->termmeta} WHERE meta_key = %s AND meta_value = %s AND term_id > %d ORDER BY term_id ASC LIMIT %d", self::TERM_FROM, $job['source'], (int) $job['cursor'], self::BATCH_SIZE ) );
			return array_map( 'intval', (array) $ids );
		}
		$batch = $source->batch_keys();
		$keys  = array_values( array_map( 'strval', (array) $batch['keys'] ) );
		if ( ! $keys ) {
			return array();
		}
		$in   = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
		$args = array_merge( $keys, array( (int) $job['cursor'], self::BATCH_SIZE ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- placeholders built to match $keys.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT term_id FROM {$wpdb->termmeta} WHERE meta_key IN ($in) AND term_id > %d ORDER BY term_id ASC LIMIT %d", $args ) );
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Write a term's imported SEO title and description, only where Perdita
	 * has none, and mark what was written so Undo can take out just that.
	 *
	 * @param int    $term_id Term id.
	 * @param string $id      Source id.
	 * @param string $title   Translated title, '' for none.
	 * @param string $desc    Translated description, '' for none.
	 * @return string written, kept or none.
	 */
	public static function write_term( $term_id, $id, $title, $desc ) {
		if ( ! class_exists( 'Perdita_SEO_Terms' ) ) {
			require_once PERDITA_CORE_DIR . 'inc/class-perdita-seo-terms.php';
		}
		$wrote = array();
		$kept  = false;
		$pairs = array(
			Perdita_SEO_Terms::META_TITLE       => Perdita_SEO_Store::sanitize_template( $title ),
			Perdita_SEO_Terms::META_DESCRIPTION => Perdita_SEO_Store::sanitize_template( $desc ),
		);
		foreach ( $pairs as $key => $value ) {
			if ( '' === $value ) {
				continue;
			}
			if ( '' !== trim( (string) get_term_meta( $term_id, $key, true ) ) ) {
				$kept = true;
				continue;
			}
			update_term_meta( $term_id, $key, $value );
			$wrote[ $key ] = $value;
		}
		if ( $wrote ) {
			update_term_meta( $term_id, self::TERM_FROM, $id );
			update_term_meta( $term_id, self::TERM_WROTE, $wrote );
			return 'written';
		}
		return $kept ? 'kept' : 'none';
	}

	/**
	 * Take out what an import wrote to one term, unless someone has edited
	 * it since.
	 *
	 * @param int    $term_id Term id.
	 * @param string $id      Source id.
	 * @return string removed or none.
	 */
	private static function undo_term( $term_id, $id ) {
		if ( (string) get_term_meta( $term_id, self::TERM_FROM, true ) !== $id ) {
			return 'none';
		}
		$wrote = get_term_meta( $term_id, self::TERM_WROTE, true );
		foreach ( is_array( $wrote ) ? $wrote : array() as $key => $value ) {
			if ( 0 === strpos( (string) $key, '_perdita_seo_' ) && (string) get_term_meta( $term_id, $key, true ) === (string) $value ) {
				delete_term_meta( $term_id, $key );
			}
		}
		delete_term_meta( $term_id, self::TERM_FROM );
		delete_term_meta( $term_id, self::TERM_WROTE );
		return 'removed';
	}

	/* ---------- notices ---------- */

	/**
	 * Active plugins with settings Perdita could import, that this person
	 * hasn't dismissed and nobody has imported or skipped yet.
	 *
	 * @param int $user_id User id.
	 * @return Perdita_Switch_Source[]
	 */
	public static function nudges( $user_id ) {
		$dismissed = get_user_meta( (int) $user_id, self::DISMISSED, true );
		$dismissed = is_array( $dismissed ) ? $dismissed : array();
		$out       = array();
		foreach ( self::sources() as $id => $source ) {
			if ( in_array( $id, $dismissed, true ) ) {
				continue;
			}
			$state = self::state( $id );
			if ( $state['imported'] || $state['skipped'] ) {
				continue;
			}
			$det = $source->detect();
			if ( $det['active'] && $det['has_settings'] ) {
				$out[ $id ] = $source;
			}
		}
		return $out;
	}

	/**
	 * Dismiss one plugin's notice for one person, for good.
	 *
	 * @param int    $user_id User id.
	 * @param string $id      Source id.
	 */
	public static function dismiss( $user_id, $id ) {
		if ( ! self::get( $id ) ) {
			return;
		}
		$dismissed = get_user_meta( (int) $user_id, self::DISMISSED, true );
		$dismissed = is_array( $dismissed ) ? $dismissed : array();
		if ( ! in_array( $id, $dismissed, true ) ) {
			$dismissed[] = $id;
			update_user_meta( (int) $user_id, self::DISMISSED, $dismissed );
		}
	}
}
