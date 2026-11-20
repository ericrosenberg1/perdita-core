<?php
/**
 * Perdita Firewall engine.
 *
 * Pure PHP 7.4+, no WordPress function calls, so it can run before WordPress
 * loads (auto_prepend_file), from a must-use plugin, or from Perdita Core's
 * main file. WordPress compiles the settings into
 * wp-content/perdita-firewall/config.php (Perdita_Firewall_Config::compile())
 * and the engine only ever reads that one array.
 *
 * Entry points:
 *   Perdita_Firewall::run( $config, $server, $get, $post, $cookie, $files, $body )
 *     returns a verdict array and has no side effects.
 *   Perdita_Firewall::boot( $config_path, $loader )
 *     reads the compiled config and the superglobals, runs, logs a match, and
 *     sends the 403 (or 429) page on a block.
 *
 * Verdict: array(
 *   'action'   => 'allow' | 'block' | 'monitor' | 'throttle',
 *   'reason'   => why the request was allowed without inspection, or '',
 *   'rule'     => rule id such as 'sqli-001', or '',
 *   'category' => sqli | xss | traversal | rfi | php_upload | bad_bots | probes
 *                 | xmlrpc | ip_blocklist | country | rate_limit | custom, or '',
 *   'field'    => where it matched: 'uri', 'ua', 'query:<name>', 'post:<name>',
 *                 'cookie:<name>', 'json:<name>', 'file:<name>', 'header:<name>',
 *                 'ip', or '',
 *   'match'    => up to 120 bytes around the match,
 *   'ip'       => the visitor address the engine used,
 *   'trusted'  => whether a valid trust cookie came with the request,
 *   'status'   => HTTP status for a block (403 or 429),
 *   'logged'   => list of custom rule ids whose action is 'log' and matched,
 *   'notes'    => list of strings, such as 'rate_limits_skipped_no_apcu' or
 *                 'inspection_truncated',
 * )
 *
 * Config sections Perdita Pro may add through the perdita_firewall_config
 * filter. Each one is a no-op when absent. The engine evaluates them in this
 * order, after the allowlist and before the built-in rules:
 *   'ip_blocklist' => list of IPv4, IPv6, or CIDR strings (the compiler parses
 *                     them into 'blocklist'). Blocks even trusted visitors.
 *   'country'      => array( 'mode' => 'off'|'block'|'allow',
 *                     'countries' => array( 'US', ... ), 'scope' => 'site'|'login',
 *                     'trust_server' => bool ). See country_code().
 *   'rate_limits'  => list of array( 'route' => '/prefix' | 'login' | 'xmlrpc' |
 *                     'rest' | 'wc-ajax' | 'any' | '404', 'limit' => per minute,
 *                     'action' => 'block'|'throttle', 'block_minutes' => int ).
 *                     Needs APCu. Without it the engine skips them and says so
 *                     in the verdict notes. '404' is left to WordPress.
 *   'custom_rules' => list of array( 'id' => string, 'field' => 'uri'|'query'|
 *                     'post'|'cookie'|'header:<name>'|'user_agent'|'method'|'ip',
 *                     'op' => 'contains'|'equals'|'starts'|'regex', 'value' => string,
 *                     'action' => 'block'|'allow'|'log', 'enabled' => bool ).
 *                     'allow' skips every built-in rule for the request.
 *
 * @package Perdita_Core
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'PERDITA_FIREWALL_EARLY' ) ) {
	exit;
}

if ( class_exists( 'Perdita_Firewall', false ) ) {
	return;
}

class Perdita_Firewall {

	/**
	 * Engine version. The compiler writes it into the config.
	 */
	const VERSION = '1';

	/**
	 * Cap on one inspected value, in bytes.
	 */
	const VALUE_CAP = 65536;

	/**
	 * Cap on everything inspected in one request, in bytes.
	 */
	const TOTAL_CAP = 262144;

	/**
	 * Log cap per day, in bytes.
	 */
	const LOG_CAP = 5242880;

	/**
	 * Days of log files kept.
	 */
	const LOG_DAYS = 14;

	/**
	 * Name of the signed trust cookie.
	 */
	const TRUST_COOKIE = 'perdita_fw_trust';

	/**
	 * Characters that can't start an attack on their own. A value made only of
	 * these skips the content regex unless it names a SQL keyword.
	 */
	const SAFE_CHARS = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789 _.,-@';

	/**
	 * Whether boot() already ran in this request.
	 *
	 * @var bool
	 */
	private static $booted = false;

	/**
	 * Compiled regex cache, keyed by the rule set it covers.
	 *
	 * @var array
	 */
	private static $regex = array();

	/**
	 * Bytes inspected in the current run().
	 *
	 * @var int
	 */
	private static $inspected = 0;

	/**
	 * Whether the current run() hit the inspection cap.
	 *
	 * @var bool
	 */
	private static $truncated = false;

	/* ---------- rules ---------- */

	/**
	 * Every built-in content rule, by category and stable id. Each pattern runs
	 * on a normalized value: URL and HTML entity decoded, SQL comments taken
	 * out, and lower case. Patterns look for structure (a quote, then a
	 * tautology, a column list after UNION SELECT, a function call with a
	 * number) and never for a bare keyword, so "1 Union Select Rd" and a
	 * product named "Drop Table" pass.
	 *
	 * @return array category => array( id => pattern without delimiters ).
	 */
	public static function rules() {
		$q = '[\'"`]';
		return array(
			'sqli'      => array(
				// UNION SELECT followed by a column list an attacker would use.
				'sqli-001' => '\bunion(?:[\s(]+(?:all|distinct))?(?:[\s(]|#[^\n]*\n)+select[\s(]+(?:null|\d+\s*(?:,|--|#|$|\)|from\b)|@@\w+|0x[0-9a-f]+|char\s*\(|concat(?:_ws)?\s*\(|group_concat\s*\(|(?:version|user|database|schema)\s*\(\s*\))',
				// UNION SELECT ... FROM table, ending the clause.
				'sqli-002' => '\bunion(?:[\s(]+(?:all|distinct))?[\s(]+select\s.{1,300}?\bfrom\s+[\w`.]+\s*(?:where\b|limit\b|order\b|group\b|--|#|;|\)|$)',
				// Time-based blind injection.
				'sqli-003' => '\b(?:sleep|pg_sleep|benchmark)\s*\(\s*\d|\bwaitfor\s+delay\s*\'|\bdbms_pipe\.receive_message\s*\(',
				// Schema and file access.
				'sqli-004' => '\binformation_schema\b|\bmysql\.(?:user|db)\b|\bload_file\s*\(|\binto\s+(?:out|dump)file\b|\bsys\.(?:objects|tables|columns|user_summary)\b|\bpg_(?:catalog|shadow)\b|\bsqlite_master\b',
				// A quote or a number, then a tautology such as or 1=1 or 'x'='x'.
				'sqli-005' => $q . '\s*(?:or|and|xor|\|\||&&)\s*' . $q . '?(\w+)' . $q . '?\s*(?:=|<=>|\blike\b)\s*' . $q . '?\g{-1}\b|(?:^\s*-?\d+|[\'"`)])\s*(?:or|and|xor)\s+(\d+)\s*(?:=|<=>)\s*\g{-1}\b|' . $q . '\s*(?:or|\|\|)\s+(?:true|1)\s*(?:--|#|$)',
				// A quote that closes the string, then a comment that drops the rest.
				'sqli-006' => $q . '\s*\)*\s*(?:--[\s-]?[\w-]{0,3}|#|/\*)\s*$',
				// Stacked query after a closing quote, paren, or number.
				'sqli-007' => '[\'"`)\d]\s*;\s*(?:drop\s+(?:table|database)|delete\s+from|insert\s+into|update\s+[\w`]+\s+set|truncate\s+table|alter\s+table|create\s+(?:table|user)|exec(?:ute)?\s+\w|declare\s+@|shutdown\b|select\s.+?\bfrom\b)',
				// Error-based extraction and encoded strings.
				'sqli-008' => '\b(?:extractvalue|updatexml)\s*\(\s*[^,]{0,40},\s*(?:concat|0x|char|\(\s*select)|\bexp\s*\(\s*\~\s*\(|\b(?:concat(?:_ws)?|group_concat)\s*\(\s*(?:0x[0-9a-f]+|char\s*\(|@@|\(?\s*select\b)|\bchar\s*\(\s*\d+(?:\s*,\s*\d+){3,}\s*\)|@@(?:version|datadir|hostname|basedir)\b',
				// Boolean and nested subqueries.
				'sqli-009' => '\(\s*select\s+(?:count\s*\(|\*\s+from|\d+\s*(?:from\b|\*|,)|@@|sleep\s*\(|concat|char\s*\(|substr(?:ing)?\s*\(|ascii\s*\(|if\s*\(|case\s+when)|\b(?:ascii|ord|substr|substring|mid|length)\s*\(\s*\(\s*select\b',
				// Column counting and procedure probes.
				'sqli-010' => $q . '\s*\)*\s*(?:order|group)\s+by\s+\d+\s*(?:--|#|$)|\bprocedure\s+analyse\s*\(',
			),
			'xss'       => array(
				'xss-001' => '<\s*/?\s*script\b',
				// An event handler inside a tag.
				'xss-002' => '<[a-z!/][^>]{0,500}?[\s/"\'`]on[a-z]{3,}\s*=',
				// A script URL in an attribute, or a script URL that calls something.
				'xss-003' => '(?:href|src|action|formaction|xlink:href|data|background|lowsrc|poster|srcset)\s*=\s*[\'"`]?\s*(?:j\s*a\s*v\s*a|v\s*b|l\s*i\s*v\s*e)\s*s\s*c\s*r\s*i\s*p\s*t\s*:|(?:java|vb)script\s*:\s*[\w$.\[\]\'"]+\s*[(`]|\bdata\s*:\s*text/html\s*[;,]',
				// Tags that load other documents or code.
				'xss-004' => '<\s*(?:iframe|frame|frameset|object|embed|applet|base|portal)\b|<\s*meta\b[^>]{0,200}http-equiv|<\s*link\b[^>]{0,200}rel\s*=\s*[\'"]?\s*import',
				// Style and srcdoc tricks.
				'xss-005' => '\bsrcdoc\s*=|\bstyle\s*=[^>]{0,300}(?:expression\s*\(|url\s*\(\s*[\'"]?\s*javascript:|behavior\s*:)|-moz-binding\s*:',
				// The usual proof-of-concept calls.
				'xss-006' => '\b(?:alert|prompt|confirm)\s*(?:\(|`)\s*(?:[\'"`]?\s*(?:xss|document\.|window\.)|\d|document\.|string\.fromcharcode)|\beval\s*\(\s*(?:atob|unescape|string\.fromcharcode|location|name)\b',
			),
			'traversal' => array(
				'trav-001' => '(?:\.\.[/\\\\]){2,}|(?:^|[/\\\\=])\.\.[/\\\\].{0,200}?(?:wp-config|etc[/\\\\]passwd|\.env\b|\.htaccess|\.git\b|win\.ini|boot\.ini|proc[/\\\\]self)',
				'trav-002' => '(?<![a-z0-9])(?:php|phar|zip|compress\.zlib|compress\.bzip2|expect|glob|ogg|rar|ssh2(?:\.\w+)?)://|(?<![a-z0-9])(?:file:///|data://)',
				// trav-003 is the null byte, checked outside the regex.
				'trav-004' => '(?:^|[^\w.])/?(?:etc/(?:passwd|shadow|group|issue)\b|proc/(?:self|\d+)/(?:environ|cmdline|fd)\b|windows[/\\\\]win\.ini\b|boot\.ini\b)',
				'trav-005' => '^[^\s]*wp-config\.php$',
			),
			'rfi'       => array(
				'rfi-001' => '^(?:https?|ftps?)://\S+?\.(?:txt|php\d?|phtml|inc|gif|jpe?g|png)\?+$',
				// rfi-002 is a remote URL in an include-style parameter, checked outside the regex.
				'rfi-003' => '\ballow_url_include\b|\bauto_(?:prepend|append)_file\b',
			),
		);
	}

	/**
	 * Literal strings a normalized value has to hold before a rule can match,
	 * by rule id. null means the rule always runs. Long values (a block
	 * editor body) skip every rule whose needles are absent, which keeps a
	 * 30 KB body well under a millisecond.
	 *
	 * @return array id => string[]|null.
	 */
	public static function needles() {
		return array(
			'sqli-001' => array( 'union' ),
			'sqli-002' => array( 'union' ),
			'sqli-003' => array( 'sleep', 'benchmark', 'waitfor', 'dbms_pipe' ),
			'sqli-004' => array( 'information_schema', 'mysql.', 'load_file', 'outfile', 'dumpfile', 'sys.', 'pg_catalog', 'pg_shadow', 'sqlite_master' ),
			'sqli-005' => null,
			'sqli-006' => array( '--', '#', '/*' ),
			'sqli-007' => array( ';' ),
			'sqli-008' => array( 'extractvalue', 'updatexml', 'exp', 'concat', 'char', '@@' ),
			'sqli-009' => array( 'select' ),
			'sqli-010' => array( 'by', 'analyse' ),
			'xss-001'  => array( 'script' ),
			'xss-002'  => array( '=' ),
			'xss-003'  => array( ':' ),
			'xss-004'  => array( '<' ),
			'xss-005'  => array( 'srcdoc', 'style', 'binding' ),
			'xss-006'  => array( 'alert', 'prompt', 'confirm', 'eval' ),
			'trav-001' => array( '..' ),
			'trav-002' => array( 'php://', 'phar://', 'zip://', 'compress.', 'expect://', 'glob://', 'ogg://', 'rar://', 'ssh2', 'file:///', 'data://' ),
			'trav-004' => array( 'etc/', 'proc/', 'win.ini', 'boot.ini' ),
			'trav-005' => array( 'wp-config' ),
			'rfi-001'  => null,
			'rfi-003'  => array( 'allow_url', 'auto_' ),
		);
	}

	/**
	 * Request-level rules on the URI path and the user agent.
	 *
	 * @return array id => pattern.
	 */
	public static function request_rules() {
		return array(
			'probe-001' => '(?:^|/)\.(?:env(?:\.[\w-]+)?|git|svn|hg|bzr|ds_store|aws|ssh|htpasswd|npmrc|docker|vscode|idea)(?:/|$)',
			'probe-002' => '/wp-config\.php(?:[\~#]|[._-][\w.\~-]*)$|/wp-config[._-](?:bak|old|orig|save|swp|txt|backup|copy|1)\b',
			'probe-003' => '/(?:debug|error|errors|php_errors?)\.log$|/error_log$|/[\w.-]+\.(?:sql|sql\.(?:gz|zip|bz2)|mdb|sqlite3?)$',
			'bot-001'   => 'sqlmap|wpscan|nikto|nessus|openvas|acunetix|netsparker|havij|w3af|dirbuster|gobuster|\bdirb\b|wfuzz|fuzz faster u fool|\bnuclei\b|masscan|zgrab|nmap scripting engine|\bzmeu\b|morfeus|fimap|commix|jaeles|arachni|\bparos\b|webinspect|appscan|\bhydra\b|whatweb|joomscan|feroxbuster|\bffuf\b|xsstrike|cmsmap|blackwidow',
		);
	}

	/**
	 * Parameter names a remote include rides in (rfi-002).
	 */
	const INCLUDE_PARAMS = '/^(?:file|path|template|include|inc|dir|doc|document|folder|root|load|abspath|mosconfig_absolute_path)$/';

	/**
	 * Field names never inspected: passwords are hashed, never queried, and
	 * may hold any character.
	 */
	const SKIP_PARAMS = '/^(?:pwd|pass|pass1|pass2|password|passwd|user_pass|new_password|password_1|password_2|password_current|account_password|post_password|pass1-text|confirm_password)$/';

	/* ---------- boot ---------- */

	/**
	 * Read the compiled config and the superglobals, run, log, and answer a
	 * block. Safe to call more than once per request: only the first call runs.
	 *
	 * @param string $config_path Absolute path to config.php.
	 * @param string $loader      prepend, mu, or plugin.
	 * @return array|null The verdict, or null when nothing ran.
	 */
	public static function boot( $config_path, $loader = 'plugin' ) {
		if ( self::$booted || defined( 'PERDITA_FIREWALL_LOADED' ) ) {
			return null;
		}
		self::$booted = true;
		define( 'PERDITA_FIREWALL_LOADED', (string) $loader );
		if ( defined( 'PERDITA_FIREWALL_DISABLE' ) && PERDITA_FIREWALL_DISABLE ) {
			return null;
		}
		if ( 'cli' === PHP_SAPI || ! is_string( $config_path ) || ! is_file( $config_path ) ) {
			return null;
		}
		$config = include $config_path;
		if ( ! is_array( $config ) || empty( $config['enabled'] ) ) {
			return null;
		}
		$server  = $_SERVER;
		$body_fn = function () use ( $server ) {
			$type = isset( $server['CONTENT_TYPE'] ) ? strtolower( (string) $server['CONTENT_TYPE'] ) : '';
			if ( false === strpos( $type, 'json' ) ) {
				return '';
			}
			$raw = @file_get_contents( 'php://input', false, null, 0, self::TOTAL_CAP ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- runs before WordPress.
			return is_string( $raw ) ? $raw : '';
		};
		$verdict = self::run( $config, $server, $_GET, $_POST, $_COOKIE, $_FILES, $body_fn ); // phpcs:ignore WordPress.Security.NonceVerification -- a firewall reads every request.
		if ( 'allow' !== $verdict['action'] || ! empty( $verdict['logged'] ) ) {
			self::log( $config, $verdict, $server );
		}
		if ( in_array( $verdict['action'], array( 'block', 'throttle' ), true ) ) {
			self::respond( $verdict );
		}
		return $verdict;
	}

	/* ---------- run ---------- */

	/**
	 * Inspect one request. No side effects except APCu counters for Pro's
	 * rate limits.
	 *
	 * @param array                $config Compiled config.
	 * @param array                $server $_SERVER.
	 * @param array                $get    $_GET.
	 * @param array                $post   $_POST.
	 * @param array                $cookie $_COOKIE.
	 * @param array                $files  $_FILES.
	 * @param string|callable|null $body   Raw body, or a callable that returns it (read only for JSON from an untrusted visitor).
	 * @return array Verdict, see the file docblock.
	 */
	public static function run( array $config, array $server, array $get, array $post, array $cookie, array $files, $body = null ) {
		self::$inspected = 0;
		self::$truncated = false;
		$verdict         = array(
			'action'   => 'allow',
			'reason'   => '',
			'rule'     => '',
			'category' => '',
			'field'    => '',
			'match'    => '',
			'ip'       => '',
			'trusted'  => false,
			'status'   => 0,
			'logged'   => array(),
			'notes'    => array(),
		);
		if ( empty( $config['enabled'] ) ) {
			$verdict['reason'] = 'off';
			return $verdict;
		}
		$block_action = ( isset( $config['mode'] ) && 'block' === $config['mode'] ) ? 'block' : 'monitor';
		$ip           = self::client_ip( $config, $server );
		$verdict['ip'] = $ip;

		// 1. The allowlist always wins.
		if ( ! empty( $config['allowlist'] ) && '' !== $ip && self::ip_in( $ip, $config['allowlist'] ) ) {
			$verdict['reason'] = 'allowlist';
			return $verdict;
		}

		$uri   = isset( $server['REQUEST_URI'] ) ? (string) $server['REQUEST_URI'] : '/';
		$qpos  = strpos( $uri, '?' );
		$path  = false === $qpos ? $uri : substr( $uri, 0, $qpos );
		$npath = self::normalize( $path );
		$rules = isset( $config['rules'] ) && is_array( $config['rules'] ) ? $config['rules'] : array();

		// 2. Pro: IP blocklist. Blocks trusted visitors too.
		if ( ! empty( $config['blocklist'] ) && '' !== $ip && self::ip_in( $ip, $config['blocklist'] ) ) {
			return self::hit( $verdict, $block_action, 'ip-001', 'ip_blocklist', 'ip', $ip, 403 );
		}

		// 3. Pro: country rules.
		if ( ! empty( $config['country'] ) && is_array( $config['country'] ) ) {
			$c = self::check_country( $config, $server, $ip, $npath );
			if ( '' !== $c ) {
				return self::hit( $verdict, $block_action, 'country-001', 'country', 'ip', $c, 403 );
			}
		}

		// 4. Pro: rate limits.
		if ( ! empty( $config['rate_limits'] ) && is_array( $config['rate_limits'] ) ) {
			$r = self::check_rate_limits( $config['rate_limits'], $server, $get, $ip, $npath, $verdict );
			if ( null !== $r ) {
				return $r;
			}
		}

		// 5. Pro: custom rules.
		if ( ! empty( $config['custom_rules'] ) && is_array( $config['custom_rules'] ) ) {
			$r = self::check_custom( $config['custom_rules'], $server, $get, $post, $cookie, $ip, $uri, $verdict, $block_action );
			if ( null !== $r ) {
				return $r;
			}
		}

		// 6. WordPress's own cron loopback is never inspected.
		if ( '/wp-cron.php' === substr( $npath, -12 ) && self::is_loopback( $ip, $server ) ) {
			$verdict['reason'] = 'cron_loopback';
			return $verdict;
		}

		$exclusions = self::exclusions_for( $config, $npath );

		// 7. XML-RPC, when the owner turned it off.
		if ( ! empty( $rules['xmlrpc'] ) && ! empty( $config['disable_xmlrpc'] ) && '/xmlrpc.php' === substr( $npath, -11 ) && ! self::excluded( $exclusions, 'xmlrpc-001', 'xmlrpc', '' ) ) {
			return self::hit( $verdict, $block_action, 'xmlrpc-001', 'xmlrpc', 'uri', $path, 403 );
		}

		$req = self::request_rules();
		// 8. Probes for secrets routed to PHP.
		if ( ! empty( $rules['probes'] ) && '' !== $npath && '/' !== $npath ) {
			foreach ( array( 'probe-001', 'probe-002', 'probe-003' ) as $id ) {
				if ( preg_match( '~' . $req[ $id ] . '~', $npath, $m ) && ! self::excluded( $exclusions, $id, 'probes', '' ) ) {
					return self::hit( $verdict, $block_action, $id, 'probes', 'uri', $m[0], 403 );
				}
			}
		}

		$ua = isset( $server['HTTP_USER_AGENT'] ) ? (string) $server['HTTP_USER_AGENT'] : '';
		// 9. Scanner user agents.
		if ( ! empty( $rules['bad_bots'] ) && '' !== $ua && preg_match( '~' . $req['bot-001'] . '~i', $ua, $m ) && ! self::excluded( $exclusions, 'bot-001', 'bad_bots', '' ) ) {
			return self::hit( $verdict, $block_action, 'bot-001', 'bad_bots', 'ua', $m[0], 403 );
		}

		// 10. A verified trust cookie skips the content rules.
		if ( isset( $cookie[ self::TRUST_COOKIE ] ) && ! empty( $config['key'] ) && false !== self::verify_trust( (string) $cookie[ self::TRUST_COOKIE ], (string) $config['key'] ) ) {
			$verdict['trusted'] = true;
			$verdict['reason']  = 'trusted';
			return $verdict;
		}

		// 11. Content rules.
		$cats = array();
		foreach ( array( 'sqli', 'xss', 'traversal', 'rfi' ) as $cat ) {
			if ( ! empty( $rules[ $cat ] ) ) {
				$cats[] = $cat;
			}
		}
		if ( $cats ) {
			$ctx = array(
				'cats'       => $cats,
				'exclusions' => $exclusions,
				'rfi'        => in_array( 'rfi', $cats, true ),
				'trav'       => in_array( 'traversal', $cats, true ),
				'host'       => isset( $config['host'] ) ? (string) $config['host'] : '',
			);
			if ( '/' !== $path ) {
				$r = self::scan_value( $ctx, 'uri', '', $path, false );
				if ( $r ) {
					return self::hit( $verdict, $block_action, $r[0], $r[1], 'uri', $r[2], 403 );
				}
			}
			foreach ( array( 'query' => $get, 'post' => $post ) as $source => $values ) {
				if ( $values ) {
					$r = self::scan_array( $ctx, $source, $values );
					if ( $r ) {
						return self::hit( $verdict, $block_action, $r[0], $r[1], $r[3], $r[2], 403 );
					}
				}
			}
			if ( null !== $body && empty( $post ) ) {
				$type = isset( $server['CONTENT_TYPE'] ) ? strtolower( (string) $server['CONTENT_TYPE'] ) : '';
				if ( false !== strpos( $type, 'json' ) ) {
					$raw = is_callable( $body ) ? call_user_func( $body ) : (string) $body;
					if ( is_string( $raw ) && '' !== $raw ) {
						$json = json_decode( substr( $raw, 0, self::TOTAL_CAP ), true, 64 );
						if ( is_array( $json ) ) {
							$r = self::scan_array( $ctx, 'json', $json );
						} elseif ( is_string( $json ) ) {
							$r = self::scan_value( $ctx, 'json', '', $json, true );
							$r = $r ? array( $r[0], $r[1], $r[2], 'json:' ) : null;
						} else {
							$r = null;
						}
						if ( $r ) {
							return self::hit( $verdict, $block_action, $r[0], $r[1], $r[3], $r[2], 403 );
						}
					}
				}
			}
			if ( '' !== $ua ) {
				$r = self::scan_value( $ctx, 'ua', '', $ua, false );
				if ( $r ) {
					return self::hit( $verdict, $block_action, $r[0], $r[1], 'ua', $r[2], 403 );
				}
			}
			if ( $cookie && in_array( 'sqli', $cats, true ) ) {
				$sctx         = $ctx;
				$sctx['cats'] = array( 'sqli' );
				$sctx['rfi']  = false;
				$sctx['trav'] = false;
				foreach ( $cookie as $name => $value ) {
					if ( is_string( $value ) && '' !== $value && 0 !== strpos( (string) $name, 'wordpress_' ) && self::TRUST_COOKIE !== $name ) {
						$r = self::scan_value( $sctx, 'cookie', (string) $name, $value, false );
						if ( $r ) {
							return self::hit( $verdict, $block_action, $r[0], $r[1], 'cookie:' . $name, $r[2], 403 );
						}
					}
				}
			}
		}

		// 12. PHP files in an untrusted upload.
		if ( ! empty( $rules['php_upload'] ) && $files ) {
			$r = self::check_files( $files, $exclusions );
			if ( $r ) {
				return self::hit( $verdict, $block_action, $r[0], 'php_upload', $r[1], $r[2], 403 );
			}
		}

		if ( self::$truncated ) {
			$verdict['notes'][] = 'inspection_truncated';
		}
		return $verdict;
	}

	/**
	 * Fill a verdict for a match.
	 *
	 * @param array  $verdict  Verdict so far.
	 * @param string $action   block or monitor.
	 * @param string $rule     Rule id.
	 * @param string $category Category.
	 * @param string $field    Field.
	 * @param string $match    Matched text.
	 * @param int    $status   HTTP status for a block.
	 * @return array
	 */
	private static function hit( array $verdict, $action, $rule, $category, $field, $match, $status ) {
		$verdict['action']   = $action;
		$verdict['rule']     = $rule;
		$verdict['category'] = $category;
		$verdict['field']    = $field;
		$verdict['match']    = substr( (string) $match, 0, 120 );
		$verdict['status']   = (int) $status;
		if ( self::$truncated ) {
			$verdict['notes'][] = 'inspection_truncated';
		}
		return $verdict;
	}

	/* ---------- content scanning ---------- */

	/**
	 * Scan a nested array of request values.
	 *
	 * @param array  $ctx    Scan context.
	 * @param string $source query, post, or json.
	 * @param array  $values Values.
	 * @return array|null array( rule, category, match, field ) or null.
	 */
	private static function scan_array( array $ctx, $source, array $values ) {
		foreach ( $values as $name => $value ) {
			$name = (string) $name;
			if ( preg_match( self::SKIP_PARAMS, $name ) ) {
				continue;
			}
			if ( '' !== $name && strspn( $name, self::SAFE_CHARS . '[]' ) !== strlen( $name ) ) {
				$r = self::scan_value( $ctx, $source, $name, $name, false );
				if ( $r ) {
					return array( $r[0], $r[1], $r[2], $source . ':' . $name );
				}
			}
			$r = is_array( $value ) ? self::scan_nested( $ctx, $source, $name, $value, 1 ) : ( is_scalar( $value ) ? self::scan_value( $ctx, $source, $name, (string) $value, true ) : null );
			if ( $r ) {
				return array( $r[0], $r[1], $r[2], $source . ':' . $name );
			}
			if ( self::$inspected >= self::TOTAL_CAP ) {
				self::$truncated = true;
				return null;
			}
		}
		return null;
	}

	/**
	 * Scan the values under one top-level parameter.
	 *
	 * @param array  $ctx    Scan context.
	 * @param string $source Source.
	 * @param string $param  Top-level parameter name.
	 * @param array  $values Nested values.
	 * @param int    $depth  Depth.
	 * @return array|null
	 */
	private static function scan_nested( array $ctx, $source, $param, array $values, $depth ) {
		if ( $depth > 16 ) {
			return null;
		}
		foreach ( $values as $key => $value ) {
			if ( is_string( $key ) && preg_match( self::SKIP_PARAMS, $key ) ) {
				continue;
			}
			if ( is_array( $value ) ) {
				$r = self::scan_nested( $ctx, $source, $param, $value, $depth + 1 );
			} elseif ( is_string( $value ) ) {
				$r = self::scan_value( $ctx, $source, $param, $value, true );
			} else {
				continue;
			}
			if ( $r ) {
				return $r;
			}
			if ( self::$inspected >= self::TOTAL_CAP ) {
				self::$truncated = true;
				return null;
			}
		}
		return null;
	}

	/**
	 * Scan one value against the enabled content categories.
	 *
	 * @param array  $ctx      Scan context.
	 * @param string $source   uri, ua, query, post, json, cookie.
	 * @param string $param    Top-level parameter name, or ''.
	 * @param string $value    Raw value.
	 * @param bool   $is_param Whether the value is a parameter value (rfi-002, trav-005 apply).
	 * @return array|null array( rule, category, match ) or null.
	 */
	private static function scan_value( array $ctx, $source, $param, $value, $is_param ) {
		$len = strlen( $value );
		if ( $len < 3 ) {
			return null;
		}
		if ( $len > self::VALUE_CAP ) {
			$value           = substr( $value, 0, self::VALUE_CAP );
			$len             = self::VALUE_CAP;
			self::$truncated = true;
		}
		if ( self::$inspected >= self::TOTAL_CAP ) {
			self::$truncated = true;
			return null;
		}
		self::$inspected += $len;

		if ( $ctx['trav'] && false !== strpos( $value, "\0" ) && ! self::excluded( $ctx['exclusions'], 'trav-003', 'traversal', $param ) ) {
			return array( 'trav-003', 'traversal', 'null byte' );
		}
		// A value of plain words, digits, and simple punctuation can only be
		// an attack when it names UNION or INFORMATION_SCHEMA.
		if ( strspn( $value, self::SAFE_CHARS ) === $len && false === stripos( $value, 'union' ) && false === stripos( $value, 'information_schema' ) ) {
			return null;
		}
		$norm = self::normalize( $value );
		if ( $ctx['rfi'] && $is_param && '' !== $param && preg_match( self::INCLUDE_PARAMS, strtolower( $param ) ) && preg_match( '~^\s*(?:(?:https?|ftps?)://([^/\s?#:]+)|\\\\\\\\[^\\\\]+)~', $norm, $m ) ) {
			$host = isset( $m[1] ) ? $m[1] : '';
			if ( ( '' === $host || $host !== $ctx['host'] ) && ! self::excluded( $ctx['exclusions'], 'rfi-002', 'rfi', $param ) ) {
				return array( 'rfi-002', 'rfi', $m[0] );
			}
		}
		if ( $len > 256 ) {
			return self::scan_long( $ctx, $param, $norm, $is_param );
		}
		$skip = array();
		for ( $i = 0; $i < 8; $i++ ) {
			$re = self::regex( $ctx['cats'], $skip, $is_param );
			if ( '' === $re || ! preg_match( $re, $norm, $m ) || empty( $m['MARK'] ) ) {
				return null;
			}
			$id  = $m['MARK'];
			$cat = self::category_of( $id );
			if ( ! self::excluded( $ctx['exclusions'], $id, $cat, $param ) ) {
				return array( $id, $cat, $m[0] );
			}
			$skip[] = $id;
		}
		return null;
	}

	/**
	 * Scan a long normalized value one rule at a time, skipping each rule
	 * whose needles it doesn't hold.
	 *
	 * @param array  $ctx      Scan context.
	 * @param string $param    Parameter name.
	 * @param string $norm     Normalized value.
	 * @param bool   $is_param Whether parameter-only rules apply.
	 * @return array|null array( rule, category, match ) or null.
	 */
	private static function scan_long( array $ctx, $param, $norm, $is_param ) {
		static $needles = null;
		if ( null === $needles ) {
			$needles = self::needles();
		}
		$all = self::rules();
		foreach ( $ctx['cats'] as $cat ) {
			if ( ! isset( $all[ $cat ] ) ) {
				continue;
			}
			foreach ( $all[ $cat ] as $id => $pattern ) {
				if ( ! $is_param && ( 'trav-005' === $id || 'rfi-001' === $id ) ) {
					continue;
				}
				if ( isset( $needles[ $id ] ) ) {
					$found = false;
					foreach ( $needles[ $id ] as $needle ) {
						if ( false !== strpos( $norm, $needle ) ) {
							$found = true;
							break;
						}
					}
					if ( ! $found ) {
						continue;
					}
				}
				if ( preg_match( '~' . $pattern . '~s', $norm, $m ) && ! self::excluded( $ctx['exclusions'], $id, $cat, $param ) ) {
					return array( $id, $cat, $m[0] );
				}
			}
		}
		return null;
	}

	/**
	 * The combined regex for a set of categories, each rule tagged with its id.
	 *
	 * @param string[] $cats     Categories.
	 * @param string[] $skip     Rule ids to leave out (excluded for this field).
	 * @param bool     $is_param Whether parameter-only rules apply.
	 * @return string
	 */
	private static function regex( array $cats, array $skip, $is_param ) {
		$key = implode( ',', $cats ) . '|' . implode( ',', $skip ) . '|' . ( $is_param ? 1 : 0 );
		if ( isset( self::$regex[ $key ] ) ) {
			return self::$regex[ $key ];
		}
		$all   = self::rules();
		$parts = array();
		foreach ( $cats as $cat ) {
			if ( ! isset( $all[ $cat ] ) ) {
				continue;
			}
			foreach ( $all[ $cat ] as $id => $pattern ) {
				if ( in_array( $id, $skip, true ) || ( ! $is_param && 'trav-005' === $id ) || ( ! $is_param && 'rfi-001' === $id ) ) {
					continue;
				}
				$parts[] = '(?:' . $pattern . ')(*MARK:' . $id . ')';
			}
		}
		self::$regex[ $key ] = $parts ? '~' . implode( '|', $parts ) . '~s' : '';
		return self::$regex[ $key ];
	}

	/**
	 * Category of a rule id.
	 *
	 * @param string $id Rule id.
	 * @return string
	 */
	public static function category_of( $id ) {
		$map = array(
			'sqli'    => 'sqli',
			'xss'     => 'xss',
			'trav'    => 'traversal',
			'rfi'     => 'rfi',
			'upload'  => 'php_upload',
			'bot'     => 'bad_bots',
			'probe'   => 'probes',
			'xmlrpc'  => 'xmlrpc',
			'ip'      => 'ip_blocklist',
			'country' => 'country',
			'rate'    => 'rate_limit',
			'custom'  => 'custom',
		);
		$prefix = strtok( (string) $id, '-' );
		return isset( $map[ $prefix ] ) ? $map[ $prefix ] : '';
	}

	/**
	 * Decode and fold a value once: URL encoding (twice when the first pass
	 * leaves %XX behind, which is how %2527 hides a quote), numeric and named
	 * HTML entities, SQL comments (MySQL's /*!50000 version comments keep
	 * their content), then lower case.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	public static function normalize( $value ) {
		$value = (string) $value;
		if ( false !== strpos( $value, '%' ) ) {
			$value = rawurldecode( $value );
			if ( false !== strpos( $value, '%' ) && preg_match( '/%[0-9a-fA-F]{2}/', $value ) ) {
				$value = rawurldecode( $value );
			}
		}
		if ( false !== strpos( $value, '&' ) ) {
			if ( false !== strpos( $value, '&#' ) ) {
				$value = preg_replace_callback(
					'/&#(x?)([0-9a-f]{1,8});?/i',
					function ( $m ) {
						$n = '' === $m[1] ? (int) $m[2] : hexdec( $m[2] );
						return $n > 0 && $n < 128 ? chr( $n ) : ' ';
					},
					$value
				);
			}
			$value = html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		}
		if ( false !== strpos( $value, '/*' ) ) {
			$value = preg_replace( array( '~/\*!\d*~', '~/\*.*?\*/~s', '~\*/~' ), ' ', $value );
		}
		return strtolower( $value );
	}

	/**
	 * Uploaded files: a PHP-like file name (upl-001) or PHP open tags in the
	 * first 2 KB of the file (upl-002).
	 *
	 * @param array $files      $_FILES.
	 * @param array $exclusions Exclusions for this path.
	 * @return array|null array( rule, field, match ).
	 */
	private static function check_files( array $files, array $exclusions ) {
		foreach ( $files as $param => $file ) {
			if ( ! is_array( $file ) || ! isset( $file['name'] ) ) {
				continue;
			}
			$flat_names = self::flatten( (array) $file['name'] );
			$flat_tmps  = isset( $file['tmp_name'] ) ? self::flatten( (array) $file['tmp_name'] ) : array();
			foreach ( $flat_names as $i => $name ) {
				$name = strtolower( trim( (string) $name ) );
				if ( '' === $name ) {
					continue;
				}
				if ( preg_match( '~\.(?:php\d*|phtml|pht|phar|phps|pgif|shtml|asp|aspx|jsp|cgi)(?:[.\s]|$)|(?:^|/)\.(?:htaccess|user\.ini)$|\x00~', $name ) && ! self::excluded( $exclusions, 'upload-001', 'php_upload', (string) $param ) ) {
					return array( 'upload-001', 'file:' . $param, $name );
				}
				$tmp = isset( $flat_tmps[ $i ] ) ? (string) $flat_tmps[ $i ] : '';
				if ( '' !== $tmp && is_file( $tmp ) && is_readable( $tmp ) ) {
					$head = (string) @file_get_contents( $tmp, false, null, 0, 2048 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- an uploaded temp file.
					if ( preg_match( '~<\?(?:php\b|=)~i', $head ) && ! self::excluded( $exclusions, 'upload-002', 'php_upload', (string) $param ) ) {
						return array( 'upload-002', 'file:' . $param, $name );
					}
				}
			}
		}
		return null;
	}

	/* ---------- exclusions ---------- */

	/**
	 * The exclusions whose path prefix covers this path.
	 *
	 * @param array  $config Config.
	 * @param string $npath  Normalized path.
	 * @return array
	 */
	private static function exclusions_for( array $config, $npath ) {
		if ( empty( $config['exclusions'] ) || ! is_array( $config['exclusions'] ) ) {
			return array();
		}
		$out = array();
		foreach ( $config['exclusions'] as $ex ) {
			if ( ! is_array( $ex ) || empty( $ex['rule'] ) ) {
				continue;
			}
			$prefix = isset( $ex['path'] ) ? strtolower( (string) $ex['path'] ) : '';
			if ( '' === $prefix || 0 === strpos( $npath, $prefix ) ) {
				$out[] = $ex;
			}
		}
		return $out;
	}

	/**
	 * Whether an exclusion covers a rule (by id or category) for a parameter.
	 *
	 * @param array  $exclusions Exclusions for this path.
	 * @param string $id         Rule id.
	 * @param string $category   Category.
	 * @param string $param      Parameter name, or '' for the URI and user agent.
	 * @return bool
	 */
	private static function excluded( array $exclusions, $id, $category, $param ) {
		foreach ( $exclusions as $ex ) {
			if ( $ex['rule'] !== $id && $ex['rule'] !== $category ) {
				continue;
			}
			$p = isset( $ex['param'] ) ? (string) $ex['param'] : '';
			if ( '' === $p || $p === $param ) {
				return true;
			}
		}
		return false;
	}

	/* ---------- addresses ---------- */

	/**
	 * The visitor address: CF-Connecting-IP when the connection came from a
	 * Cloudflare range in the config, else REMOTE_ADDR. The same logic as
	 * Perdita_Client_IP::get().
	 *
	 * @param array $config Config.
	 * @param array $server $_SERVER.
	 * @return string
	 */
	public static function client_ip( array $config, array $server ) {
		$remote = isset( $server['REMOTE_ADDR'] ) ? trim( (string) $server['REMOTE_ADDR'] ) : '';
		if ( '' !== $remote && ! empty( $server['HTTP_CF_CONNECTING_IP'] ) && ! empty( $config['cf_ranges'] ) && self::ip_in( $remote, $config['cf_ranges'] ) ) {
			$header = trim( (string) $server['HTTP_CF_CONNECTING_IP'] );
			if ( false !== filter_var( $header, FILTER_VALIDATE_IP ) ) {
				return $header;
			}
		}
		return $remote;
	}

	/**
	 * Parse a list of addresses and CIDR blocks for ip_in().
	 *
	 * @param string[] $list Addresses or CIDR blocks, IPv4 or IPv6.
	 * @return array array( 'v4' => list of array( start, end ), 'v6' => list of array( hex network, bits ) ).
	 */
	public static function parse_ranges( $list ) {
		$out = array(
			'v4' => array(),
			'v6' => array(),
		);
		foreach ( (array) $list as $item ) {
			if ( ! is_string( $item ) ) {
				continue;
			}
			$parts = explode( '/', trim( $item ), 2 );
			$bin   = @inet_pton( $parts[0] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a malformed entry is skipped.
			if ( false === $bin ) {
				continue;
			}
			$max = strlen( $bin ) * 8;
			if ( isset( $parts[1] ) && ( '' === $parts[1] || ! ctype_digit( $parts[1] ) || (int) $parts[1] > $max ) ) {
				continue;
			}
			$bits = isset( $parts[1] ) ? (int) $parts[1] : $max;
			if ( 4 === strlen( $bin ) ) {
				$start = ip2long( $parts[0] );
				$mask  = 0 === $bits ? 0 : ( ( 0xFFFFFFFF << ( 32 - $bits ) ) & 0xFFFFFFFF );
				$start = $start & $mask;
				$out['v4'][] = array( $start, $start | ( ~$mask & 0xFFFFFFFF ) );
			} else {
				$out['v6'][] = array( bin2hex( $bin ), $bits );
			}
		}
		return $out;
	}

	/**
	 * Whether an address falls in parsed ranges.
	 *
	 * @param string $ip     Address.
	 * @param array  $ranges From parse_ranges().
	 * @return bool
	 */
	public static function ip_in( $ip, $ranges ) {
		if ( ! is_array( $ranges ) ) {
			return false;
		}
		if ( false === strpos( $ip, ':' ) ) {
			if ( empty( $ranges['v4'] ) ) {
				return false;
			}
			$long = ip2long( $ip );
			if ( false === $long ) {
				return false;
			}
			foreach ( $ranges['v4'] as $r ) {
				if ( $long >= $r[0] && $long <= $r[1] ) {
					return true;
				}
			}
			return false;
		}
		if ( empty( $ranges['v6'] ) ) {
			return false;
		}
		$bin = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a malformed address never matches.
		if ( false === $bin || 16 !== strlen( $bin ) ) {
			return false;
		}
		foreach ( $ranges['v6'] as $r ) {
			$net  = hex2bin( $r[0] );
			$bits = (int) $r[1];
			$full = intdiv( $bits, 8 );
			if ( substr( $net, 0, $full ) !== substr( $bin, 0, $full ) ) {
				continue;
			}
			$rest = $bits % 8;
			if ( 0 === $rest ) {
				return true;
			}
			$mask = chr( ( 0xff << ( 8 - $rest ) ) & 0xff );
			if ( ( $net[ $full ] & $mask ) === ( $bin[ $full ] & $mask ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether the address is this server talking to itself.
	 *
	 * @param string $ip     Visitor address.
	 * @param array  $server $_SERVER.
	 * @return bool
	 */
	private static function is_loopback( $ip, array $server ) {
		if ( '127.0.0.1' === $ip || '::1' === $ip || 0 === strpos( $ip, '127.' ) ) {
			return true;
		}
		return isset( $server['SERVER_ADDR'] ) && '' !== $ip && (string) $server['SERVER_ADDR'] === $ip;
	}

	/* ---------- trust cookie ---------- */

	/**
	 * The signed trust cookie value for a user.
	 *
	 * @param int    $user_id User id.
	 * @param int    $expires Unix time.
	 * @param string $key     HMAC key.
	 * @return string
	 */
	public static function trust_value( $user_id, $expires, $key ) {
		$data = (int) $user_id . '.' . (int) $expires;
		return $data . '.' . hash_hmac( 'sha256', $data, (string) $key );
	}

	/**
	 * Verify a trust cookie.
	 *
	 * @param string   $value Cookie value.
	 * @param string   $key   HMAC key.
	 * @param int|null $now   Unix time, for tests.
	 * @return int|false The user id, or false.
	 */
	public static function verify_trust( $value, $key, $now = null ) {
		if ( '' === (string) $key || ! preg_match( '/^(\d{1,20})\.(\d{1,12})\.([0-9a-f]{64})$/', (string) $value, $m ) ) {
			return false;
		}
		$now = null === $now ? time() : (int) $now;
		if ( (int) $m[1] < 1 || (int) $m[2] < $now ) {
			return false;
		}
		$want = hash_hmac( 'sha256', $m[1] . '.' . $m[2], (string) $key );
		return hash_equals( $want, $m[3] ) ? (int) $m[1] : false;
	}

	/* ---------- Pro sections ---------- */

	/**
	 * The visitor's country code: CF-IPCountry from a Cloudflare connection,
	 * then GEOIP_COUNTRY_CODE or HTTP_X_COUNTRY_CODE when the owner says the
	 * server sets one, then Perdita_Firewall_Geo::lookup() when Pro put that
	 * class in this folder. '' when nothing knows.
	 *
	 * @param array  $config Config.
	 * @param array  $server $_SERVER.
	 * @param string $ip     Visitor address.
	 * @return string Two upper-case letters, or ''.
	 */
	public static function country_code( array $config, array $server, $ip ) {
		$remote = isset( $server['REMOTE_ADDR'] ) ? (string) $server['REMOTE_ADDR'] : '';
		if ( ! empty( $server['HTTP_CF_IPCOUNTRY'] ) && ! empty( $config['cf_ranges'] ) && self::ip_in( $remote, $config['cf_ranges'] ) ) {
			$cc = strtoupper( trim( (string) $server['HTTP_CF_IPCOUNTRY'] ) );
			if ( preg_match( '/^[A-Z][A-Z0-9]$/', $cc ) ) {
				return $cc;
			}
		}
		if ( ! empty( $config['country']['trust_server'] ) ) {
			foreach ( array( 'GEOIP_COUNTRY_CODE', 'HTTP_X_COUNTRY_CODE' ) as $var ) {
				if ( ! empty( $server[ $var ] ) ) {
					$cc = strtoupper( trim( (string) $server[ $var ] ) );
					if ( preg_match( '/^[A-Z]{2}$/', $cc ) ) {
						return $cc;
					}
				}
			}
		}
		$geo = __DIR__ . '/class-perdita-firewall-geo.php';
		if ( '' !== (string) $ip && is_file( $geo ) ) {
			include_once $geo;
			if ( class_exists( 'Perdita_Firewall_Geo', false ) && is_callable( array( 'Perdita_Firewall_Geo', 'lookup' ) ) ) {
				$cc = strtoupper( (string) call_user_func( array( 'Perdita_Firewall_Geo', 'lookup' ), (string) $ip, $config ) );
				if ( preg_match( '/^[A-Z]{2}$/', $cc ) ) {
					return $cc;
				}
			}
		}
		return '';
	}

	/**
	 * Country rule. An unknown country never blocks, so a missing database
	 * can't lock anyone out.
	 *
	 * @param array  $config Config.
	 * @param array  $server $_SERVER.
	 * @param string $ip     Visitor address.
	 * @param string $npath  Normalized path.
	 * @return string The blocked country code, or ''.
	 */
	private static function check_country( array $config, array $server, $ip, $npath ) {
		$c    = $config['country'];
		$mode = isset( $c['mode'] ) ? (string) $c['mode'] : 'off';
		if ( 'block' !== $mode && 'allow' !== $mode ) {
			return '';
		}
		if ( isset( $c['scope'] ) && 'login' === $c['scope'] && '/wp-login.php' !== substr( $npath, -13 ) && '/xmlrpc.php' !== substr( $npath, -11 ) ) {
			return '';
		}
		$list = isset( $c['countries'] ) ? array_map( 'strtoupper', (array) $c['countries'] ) : array();
		$cc   = self::country_code( $config, $server, $ip );
		if ( '' === $cc ) {
			return '';
		}
		$listed = in_array( $cc, $list, true );
		return ( 'block' === $mode && $listed ) || ( 'allow' === $mode && ! $listed ) ? $cc : '';
	}

	/**
	 * Rate limits with APCu. Skipped, and noted in the verdict, without it.
	 *
	 * @param array  $limits  Rate limit rules.
	 * @param array  $server  $_SERVER.
	 * @param array  $get     $_GET.
	 * @param string $ip      Visitor address.
	 * @param string $npath   Normalized path.
	 * @param array  $verdict Verdict so far, by reference for notes.
	 * @return array|null A block or throttle verdict, or null.
	 */
	private static function check_rate_limits( array $limits, array $server, array $get, $ip, $npath, array &$verdict ) {
		if ( ! function_exists( 'apcu_enabled' ) || ! apcu_enabled() ) {
			$verdict['notes'][] = 'rate_limits_skipped_no_apcu';
			return null;
		}
		if ( '' === $ip ) {
			return null;
		}
		$method = isset( $server['REQUEST_METHOD'] ) ? strtoupper( (string) $server['REQUEST_METHOD'] ) : 'GET';
		$minute = (int) floor( time() / 60 );
		foreach ( $limits as $i => $rule ) {
			if ( ! is_array( $rule ) || empty( $rule['route'] ) || empty( $rule['limit'] ) ) {
				continue;
			}
			$route = (string) $rule['route'];
			if ( '404' === $route ) {
				$verdict['notes'][] = 'rate_limit_404_in_wordpress';
				continue;
			}
			$applies = false;
			if ( 'any' === $route ) {
				$applies = true;
			} elseif ( 'login' === $route ) {
				$applies = '/wp-login.php' === substr( $npath, -13 ) && 'POST' === $method;
			} elseif ( 'xmlrpc' === $route ) {
				$applies = '/xmlrpc.php' === substr( $npath, -11 );
			} elseif ( 'rest' === $route ) {
				$applies = false !== strpos( $npath, '/wp-json/' ) || isset( $get['rest_route'] );
			} elseif ( 'wc-ajax' === $route ) {
				$applies = isset( $get['wc-ajax'] );
			} elseif ( '/' === $route[0] ) {
				$applies = 0 === strpos( $npath, strtolower( $route ) );
			}
			if ( ! $applies ) {
				continue;
			}
			$id    = 'rate-' . str_pad( (string) ( (int) $i + 1 ), 3, '0', STR_PAD_LEFT );
			$base  = 'perdita_fw_rl_' . md5( $ip . '|' . $route );
			$block = ! empty( $rule['action'] ) && 'block' === $rule['action'];
			if ( $block && apcu_exists( $base . '_b' ) ) {
				return self::hit( $verdict, 'block', $id, 'rate_limit', 'ip', $ip, 429 );
			}
			$count = apcu_inc( $base . '_' . $minute, 1, $ok, 120 );
			if ( false === $count ) {
				apcu_store( $base . '_' . $minute, 1, 120 );
				$count = 1;
			}
			if ( $count > (int) $rule['limit'] ) {
				if ( $block ) {
					$minutes = isset( $rule['block_minutes'] ) ? max( 1, (int) $rule['block_minutes'] ) : 15;
					apcu_store( $base . '_b', 1, $minutes * 60 );
					return self::hit( $verdict, 'block', $id, 'rate_limit', 'ip', $ip, 429 );
				}
				return self::hit( $verdict, 'throttle', $id, 'rate_limit', 'ip', $ip, 429 );
			}
		}
		return null;
	}

	/**
	 * Custom rules.
	 *
	 * @param array  $rules        Custom rules.
	 * @param array  $server       $_SERVER.
	 * @param array  $get          $_GET.
	 * @param array  $post         $_POST.
	 * @param array  $cookie       $_COOKIE.
	 * @param string $ip           Visitor address.
	 * @param string $uri          REQUEST_URI.
	 * @param array  $verdict      Verdict so far, by reference for 'logged'.
	 * @param string $block_action block or monitor.
	 * @return array|null A final verdict, or null to go on.
	 */
	private static function check_custom( array $rules, array $server, array $get, array $post, array $cookie, $ip, $uri, array &$verdict, $block_action ) {
		foreach ( $rules as $rule ) {
			if ( ! is_array( $rule ) || empty( $rule['enabled'] ) || ! isset( $rule['field'], $rule['op'], $rule['value'] ) ) {
				continue;
			}
			$field = (string) $rule['field'];
			$vals  = array();
			if ( 'uri' === $field ) {
				$vals = array( $uri );
			} elseif ( 'query' === $field ) {
				$vals = self::flatten( $get );
			} elseif ( 'post' === $field ) {
				$vals = self::flatten( $post );
			} elseif ( 'cookie' === $field ) {
				$vals = self::flatten( $cookie );
			} elseif ( 'user_agent' === $field ) {
				$vals = array( isset( $server['HTTP_USER_AGENT'] ) ? (string) $server['HTTP_USER_AGENT'] : '' );
			} elseif ( 'method' === $field ) {
				$vals = array( isset( $server['REQUEST_METHOD'] ) ? (string) $server['REQUEST_METHOD'] : 'GET' );
			} elseif ( 'ip' === $field ) {
				$vals = array( $ip );
			} elseif ( 0 === strpos( $field, 'header:' ) ) {
				$h    = 'HTTP_' . strtoupper( str_replace( '-', '_', substr( $field, 7 ) ) );
				$vals = isset( $server[ $h ] ) ? array( (string) $server[ $h ] ) : array();
			}
			if ( '' === (string) $rule['value'] ) {
				continue;
			}
			$hit = null;
			foreach ( $vals as $v ) {
				if ( self::custom_match( (string) $rule['op'], (string) $rule['value'], (string) $v, 'ip' === $field ) ) {
					$hit = (string) $v;
					break;
				}
			}
			if ( null === $hit ) {
				continue;
			}
			$id     = 'custom-' . preg_replace( '/[^a-z0-9_-]/i', '', (string) ( isset( $rule['id'] ) ? $rule['id'] : '' ) );
			$action = isset( $rule['action'] ) ? (string) $rule['action'] : 'block';
			if ( 'allow' === $action ) {
				$verdict['reason'] = $id;
				return $verdict;
			}
			if ( 'log' === $action ) {
				$verdict['logged'][] = $id;
				continue;
			}
			return self::hit( $verdict, $block_action, $id, 'custom', $field, $hit, 403 );
		}
		return null;
	}

	/**
	 * One custom rule comparison, case-insensitive. For the ip field, equals
	 * also takes a CIDR block.
	 *
	 * @param string $op    contains, equals, starts, or regex.
	 * @param string $want  Rule value.
	 * @param string $have  Request value.
	 * @param bool   $is_ip Whether the field is the visitor address.
	 * @return bool
	 */
	private static function custom_match( $op, $want, $have, $is_ip ) {
		if ( 'contains' === $op ) {
			return false !== stripos( $have, $want );
		}
		if ( 'starts' === $op ) {
			return 0 === strncasecmp( $have, $want, strlen( $want ) );
		}
		if ( 'equals' === $op ) {
			if ( $is_ip && false !== strpos( $want, '/' ) ) {
				return self::ip_in( $have, self::parse_ranges( array( $want ) ) );
			}
			return 0 === strcasecmp( $have, $want );
		}
		if ( 'regex' === $op ) {
			return 1 === @preg_match( '#' . str_replace( '#', '\#', $want ) . '#i', $have ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a broken owner pattern never matches.
		}
		return false;
	}

	/**
	 * Flatten nested request values to a list of strings.
	 *
	 * @param array $values Values.
	 * @return string[]
	 */
	private static function flatten( array $values ) {
		$out = array();
		array_walk_recursive(
			$values,
			function ( $v ) use ( &$out ) {
				if ( is_scalar( $v ) ) {
					$out[] = (string) $v;
				}
			}
		);
		return $out;
	}

	/* ---------- log and response ---------- */

	/**
	 * Append one JSON line to today's log. One file per day (UTC), 5 MB cap
	 * per day, 14 days kept. Each file starts with an exit line so a web
	 * server that runs it as PHP prints nothing.
	 *
	 * @param array $config  Config.
	 * @param array $verdict Verdict.
	 * @param array $server  $_SERVER.
	 * @return string The reference id, or '' when nothing was written.
	 */
	public static function log( array $config, array &$verdict, array $server ) {
		$dir = isset( $config['log_dir'] ) ? (string) $config['log_dir'] : '';
		if ( '' === $dir ) {
			return '';
		}
		$ref                = isset( $verdict['ref'] ) ? $verdict['ref'] : bin2hex( random_bytes( 6 ) );
		$verdict['ref']     = $ref;
		$dir                = rtrim( $dir, '/\\' ) . '/';
		$file               = $dir . gmdate( 'Y-m-d' ) . '.log.php';
		$new                = ! is_file( $file );
		if ( $new && ! is_dir( $dir ) && ! @mkdir( $dir, 0755, true ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- runs before WordPress.
			return '';
		}
		if ( ! $new && @filesize( $file ) > self::LOG_CAP ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a race with pruning.
			return '';
		}
		$entry = array(
			'time'     => time(),
			'ref'      => $ref,
			'action'   => 'allow' === $verdict['action'] ? 'log' : $verdict['action'],
			'rule'     => $verdict['rule'],
			'category' => $verdict['category'],
			'field'    => $verdict['field'],
			'match'    => $verdict['match'],
			'ip'       => $verdict['ip'],
			'method'   => isset( $server['REQUEST_METHOD'] ) ? substr( (string) $server['REQUEST_METHOD'], 0, 10 ) : '',
			'uri'      => isset( $server['REQUEST_URI'] ) ? substr( (string) $server['REQUEST_URI'], 0, 500 ) : '',
			'ua'       => isset( $server['HTTP_USER_AGENT'] ) ? substr( (string) $server['HTTP_USER_AGENT'], 0, 300 ) : '',
			'logged'   => $verdict['logged'],
		);
		$line  = json_encode( $entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- runs before WordPress.
		if ( ! is_string( $line ) ) {
			return '';
		}
		$line = ( $new ? "<?php exit; ?>\n" : '' ) . $line . "\n";
		@file_put_contents( $file, $line, FILE_APPEND | LOCK_EX ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- runs before WordPress.
		if ( $new ) {
			$cut = gmdate( 'Y-m-d', time() - self::LOG_DAYS * 86400 );
			foreach ( (array) glob( $dir . '*.log.php' ) as $old ) {
				if ( strcmp( substr( basename( (string) $old ), 0, 10 ), $cut ) < 0 ) {
					@unlink( (string) $old ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- runs before WordPress.
				}
			}
		}
		return $ref;
	}

	/**
	 * Send the block page and stop.
	 *
	 * @param array $verdict Verdict.
	 */
	public static function respond( array $verdict ) {
		$status = isset( $verdict['status'] ) && 429 === (int) $verdict['status'] ? 429 : 403;
		$ref    = isset( $verdict['ref'] ) ? (string) $verdict['ref'] : bin2hex( random_bytes( 6 ) );
		if ( ! headers_sent() ) {
			http_response_code( $status );
			header( 'Content-Type: text/html; charset=utf-8' );
			header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
			header( 'X-Robots-Tag: noindex, nofollow' );
			if ( 429 === $status ) {
				header( 'Retry-After: 60' );
			}
		}
		$title = 429 === $status ? 'Too many requests' : 'Request blocked';
		$text  = 429 === $status ? 'You sent too many requests in a short time. Wait a minute and try again.' : 'This site blocked your request. If you think that&#8217;s a mistake, send the site owner this reference.';
		echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>' . $title . '</title>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed strings.
			. '<style>body{font:16px/1.5 system-ui,sans-serif;max-width:34rem;margin:12vh auto;padding:0 16px;color:#222}code{background:#f2f2f2;padding:2px 6px;border-radius:4px}</style></head><body>'
			. '<h1>' . $title . '</h1><p>' . $text . '</p><p>Reference: <code>' . preg_replace( '/[^a-f0-9]/', '', $ref ) . '</code></p></body></html>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed strings and a hex reference.
		exit;
	}
}
