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
 * Deferred verdicts: a content block (sqli, xss, traversal, rfi, php_upload,
 * anomaly) on a request that carries a wordpress_logged_in_* cookie but no
 * valid trust cookie isn't answered before WordPress loads. A request with an
 * Authorization header (application passwords) defers the same way. boot() keeps the verdict
 * and settles it once WordPress knows the user: on rest_authentication_errors
 * for a REST request (application passwords resolve only there), on
 * xmlrpc_call for XML-RPC (after its own login), and on init for the rest. A
 * user with the trust capability gets through, and anyone else gets the
 * same 403 and the same log line. Before WordPress (auto_prepend_file), only
 * a request for one of WordPress's own entry points defers, so a direct hit
 * on a plugin file still blocks at once. Monitor mode never defers.
 *
 * Built-in exclusions (always on, see builtin_exclusions()): the search
 * parameter s, and the comment field on wp-comments-post.php, skip the
 * traversal and rfi categories and the sqli-005 tautology rule. Every other
 * SQL rule (UNION, stacked queries, time delays) still applies to them.
 *
 * Trust cookie: user id, expiry, and an HMAC over both and the session token
 * inside the wordpress_logged_in_* cookie. It verifies only next to that
 * cookie, lives TRUST_TTL seconds, and WordPress reissues it while the user
 * keeps the capability. Every compile writes a new key.
 *
 * Verdict: array(
 *   'action'   => 'allow' | 'block' | 'monitor' | 'throttle',
 *   'reason'   => why the request was allowed without inspection, or '',
 *   'rule'     => rule id such as 'sqli-001', or '',
 *   'category' => sqli | xss | traversal | rfi | php_upload | anomaly | bad_bots
 *                 | probes | xmlrpc | ip_blocklist | country | rate_limit | custom,
 *                 or '',
 *   'field'    => where it matched: 'uri', 'ua', 'query:<name>', 'post:<name>',
 *                 'cookie:<name>', 'json:<name>', 'file:<name>', 'header:<name>',
 *                 'ip', or '',
 *   'match'    => up to 120 bytes around the match,
 *   'ip'       => the visitor address the engine used,
 *   'trusted'  => whether a valid trust cookie came with the request,
 *   'status'   => HTTP status for a block (403 or 429),
 *   'logged'   => list of custom rule ids whose action is 'log' and matched,
 *   'notes'    => list of strings, such as 'rate_limits_skipped_no_apcu' or
 *                 'inspection_truncated', 'inspection_budget_reached', 'deferred',
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
 *                     in the verdict notes. '404' is left to WordPress. A
 *                     verified trust cookie skips them.
 *   'blocked_file' => path to a PHP file returning array( ip => expiry unix time ),
 *                     under wp-content or the firewall folder. A listed
 *                     address blocks as ip-002 until its expiry.
 *   'login_paths'  => list of path prefixes that count as login, beside
 *                     wp-login.php, for the country 'login' scope and the
 *                     'login' rate route (a hidden login address).
 *   'geo'          => array( 'class_file' => path to the Perdita_Firewall_Geo
 *                     class file, under wp-content or the firewall folder,
 *                     plus the lookup settings Pro passes to lookup() ).
 *   'custom_rules' => list of array( 'id' => string, 'field' => 'uri'|'uri_raw'|'query'|
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
	 * Bytes inspected per request. Inside it every value is inspected in
	 * full. Past it, a value gets LEAN_CAP bytes from each end, the verdict
	 * notes inspection_budget_reached, and a value with bytes left between
	 * the two is an anomaly (anomaly-003), so nothing hides in a part the
	 * engine never read.
	 */
	const INSPECT_BUDGET = 1048576;

	/**
	 * Head and tail size per value once INSPECT_BUDGET is spent.
	 */
	const LEAN_CAP = 4096;

	/**
	 * rest_authentication_errors priority for a REST request's deferred
	 * verdict: after WordPress's application password (90) and cookie
	 * nonce (100) checks, so the user is the one the REST API will use.
	 */
	const REST_SETTLE_PRIORITY = 101;

	/**
	 * Log cap per day, in bytes.
	 */
	const LOG_CAP = 5242880;

	/**
	 * Days of log files kept.
	 */
	const LOG_DAYS = 14;

	/**
	 * JSON nesting depth, the same as WordPress's REST server.
	 */
	const JSON_DEPTH = 512;

	/**
	 * Cap on the values walked in one request. Past it, the request is an
	 * anomaly (anomaly-001) instead of a silent pass.
	 */
	const LEAF_CAP = 20000;

	/**
	 * Name of the signed trust cookie.
	 */
	const TRUST_COOKIE = 'perdita_fw_trust';

	/**
	 * Trust cookie lifetime in seconds. WordPress reissues it on init once
	 * it's older than TRUST_RENEW seconds.
	 */
	const TRUST_TTL = 3600;

	/**
	 * Age in seconds after which init reissues the trust cookie.
	 */
	const TRUST_RENEW = 900;

	/**
	 * Hook priority on init for settling a deferred verdict.
	 */
	const SETTLE_PRIORITY = -100000;

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
	 * Whether the current run() hit the inspection cap.
	 *
	 * @var bool
	 */
	private static $truncated = false;

	/**
	 * Bytes inspected in the current run(), against INSPECT_BUDGET.
	 *
	 * @var int
	 */
	private static $inspected = 0;

	/**
	 * Whether the current run() spent INSPECT_BUDGET.
	 *
	 * @var bool
	 */
	private static $budget_reached = false;

	/**
	 * Values walked in the current run().
	 *
	 * @var int
	 */
	private static $leaves = 0;

	/**
	 * A verdict waiting for init: array( config, verdict, server ), or null.
	 *
	 * @var array|null
	 */
	private static $pending = null;

	/**
	 * The verdict boot() reached for this request (updated when init settles
	 * a deferred one), or null.
	 *
	 * @var array|null
	 */
	private static $last = null;

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
				// UNION SELECT ... FROM anything within 300 characters.
				'sqli-002' => '\bunion(?:[\s(]+(?:all|distinct))?(?:[\s(]|#[^\n]*\n)+select[\s(].{0,300}?\bfrom\b\s*[\w`"(]',
				// Time-based blind injection: a delay with a number, a nested
				// paren, a variable, or a function call as its argument.
				'sqli-003' => '\b(?:sleep|pg_sleep|benchmark)\s*\(\s*(?:[\d(@]|[a-z_]\w*\s*\()|\bwaitfor\s+delay\s*(?:[\'"(@]|[a-z_]\w*\s*\()|\bdbms_pipe\.receive_message\s*\(',
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
			$raw = @file_get_contents( 'php://input' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- runs before WordPress.
			return is_string( $raw ) ? $raw : '';
		};
		$verdict    = self::run( $config, $server, $_GET, $_POST, $_COOKIE, $_FILES, $body_fn ); // phpcs:ignore WordPress.Security.NonceVerification -- a firewall reads every request.
		self::$last = $verdict;
		if ( self::should_defer( $verdict, $config, $_COOKIE, $server, $loader ) ) {
			self::defer( $config, $verdict, $server );
			return $verdict;
		}
		if ( 'allow' !== $verdict['action'] || ! empty( $verdict['logged'] ) ) {
			self::log( $config, $verdict, $server );
		}
		if ( in_array( $verdict['action'], array( 'block', 'throttle' ), true ) ) {
			self::respond( $verdict );
		}
		return $verdict;
	}

	/* ---------- deferred verdicts ---------- */

	/**
	 * Content rule categories a trust cookie skips, and so the ones a
	 * signed-in visitor's block may wait on init for.
	 */
	const DEFERRABLE = array( 'sqli', 'xss', 'traversal', 'rfi', 'php_upload', 'anomaly' );

	/**
	 * Whether a block should wait for init: a content block, a
	 * wordpress_logged_in_* cookie with a session token, no valid trust
	 * cookie, and (before WordPress) a request for a WordPress entry point.
	 *
	 * @param array  $verdict Verdict from run().
	 * @param array  $config  Config.
	 * @param array  $cookie  $_COOKIE.
	 * @param array  $server  $_SERVER.
	 * @param string $loader  prepend, mu, or plugin.
	 * @return bool
	 */
	public static function should_defer( array $verdict, array $config, array $cookie, array $server, $loader ) {
		if ( 'block' !== $verdict['action'] || ! empty( $verdict['trusted'] ) || ! in_array( $verdict['category'], self::DEFERRABLE, true ) ) {
			return false;
		}
		if ( ! self::session_tokens( $cookie ) && ! self::has_auth_header( $server ) ) {
			return false;
		}
		return 'prepend' !== $loader || self::entry_point( $config, $server );
	}

	/**
	 * Whether the request carries an Authorization header (or the Basic
	 * credentials PHP parsed from one).
	 *
	 * @param array $server $_SERVER.
	 * @return bool
	 */
	public static function has_auth_header( array $server ) {
		foreach ( array( 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION', 'PHP_AUTH_USER' ) as $key ) {
			if ( isset( $server[ $key ] ) && '' !== (string) $server[ $key ] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Where a deferred verdict settles: 'rest', 'xmlrpc', or 'init'.
	 *
	 * @param array $config Config.
	 * @param array $server $_SERVER.
	 * @return string
	 */
	public static function settle_point( array $config, array $server ) {
		$script = isset( $server['SCRIPT_FILENAME'] ) ? str_replace( '\\', '/', (string) $server['SCRIPT_FILENAME'] ) : '';
		if ( '/xmlrpc.php' === substr( $script, -11 ) || defined( 'XMLRPC_REQUEST' ) ) {
			return 'xmlrpc';
		}
		$uri = isset( $server['REQUEST_URI'] ) ? (string) $server['REQUEST_URI'] : '';
		$q   = isset( $server['QUERY_STRING'] ) ? (string) $server['QUERY_STRING'] : '';
		if ( preg_match( '~(?:^|&)rest_route=~', $q ) || false !== strpos( $uri, '?rest_route=' ) || false !== strpos( $uri, '&rest_route=' ) ) {
			return 'rest';
		}
		$prefix = isset( $config['rest_prefix'] ) && '' !== (string) $config['rest_prefix'] ? trim( (string) $config['rest_prefix'], '/' ) : 'wp-json';
		$qpos   = strpos( $uri, '?' );
		$path   = strtolower( false === $qpos ? $uri : substr( $uri, 0, $qpos ) );
		return preg_match( '~/' . preg_quote( strtolower( $prefix ), '~' ) . '(?:/|$)~', $path ) ? 'rest' : 'init';
	}

	/**
	 * Whether the script PHP is about to run is one of WordPress's own entry
	 * points under the compiled ABSPATH, so init is sure to fire.
	 *
	 * @param array $config Config.
	 * @param array $server $_SERVER.
	 * @return bool
	 */
	public static function entry_point( array $config, array $server ) {
		$root   = isset( $config['abspath'] ) ? (string) $config['abspath'] : '';
		$script = isset( $server['SCRIPT_FILENAME'] ) ? str_replace( '\\', '/', (string) $server['SCRIPT_FILENAME'] ) : '';
		if ( '' === $root || '' === $script ) {
			return false;
		}
		$root = rtrim( str_replace( '\\', '/', $root ), '/' ) . '/';
		if ( 0 !== strpos( $script, $root ) ) {
			$real   = @realpath( $script ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- runs before WordPress.
			$rroot  = @realpath( $root ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- runs before WordPress.
			$script = false === $real ? '' : str_replace( '\\', '/', $real );
			$root   = false === $rroot ? '' : rtrim( str_replace( '\\', '/', $rroot ), '/' ) . '/';
			if ( '' === $script || '' === $root || 0 !== strpos( $script, $root ) ) {
				return false;
			}
		}
		$rel = substr( $script, strlen( $root ) );
		if ( preg_match( '~^wp-admin/load-(?:scripts|styles)\.php$~', $rel ) ) {
			return false;
		}
		return (bool) preg_match( '~^(?:index|wp-login|wp-comments-post|xmlrpc|wp-cron|wp-signup|wp-activate|wp-trackback|wp-links-opml|wp-mail)\.php$|^wp-admin/(?:(?:network|user)/)?[\w.-]+\.php$~', $rel );
	}

	/**
	 * Keep a verdict for init. Hooks settle() through the plugin API when
	 * WordPress's plugin API is loaded, or through the global $wp_filter
	 * array WordPress builds its hooks from when it isn't yet.
	 *
	 * @param array $config  Config.
	 * @param array $verdict Verdict.
	 * @param array $server  $_SERVER.
	 */
	public static function defer( array $config, array $verdict, array $server ) {
		$verdict['notes'][] = 'deferred';
		self::$pending      = array(
			'config'  => $config,
			'verdict' => $verdict,
			'server'  => $server,
		);
		$point = self::settle_point( $config, $server );
		if ( 'rest' === $point ) {
			// The REST API resolves application passwords during dispatch,
			// after init. 'wp' catches a request that turned out not to be REST.
			self::hook( 'rest_authentication_errors', array( __CLASS__, 'settle_rest' ), self::REST_SETTLE_PRIORITY, 1 );
			self::hook( 'wp', array( __CLASS__, 'settle' ), self::SETTLE_PRIORITY, 0 );
		} elseif ( 'xmlrpc' === $point ) {
			// XML-RPC logs the user in inside each method, then fires
			// xmlrpc_call. A user already known at init passes there.
			self::hook( 'init', array( __CLASS__, 'settle_if_trusted' ), self::SETTLE_PRIORITY, 0 );
			self::hook( 'xmlrpc_call', array( __CLASS__, 'settle' ), self::SETTLE_PRIORITY, 0 );
		} else {
			self::hook( 'init', array( __CLASS__, 'settle' ), self::SETTLE_PRIORITY, 0 );
		}
		self::$pending['point'] = $point;
		register_shutdown_function( array( __CLASS__, 'settle_at_shutdown' ) );
	}

	/**
	 * Hook a callback through the plugin API when it's loaded, or through the
	 * global $wp_filter array WordPress builds its hooks from when it isn't.
	 *
	 * @param string   $tag      Hook name.
	 * @param callable $callback Callback.
	 * @param int      $priority Priority.
	 * @param int      $args     Accepted args.
	 */
	private static function hook( $tag, $callback, $priority, $args ) {
		if ( function_exists( 'add_action' ) ) {
			add_action( $tag, $callback, $priority, $args );
			return;
		}
		if ( ! isset( $GLOBALS['wp_filter'] ) || ! is_array( $GLOBALS['wp_filter'] ) ) {
			$GLOBALS['wp_filter'] = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- WordPress builds its hooks from this array.
		}
		$GLOBALS['wp_filter'][ $tag ][ $priority ][] = array( // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- WordPress builds its hooks from this array.
			'function'      => $callback,
			'accepted_args' => $args,
		);
	}

	/**
	 * Settle callback: allow a user with the trust capability, else log and
	 * send the 403.
	 */
	public static function settle() {
		$verdict = self::resolve_pending();
		if ( is_array( $verdict ) && 'block' === $verdict['action'] ) {
			self::respond( $verdict );
		}
	}

	/**
	 * rest_authentication_errors callback: settle, and pass the value on.
	 *
	 * @param mixed $errors The filtered value.
	 * @return mixed
	 */
	public static function settle_rest( $errors ) {
		self::settle();
		return $errors;
	}

	/**
	 * XML-RPC init callback: let a user WordPress already knows through, and
	 * leave anyone else for xmlrpc_call.
	 */
	public static function settle_if_trusted() {
		if ( null !== self::$pending && self::can_trust( self::$pending['config'] ) ) {
			self::resolve_pending( true );
		}
	}

	/**
	 * Whether the current WordPress user has the trust capability.
	 *
	 * @param array $config Config.
	 * @return bool
	 */
	private static function can_trust( array $config ) {
		$cap = isset( $config['trust_cap'] ) && is_string( $config['trust_cap'] ) && '' !== $config['trust_cap'] ? $config['trust_cap'] : 'edit_posts';
		return function_exists( 'current_user_can' ) && current_user_can( $cap );
	}

	/**
	 * Settle the kept verdict without sending anything.
	 *
	 * @param bool|null $can Whether the user has the trust capability, or null to ask WordPress.
	 * @return array|null The settled verdict, or null when nothing waited.
	 */
	public static function resolve_pending( $can = null ) {
		if ( null === self::$pending ) {
			return null;
		}
		$pending       = self::$pending;
		self::$pending = null;
		$config        = $pending['config'];
		$verdict       = $pending['verdict'];
		if ( null === $can ) {
			$can = self::can_trust( $config );
		}
		if ( $can ) {
			$verdict['action']  = 'allow';
			$verdict['reason']  = 'trusted_user';
			$verdict['trusted'] = true;
			self::$last         = $verdict;
			return $verdict;
		}
		self::log( $config, $verdict, $pending['server'] );
		self::$last = $verdict;
		return $verdict;
	}

	/**
	 * The verdict boot() reached for this request, for Perdita Pro's traffic
	 * log: monitor-mode matches, custom 'log' rules, and a deferred verdict
	 * once init settles it. null when the engine didn't run.
	 *
	 * @return array|null
	 */
	public static function last_verdict() {
		return self::$last;
	}

	/**
	 * Shutdown fallback: a kept verdict init never settled (the request
	 * ended before init) still gets its log line.
	 */
	public static function settle_at_shutdown() {
		if ( null === self::$pending ) {
			return;
		}
		self::$pending['verdict']['notes'][] = 'unsettled';
		self::resolve_pending( false );
	}

	/**
	 * Whether a verdict waits for init.
	 *
	 * @return bool
	 */
	public static function has_pending() {
		return null !== self::$pending;
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
		self::$truncated      = false;
		self::$leaves         = 0;
		self::$inspected      = 0;
		self::$budget_reached = false;
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

		// 2. Pro: IP blocklist, then the auto-block file. Both block trusted
		// visitors too.
		if ( ! empty( $config['blocklist'] ) && '' !== $ip && self::ip_in( $ip, $config['blocklist'] ) ) {
			return self::hit( $verdict, $block_action, 'ip-001', 'ip_blocklist', 'ip', $ip, 403 );
		}
		if ( ! empty( $config['blocked_file'] ) && '' !== $ip && self::auto_blocked( $config, $ip ) ) {
			return self::hit( $verdict, $block_action, 'ip-002', 'ip_blocklist', 'ip', $ip, 403 );
		}

		// A trust cookie that verifies skips the rate limits here and the
		// content rules at step 10. The blocklist, country, and custom rules
		// still apply.
		$trusted = isset( $cookie[ self::TRUST_COOKIE ] ) && ! empty( $config['key'] ) && false !== self::verify_trust( (string) $cookie[ self::TRUST_COOKIE ], (string) $config['key'], null, self::session_tokens( $cookie ) );

		// 3. Pro: country rules.
		if ( ! empty( $config['country'] ) && is_array( $config['country'] ) ) {
			$c = self::check_country( $config, $server, $ip, $npath );
			if ( '' !== $c ) {
				return self::hit( $verdict, $block_action, 'country-001', 'country', 'ip', $c, 403 );
			}
		}

		// 4. Pro: rate limits, skipped for a trusted visitor.
		if ( ! $trusted && ! empty( $config['rate_limits'] ) && is_array( $config['rate_limits'] ) ) {
			$r = self::check_rate_limits( $config, $server, $get, $ip, $npath, $verdict );
			if ( null !== $r ) {
				return $r;
			}
		}

		// 5. Pro: custom rules.
		if ( ! empty( $config['custom_rules'] ) && is_array( $config['custom_rules'] ) ) {
			$r = self::check_custom( $config['custom_rules'], $server, $get, $post, $cookie, $ip, $uri, $npath, $verdict, $block_action );
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
		if ( $trusted ) {
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
						$r = self::scan_json( $ctx, $raw );
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
		if ( self::$budget_reached ) {
			$verdict['notes'][] = 'inspection_budget_reached';
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
		if ( self::$budget_reached ) {
			$verdict['notes'][] = 'inspection_budget_reached';
		}
		return $verdict;
	}

	/* ---------- content scanning ---------- */

	/**
	 * Scan a JSON body: decoded whole to WordPress's depth (WordPress decodes
	 * the same body anyway, so nothing hides past a size cap), then walked
	 * like a form. A body too deep to decode is an anomaly (anomaly-002). A
	 * body that isn't valid JSON is scanned as text.
	 *
	 * @param array  $ctx Scan context.
	 * @param string $raw Raw body.
	 * @return array|null array( rule, category, match, field ) or null.
	 */
	private static function scan_json( array $ctx, $raw ) {
		$json = json_decode( $raw, true, self::JSON_DEPTH );
		if ( is_array( $json ) ) {
			return self::scan_array( $ctx, 'json', $json );
		}
		if ( null === $json && JSON_ERROR_DEPTH === json_last_error() ) {
			if ( self::excluded( $ctx['exclusions'], 'anomaly-002', 'anomaly', '' ) ) {
				self::$truncated = true;
				return null;
			}
			return array( 'anomaly-002', 'anomaly', 'json deeper than ' . self::JSON_DEPTH, 'json:' );
		}
		if ( is_string( $json ) ) {
			$r = self::scan_value( $ctx, 'json', '', $json, true );
			return $r ? array( $r[0], $r[1], $r[2], 'json:' ) : null;
		}
		if ( null !== $json || JSON_ERROR_NONE === json_last_error() ) {
			return null;
		}
		$r = self::scan_value( $ctx, 'json', '', $raw, false );
		return $r ? array( $r[0], $r[1], $r[2], 'json:' ) : null;
	}

	/**
	 * Scan request values, nested to any depth, without recursion. Every
	 * value walked counts toward LEAF_CAP, and a request past the cap is an
	 * anomaly (anomaly-001) instead of a silent pass.
	 *
	 * @param array  $ctx    Scan context.
	 * @param string $source query, post, or json.
	 * @param array  $values Values.
	 * @return array|null array( rule, category, match, field ) or null.
	 */
	private static function scan_array( array $ctx, $source, array $values ) {
		foreach ( $values as $name => $value ) {
			$name = (string) $name;
			if ( ++self::$leaves > self::LEAF_CAP ) {
				return self::over_leaf_cap( $ctx, $source, $name );
			}
			if ( preg_match( self::SKIP_PARAMS, $name ) ) {
				continue;
			}
			if ( '' !== $name && strspn( $name, self::SAFE_CHARS . '[]' ) !== strlen( $name ) ) {
				$r = self::scan_value( $ctx, $source, $name, $name, false );
				if ( $r ) {
					return array( $r[0], $r[1], $r[2], $source . ':' . $name );
				}
			}
			if ( is_array( $value ) ) {
				$stack = array( $value );
				while ( $stack ) {
					foreach ( array_pop( $stack ) as $key => $child ) {
						if ( ++self::$leaves > self::LEAF_CAP ) {
							return self::over_leaf_cap( $ctx, $source, $name );
						}
						if ( is_string( $key ) && preg_match( self::SKIP_PARAMS, $key ) ) {
							continue;
						}
						if ( is_array( $child ) ) {
							$stack[] = $child;
							continue;
						}
						if ( ! is_string( $child ) ) {
							continue;
						}
						$r = self::scan_value( $ctx, $source, $name, $child, true );
						if ( $r ) {
							return array( $r[0], $r[1], $r[2], $source . ':' . $name );
						}
					}
				}
				continue;
			}
			$r = is_scalar( $value ) ? self::scan_value( $ctx, $source, $name, (string) $value, true ) : null;
			if ( $r ) {
				return array( $r[0], $r[1], $r[2], $source . ':' . $name );
			}
		}
		return null;
	}

	/**
	 * The anomaly-001 result for a request past LEAF_CAP, or null (with the
	 * truncated note) when an owner's exclusion covers it.
	 *
	 * @param array  $ctx    Scan context.
	 * @param string $source Source.
	 * @param string $name   Top-level parameter name.
	 * @return array|null
	 */
	private static function over_leaf_cap( array $ctx, $source, $name ) {
		if ( self::excluded( $ctx['exclusions'], 'anomaly-001', 'anomaly', $name ) ) {
			self::$truncated = true;
			return null;
		}
		return array( 'anomaly-001', 'anomaly', 'more than ' . self::LEAF_CAP . ' values', $source . ':' . $name );
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
		$left = self::INSPECT_BUDGET - self::$inspected;
		if ( $len <= $left ) {
			self::$inspected += $len;
			return self::scan_piece( $ctx, $source, $param, $value, $is_param );
		}
		self::$budget_reached = true;
		$head                 = max( $left, self::LEAN_CAP );
		$tail                 = self::LEAN_CAP;
		if ( $len <= $head + $tail ) {
			self::$inspected += $len;
			return self::scan_piece( $ctx, $source, $param, $value, $is_param );
		}
		// The head and the tail, each scanned on its own. Rules anchored to
		// the start of a value skip the tail. The bytes between them go
		// uninspected, so the value is an anomaly unless a rule matched.
		self::$inspected += $head + $tail;
		$r                = self::scan_piece( $ctx, $source, $param, substr( $value, 0, $head ), $is_param );
		if ( ! $r ) {
			$r = self::scan_piece( $ctx, $source, $param, substr( $value, -$tail ), false );
		}
		if ( $r ) {
			return $r;
		}
		if ( self::excluded( $ctx['exclusions'], 'anomaly-003', 'anomaly', $param ) ) {
			self::$truncated = true;
			return null;
		}
		return array( 'anomaly-003', 'anomaly', ( $len - $head - $tail ) . ' bytes uninspected past ' . self::INSPECT_BUDGET );
	}

	/**
	 * Scan one piece of a value (all of it, or its head or tail).
	 *
	 * @param array  $ctx      Scan context.
	 * @param string $source   Source.
	 * @param string $param    Top-level parameter name, or ''.
	 * @param string $value    Raw piece.
	 * @param bool   $is_param Whether parameter-only rules apply.
	 * @return array|null array( rule, category, match ) or null.
	 */
	private static function scan_piece( array $ctx, $source, $param, $value, $is_param ) {
		$len = strlen( $value );
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
			'anomaly' => 'anomaly',
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
	 * The exclusions whose path covers this path, plus the built-in ones.
	 *
	 * @param array  $config Config.
	 * @param string $npath  Normalized path.
	 * @return array
	 */
	private static function exclusions_for( array $config, $npath ) {
		$out = self::builtin_exclusions( $npath );
		if ( empty( $config['exclusions'] ) || ! is_array( $config['exclusions'] ) ) {
			return $out;
		}
		foreach ( $config['exclusions'] as $ex ) {
			if ( ! is_array( $ex ) || empty( $ex['rule'] ) ) {
				continue;
			}
			if ( self::path_covers( isset( $ex['path'] ) ? (string) $ex['path'] : '', $npath ) ) {
				$out[] = $ex;
			}
		}
		return $out;
	}

	/**
	 * Whether an exclusion path covers a request path, on a segment
	 * boundary: /wp-json/my-plugin covers /wp-json/my-plugin and
	 * /wp-json/my-plugin/v1, never /wp-json/my-plugin-other.
	 *
	 * @param string $prefix Exclusion path, '' for every path.
	 * @param string $npath  Normalized request path.
	 * @return bool
	 */
	public static function path_covers( $prefix, $npath ) {
		$prefix = rtrim( strtolower( (string) $prefix ), '/' );
		if ( '' === $prefix ) {
			return true;
		}
		$npath = (string) $npath;
		return $npath === $prefix || 0 === strpos( $npath, $prefix . '/' );
	}

	/**
	 * An exclusion path in the one form the engine compares: no query or
	 * fragment, URL decoded, lower case, one leading slash, no repeated
	 * slashes or dot segments, and no trailing slash ('/' stays '/').
	 *
	 * @param string $path Path as typed or logged.
	 * @return string
	 */
	public static function exclusion_path( $path ) {
		$path = (string) $path;
		$path = substr( $path, 0, strcspn( $path, '?#' ) );
		$path = strtolower( rawurldecode( $path ) );
		$path = (string) preg_replace( '~[^\w/.\~@+-]~', '', $path );
		$keep = array();
		foreach ( explode( '/', $path ) as $seg ) {
			if ( '' !== $seg && '.' !== $seg && '..' !== $seg ) {
				$keep[] = $seg;
			}
		}
		return '/' . implode( '/', $keep );
	}

	/**
	 * Exclusions every site gets. The search parameter s and the comment
	 * field on wp-comments-post.php carry prose about code and security, so
	 * they skip the traversal and rfi categories and the sqli-005 tautology
	 * rule. UNION, stacked-query, and time-delay rules still apply.
	 *
	 * @param string $npath Normalized request path.
	 * @return array
	 */
	public static function builtin_exclusions( $npath ) {
		$out = array();
		foreach ( array( 'traversal', 'rfi', 'sqli-005' ) as $rule ) {
			$out[] = array(
				'rule'  => $rule,
				'path'  => '',
				'param' => 's',
			);
		}
		if ( '/wp-comments-post.php' === substr( (string) $npath, -21 ) ) {
			foreach ( array( 'traversal', 'rfi', 'sqli-005' ) as $rule ) {
				$out[] = array(
					'rule'  => $rule,
					'path'  => '',
					'param' => 'comment',
				);
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
	 * The signed trust cookie value for a user and a login session.
	 *
	 * @param int    $user_id User id.
	 * @param int    $expires Unix time.
	 * @param string $key     HMAC key.
	 * @param string $token   Session token from the wordpress_logged_in_* cookie.
	 * @return string
	 */
	public static function trust_value( $user_id, $expires, $key, $token ) {
		$data = (int) $user_id . '.' . (int) $expires;
		return $data . '.' . hash_hmac( 'sha256', $data . '|' . (string) $token, (string) $key );
	}

	/**
	 * Verify a trust cookie against the session tokens the request carries.
	 * An expiry further out than TRUST_TTL (plus a minute of clock slack)
	 * never verifies.
	 *
	 * @param string   $value  Cookie value.
	 * @param string   $key    HMAC key.
	 * @param int|null $now    Unix time, for tests.
	 * @param string[] $tokens Session tokens from session_tokens().
	 * @return int|false The user id, or false.
	 */
	public static function verify_trust( $value, $key, $now = null, array $tokens = array() ) {
		if ( '' === (string) $key || ! $tokens || ! preg_match( '/^(\d{1,20})\.(\d{1,12})\.([0-9a-f]{64})$/', (string) $value, $m ) ) {
			return false;
		}
		$now = null === $now ? time() : (int) $now;
		if ( (int) $m[1] < 1 || (int) $m[2] < $now || (int) $m[2] > $now + self::TRUST_TTL + 60 ) {
			return false;
		}
		foreach ( $tokens as $token ) {
			$want = hash_hmac( 'sha256', $m[1] . '.' . $m[2] . '|' . (string) $token, (string) $key );
			if ( hash_equals( $want, $m[3] ) ) {
				return (int) $m[1];
			}
		}
		return false;
	}

	/**
	 * Session tokens from the request's wordpress_logged_in_* cookies, read
	 * split the way WordPress splits them (user|expiration|token|hmac).
	 * Nothing here proves the cookie is genuine: WordPress checks that later.
	 *
	 * @param array $cookie $_COOKIE.
	 * @return string[]
	 */
	public static function session_tokens( array $cookie ) {
		$out = array();
		foreach ( $cookie as $name => $value ) {
			if ( 0 !== strpos( (string) $name, 'wordpress_logged_in_' ) || ! is_string( $value ) ) {
				continue;
			}
			$parts = explode( '|', $value );
			if ( 4 === count( $parts ) && preg_match( '/^[A-Za-z0-9]{20,128}$/', $parts[2] ) ) {
				$out[] = $parts[2];
			}
		}
		return $out;
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
		$geo = '';
		if ( ! empty( $config['geo']['class_file'] ) ) {
			$geo = self::safe_file( $config, (string) $config['geo']['class_file'] );
		}
		if ( '' === $geo && is_file( __DIR__ . '/class-perdita-firewall-geo.php' ) ) {
			$geo = __DIR__ . '/class-perdita-firewall-geo.php';
		}
		if ( '' !== (string) $ip && '' !== $geo ) {
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
	 * Whether a path is a login screen: wp-login.php, or a path under one of
	 * $config['login_paths'] (Perdita Pro's hidden login address), matched on
	 * a segment boundary.
	 *
	 * @param array  $config Config.
	 * @param string $npath  Normalized path.
	 * @return bool
	 */
	public static function is_login_path( array $config, $npath ) {
		if ( '/wp-login.php' === substr( (string) $npath, -13 ) ) {
			return true;
		}
		if ( empty( $config['login_paths'] ) || ! is_array( $config['login_paths'] ) ) {
			return false;
		}
		foreach ( $config['login_paths'] as $prefix ) {
			$prefix = self::exclusion_path( (string) $prefix );
			if ( '/' !== $prefix && self::path_covers( $prefix, $npath ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * A file Perdita Pro asks the engine to include, resolved, only when it
	 * sits under wp-content or the firewall folder.
	 *
	 * @param array  $config Config (content_dir and fw_dir).
	 * @param string $file   Path.
	 * @return string The real path, or '' when it's missing or outside both.
	 */
	public static function safe_file( array $config, $file ) {
		$real = @realpath( (string) $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- runs before WordPress.
		if ( false === $real || ! is_file( $real ) || '.php' !== strtolower( substr( $real, -4 ) ) ) {
			return '';
		}
		$roots = array();
		foreach ( array( 'fw_dir', 'content_dir' ) as $k ) {
			if ( ! empty( $config[ $k ] ) && is_string( $config[ $k ] ) ) {
				$roots[] = $config[ $k ];
			}
		}
		if ( defined( 'WP_CONTENT_DIR' ) ) {
			$roots[] = (string) WP_CONTENT_DIR;
		}
		foreach ( $roots as $root ) {
			$r = @realpath( $root ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- runs before WordPress.
			if ( false !== $r && 0 === strpos( $real, rtrim( $r, '/\\' ) . DIRECTORY_SEPARATOR ) ) {
				return $real;
			}
		}
		return '';
	}

	/**
	 * Whether Perdita Pro's auto-block file (ip => expiry) lists an address
	 * that hasn't expired. The engine skips expired entries itself, so Pro
	 * needn't recompile at each expiry.
	 *
	 * @param array  $config Config with blocked_file.
	 * @param string $ip     Visitor address.
	 * @return bool
	 */
	private static function auto_blocked( array $config, $ip ) {
		$file = self::safe_file( $config, (string) $config['blocked_file'] );
		if ( '' === $file ) {
			return false;
		}
		$list = include $file;
		return is_array( $list ) && isset( $list[ $ip ] ) && (int) $list[ $ip ] > time();
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
		if ( isset( $c['scope'] ) && 'login' === $c['scope'] && ! self::is_login_path( $config, $npath ) && '/xmlrpc.php' !== substr( $npath, -11 ) ) {
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
	 * @param array  $config  Config (rate_limits, and login_paths for the login route).
	 * @param array  $server  $_SERVER.
	 * @param array  $get     $_GET.
	 * @param string $ip      Visitor address.
	 * @param string $npath   Normalized path.
	 * @param array  $verdict Verdict so far, by reference for notes.
	 * @return array|null A block or throttle verdict, or null.
	 */
	private static function check_rate_limits( array $config, array $server, array $get, $ip, $npath, array &$verdict ) {
		$limits = (array) $config['rate_limits'];
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
				$applies = 'POST' === $method && self::is_login_path( $config, $npath );
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
	private static function check_custom( array $rules, array $server, array $get, array $post, array $cookie, $ip, $uri, $npath, array &$verdict, $block_action ) {
		$curi = null;
		$cget = null;
		foreach ( $rules as $rule ) {
			if ( ! is_array( $rule ) || empty( $rule['enabled'] ) || ! isset( $rule['field'], $rule['op'], $rule['value'] ) ) {
				continue;
			}
			$field = (string) $rule['field'];
			$vals  = array();
			if ( 'uri' === $field ) {
				if ( null === $curi ) {
					$curi = self::custom_uri( $uri, $npath );
				}
				$vals = array( $curi );
			} elseif ( 'uri_raw' === $field ) {
				$vals = array( $uri );
			} elseif ( 'query' === $field ) {
				if ( null === $cget ) {
					$cget = array_map( array( __CLASS__, 'normalize' ), self::flatten( $get ) );
				}
				$vals = $cget;
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
	 * The request URI a custom 'uri' rule sees: the path decoded and
	 * normalized the way the built-in rules see it, with repeated slashes
	 * and ./ segments collapsed, plus the decoded query string. WordPress
	 * routes /%70rivate-area/ and //private-area/ to /private-area/, so a
	 * rule on the raw form would miss them.
	 *
	 * @param string $uri   Raw REQUEST_URI.
	 * @param string $npath normalize() of the path.
	 * @return string
	 */
	public static function custom_uri( $uri, $npath ) {
		$path = (string) preg_replace( '~/{2,}~', '/', str_replace( '\\', '/', (string) $npath ) );
		while ( false !== strpos( $path, '/./' ) ) {
			$path = str_replace( '/./', '/', $path );
		}
		$qpos = strpos( (string) $uri, '?' );
		return false === $qpos ? $path : $path . '?' . self::normalize( substr( (string) $uri, $qpos + 1 ) );
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
