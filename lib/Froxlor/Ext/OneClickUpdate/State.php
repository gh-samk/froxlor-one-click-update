<?php

namespace Froxlor\Ext\OneClickUpdate;

/**
 * The files shared between the panel and the root service, all below
 * /var/lib/froxlor-one-click-update/:
 *
 *   runner.json      written by install.sh: version of the service, update
 *                    method, snapshot setting (root, 0640)
 *   status.json      progress and result of the current/last run (root, 0640)
 *   logs/*.log       one log per run (root, 0640)
 *   request/         the only place the panel may write (group of the panel
 *                    user, 0770): "trigger" starts a run through the systemd
 *                    path unit, "meta.json" notes who asked, for display.
 *
 * The panel never decides anything that root acts on: the service ignores
 * what request/ contains apart from the existence of "trigger".
 */
final class State
{
	public const DIR = '/var/lib/froxlor-one-click-update';

	/** A run that claims to be running for longer than this is shown as stuck. */
	public const STALE_AFTER = 3 * 3600;

	/** A request the service has not taken after this long is shown as stuck. */
	public const REQUEST_TIMEOUT = 120;

	/** Bytes read from the end of a log for display. */
	private const LOG_TAIL = 65536;

	private static string $dir = self::DIR;

	/** For tests. */
	public static function setDir(string $dir): void
	{
		self::$dir = rtrim($dir, '/');
	}

	public static function dir(): string
	{
		return self::$dir;
	}

	/** Information about the installed service, or null if it is not installed. */
	public static function runner(): ?array
	{
		return self::readJson(self::$dir . '/runner.json');
	}

	/** The current or last run, or null before the first run. */
	public static function status(): ?array
	{
		return self::readJson(self::$dir . '/status.json');
	}

	/** A request is waiting for the service to pick it up. */
	public static function requestPending(): bool
	{
		$trigger = self::$dir . '/request/trigger';

		return file_exists($trigger) || is_link($trigger);
	}

	/**
	 * The request waits longer than the service needs to start: the path
	 * unit is not active, or systemd gave up on it.
	 */
	public static function requestStuck(?int $now = null): bool
	{
		$stat = @lstat(self::$dir . '/request/trigger');

		return $stat !== false && (int)$stat['mtime'] < ($now ?? time()) - self::REQUEST_TIMEOUT;
	}

	/** Who requested the last run and when (as written by this panel). */
	public static function requestMeta(): ?array
	{
		return self::readJson(self::$dir . '/request/meta.json');
	}

	public static function isRunning(?array $status, ?int $now = null): bool
	{
		if (!is_array($status) || ($status['state'] ?? '') !== 'running') {
			return false;
		}

		return (int)($status['started'] ?? 0) > ($now ?? time()) - self::STALE_AFTER;
	}

	public static function isStale(?array $status, ?int $now = null): bool
	{
		return is_array($status) && ($status['state'] ?? '') === 'running' && !self::isRunning($status, $now);
	}

	/**
	 * Asks the service for a run: notes who asked (meta.json) and creates the
	 * trigger file. Both are written to a temporary name first and renamed,
	 * so the path unit never sees a half-written request.
	 *
	 * @throws \RuntimeException if the request directory is not writable
	 */
	public static function request(string $admin, string $target): void
	{
		$dir = self::$dir . '/request';
		if (!is_dir($dir) || !is_writable($dir)) {
			throw new \RuntimeException(Lang::t('The request directory %s is not writable for the panel. Run install.sh again.', $dir));
		}
		$meta = json_encode(['admin' => $admin, 'target' => $target, 'requested' => time()], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		self::writeAtomic($dir . '/meta.json', (string)$meta);
		self::writeAtomic($dir . '/trigger', '');
	}

	/**
	 * The end of the log of a run. The name comes from status.json and must
	 * look like the service's own names; anything else is not opened.
	 */
	public static function logTail(string $name): ?string
	{
		if (!preg_match('/^update-\d{8}-\d{6}\.log$/', $name)) {
			return null;
		}
		$file = self::$dir . '/logs/' . $name;
		if (is_link($file) || !is_file($file) || !is_readable($file)) {
			return null;
		}
		$size = (int)@filesize($file);
		$offset = max(0, $size - self::LOG_TAIL);
		$text = @file_get_contents($file, false, null, $offset);
		if ($text === false) {
			return null;
		}
		if ($offset > 0) {
			// Start at a full line.
			$nl = strpos($text, "\n");
			$text = '…' . ($nl === false ? $text : substr($text, $nl));
		}
		// Valid UTF-8 only: Twig would otherwise refuse to escape the text.
		return mb_convert_encoding($text, 'UTF-8', 'UTF-8');
	}

	private static function readJson(string $file): ?array
	{
		if (is_link($file) || !is_file($file) || !is_readable($file)) {
			return null;
		}
		$data = json_decode((string)@file_get_contents($file, false, null, 0, 1 << 20), true);

		return is_array($data) ? $data : null;
	}

	private static function writeAtomic(string $file, string $content): void
	{
		$tmp = dirname($file) . '/.' . basename($file) . '.' . bin2hex(random_bytes(4));
		if (@file_put_contents($tmp, $content, LOCK_EX) === false || !@rename($tmp, $file)) {
			@unlink($tmp);
			throw new \RuntimeException(Lang::t('Could not write %s.', $file));
		}
	}
}
