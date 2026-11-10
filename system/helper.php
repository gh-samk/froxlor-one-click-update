<?php

/**
 * Root-side helper of One-Click Update, called by the froxlor-one-click-update
 * script. Installed outside the Froxlor directory (root-owned) together with
 * DbConfig.php.
 *
 *   helper.php state <froxlor-dir>
 *       JSON: version of the files, version recorded in the database,
 *       whether a database update is pending, update channel.
 *   helper.php panel-group <froxlor-dir>
 *       Name of the group the panel's PHP runs as (from Froxlor's settings).
 *   helper.php client-options <froxlor-dir> <file>
 *       Writes a [client] options file for mariadb-dump/mariadb into <file>
 *       (which must exist, be a regular file and be owned by root) and prints
 *       the name of Froxlor's database.
 *   helper.php status-set <file> <gid> [--reset] key=value ...
 *       Merges the pairs into the JSON file (atomic replace, mode 0640,
 *       group <gid>). Values that look like integers are stored as numbers,
 *       "-" removes a key.
 *   helper.php status-get <file> <key>
 *       Prints one value (empty if missing).
 *
 * Froxlor's own code is never loaded here: the Froxlor directory belongs to
 * the panel user. Versions are read from the source text, the database
 * settings are parsed from lib/userdata.inc.php (see DbConfig).
 */

if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit(1);
}

require __DIR__ . '/DbConfig.php';

use Froxlor\Ext\OneClickUpdate\DbConfig;

function ocu_fail(string $message): never
{
	fwrite(STDERR, $message . "\n");
	exit(1);
}

/**
 * VERSION, DBVERSION and BRANDING of lib/Froxlor/Froxlor.php, read as text.
 *
 * @return array{version:string, dbversion:string, branding:string}
 */
function ocu_file_versions(string $froxlor): array
{
	$file = $froxlor . '/lib/Froxlor/Froxlor.php';
	if (is_link($file) || !is_file($file)) {
		ocu_fail("$file is not a regular file.");
	}
	$code = (string)file_get_contents($file, false, null, 0, 1 << 20);
	$get = static function (string $name) use ($code): string {
		return preg_match('/const\s+' . $name . "\s*=\s*'([^']*)'/", $code, $m) ? $m[1] : '';
	};
	$version = $get('VERSION');
	if (!preg_match('/^\d+\.\d+\.\d+[0-9A-Za-z.+~-]*$/', $version)) {
		ocu_fail("No valid version found in $file.");
	}

	return ['version' => $version, 'dbversion' => $get('DBVERSION'), 'branding' => $get('BRANDING')];
}

/** @return array{host:string, port:int, socket:string, user:string, password:string, db:string, ca_file:string, verify_cert:bool} */
function ocu_db_config(string $froxlor): array
{
	try {
		[$sql] = DbConfig::readUserdata($froxlor . '/lib/userdata.inc.php');
	} catch (\RuntimeException $e) {
		ocu_fail($e->getMessage());
	}
	if (!isset($sql['db'], $sql['user'])) {
		ocu_fail('userdata.inc.php contains no usable $sql configuration.');
	}

	return DbConfig::normalize($sql, (string)$sql['db']);
}

function ocu_froxlor_dir(string $dir): string
{
	$dir = rtrim($dir, '/');
	if ($dir === '' || !is_file($dir . '/lib/init.php') || !is_file($dir . '/lib/Froxlor/Froxlor.php')) {
		ocu_fail("'$dir' is not a Froxlor installation.");
	}

	return $dir;
}

/** Atomic replace of a JSON file, owner root, group $gid, mode 0640. */
function ocu_write_json(string $file, array $data, int $gid): void
{
	$dir = dirname($file);
	if (is_link($dir) || !is_dir($dir)) {
		ocu_fail("$dir is not a directory.");
	}
	$tmp = $dir . '/.' . basename($file) . '.' . bin2hex(random_bytes(4));
	$old = umask(0077);
	$fh = @fopen($tmp, 'x');
	umask($old);
	if ($fh === false) {
		ocu_fail("Cannot create $tmp.");
	}
	fwrite($fh, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
	fclose($fh);
	if ($gid >= 0) {
		@chgrp($tmp, $gid);
	}
	chmod($tmp, 0640);
	if (!rename($tmp, $file)) {
		@unlink($tmp);
		ocu_fail("Cannot replace $file.");
	}
}

/** @return array<string,mixed> */
function ocu_read_json(string $file): array
{
	if (is_link($file) || !is_file($file)) {
		return [];
	}
	$data = json_decode((string)file_get_contents($file, false, null, 0, 1 << 20), true);

	return is_array($data) ? $data : [];
}

$command = $argv[1] ?? '';

switch ($command) {
	case 'state':
		$froxlor = ocu_froxlor_dir($argv[2] ?? '');
		$files = ocu_file_versions($froxlor);
		$c = ocu_db_config($froxlor);
		try {
			$pdo = DbConfig::pdo($c, $c['db']);
			$rows = $pdo->query("SELECT `settinggroup`, `varname`, `value` FROM `panel_settings`
				WHERE (`settinggroup` = 'panel' AND `varname` IN ('version', 'db_version'))
				   OR (`settinggroup` = 'system' AND `varname` = 'update_channel')")->fetchAll(PDO::FETCH_ASSOC);
		} catch (\PDOException $e) {
			ocu_fail('Database connection failed: ' . $e->getMessage());
		}
		$settings = [];
		foreach ($rows as $row) {
			$settings[$row['settinggroup'] . '.' . $row['varname']] = (string)$row['value'];
		}
		$dbVersion = $settings['panel.version'] ?? '';
		$dbDbVersion = $settings['panel.db_version'] ?? '';
		echo json_encode([
			'version' => $files['version'],
			'dbversion' => $files['dbversion'],
			'branding' => $files['branding'],
			'db_version' => $dbVersion,
			'db_dbversion' => $dbDbVersion,
			// Same rule as Froxlor::hasUpdates() || Froxlor::hasDbUpdates().
			'pending' => $dbVersion !== $files['version'] || $dbDbVersion !== $files['dbversion'],
			'channel' => $settings['system.update_channel'] ?? 'stable',
		], JSON_UNESCAPED_SLASHES) . "\n";
		break;

	case 'panel-group':
		// The group the panel's PHP runs as, by the same rule as Froxlor's
		// MasterCron::validateOwnership(): own PHP-FPM/FCGID vhost user, else
		// the web server user.
		$froxlor = ocu_froxlor_dir($argv[2] ?? '');
		$c = ocu_db_config($froxlor);
		try {
			$rows = DbConfig::pdo($c, $c['db'])->query("SELECT CONCAT(`settinggroup`, '.', `varname`) AS `k`, `value` FROM `panel_settings`
				WHERE `settinggroup` IN ('system', 'phpfpm')")->fetchAll(PDO::FETCH_KEY_PAIR);
		} catch (\PDOException $e) {
			ocu_fail('Database connection failed: ' . $e->getMessage());
		}
		$group = (string)($rows['system.httpgroup'] ?? '');
		if ((int)($rows['phpfpm.enabled'] ?? 0) === 1 && (int)($rows['phpfpm.enabled_ownvhost'] ?? 0) === 1) {
			$group = (string)($rows['phpfpm.vhost_httpgroup'] ?? $group);
		} elseif ((int)($rows['system.mod_fcgid'] ?? 0) === 1 && (int)($rows['system.mod_fcgid_ownvhost'] ?? 0) === 1) {
			$group = (string)($rows['system.mod_fcgid_httpgroup'] ?? $group);
		}
		if (!preg_match('/^[A-Za-z0-9._][A-Za-z0-9._-]{0,31}$/', $group)) {
			ocu_fail('No valid panel group found in Froxlor\'s settings.');
		}
		echo $group . "\n";
		break;

	case 'client-options':
		$froxlor = ocu_froxlor_dir($argv[2] ?? '');
		$file = $argv[3] ?? '';
		// The caller creates the file (mktemp, mode 0600) in a root-only
		// directory; this only fills it.
		$asRoot = function_exists('posix_geteuid') && posix_geteuid() === 0;
		if ($file === '' || is_link($file) || !is_file($file) || ($asRoot && fileowner($file) !== 0)) {
			ocu_fail('client-options: target must be an existing regular file owned by root.');
		}
		$c = ocu_db_config($froxlor);
		if (file_put_contents($file, implode("\n", DbConfig::clientOptions($c)) . "\n") === false) {
			ocu_fail("Cannot write $file.");
		}
		chmod($file, 0600);
		echo $c['db'] . "\n";
		break;

	case 'status-set':
		$file = $argv[2] ?? '';
		$gid = (int)($argv[3] ?? -1);
		$pairs = array_slice($argv, 4);
		$data = ocu_read_json($file);
		if (($pairs[0] ?? '') === '--reset') {
			$data = [];
			array_shift($pairs);
		}
		foreach ($pairs as $pair) {
			$pos = strpos($pair, '=');
			if ($pos === false || $pos === 0) {
				ocu_fail("status-set: '$pair' is not key=value.");
			}
			$key = substr($pair, 0, $pos);
			$value = substr($pair, $pos + 1);
			if ($value === '-') {
				unset($data[$key]);
			} elseif (preg_match('/^-?[1-9]\d{0,17}$|^0$/', $value)) {
				$data[$key] = (int)$value;
			} else {
				$data[$key] = $value;
			}
		}
		ocu_write_json($file, $data, $gid);
		break;

	case 'status-get':
		$value = ocu_read_json($argv[2] ?? '')[$argv[3] ?? ''] ?? '';
		echo is_scalar($value) ? (string)$value : json_encode($value);
		echo "\n";
		break;

	default:
		ocu_fail('Usage: helper.php state|client-options|status-set|status-get ...');
}
