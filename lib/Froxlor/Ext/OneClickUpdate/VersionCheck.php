<?php

namespace Froxlor\Ext\OneClickUpdate;

use Froxlor\CurrentUser;
use Froxlor\Froxlor;
use Froxlor\Install\AutoUpdate;
use Froxlor\Install\Update;
use Froxlor\Settings;

/**
 * Asks froxlor.org whether a newer version exists — with Froxlor's own
 * AutoUpdate::checkVersion(), the same call as the dashboard and
 * "froxlor-cli froxlor:update --check-only" (channel from Froxlor's settings).
 *
 * The answer is kept in the session for a few minutes, so reloading the page
 * (it refreshes itself while an update runs) does not ask the version server
 * every time. It is also stored in Froxlor's own update-check cache, in
 * Froxlor's format, so the version note in the panel's top bar agrees.
 */
final class VersionCheck
{
	private const SESSION_KEY = 'ext_oneclickupdate_check';
	private const CACHE_SECONDS = 600;

	/**
	 * @return array{ok:bool, newer:bool, version:string, info:string, link:string, error:string, checked:int}
	 *   version = newest available version (only if newer), info/link = text or
	 *   link sent by the version server, error = why the check failed
	 */
	public static function get(bool $force = false): array
	{
		$key = Froxlor::VERSION . '|' . (string)Settings::Get('system.update_channel');
		$cached = CurrentUser::getField(self::SESSION_KEY);
		if (!$force && is_array($cached) && ($cached['key'] ?? '') === $key
			&& (int)($cached['result']['checked'] ?? 0) > time() - self::CACHE_SECONDS) {
			return $cached['result'];
		}

		$result = self::check();
		CurrentUser::setField(self::SESSION_KEY, ['key' => $key, 'result' => $result]);

		return $result;
	}

	/** Forget the cached answer (after an update the installed version changed). */
	public static function forget(): void
	{
		CurrentUser::setField(self::SESSION_KEY, null);
	}

	private static function check(): array
	{
		$result = ['ok' => false, 'newer' => false, 'version' => '', 'info' => '', 'link' => '', 'error' => '', 'checked' => time()];
		try {
			$rc = AutoUpdate::checkVersion();
		} catch (\Throwable $e) {
			$result['error'] = $e->getMessage();

			return $result;
		}

		$info = trim((string)AutoUpdate::getFromResult('info'));
		if (preg_match('~^https://[^\s<>"\']+$~', $info)) {
			$result['link'] = $info;
		} else {
			$result['info'] = self::plain($info);
		}

		if ($rc === 1) {
			$version = (string)AutoUpdate::getFromResult('version');
			if (!preg_match('/^\d+\.\d+\.\d+[0-9A-Za-z.+~-]*$/', $version)) {
				$result['error'] = Lang::t('The version server sent an invalid version number.');

				return $result;
			}
			$result['ok'] = true;
			$result['newer'] = true;
			$result['version'] = $version;
		} elseif ($rc === 0) {
			$result['ok'] = true;
		} elseif ($rc < 0) {
			$result['error'] = self::plain(AutoUpdate::getLastError());
		} else {
			// Local problems, numbered like Froxlor's lng('error.autoupdate_N').
			$result['error'] = self::plain((string)lng($rc === 3 ? 'error.customized_version' : 'error.autoupdate_' . $rc));
		}

		self::storeForFroxlor($rc, $result);

		return $result;
	}

	/**
	 * Same structure as Froxlor's Api\Commands\Froxlor::checkUpdate(), so the
	 * dashboard's version popover shows the same result.
	 */
	private static function storeForFroxlor(int $rc, array $result): void
	{
		if (!method_exists(Update::class, 'storeUpdateCheckData')) {
			return;
		}
		try {
			$message = '';
			if ($result['newer']) {
				$channel = (string)Settings::Get('system.update_channel');
				$message = (string)lng('update.uc_newinfo', [$channel !== 'stable' ? $channel . ' ' : '', $result['version'], Froxlor::VERSION]);
			}
			Update::storeUpdateCheckData([
				'isnewerversion' => $result['newer'] ? 1 : 0,
				'version' => Froxlor::VERSION,
				'message' => $message,
				'link' => (string)AutoUpdate::getFromResult('url'),
				'additional_info' => $result['error'] !== '' ? $result['error'] : (string)AutoUpdate::getFromResult('info'),
				'aucheck' => $rc,
			]);
		} catch (\Throwable $e) {
			// Only a convenience for the dashboard.
		}
	}

	private static function plain(string $text): string
	{
		return trim(html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
	}
}
