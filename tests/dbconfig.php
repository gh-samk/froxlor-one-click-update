<?php

/**
 * Checks system/DbConfig.php, which the root service uses on a file that
 * belongs to the panel user (lib/userdata.inc.php):
 *
 *   php tests/dbconfig.php
 *
 * - the file is parsed, never executed, and the usual format is understood
 * - a parse error never repeats the file's content (it may end up in a log
 *   the panel can read, and the file may be a link to a root-only file)
 * - links are refused
 * - a line break in a value cannot add options to the client options file
 */

if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit(1);
}

require __DIR__ . '/../system/DbConfig.php';

use Froxlor\Ext\OneClickUpdate\DbConfig;

$failures = 0;
$check = static function (bool $ok, string $text) use (&$failures): void {
	printf("  %s %s\n", $ok ? 'OK    ' : 'FAILED', $text);
	if (!$ok) {
		$failures++;
	}
};
$dir = sys_get_temp_dir() . '/ocu-dbconfig-' . bin2hex(random_bytes(4));
mkdir($dir, 0700);
$write = static function (string $name, string $content) use ($dir): string {
	file_put_contents("$dir/$name", $content);
	return "$dir/$name";
};
$error = static function (callable $fn): ?string {
	try {
		$fn();
	} catch (\RuntimeException $e) {
		return $e->getMessage();
	}
	return null;
};

$ok = $write('ok.php', "<?php\n\$sql = ['host' => 'localhost', 'user' => 'froxlor', 'password' => 'p\"a\\\\ss', 'db' => 'froxlor'];\n\$sql_root = [0 => ['caption' => 'Default', 'host' => 'localhost', 'user' => 'root', 'password' => 'x']];\n");
[$sql] = DbConfig::readUserdata($ok);
$check(($sql['user'] ?? '') === 'froxlor' && ($sql['db'] ?? '') === 'froxlor', 'usual format is read');
$lines = DbConfig::clientOptions(DbConfig::normalize($sql, 'froxlor'));
$check(in_array('password="p\\"a\\\\ss"', $lines, true), 'quotes and backslashes are escaped in the options file');

$code = $write('code.php', "<?php\n\$sql = ['user' => 'u', 'password' => 'p', 'db' => 'd'];\nsystem('id');\n");
$check($error(static fn() => DbConfig::readUserdata($code)) !== null, 'code in the file is refused, not run');

$secret = $write('shadow', "root:SECRET-HASH-123:19000:0:99999:7:::\n");
$msg = (string)$error(static fn() => DbConfig::readUserdata($secret));
$check($msg !== '' && !str_contains($msg, 'SECRET'), 'parse error does not repeat the file content');

symlink($secret, "$dir/link.php");
$check($error(static fn() => DbConfig::readUserdata("$dir/link.php")) !== null, 'a link is refused');

$nl = $write('nl.php', "<?php\n\$sql = ['user' => 'u', 'password' => \"x\\nplugin-dir=/tmp\\ndefault-auth=evil\", 'db' => 'd'];\n");
[$sql] = DbConfig::readUserdata($nl);
$check($error(static fn() => DbConfig::clientOptions(DbConfig::normalize($sql, 'd'))) !== null, 'a line break in a value is refused for the options file');

array_map('unlink', glob("$dir/*"));
rmdir($dir);

echo $failures === 0 ? "All checks passed.\n" : "$failures check(s) failed.\n";
exit($failures === 0 ? 0 : 1);
