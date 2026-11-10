<?php

/**
 * Compatibility check of the installed extensions with the installed Froxlor.
 *
 *   php compat.php <froxlor-dir>
 *
 * Run by froxlor-one-click-update as the OWNER OF THE FROXLOR DIRECTORY (the
 * panel user), never as root: it loads Froxlor's code and the extensions'
 * code, both of which that user can change anyway. It runs once before an
 * update (to know what already failed) and once after it.
 *
 * What it does — everything Froxlor itself would do on a page view, minus
 * the request:
 *   navigation  all lib/navigation/*.php merged and built, as an admin and
 *               as a customer (a broken menu file breaks EVERY panel page)
 *   settings    all actions/admin/settings/*.php merged (the settings page,
 *               the global search and the settings export load them)
 *   listings    lib/tablelisting/**\/*.ext.*.php (extensions' table lists)
 *   classes     every class below lib/Froxlor/Ext/ autoloaded: a missing
 *               parent class or interface, or a method signature that no
 *               longer matches Froxlor's, fails here
 *   references  the extensions' PHP files scanned for what they use of
 *               Froxlor: classes (new, ::, instanceof), static methods,
 *               class constants, functions — each must still exist
 *   callbacks   [Class::class, 'method'] entries in the arrays above
 *   templates   every Twig template compiled with Froxlor's Twig setup;
 *               extended, included and imported templates and the macros
 *               called from them must exist
 *
 * Output, one line each: "FAIL <area> <subject>: <message>" for every
 * problem, "PASS <area>: <count>" per area, "ABORT <message>" if Froxlor
 * itself cannot be loaded, and "DONE" as the very last line — without it
 * the check did not run to the end (loaded code called exit, for example).
 * Exit code: 0 all fine, 1 problems, 2 aborted. A fatal PHP error ends the
 * check; the line names the step it happened in.
 *
 * Deliberately NOT reported, because they cannot break anything on their
 * own: "instanceof X" and "X::class" with a missing X (false / a string in
 * PHP), uses guarded by function_exists()/class_exists()/... in the same
 * file, and anything in a file that compares Froxlor's version (code for
 * several Froxlor versions). Functions are only checked when they belong to
 * Froxlor (namespace Froxlor\ or its global helpers lng(), view() ...):
 * PHP extensions of the web server (fastcgi_finish_request ...) may be
 * missing on the command line. Froxlor's own templates (templates/Froxlor/,
 * templates/misc/) are not compiled; templates of extensions that use them
 * are.
 */

if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit(1);
}

error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED & ~E_NOTICE & ~E_WARNING & ~E_USER_NOTICE & ~E_USER_WARNING);
// Fatal errors are reported by the shutdown function, with the step they happened in.
ini_set('display_errors', '0');
ini_set('log_errors', '0');

final class Compat
{
	/** Froxlor's global helper functions (lib/functions.php). */
	private const FROXLOR_FUNCTIONS = ['lng', 'view', 'old', 'mix', 'vite'];

	private const FATAL = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR;

	private string $dir;
	private string $current = 'bootstrap';
	private int $failures = 0;
	private array $admin = [];
	private array $customer = [];
	/** While a file is scanned: its problems, reported at the end unless guarded. */
	private ?array $deferred = null;
	/** Names the scanned file checks itself (function_exists('x'), class_exists(X::class) ...). */
	private array $guarded = [];

	public function __construct(string $dir)
	{
		$this->dir = $dir;
	}

	public function run(): int
	{
		register_shutdown_function(function (): void {
			$e = error_get_last();
			if ($e !== null && ($e['type'] & self::FATAL)) {
				self::line(sprintf('FAIL %s: PHP fatal error: %s (%s:%d)', $this->current, self::clean($e['message']), $this->rel($e['file']), $e['line']));
				self::line('ABORT the check stopped at this fatal error');
				// exit() in a shutdown function sets the exit code.
				exit($this->current === 'bootstrap' ? 2 : 1);
			}
		});

		try {
			$this->bootstrap();
		} catch (\Throwable $e) {
			self::line('ABORT Froxlor cannot be loaded: ' . self::describe($e));

			return 2;
		}

		$this->navigation();
		$this->settings();
		$this->listings();
		$this->classes();
		$this->references();
		$this->templates();
		self::line('DONE');

		return $this->failures > 0 ? 1 : 0;
	}

	// ---------------------------------------------------------------------

	private function bootstrap(): void
	{
		if (!is_file($this->dir . '/vendor/autoload.php') || !is_file($this->dir . '/lib/init.php')) {
			throw new \RuntimeException($this->dir . ' is not a Froxlor installation');
		}
		// Froxlor's own include paths are relative to its directory; pages
		// define AREA before lib/init.php loads the menu.
		chdir($this->dir);
		if (!defined('AREA')) {
			define('AREA', 'admin');
		}
		$_SERVER['SCRIPT_FILENAME'] = $this->dir . '/admin_index.php';
		$_SERVER['SCRIPT_NAME'] = '/admin_index.php';
		$_SERVER['REQUEST_URI'] = '/admin_index.php';
		$_SERVER['HTTP_HOST'] = 'localhost';
		$_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'en';
		$_SESSION = [];

		require $this->dir . '/vendor/autoload.php';
		require_once $this->dir . '/lib/tables.inc.php';
		require_once $this->dir . '/lib/functions.php';

		// The users the panel would show the pages to: the first admin who may
		// change server settings and the first customer (if there is one).
		$stmt = \Froxlor\Database\Database::query('SELECT * FROM `' . TABLE_PANEL_ADMINS . '` WHERE `change_serversettings` = 1 ORDER BY `adminid` LIMIT 1');
		$admin = $stmt->fetch(\PDO::FETCH_ASSOC);
		if (!is_array($admin)) {
			throw new \RuntimeException('no admin with change_serversettings found');
		}
		$this->admin = $admin + ['adminsession' => 1];
		$stmt = \Froxlor\Database\Database::query('SELECT * FROM `' . TABLE_PANEL_CUSTOMERS . '` ORDER BY `customerid` LIMIT 1');
		$customer = $stmt->fetch(\PDO::FETCH_ASSOC);
		if (is_array($customer)) {
			$this->customer = $customer + ['adminsession' => 0];
		}
		$this->asUser($this->admin);
	}

	private function asUser(array $user): void
	{
		\Froxlor\CurrentUser::setData($user);
		if (class_exists(\Froxlor\UI\Panel\UI::class) && method_exists(\Froxlor\UI\Panel\UI::class, 'setCurrentUser')) {
			\Froxlor\UI\Panel\UI::setCurrentUser($user);
		}
	}

	// --- navigation, settings, listings ----------------------------------

	private function navigation(): void
	{
		$areas = ['admin' => $this->admin];
		if ($this->customer !== []) {
			$areas['customer'] = $this->customer;
		}
		$count = 0;
		// Loaded once, as Froxlor does per request: a menu file that declares
		// a function would fail on a second include.
		$this->current = 'navigation';
		try {
			$data = \Froxlor\PhpHelper::loadConfigArrayDir('lib/navigation/');
		} catch (\Throwable $e) {
			$this->fail('navigation', self::describe($e));
			self::line('PASS navigation: 0 area(s)');

			return;
		}
		foreach ($areas as $area => $user) {
			$this->current = "navigation $area";
			$this->asUser($user);
			try {
				if (!is_array($data) || !isset($data[$area]) || !is_array($data[$area])) {
					$this->fail("navigation $area", 'the merged menu has no "' . $area . '" part');
					continue;
				}
				\Froxlor\UI\HTML::buildNavigation($data[$area], $user);
				$this->callbacks("navigation $area", $data[$area]);
				$count++;
			} catch (\Throwable $e) {
				$this->fail("navigation $area", self::describe($e));
			}
		}
		$this->asUser($this->admin);
		self::line("PASS navigation: $count area(s)");
	}

	private function settings(): void
	{
		$this->current = 'settings';
		try {
			$data = \Froxlor\PhpHelper::loadConfigArrayDir($this->dir . '/actions/admin/settings/');
			if (!is_array($data) || !isset($data['groups']) || !is_array($data['groups'])) {
				$this->fail('settings', 'the merged settings have no "groups" part');

				return;
			}
			$this->callbacks('settings', $data);
			self::line('PASS settings: ' . count($data['groups']) . ' group(s)');
		} catch (\Throwable $e) {
			$this->fail('settings', self::describe($e));
		}
	}

	private function listings(): void
	{
		$count = 0;
		foreach ($this->files('lib/tablelisting', '/\.ext\..*\.php$/') as $file) {
			$rel = $this->rel($file);
			$this->current = "listing $rel";
			try {
				$data = (static fn(string $f) => include $f)($file);
				if (!is_array($data)) {
					$this->fail("listing $rel", 'does not return an array');
					continue;
				}
				$this->callbacks("listing $rel", $data);
				$count++;
			} catch (\Throwable $e) {
				$this->fail("listing $rel", self::describe($e));
			}
		}
		self::line("PASS listings: $count");
	}

	/** [Class::class, 'method'] pairs anywhere in a config array must be callable. */
	private function callbacks(string $area, array $data): void
	{
		$walk = function (array $node) use (&$walk, $area): void {
			if (count($node) === 2 && array_is_list($node) && is_string($node[0]) && is_string($node[1])
				&& str_starts_with(ltrim($node[0], '\\'), 'Froxlor\\') && preg_match('/^[A-Za-z_]\w*$/', $node[1])) {
				$this->checkMethod($area, ltrim($node[0], '\\'), $node[1], 'callback');

				return;
			}
			foreach ($node as $value) {
				if (is_array($value)) {
					$walk($value);
				}
			}
		};
		$walk($data);
	}

	// --- classes ---------------------------------------------------------

	private function classes(): void
	{
		$count = 0;
		$base = $this->dir . '/lib/Froxlor/Ext/';
		foreach ($this->extFiles() as $file) {
			$relative = substr($file, strlen($base), -4);
			// PSR-4: only files named like a class (Name.php) hold one.
			if (!preg_match('#^([A-Z]\w*/)*[A-Z]\w*$#', $relative)) {
				continue;
			}
			$class = 'Froxlor\\Ext\\' . str_replace('/', '\\', $relative);
			$this->current = "class $class";
			try {
				if (!self::typeExists($class)) {
					$this->fail("class $class", $this->rel($file) . ' does not declare it');
					continue;
				}
				$count++;
			} catch (\Throwable $e) {
				$this->fail("class $class", self::describe($e));
			}
		}
		self::line("PASS classes: $count");
	}

	// --- references ------------------------------------------------------

	private function references(): void
	{
		$files = $this->extensionFiles();
		$count = 0;
		foreach ($files as $file) {
			$rel = $this->rel($file);
			$this->current = "references $rel";
			try {
				$this->scan($file, $rel);
				$count++;
			} catch (\Throwable $e) {
				$this->fail("references $rel", self::describe($e));
			}
		}
		self::line("PASS references: $count file(s)");
	}

	/**
	 * The PHP files that belong to extensions rather than to Froxlor: all of
	 * lib/Froxlor/Ext/, the "*.ext.*" files in Froxlor's config directories
	 * (the naming the extensions use) and entry pages in the Froxlor
	 * directory that use an extension class.
	 *
	 * @return list<string>
	 */
	private function extensionFiles(): array
	{
		$files = $this->extFiles();
		foreach (['lib/navigation', 'actions/admin/settings', 'lib/tablelisting'] as $dir) {
			$files = array_merge($files, $this->files($dir, '/\.ext\..*\.php$/'));
		}
		foreach (glob($this->dir . '/*.php') ?: [] as $file) {
			if (is_file($file) && !is_link($file) && str_contains((string)file_get_contents($file, false, null, 0, 1 << 20), 'Froxlor\\Ext\\')) {
				$files[] = $file;
			}
		}
		sort($files);

		return array_values(array_unique($files));
	}

	/**
	 * PHP files below lib/Froxlor/Ext/, without libraries an extension may
	 * bring along (vendor/, node_modules/): those are loaded on demand only.
	 *
	 * @return list<string>
	 */
	private function extFiles(): array
	{
		return array_values(array_filter(
			$this->files('lib/Froxlor/Ext', '/\.php$/'),
			static fn(string $f): bool => !preg_match('#/(vendor|node_modules)/#', $f)
		));
	}

	/** Checks what one PHP file uses of Froxlor (classes, static members, functions). */
	private function scan(string $file, string $rel): void
	{
		$code = (string)file_get_contents($file, false, null, 0, 4 << 20);
		$tokens = array_values(array_filter(
			token_get_all($code),
			static fn($t) => !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_INLINE_HTML, T_OPEN_TAG, T_CLOSE_TAG], true)
		));
		$n = count($tokens);
		$namespace = '';
		$classes = [];   // alias => full name (use)
		$functions = []; // alias => full name (use function)
		$declared = [];  // functions declared in this file
		$depth = 0;
		$importDepth = 0; // brace depth of "use" imports: 1 inside "namespace X { ... }"
		$attribute = 0;  // inside #[...]: names there are attributes, not calls
		$seen = [];

		$id = static fn($t) => is_array($t) ? $t[0] : $t;
		$text = static fn($t) => is_array($t) ? $t[1] : $t;
		$line = static function (int $i) use ($tokens, $n): int {
			for ($j = $i; $j < $n; $j++) {
				if (is_array($tokens[$j])) {
					return $tokens[$j][2];
				}
			}

			return 0;
		};
		$nameTokens = [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE];

		$this->deferred = [];
		$this->guarded = [];
		$guards = ['function_exists', 'class_exists', 'interface_exists', 'trait_exists', 'enum_exists', 'method_exists', 'property_exists', 'is_callable', 'defined'];
		for ($i = 0; $i < $n - 1; $i++) {
			if ($id($tokens[$i]) === T_FUNCTION) {
				$j = $i + 1;
				if ($id($tokens[$j]) === '&') {
					$j++;
				}
				if ($id($tokens[$j]) === T_STRING) {
					$declared[strtolower($text($tokens[$j]))] = true;
				}
			}
		}

		$resolve = function (string $name) use (&$namespace, &$classes): ?string {
			$lower = strtolower($name);
			if (in_array($lower, ['self', 'static', 'parent'], true)) {
				return null;
			}
			if ($name[0] === '\\') {
				return substr($name, 1);
			}
			if (str_starts_with($lower, 'namespace\\')) {
				return ltrim($namespace . '\\' . substr($name, 10), '\\');
			}
			$parts = explode('\\', $name, 2);
			$first = strtolower($parts[0]);
			if (isset($classes[$first])) {
				return $classes[$first] . (isset($parts[1]) ? '\\' . $parts[1] : '');
			}

			return ltrim($namespace . '\\' . $name, '\\');
		};

		for ($i = 0; $i < $n; $i++) {
			$t = $tokens[$i];
			$tid = $id($t);

			if ($tid === T_ATTRIBUTE) {
				$attribute++;
				continue;
			}
			if ($tid === '{' || $tid === T_CURLY_OPEN || $tid === T_DOLLAR_OPEN_CURLY_BRACES) {
				$depth++;
				continue;
			}
			if ($tid === '}') {
				$depth = max(0, $depth - 1);
				continue;
			}
			if ($tid === ']' && $attribute > 0) {
				// Attributes may contain arrays; count brackets until the closing one.
				$attribute--;
				continue;
			}
			if ($tid === '[' && $attribute > 0) {
				$attribute++;
				continue;
			}

			if ($tid === T_NAMESPACE && in_array($id($tokens[$i + 1] ?? ''), [T_STRING, T_NAME_QUALIFIED, '{'], true)) {
				$braced = $id($tokens[$i + 1]) === '{' || $id($tokens[$i + 2] ?? '') === '{';
				$namespace = $id($tokens[$i + 1]) === '{' ? '' : $text($tokens[$i + 1]);
				$importDepth = $braced ? $depth + 1 : 0;
				$classes = [];
				$functions = [];
				if ($id($tokens[$i + 1]) !== '{') {
					$i++;
				}
				continue;
			}

			// Imports: "use" at the top level, not a closure's use (...) or a trait use.
			if ($tid === T_USE && $depth === $importDepth && $id($tokens[$i + 1] ?? '') !== '(') {
				$kind = 'class';
				$j = $i + 1;
				if ($id($tokens[$j]) === T_FUNCTION) {
					$kind = 'function';
					$j++;
				} elseif ($id($tokens[$j]) === T_CONST) {
					$kind = 'const';
					$j++;
				}
				$statement = '';
				for (; $j < $n && $id($tokens[$j]) !== ';'; $j++) {
					$statement .= $text($tokens[$j]) . ' ';
				}
				$i = $j;
				if ($kind === 'const') {
					continue;
				}
				foreach (self::parseUse($statement) as $alias => $full) {
					if ($kind === 'function') {
						$functions[strtolower($alias)] = $full;
					} else {
						$classes[strtolower($alias)] = $full;
					}
				}
				continue;
			}

			if (!in_array($tid, $nameTokens, true)) {
				continue;
			}
			$prev = $id($tokens[$i - 1] ?? '');
			$next = $id($tokens[$i + 1] ?? '');
			$name = $text($t);

			// new Name; "instanceof Name" is false for a missing class, not an error
			if ($prev === T_INSTANCEOF) {
				continue;
			}
			if ($prev === T_NEW) {
				$class = $resolve($name);
				if ($class !== null && self::isFroxlor($class)) {
					$this->checkType($rel, $line($i), $class, $seen);
				}
				continue;
			}

			// Name::member
			if ($next === T_DOUBLE_COLON) {
				if (in_array($prev, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true)) {
					continue;
				}
				$class = $resolve($name);
				if ($class === null || !self::isFroxlor($class)) {
					continue;
				}
				$member = $tokens[$i + 2] ?? '';
				$after = $id($tokens[$i + 3] ?? '');
				if ($id($member) === T_CLASS) {
					// [Name::class, 'method']
					$method = $tokens[$i + 4] ?? '';
					if ($prev === '[' && $after === ',' && $id($method) === T_CONSTANT_ENCAPSED_STRING && $id($tokens[$i + 5] ?? '') === ']') {
						$m = trim($text($method), '\'"');
						if (preg_match('/^[A-Za-z_]\w*$/', $m)) {
							$this->checkMethod("references $rel:" . $line($i), $class, $m, 'callback', $seen);
							continue;
						}
					}
					// Name::class alone is only a string.
				} elseif ($id($member) === T_VARIABLE) {
					if ($after !== '(') {
						// Name::$method(...) is a dynamic call, not a property.
						$this->checkProperty($rel, $line($i), $class, substr($text($member), 1), $seen);
					}
				} elseif ($id($member) === T_STRING || (is_array($member) && $after === '(')) {
					if ($after === '(') {
						$this->checkMethod("references $rel:" . $line($i), $class, $text($member), 'method', $seen);
					} else {
						$this->checkConstant($rel, $line($i), $class, $text($member), $seen);
					}
				}
				continue;
			}

			// function calls: name(...)
			if ($next === '(' && $attribute === 0 && $tid !== T_NAME_RELATIVE) {
				if (in_array($prev, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW, T_CONST, '&'], true)) {
					continue;
				}
				if (in_array(strtolower(ltrim($name, '\\')), $guards, true)) {
					// function_exists('x'), class_exists(X::class), method_exists(X::class, 'm'):
					// the names in the parentheses are guarded.
					for ($j = $i + 2, $level = 1; $j < $n && $level > 0; $j++) {
						$g = $id($tokens[$j]);
						if ($g === '(') {
							$level++;
						} elseif ($g === ')') {
							$level--;
						} elseif ($g === T_CONSTANT_ENCAPSED_STRING) {
							$literal = str_replace('\\\\', '\\', trim($text($tokens[$j]), '\'"'));
							$this->guarded[strtolower(ltrim($literal, '\\'))] = true;
						} elseif (in_array($g, $nameTokens, true) && $id($tokens[$j + 1] ?? '') === T_DOUBLE_COLON && $id($tokens[$j + 2] ?? '') === T_CLASS) {
							$guardedClass = $resolve($text($tokens[$j]));
							if ($guardedClass !== null) {
								$this->guarded[strtolower($guardedClass)] = true;
							}
						}
					}
				}
				$this->checkFunction($rel, $line($i), $name, $namespace, $functions, $declared, $seen, static fn(string $q): string => (string)$resolve($q));
			}
		}

		// A file that compares Froxlor's version carries code for several
		// versions: what it uses may only exist in one of them.
		$deferred = $this->deferred;
		$this->deferred = null;
		if (preg_match('/version_compare\s*\([^;]*Froxlor::(VERSION|getVersion|getShortVersion|getFullVersion)|isFroxlorVersion|versionCompare2/', $code)) {
			return;
		}
		// A use the file guards itself (if (function_exists('x')) x(); ...) is fine.
		foreach ($deferred as [$subject, $message, $targets]) {
			foreach ($targets as $target) {
				if (isset($this->guarded[strtolower($target)])) {
					continue 2;
				}
			}
			$this->fail($subject, $message);
		}
	}

	/**
	 * "A\B, A\C as D" or "A\{B, C as D}" (the text after "use") as alias => full name.
	 *
	 * @return array<string, string>
	 */
	private static function parseUse(string $statement): array
	{
		$statement = preg_replace('/\s+/', ' ', trim($statement));
		$result = [];
		if (preg_match('/^(.*?)\s*\\\\?\s*\{(.*)\}$/', $statement, $m)) {
			$prefix = trim(str_replace(' ', '', $m[1]), '\\');
			foreach (explode(',', $m[2]) as $item) {
				$item = trim($item);
				if ($item === '') {
					continue;
				}
				[$name, $alias] = self::useItem($item);
				$result[$alias] = $prefix . '\\' . $name;
			}

			return $result;
		}
		foreach (explode(',', $statement) as $item) {
			$item = trim($item);
			if ($item === '') {
				continue;
			}
			[$name, $alias] = self::useItem($item);
			$result[$alias] = $name;
		}

		return $result;
	}

	/** @return array{0:string, 1:string} full name, alias */
	private static function useItem(string $item): array
	{
		$parts = preg_split('/\s+as\s+/i', $item);
		$name = trim(str_replace(' ', '', $parts[0]), '\\');
		$alias = isset($parts[1]) ? trim($parts[1]) : substr((string)strrchr('\\' . $name, '\\'), 1);

		return [$name, $alias];
	}

	private static function isFroxlor(string $name): bool
	{
		return str_starts_with($name, 'Froxlor\\');
	}

	private function checkType(string $rel, int $line, string $class, array &$seen): void
	{
		$key = "type $class";
		if (isset($seen[$key])) {
			return;
		}
		$seen[$key] = true;
		try {
			if (!self::typeExists($class)) {
				$this->report("references $rel:$line", "class $class does not exist", [$class]);
			}
		} catch (\Throwable $e) {
			$this->report("references $rel:$line", "class $class cannot be loaded: " . self::describe($e), [$class]);
		}
	}

	private function checkMethod(string $subject, string $class, string $method, string $what, ?array &$seen = null): void
	{
		$key = "method $class::$method";
		if ($seen !== null) {
			if (isset($seen[$key])) {
				return;
			}
			$seen[$key] = true;
		}
		try {
			$targets = [$class, "$class::$method", $method];
			if (!self::typeExists($class)) {
				$this->report($subject, "class $class does not exist", $targets);
			} elseif (!method_exists($class, $method) && !method_exists($class, '__callStatic') && !method_exists($class, '__call')) {
				$this->report($subject, ($what === 'callback' ? 'callback ' : '') . "$class::$method() does not exist", $targets);
			}
		} catch (\Throwable $e) {
			$this->report($subject, "class $class cannot be loaded: " . self::describe($e), [$class]);
		}
	}

	private function checkConstant(string $rel, int $line, string $class, string $constant, array &$seen): void
	{
		$key = "const $class::$constant";
		if (isset($seen[$key])) {
			return;
		}
		$seen[$key] = true;
		try {
			if (!self::typeExists($class)) {
				$this->report("references $rel:$line", "class $class does not exist", [$class]);
			} elseif (!(new \ReflectionClass($class))->hasConstant($constant)) {
				$this->report("references $rel:$line", "constant $class::$constant does not exist", [$class, "$class::$constant"]);
			}
		} catch (\Throwable $e) {
			$this->report("references $rel:$line", "class $class cannot be loaded: " . self::describe($e), [$class]);
		}
	}

	private function checkProperty(string $rel, int $line, string $class, string $property, array &$seen): void
	{
		$key = "prop $class::$property";
		if (isset($seen[$key])) {
			return;
		}
		$seen[$key] = true;
		try {
			if (!self::typeExists($class)) {
				$this->report("references $rel:$line", "class $class does not exist", [$class]);
			} elseif (!property_exists($class, $property)) {
				$this->report("references $rel:$line", "property $class::\$$property does not exist", [$class, $property]);
			}
		} catch (\Throwable $e) {
			$this->report("references $rel:$line", "class $class cannot be loaded: " . self::describe($e), [$class]);
		}
	}

	private function checkFunction(string $rel, int $line, string $name, string $namespace, array $functions, array $declared, array &$seen, callable $resolveQualified): void
	{
		$lower = strtolower(ltrim($name, '\\'));
		if ($name[0] !== '\\' && !str_contains($name, '\\') && isset($declared[$lower])) {
			return;
		}
		if ($name[0] === '\\') {
			$candidates = [substr($name, 1)];
		} elseif (isset($functions[$lower])) {
			$candidates = [$functions[$lower]];
		} elseif (str_contains($name, '\\')) {
			$candidates = [$resolveQualified($name)];
		} else {
			// Unqualified: the namespaced function first, then the global one.
			$candidates = array_filter([$namespace !== '' ? $namespace . '\\' . $name : null, $name]);
		}
		// Only Froxlor's functions: PHP extensions of the web server may be
		// missing on the command line, an extension's own helper files may
		// only be loaded where they are needed.
		$froxlor = array_filter($candidates, static fn(string $c): bool =>
			(str_starts_with($c, 'Froxlor\\') && !str_starts_with($c, 'Froxlor\\Ext\\'))
			|| (!str_contains($c, '\\') && in_array(strtolower($c), self::FROXLOR_FUNCTIONS, true)));
		if ($froxlor === []) {
			return;
		}
		$key = 'function ' . implode('|', $candidates);
		if (isset($seen[$key])) {
			return;
		}
		$seen[$key] = true;
		foreach ($candidates as $candidate) {
			if (function_exists($candidate)) {
				return;
			}
		}
		$this->report("references $rel:$line", 'function ' . end($candidates) . '() does not exist', $candidates);
	}

	private static function typeExists(string $name): bool
	{
		return class_exists($name) || interface_exists($name) || trait_exists($name) || enum_exists($name);
	}

	// --- templates -------------------------------------------------------

	private function templates(): void
	{
		$this->current = 'templates';
		try {
			\Froxlor\UI\Panel\UI::initTwig();
			$twig = \Froxlor\UI\Panel\UI::twig();
			if (!$twig instanceof \Twig\Environment) {
				$this->fail('templates', 'Froxlor did not set up Twig');

				return;
			}
		} catch (\Throwable $e) {
			$this->fail('templates', 'Twig setup: ' . self::describe($e));

			return;
		}

		// The extensions register their Twig functions themselves when they
		// render (Froxlor\Ext\<Name>\Lang::registerTwig): do the same here.
		foreach (glob($this->dir . '/lib/Froxlor/Ext/*', GLOB_ONLYDIR) ?: [] as $extDir) {
			$class = 'Froxlor\\Ext\\' . basename($extDir) . '\\Lang';
			$this->current = "templates $class::registerTwig";
			try {
				if (is_file($extDir . '/Lang.php') && class_exists($class) && method_exists($class, 'registerTwig')) {
					$class::registerTwig($twig);
				}
			} catch (\Throwable $e) {
				$this->fail("templates $class::registerTwig", self::describe($e));
			}
		}

		$loader = $twig->getLoader();
		$base = $this->dir . '/templates/';
		$count = 0;
		$macroCache = [];
		foreach ($this->files('templates', '/\.twig$/') as $file) {
			$name = substr($file, strlen($base));
			if (preg_match('#^(Froxlor|misc)/#', $name)) {
				// Froxlor's own templates: they come with the update.
				continue;
			}
			$this->current = "template $name";
			try {
				$module = $twig->parse($twig->tokenize($loader->getSourceContext($name)));
				$twig->compile($module);
				$count++;
			} catch (\Throwable $e) {
				$this->fail("template $name", self::describe($e));
				continue;
			}
			try {
				foreach ($this->templateProblems($twig, $module, $macroCache) as $problem) {
					$this->fail("template $name", $problem);
				}
			} catch (\Throwable $e) {
				// The reference analysis depends on Twig's node classes; if they
				// changed, it says nothing rather than something wrong.
			}
		}
		$this->current = 'templates';
		self::line("PASS templates: $count");
	}

	/**
	 * Templates a template extends, includes, embeds or imports (when named
	 * literally) must exist; macros called through an import must be defined
	 * in the imported template.
	 *
	 * @return list<string>
	 */
	private function templateProblems(\Twig\Environment $twig, \Twig\Node\ModuleNode $module, array &$macroCache): array
	{
		$loader = $twig->getLoader();
		$problems = [];
		$imports = []; // alias => template name
		$calls = [];   // [alias, macro, line]

		$constant = static function ($node): ?string {
			return $node instanceof \Twig\Node\Expression\ConstantExpression && is_string($node->getAttribute('value'))
				? $node->getAttribute('value') : null;
		};
		$need = function (?string $template, int $line) use ($loader, &$problems): void {
			if ($template !== null && $template !== '' && !$loader->exists($template)) {
				$problems[] = "line $line: template \"$template\" does not exist";
			}
		};

		$walk = function (\Twig\Node\Node $node) use (&$walk, $constant, $need, &$imports, &$calls): void {
			$short = (new \ReflectionClass($node))->getShortName();
			if ($node instanceof \Twig\Node\ModuleNode && $node->hasNode('parent')) {
				$need($constant($node->getNode('parent')), $node->getTemplateLine());
			}
			if ($node instanceof \Twig\Node\IncludeNode && !$node instanceof \Twig\Node\EmbedNode
				&& !$node->getAttribute('ignore_missing') && $node->hasNode('expr')) {
				$need($constant($node->getNode('expr')), $node->getTemplateLine());
			}
			if ($node instanceof \Twig\Node\ImportNode) {
				$template = $constant($node->getNode('expr'));
				$need($template, $node->getTemplateLine());
				$var = $node->getNode('var');
				if ($var->hasAttribute('name')) {
					// An alias imported more than once (per macro, or "_self")
					// is not followed: which import applies depends on scope.
					$alias = (string)$var->getAttribute('name');
					$imports[$alias] = array_key_exists($alias, $imports) && $imports[$alias] !== $template ? null : $template;
				}
			}
			if ($node instanceof \Twig\Node\Expression\FunctionExpression
				&& in_array($node->getAttribute('name'), ['include', 'source'], true) && $node->hasNode('arguments')) {
				$args = $node->getNode('arguments');
				$first = null;
				$count = 0;
				$ignoreMissing = false;
				foreach ($args as $key => $arg) {
					$first ??= $arg;
					$count++;
					$ignoreMissing = $ignoreMissing || $key === 'ignore_missing';
				}
				// include(t, vars, with_context, ignore_missing), source(t, ignore_missing)
				$positional = $node->getAttribute('name') === 'include' ? 4 : 2;
				if ($first !== null && !$ignoreMissing && $count < $positional) {
					$need($constant($first), $node->getTemplateLine());
				}
			}
			// m.name(): MacroReferenceExpression (Twig >= 3.15) or MethodCallExpression.
			if ($short === 'MacroReferenceExpression' && $node->hasNode('template') && $node->hasAttribute('name')) {
				$tpl = $node->getNode('template');
				if ($tpl->hasAttribute('name')) {
					$calls[] = [(string)$tpl->getAttribute('name'), (string)$node->getAttribute('name'), $node->getTemplateLine()];
				}
			} elseif ($short === 'MethodCallExpression' && $node->hasNode('node') && $node->hasAttribute('method')) {
				$tpl = $node->getNode('node');
				if ($tpl->hasAttribute('name')) {
					$calls[] = [(string)$tpl->getAttribute('name'), (string)$node->getAttribute('method'), $node->getTemplateLine()];
				}
			}
			foreach ($node as $child) {
				if ($child instanceof \Twig\Node\Node) {
					$walk($child);
				}
			}
		};
		$walk($module);

		foreach ($calls as [$alias, $macro, $line]) {
			$template = $imports[$alias] ?? null;
			if ($template === null || !$loader->exists($template)) {
				continue;
			}
			if (!isset($macroCache[$template])) {
				// Macros of the template and of the templates it extends.
				$names = [];
				$current = $template;
				for ($level = 0; $current !== null && $level < 10 && $loader->exists($current); $level++) {
					$imported = $twig->parse($twig->tokenize($loader->getSourceContext($current)));
					foreach ($imported->getNode('macros') as $key => $unused) {
						$names[] = 'macro_' . $key;
					}
					$parent = $imported->hasNode('parent') ? $imported->getNode('parent') : null;
					$current = $parent !== null ? $constant($parent) : null;
					if ($parent !== null && $current === null) {
						// Dynamic parent: the macro may be anywhere.
						$names = null;
						break;
					}
				}
				$macroCache[$template] = $names;
			}
			$wanted = str_starts_with($macro, 'macro_') ? $macro : 'macro_' . $macro;
			if ($macroCache[$template] !== null && !in_array($wanted, $macroCache[$template], true)) {
				$problems[] = "line $line: macro " . substr($wanted, 6) . "() is not defined in \"$template\"";
			}
		}

		return array_values(array_unique($problems));
	}

	// --- helpers ---------------------------------------------------------

	/**
	 * Regular files below a directory of the Froxlor installation whose name
	 * matches, sorted; symbolic links are not followed.
	 *
	 * @return list<string>
	 */
	private function files(string $dir, string $pattern): array
	{
		$root = $this->dir . '/' . $dir;
		if (!is_dir($root) || is_link($root)) {
			return [];
		}
		$result = [];
		$it = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::LEAVES_ONLY,
			\RecursiveIteratorIterator::CATCH_GET_CHILD
		);
		foreach ($it as $file) {
			if ($file->isLink() || !$file->isFile() || !preg_match($pattern, $file->getFilename())) {
				continue;
			}
			$result[] = $file->getPathname();
		}
		sort($result);

		return $result;
	}

	/** A problem found while scanning a file: kept until the whole file is known. */
	private function report(string $subject, string $message, array $targets): void
	{
		if ($this->deferred === null) {
			$this->fail($subject, $message);

			return;
		}
		$this->deferred[] = [$subject, $message, $targets];
	}

	private function fail(string $subject, string $message): void
	{
		$this->failures++;
		self::line('FAIL ' . $subject . ': ' . self::clean($message));
	}

	private function rel(string $file): string
	{
		return str_starts_with($file, $this->dir . '/') ? substr($file, strlen($this->dir) + 1) : $file;
	}

	private static function describe(\Throwable $e): string
	{
		$message = self::clean($e->getMessage());
		if (strlen($message) > 400) {
			$message = substr($message, 0, 400) . '…';
		}

		return (new \ReflectionClass($e))->getShortName() . ': ' . $message;
	}

	private static function clean(string $text): string
	{
		return trim((string)preg_replace('/[\x00-\x1f\x7f]+/', ' ', $text));
	}

	private static function line(string $text): void
	{
		fwrite(STDOUT, $text . "\n");
	}
}

$dir = rtrim((string)($argv[1] ?? ''), '/');
if ($dir === '' || !is_dir($dir)) {
	fwrite(STDERR, "Usage: php compat.php <froxlor-dir>\n");
	exit(2);
}
$dir = (string)realpath($dir);
exit((new Compat($dir))->run());
