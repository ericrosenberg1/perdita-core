<?php
/**
 * One plugin Perdita can replace, for the Switch to Perdita screen.
 *
 * THE CONTRACT. Perdita Pro and any other plugin add sources through the
 * `perdita_switch_sources` filter, so the names below are a contract.
 *
 *   add_filter( 'perdita_switch_sources', function ( $sources ) {
 *       $sources['wp-rocket'] = new My_WP_Rocket_Source();
 *       return $sources;
 *   } );
 *
 * The filter receives and returns an array of Perdita_Switch_Source objects
 * keyed by id(). Anything that isn't an instance of this class, or whose key
 * doesn't match its id(), is dropped. A source must implement:
 *
 *   id()             string   A lowercase slug, unique across sources.
 *   label()          string   The plugin's name, as its owner knows it.
 *   plugin_files()   string[] Plugin basenames that mean "this plugin is
 *                             installed", e.g. wp-rocket/wp-rocket.php.
 *   module()         string   The Perdita module id the settings land in
 *                             (caching, seo, smtp, analytics, security, or
 *                             any id a Pro module registers).
 *   has_settings()   bool     Whether the plugin left settings behind. Read
 *                             only, and cheap: the screen calls it per card.
 *   plan()           array    The dry run. Never writes. Returns
 *                             array( 'patch' => array, 'rows' => array,
 *                             'notes' => string[] ). Build it with offer(),
 *                             unsupported() and skip() so every value is
 *                             checked against the module's own sanitize()
 *                             before it shows as importable.
 *
 * and may override:
 *
 *   apply( $patch )     Write the patch. The default goes through
 *                       Perdita_Switch_Target::write(), which runs the
 *                       module's own sanitize() and save path. A Pro module
 *                       that isn't one of Core's targets overrides this and
 *                       calls its own sanitize and save, never a raw
 *                       update_option() of unvalidated data.
 *   snapshot_options()  Option names to copy before an import, so Undo can
 *                       put them back. Defaults to the module's option.
 *   can_deactivate()    true, or a sentence saying why not.
 *   conflict_note()     One sentence for the card about running both at once.
 *   pro_note()          One plain sentence when Perdita Pro covers what this
 *                       import can't, or ''.
 *   leftovers()         Files the plugin leaves after deactivation, from
 *                       Perdita_Switch_Leftovers, and fix_leftover( $item )
 *                       to clear one after the owner confirms. Each item is
 *                       array( id, label, detail, removable, line ) plus an
 *                       optional 'confirm', the sentence beside its
 *                       confirmation box. Without 'confirm' the box reads
 *                       "Remove this leftover. You'll get a backup copy
 *                       first." Say where the copy goes when you set it.
 *                       Back up a file with Perdita_Switch_Backups::
 *                       save_file( $path, $kind ) and a folder with
 *                       save_dir( $dir, $kind ), never beside the original:
 *                       those copies land in the protected uploads folder
 *                       and show under "Backup copies" on the screen.
 *   before_reactivate() Clear anything of Perdita's that would stop the
 *                       plugin setting itself up again on Reactivate.
 *   reactivate_note()   One sentence shown after Reactivate when the plugin
 *                       doesn't come back exactly as it was, or ''.
 *   batch_keys()        Term meta keys a resumable batch reads, as
 *                       array( 'type' => 'term', 'keys' => string[] ), and
 *                       batch_step( $term_id ) to import one term. Undo
 *                       reverses the batch from the meta it wrote, so a
 *                       source needs no undo code of its own.
 *
 * Sources only ever READ the other plugin's options, files and meta. They
 * never write, rename or delete anything that belongs to it.
 *
 * SCREEN HOOKS. Three actions let a plugin add its own markup to the Switch
 * to Perdita screen. Keep the names and signatures.
 *
 *   do_action( 'perdita_switch_screen_before', Perdita_Switch_Source[] $sources )
 *       Near the top of the screen, after its notices and introduction and
 *       before the running batch and the cards. $sources is every
 *       registered source, keyed by id().
 *   do_action( 'perdita_switch_card_after', Perdita_Switch_Source $source, array $state )
 *       At the end of each source's card, after the card's own controls
 *       (preview, actions, leftovers) and inside its wrapper,
 *       div.pds-card#pds-{id}. $state is Perdita_Switch::state( $id ).
 *       Register with 2 accepted args.
 *   do_action( 'perdita_switch_screen_after', Perdita_Switch_Source[] $sources )
 *       At the bottom of the screen, after the cards and the backup copies,
 *       inside the screen's wrapper.
 *
 * Escape everything you print. A form that posts to admin-post.php needs its
 * own nonce and capability check.
 *
 * A row in plan()['rows'] is an array of:
 *   setting  string  The setting's name in the other plugin.
 *   found    string  What the other plugin has, ready to print. A secret
 *                    shows its last 4 characters at most (mask()).
 *   target   string  The Perdita setting it maps to, or ''.
 *   value    string  What Perdita saves, after its own validation.
 *   status   string  import, skip (a value Perdita refuses) or none (no
 *                    Perdita equivalent).
 *   reason   string  Why, for skip and none. Plain words.
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

abstract class Perdita_Switch_Source {

	/**
	 * The plan being built by offer() and friends.
	 *
	 * @var array
	 */
	protected $building = array();

	/**
	 * Unique slug.
	 *
	 * @return string
	 */
	abstract public function id();

	/**
	 * The plugin's name.
	 *
	 * @return string
	 */
	abstract public function label();

	/**
	 * Plugin basenames (folder/file.php).
	 *
	 * @return string[]
	 */
	abstract public function plugin_files();

	/**
	 * The Perdita module id the settings land in.
	 *
	 * @return string
	 */
	abstract public function module();

	/**
	 * Whether the plugin left settings to import.
	 *
	 * @return bool
	 */
	abstract public function has_settings();

	/**
	 * The dry run: what would be imported, what is refused, and what has no
	 * Perdita equivalent. Writes nothing.
	 *
	 * @return array patch, rows, notes.
	 */
	abstract public function plan();

	/* ---------- detection ---------- */

	/**
	 * Installed, active and has-settings state, plus the basename found.
	 *
	 * @return array installed, active, has_settings, plugin.
	 */
	public function detect() {
		$found  = '';
		$active = false;
		$all    = self::installed_plugins();
		foreach ( $this->plugin_files() as $basename ) {
			if ( isset( $all[ $basename ] ) ) {
				$found = '' === $found ? $basename : $found;
				if ( self::plugin_active( $basename ) ) {
					$found  = $basename;
					$active = true;
					break;
				}
			}
		}
		return array(
			'installed'    => '' !== $found,
			'active'       => $active,
			'has_settings' => (bool) $this->has_settings(),
			'plugin'       => $found,
		);
	}

	/**
	 * Every installed plugin, keyed by basename.
	 *
	 * @return array
	 */
	public static function installed_plugins() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		return (array) get_plugins();
	}

	/**
	 * Whether a plugin is active on this site or network-wide.
	 *
	 * @param string $basename Plugin basename.
	 * @return bool
	 */
	public static function plugin_active( $basename ) {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		return is_plugin_active( $basename );
	}

	/* ---------- the pieces a source may override ---------- */

	/**
	 * The preview rows, from plan().
	 *
	 * @return array rows, notes.
	 */
	public function scan() {
		$plan = $this->plan();
		return array(
			'rows'  => isset( $plan['rows'] ) ? (array) $plan['rows'] : array(),
			'notes' => isset( $plan['notes'] ) ? (array) $plan['notes'] : array(),
		);
	}

	/**
	 * Write a plan's patch through the module's own save path.
	 *
	 * @param array $patch Patch from plan().
	 * @return bool Whether anything was written.
	 */
	public function apply( array $patch ) {
		return Perdita_Switch_Target::write( $this->module(), $patch );
	}

	/**
	 * Option names an import may change, copied before it runs.
	 *
	 * @return string[]
	 */
	public function snapshot_options() {
		return Perdita_Switch_Target::options( $this->module() );
	}

	/**
	 * Whether Perdita may offer to deactivate the plugin.
	 *
	 * @return true|string True, or why not.
	 */
	public function can_deactivate() {
		return true;
	}

	/**
	 * What happens while both run, for the card.
	 *
	 * @return string
	 */
	public function conflict_note() {
		return Perdita_Switch_Target::conflict_note( $this->module(), $this->label() );
	}

	/**
	 * One plain line when Perdita Pro covers what this import can't.
	 *
	 * @return string
	 */
	public function pro_note() {
		return '';
	}

	/**
	 * Files this plugin leaves behind, once it's inactive.
	 *
	 * @return array[] From Perdita_Switch_Leftovers::find().
	 */
	public function leftovers() {
		return array();
	}

	/**
	 * Runs right before the Reactivate button turns the plugin back on.
	 * Clear anything of Perdita's that would stop the plugin setting itself
	 * up again. Never touch the plugin's own files here.
	 */
	public function before_reactivate() {
	}

	/**
	 * One sentence for the owner after Reactivate, when the plugin doesn't
	 * come back exactly as it was, or ''.
	 *
	 * @return string
	 */
	public function reactivate_note() {
		return '';
	}

	/**
	 * Meta a resumable batch works through, or an empty array.
	 *
	 * @return array type (term), keys (string[]).
	 */
	public function batch_keys() {
		return array();
	}

	/**
	 * Import one object's meta. Called by the batch runner for each id that
	 * carries any of batch_keys().
	 *
	 * @param int $object_id Term id.
	 * @return string written, kept (Perdita already had a value), skipped or none.
	 */
	public function batch_step( $object_id ) {
		unset( $object_id );
		return 'none';
	}

	/* ---------- plan builders ---------- */

	/**
	 * Start a fresh plan.
	 */
	protected function begin() {
		$this->building = array(
			'patch' => array(),
			'rows'  => array(),
			'notes' => array(),
		);
	}

	/**
	 * Finish the plan.
	 *
	 * @return array
	 */
	protected function finish() {
		$plan           = $this->building;
		$this->building = array();
		return $plan;
	}

	/**
	 * Offer one value for import. It goes through the module's own check,
	 * and shows as importable only when that check keeps it as it is. A
	 * list keeps the entries the check keeps, and each one it drops shows
	 * as skipped.
	 *
	 * @param string $setting Setting name in the other plugin.
	 * @param mixed  $found   The other plugin's value, for display.
	 * @param string $key     Perdita key (dot path for nested SEO keys).
	 * @param mixed  $value   Value to save, in Perdita's shape.
	 * @param string $target  Perdita setting name.
	 * @param array  $opts    secret (bool): mask both values. note (string):
	 *                        a sentence to show on an imported row.
	 * @return bool Whether the value goes in.
	 */
	protected function offer( $setting, $found, $key, $value, $target, array $opts = array() ) {
		$secret = ! empty( $opts['secret'] );
		$check  = Perdita_Switch_Target::check( $this->module(), $key, $value );
		if ( is_array( $value ) ) {
			foreach ( $check['dropped'] as $bad ) {
				$this->building['rows'][] = array(
					'setting' => $setting,
					'found'   => self::show( $bad ),
					'target'  => $target,
					'value'   => '',
					'status'  => 'skip',
					'reason'  => __( 'This entry isn\'t valid for this setting in Perdita, so it\'s left out.', 'perdita-core' ),
				);
			}
			if ( empty( $check['clean'] ) && ! empty( $value ) ) {
				return false;
			}
		} elseif ( ! $check['ok'] ) {
			$this->building['rows'][] = array(
				'setting' => $setting,
				'found'   => $secret ? self::mask( $found ) : self::show( $found ),
				'target'  => $target,
				'value'   => '',
				'status'  => 'skip',
				'reason'  => isset( $opts['refused'] ) ? (string) $opts['refused'] : __( 'This value isn\'t valid for this setting in Perdita, so it\'s left out.', 'perdita-core' ),
			);
			return false;
		}
		// A list goes in as the check left it. A single value goes in as
		// given, so a secret reaches the module's sanitize() in plain text
		// and is encrypted there, once.
		self::set_path( $this->building['patch'], $key, is_array( $value ) ? $check['clean'] : $value );
		$this->building['rows'][] = array(
			'setting' => $setting,
			'found'   => $secret ? self::mask( $found ) : self::show( $found ),
			'target'  => $target,
			'value'   => $secret ? self::mask( $value ) : self::show( $check['clean'] ),
			'status'  => 'import',
			'reason'  => isset( $opts['note'] ) ? (string) $opts['note'] : '',
		);
		return true;
	}

	/**
	 * A setting found with a value Perdita won't take.
	 *
	 * @param string $setting Setting name.
	 * @param mixed  $found   Value, for display.
	 * @param string $reason  Why.
	 * @param string $target  Perdita setting it would have mapped to.
	 */
	protected function skip( $setting, $found, $reason, $target = '' ) {
		$this->building['rows'][] = array(
			'setting' => $setting,
			'found'   => self::show( $found ),
			'target'  => $target,
			'value'   => '',
			'status'  => 'skip',
			'reason'  => (string) $reason,
		);
	}

	/**
	 * A setting with no Perdita equivalent.
	 *
	 * @param string $setting Setting name.
	 * @param mixed  $found   Value, for display.
	 * @param string $reason  What Perdita does instead, or that it has none.
	 */
	protected function unsupported( $setting, $found, $reason ) {
		$this->building['rows'][] = array(
			'setting' => $setting,
			'found'   => self::show( $found ),
			'target'  => '',
			'value'   => '',
			'status'  => 'none',
			'reason'  => (string) $reason,
		);
	}

	/**
	 * A sentence under the table.
	 *
	 * @param string $text Note.
	 */
	protected function note( $text ) {
		$this->building['notes'][] = (string) $text;
	}

	/* ---------- helpers ---------- */

	/**
	 * A value as a short line of text for the preview table.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	public static function show( $value ) {
		if ( is_bool( $value ) ) {
			return $value ? __( 'on', 'perdita-core' ) : __( 'off', 'perdita-core' );
		}
		if ( is_array( $value ) ) {
			$flat = array();
			foreach ( $value as $item ) {
				$flat[] = is_scalar( $item ) ? (string) $item : '…';
			}
			$value = implode( ', ', $flat );
		}
		if ( is_object( $value ) ) {
			return '…';
		}
		$value = wp_strip_all_tags( (string) $value );
		// Direction overrides and zero-width characters could make one value
		// read as another in the preview.
		$value = (string) preg_replace( '/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u', '', $value );
		$value = preg_replace( '/\s+/', ' ', $value );
		return function_exists( 'mb_strimwidth' ) ? mb_strimwidth( (string) $value, 0, 160, '…' ) : substr( (string) $value, 0, 160 );
	}

	/**
	 * A secret as its last 4 characters at most.
	 *
	 * @param mixed $secret Secret.
	 * @return string
	 */
	public static function mask( $secret ) {
		$secret = is_scalar( $secret ) ? (string) $secret : '';
		if ( '' === $secret ) {
			return '';
		}
		$tail = strlen( $secret ) > 8 ? substr( $secret, -4 ) : '';
		return '••••' . $tail;
	}

	/**
	 * Set a dot-path key in an array.
	 *
	 * @param array  $arr   Array, by reference.
	 * @param string $path  Dot path.
	 * @param mixed  $value Value.
	 */
	public static function set_path( array &$arr, $path, $value ) {
		$node = &$arr;
		foreach ( explode( '.', (string) $path ) as $part ) {
			if ( ! isset( $node[ $part ] ) || ! is_array( $node[ $part ] ) ) {
				$node[ $part ] = array();
			}
			$node = &$node[ $part ];
		}
		$node = $value;
	}

	/**
	 * Read an option that may be a JSON string or an array, as an array.
	 *
	 * @param string $name Option name.
	 * @return array
	 */
	protected static function json_option( $name ) {
		$raw = get_option( $name, array() );
		if ( is_string( $raw ) && '' !== $raw ) {
			if ( strlen( $raw ) > 2000000 ) {
				return array();
			}
			$raw = json_decode( $raw, true );
		}
		return is_array( $raw ) ? self::scalars_only( $raw ) : array();
	}

	/**
	 * Read an array option, with every object dropped. WordPress unserializes
	 * options itself, so a stored object arrives as __PHP_Incomplete_Class or
	 * a real class. Neither is a setting.
	 *
	 * @param string $name Option name.
	 * @return array
	 */
	protected static function array_option( $name ) {
		$raw = get_option( $name, array() );
		return is_array( $raw ) ? self::scalars_only( $raw ) : array();
	}

	/**
	 * An array with objects removed at every depth, and strings cut at 64 KB.
	 *
	 * @param array $arr   Array.
	 * @param int   $depth Current depth.
	 * @return array
	 */
	public static function scalars_only( array $arr, $depth = 0 ) {
		$out = array();
		if ( $depth > 8 ) {
			return $out;
		}
		foreach ( $arr as $k => $v ) {
			if ( is_array( $v ) ) {
				$out[ $k ] = self::scalars_only( $v, $depth + 1 );
			} elseif ( is_scalar( $v ) || null === $v ) {
				$out[ $k ] = is_string( $v ) && strlen( $v ) > 65536 ? '' : $v;
			}
		}
		return $out;
	}

	/**
	 * A scalar from an array, or a default.
	 *
	 * @param array  $arr     Array.
	 * @param string $key     Key.
	 * @param mixed  $default Default.
	 * @return mixed
	 */
	protected static function val( array $arr, $key, $default = '' ) {
		return isset( $arr[ $key ] ) && is_scalar( $arr[ $key ] ) ? $arr[ $key ] : $default;
	}

	/**
	 * Whether a flag value means on ('on', '1', 1, true, 'yes').
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	protected static function on( $value ) {
		if ( is_bool( $value ) ) {
			return $value;
		}
		return in_array( strtolower( trim( (string) $value ) ), array( '1', 'on', 'yes', 'true' ), true );
	}
}
