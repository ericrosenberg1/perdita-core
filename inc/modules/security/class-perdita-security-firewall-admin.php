<?php
/**
 * Firewall section of the Security screen.
 *
 * Stable interface (the main Security screen calls these two and nothing else):
 *
 *   Perdita_Security_Firewall_Admin::render_section( array $settings ): void
 *     Prints the firewall fields and the recent-blocks log inside the main
 *     settings form. $settings is Perdita_Security::get_settings(). Each log
 *     row has an Allow link (admin-post.php?action=perdita_firewall_allow,
 *     nonced per entry) that writes one exclusion.
 *
 *   Perdita_Security_Firewall_Admin::sanitize( array $input ): array
 *     Takes the unslashed $_POST of the main save and returns the clean
 *     firewall keys: firewall, firewall_mode, firewall_load, fw_rules,
 *     fw_trust_roles, fw_exclusions. Returns array() when the form didn't
 *     carry this section (hidden field perdita_fw_section), so the stored
 *     values stay.
 *
 * Both methods are static. The config compiles on the option update that
 * follows the save (Perdita_Firewall_Config).
 *
 * @package Perdita_Core
 */

defined( 'ABSPATH' ) || exit;

class Perdita_Security_Firewall_Admin {

	/**
	 * Rule category labels.
	 *
	 * @return array
	 */
	public static function categories() {
		return array(
			'sqli'       => __( 'SQL injection', 'perdita-core' ),
			'xss'        => __( 'Cross-site scripting', 'perdita-core' ),
			'traversal'  => __( 'Path traversal and local file includes (../, php://, null bytes)', 'perdita-core' ),
			'rfi'        => __( 'Remote file includes', 'perdita-core' ),
			'php_upload' => __( 'PHP files in uploads from visitors', 'perdita-core' ),
			'bad_bots'   => __( 'Vulnerability scanners (sqlmap, WPScan, Nikto, and others)', 'perdita-core' ),
			'probes'     => __( 'Probes for .env, .git, wp-config.php backups, and debug.log', 'perdita-core' ),
			'xmlrpc'     => __( 'XML-RPC requests, when you turn XML-RPC off below', 'perdita-core' ),
		);
	}

	/**
	 * Capabilities the trust cookie can require.
	 *
	 * @return array
	 */
	public static function trust_caps() {
		return array(
			'edit_posts'         => __( 'Contributors and up (edit_posts)', 'perdita-core' ),
			'publish_posts'      => __( 'Authors and up (publish_posts)', 'perdita-core' ),
			'edit_others_posts'  => __( 'Editors and up (edit_others_posts)', 'perdita-core' ),
			'manage_options'     => __( 'Administrators only (manage_options)', 'perdita-core' ),
			'manage_woocommerce' => __( 'Shop managers and administrators (manage_woocommerce)', 'perdita-core' ),
		);
	}

	/**
	 * Print the section.
	 *
	 * @param array $settings Security settings.
	 */
	public static function render_section( $settings ) {
		$s     = array_merge( Perdita_Firewall_Config::defaults(), is_array( $settings ) ? $settings : array() );
		$rules = is_array( $s['fw_rules'] ) ? $s['fw_rules'] : array();

		echo '<h2>' . esc_html__( 'Firewall', 'perdita-core' ) . '</h2>';
		echo '<input type="hidden" name="perdita_fw_section" value="1" />';
		echo '<p class="description perdita-security-note">' . esc_html__( 'Start in monitor mode for a few days and read the log below. You stay signed in and trusted: anyone with the role you pick below gets a signed cookie, and Perdita skips the content rules for their requests. The allowlist always wins. To turn the firewall off in an emergency, add define( \'PERDITA_FIREWALL_DISABLE\', true ); to wp-config.php, or delete wp-content/perdita-firewall/.', 'perdita-core' ) . '</p>';
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row">' . esc_html__( 'Firewall', 'perdita-core' ) . '</th><td><label><input type="checkbox" name="firewall" value="1"' . checked( ! empty( $s['firewall'] ), true, false ) . ' /> ' . esc_html__( 'Turn on the firewall', 'perdita-core' ) . '</label></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Mode', 'perdita-core' ) . '</th><td><fieldset>';
		foreach ( array(
			'monitor' => __( 'Monitor: log what would be blocked, and let it through', 'perdita-core' ),
			'block'   => __( 'Block: answer a match with a 403 page', 'perdita-core' ),
		) as $value => $label ) {
			echo '<label><input type="radio" name="firewall_mode" value="' . esc_attr( $value ) . '"' . checked( $s['firewall_mode'], $value, false ) . ' /> ' . esc_html( $label ) . '</label><br />';
		}
		echo '</fieldset></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Loader', 'perdita-core' ) . '</th><td><fieldset>';
		foreach ( array(
			'plugin'  => __( 'Plugin: Perdita Core runs the firewall as it loads (no files written)', 'perdita-core' ),
			'mu'      => __( 'Must-use plugin: Perdita runs the firewall before every other plugin (writes wp-content/mu-plugins/0-perdita-firewall.php)', 'perdita-core' ),
			'prepend' => __( 'Before WordPress: PHP runs the firewall first through auto_prepend_file (writes a marked block in .user.ini or .htaccess)', 'perdita-core' ),
		) as $value => $label ) {
			echo '<label><input type="radio" name="firewall_load" value="' . esc_attr( $value ) . '"' . checked( $s['firewall_load'], $value, false ) . ' /> ' . esc_html( $label ) . '</label><br />';
		}
		if ( ! empty( $s['firewall'] ) ) {
			require_once __DIR__ . '/firewall/class-perdita-firewall-loader.php';
			$status = Perdita_Firewall_Loader::status();
			echo '<p class="description"><strong>' . ( $status['working'] ? esc_html__( 'Working.', 'perdita-core' ) : esc_html__( 'Status:', 'perdita-core' ) ) . '</strong> ' . esc_html( $status['message'] ) . '</p>';
		}
		echo '</fieldset></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Rules', 'perdita-core' ) . '</th><td><fieldset>';
		foreach ( self::categories() as $cat => $label ) {
			$on = ! array_key_exists( $cat, $rules ) || ! empty( $rules[ $cat ] );
			echo '<label><input type="checkbox" name="fw_rules[' . esc_attr( $cat ) . ']" value="1"' . checked( $on, true, false ) . ' /> ' . esc_html( $label ) . '</label><br />';
		}
		echo '</fieldset></td></tr>';

		echo '<tr><th scope="row"><label for="pfw-trust">' . esc_html__( 'Trusted signed-in users', 'perdita-core' ) . '</label></th><td><select id="pfw-trust" name="fw_trust_roles">';
		foreach ( self::trust_caps() as $cap => $label ) {
			echo '<option value="' . esc_attr( $cap ) . '"' . selected( $s['fw_trust_roles'], $cap, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select><p class="description">' . esc_html__( 'Perdita skips the content rules for their posts, block editor saves, and Customizer changes. IP blocks still apply.', 'perdita-core' ) . '</p></td></tr>';

		$lines = array();
		foreach ( (array) $s['fw_exclusions'] as $ex ) {
			if ( is_array( $ex ) && ! empty( $ex['rule'] ) ) {
				$lines[] = $ex['rule'] . ' | ' . ( isset( $ex['path'] ) ? $ex['path'] : '' ) . ' | ' . ( isset( $ex['param'] ) ? $ex['param'] : '' );
			}
		}
		echo '<tr><th scope="row"><label for="pfw-ex">' . esc_html__( 'Exclusions', 'perdita-core' ) . '</label></th><td>';
		echo '<textarea id="pfw-ex" name="fw_exclusions_text" rows="4" cols="60" class="large-text code">' . esc_textarea( implode( "\n", $lines ) ) . '</textarea>';
		echo '<p class="description">' . esc_html__( 'One per line: rule id or category | path | parameter name (optional). Perdita applies an exclusion for /wp-json/my-plugin to /wp-json/my-plugin/v1 too, and never to /wp-json/my-plugin-pro. For example: sqli-005 | /wp-json/my-plugin | query. Click Allow in the log below to add a line.', 'perdita-core' ) . '</p></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Built-in exclusions', 'perdita-core' ) . '</th><td><p class="description">' . esc_html__( 'On every site, Perdita skips the traversal, remote include, and sqli-005 rules for the search box (s) and the comment field on wp-comments-post.php, so visitors can write about code. Perdita still checks both for UNION, stacked, and time-delay SQL.', 'perdita-core' ) . '</p></td></tr>';

		echo '</tbody></table>';
		self::render_log();
	}

	/**
	 * Print the recent-blocks log.
	 */
	private static function render_log() {
		$entries = Perdita_Firewall_Config::recent( 50 );
		echo '<h3>' . esc_html__( 'Recent firewall matches', 'perdita-core' ) . '</h3>';
		if ( ! $entries ) {
			echo '<p>' . esc_html__( 'Nothing logged in the last 14 days.', 'perdita-core' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array( __( 'Time', 'perdita-core' ), __( 'Action', 'perdita-core' ), __( 'Rule', 'perdita-core' ), __( 'Where', 'perdita-core' ), __( 'Address', 'perdita-core' ), __( 'Request', 'perdita-core' ), '' ) as $head ) {
			echo '<th scope="col">' . esc_html( $head ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $entries as $e ) {
			$ref = preg_replace( '/[^a-f0-9]/', '', (string) $e['ref'] );
			echo '<tr>';
			echo '<td>' . esc_html( wp_date( 'Y-m-d H:i', isset( $e['time'] ) ? (int) $e['time'] : 0 ) ) . '</td>';
			echo '<td>' . esc_html( isset( $e['action'] ) ? (string) $e['action'] : '' ) . '</td>';
			echo '<td><code>' . esc_html( isset( $e['rule'] ) ? (string) $e['rule'] : '' ) . '</code></td>';
			echo '<td>' . esc_html( isset( $e['field'] ) ? (string) $e['field'] : '' ) . '<br /><code>' . esc_html( isset( $e['match'] ) ? (string) $e['match'] : '' ) . '</code></td>';
			echo '<td>' . esc_html( isset( $e['ip'] ) ? (string) $e['ip'] : '' ) . '</td>';
			echo '<td>' . esc_html( ( isset( $e['method'] ) ? $e['method'] . ' ' : '' ) . ( isset( $e['uri'] ) ? substr( (string) $e['uri'], 0, 120 ) : '' ) ) . '<br /><small>' . esc_html( isset( $e['ref'] ) ? $ref : '' ) . '</small></td>';
			echo '<td>';
			if ( Perdita_Firewall_Config::exclusion_for_entry( $e ) ) {
				$url = wp_nonce_url( admin_url( 'admin-post.php?action=perdita_firewall_allow&ref=' . $ref ), 'perdita_firewall_allow_' . $ref );
				echo '<a class="button button-small" href="' . esc_url( $url ) . '">' . esc_html__( 'Allow', 'perdita-core' ) . '</a>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Clean the firewall keys from the main save.
	 *
	 * @param array $input Unslashed $_POST.
	 * @return array
	 */
	public static function sanitize( $input ) {
		if ( ! is_array( $input ) || empty( $input['perdita_fw_section'] ) ) {
			return array();
		}
		$mode  = isset( $input['firewall_mode'] ) && is_string( $input['firewall_mode'] ) ? sanitize_key( $input['firewall_mode'] ) : 'monitor';
		$load  = isset( $input['firewall_load'] ) && is_string( $input['firewall_load'] ) ? sanitize_key( $input['firewall_load'] ) : 'plugin';
		$cap   = isset( $input['fw_trust_roles'] ) && is_string( $input['fw_trust_roles'] ) ? sanitize_key( $input['fw_trust_roles'] ) : 'edit_posts';
		$given = isset( $input['fw_rules'] ) && is_array( $input['fw_rules'] ) ? $input['fw_rules'] : array();
		$rules = array();
		foreach ( array_keys( self::categories() ) as $cat ) {
			$rules[ $cat ] = ! empty( $given[ $cat ] );
		}
		if ( isset( $input['fw_exclusions_text'] ) && is_string( $input['fw_exclusions_text'] ) ) {
			$raw = $input['fw_exclusions_text'];
		} elseif ( isset( $input['fw_exclusions'] ) ) {
			$raw = $input['fw_exclusions'];
		} else {
			$raw = array();
		}
		return array(
			'firewall'       => ! empty( $input['firewall'] ),
			'firewall_mode'  => in_array( $mode, array( 'monitor', 'block' ), true ) ? $mode : 'monitor',
			'firewall_load'  => in_array( $load, array( 'plugin', 'mu', 'prepend' ), true ) ? $load : 'plugin',
			'fw_rules'       => $rules,
			'fw_trust_roles' => array_key_exists( $cap, self::trust_caps() ) ? $cap : 'edit_posts',
			'fw_exclusions'  => self::sanitize_exclusions( $raw ),
		);
	}

	/**
	 * Clean exclusions from text lines ("rule | path | param") or a list of
	 * arrays with rule, path, and param.
	 *
	 * @param string|array $raw Input.
	 * @return array[]
	 */
	public static function sanitize_exclusions( $raw ) {
		$items = array();
		if ( is_string( $raw ) ) {
			foreach ( preg_split( '/\r\n|\r|\n/', $raw ) as $line ) {
				$parts = array_map( 'trim', explode( '|', $line ) );
				if ( '' !== $parts[0] ) {
					$items[] = array(
						'rule'  => $parts[0],
						'path'  => isset( $parts[1] ) ? $parts[1] : '',
						'param' => isset( $parts[2] ) ? $parts[2] : '',
					);
				}
			}
		} elseif ( is_array( $raw ) ) {
			$items = $raw;
		}
		require_once __DIR__ . '/firewall/class-perdita-firewall.php';
		$cats = array_keys( self::categories() );
		$out  = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || ! isset( $item['rule'] ) || ! is_string( $item['rule'] ) ) {
				continue;
			}
			$rule = strtolower( trim( $item['rule'] ) );
			if ( ! in_array( $rule, $cats, true ) && ! preg_match( '/^[a-z]+-[a-z0-9_-]{1,40}$/', $rule ) ) {
				continue;
			}
			$path = isset( $item['path'] ) && is_string( $item['path'] ) ? trim( $item['path'] ) : '';
			$path = '' === $path ? '' : Perdita_Firewall::exclusion_path( $path );
			$param = isset( $item['param'] ) && is_string( $item['param'] ) ? preg_replace( '/[^\w\[\].-]/', '', trim( $item['param'] ) ) : '';
			$clean = array(
				'rule'  => $rule,
				'path'  => strtolower( substr( $path, 0, 200 ) ),
				'param' => substr( $param, 0, 100 ),
			);
			if ( ! in_array( $clean, $out, true ) ) {
				$out[] = $clean;
			}
			if ( count( $out ) >= 500 ) {
				break;
			}
		}
		return $out;
	}
}
