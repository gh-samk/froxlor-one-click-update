<?php

namespace Froxlor\Ext\OneClickUpdate;

use Froxlor\CurrentUser;
use Froxlor\Froxlor;
use Froxlor\FroxlorLogger;
use Froxlor\Settings;
use Froxlor\UI\Panel\UI;
use Froxlor\UI\Request;
use Froxlor\UI\Response;

/**
 * The update page (admin_one_click_update.php).
 *
 *   GET                 overview: installed and available version, last run
 *   GET  ?check=1       ask the version server again
 *   GET  ?page=progress JSON for the live view while a run is going on
 *   POST action=start   request a run (CSRF already checked by Froxlor's
 *                       lib/init.php), then POST-redirect-GET
 *
 * The page itself changes nothing on the server: it only leaves a request
 * for the root service (see State) and shows what the service reports.
 */
final class Page
{
	public const TEMPLATE = 'OneClickUpdate/update.html.twig';
	private const FLASH = 'ext_oneclickupdate_flash';

	/** Steps of a run, in order, as reported by the service in status.json "phase". */
	private const PHASES = ['check', 'wait', 'snapshot', 'update', 'verify', 'extensions'];

	public static function run(array $userinfo, string $filename): void
	{
		if (Request::get('page') === 'progress') {
			self::progress();
		}

		if (Request::post('send') === 'send' && Request::post('action') === 'start') {
			self::start($userinfo);
			Response::redirectTo($filename);
		}

		$status = State::status();
		$runner = State::runner();
		[$running, $pending, $stuck] = self::activity($status);

		// While a run is going on the version server is not asked; afterwards
		// the installed version has changed and the cached answer is stale.
		$check = null;
		if (!$running) {
			$force = Request::get('check') === '1';
			if (is_array($status) && ($status['state'] ?? '') === 'done' && (int)($status['finished'] ?? 0) > (int)(CurrentUser::getField('ext_oneclickupdate_seen') ?? 0)) {
				$force = true;
				CurrentUser::setField('ext_oneclickupdate_seen', (int)$status['finished']);
			}
			$check = VersionCheck::get($force);
		}

		self::render([
			'current' => Froxlor::VERSION,
			'channel' => self::channelLabel((string)Settings::Get('system.update_channel')),
			'check' => $check,
			'installed' => $runner !== null,
			'runner' => self::runnerView($runner),
			'running' => $running,
			'pending' => $pending,
			'stale' => State::isStale($status),
			'stuck' => $stuck,
			'run' => self::statusView($status),
			'requested_by' => self::requestedBy(),
			'log' => is_array($status) ? (State::logTail((string)($status['log'] ?? '')) ?? '') : '',
			'steps' => self::steps($pending ? null : $status),
			'flash' => self::takeFlash(),
		]);
	}

	private static function start(array $userinfo): void
	{
		if (State::runner() === null) {
			self::flash(Lang::t('The update service is not installed on this server. Run install.sh as root first.'), 'danger');

			return;
		}
		[$running] = self::activity(State::status());
		if ($running) {
			self::flash(Lang::t('An update is already running.'), 'danger');

			return;
		}
		$check = VersionCheck::get(true);
		if (!$check['newer']) {
			self::flash($check['ok'] ? Lang::t('Froxlor is already up to date.') : Lang::t('The version check failed: %s', $check['error']), $check['ok'] ? 'success' : 'danger');

			return;
		}
		try {
			State::request((string)$userinfo['loginname'], $check['version']);
		} catch (\Throwable $e) {
			self::flash($e->getMessage(), 'danger');

			return;
		}
		try {
			FroxlorLogger::getInstanceOf($userinfo)->logAction(FroxlorLogger::ADM_ACTION, LOG_WARNING, sprintf('requested a Froxlor update from %s to %s (One-Click Update)', Froxlor::VERSION, $check['version']));
		} catch (\Throwable $e) {
			// Logging is not essential for the request.
		}
		VersionCheck::forget();
		self::flash(Lang::t('Update to %s requested. It starts within a few seconds.', $check['version']));
	}

	/** Live view: status, steps and the end of the log as JSON. */
	private static function progress(): never
	{
		$status = State::status();
		[$running, $pending] = self::activity($status);
		header('Content-Type: application/json; charset=UTF-8');
		// While the request waits, status.json still describes the previous
		// run: no steps or log from it.
		echo json_encode([
			'running' => $running,
			'pending' => $pending,
			'phase' => $pending ? '' : (string)($status['phase'] ?? ''),
			'steps' => self::steps($pending ? null : $status),
			'log' => !$pending && is_array($status) ? (State::logTail((string)($status['log'] ?? '')) ?? '') : '',
		], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
		exit;
	}

	/**
	 * @return array{0:bool, 1:bool, 2:bool} running (a run or a fresh request),
	 *   pending (request not yet taken), stuck (request not taken in time)
	 */
	private static function activity(?array $status): array
	{
		$inProgress = State::isRunning($status);
		$request = State::requestPending() && !$inProgress;
		$stuck = $request && State::requestStuck();

		return [$inProgress || ($request && !$stuck), $request && !$stuck, $stuck];
	}

	/** The status of the last run, prepared for display. */
	private static function statusView(?array $status): ?array
	{
		if ($status === null) {
			return null;
		}
		$state = (string)($status['state'] ?? '');
		$from = (string)($status['from'] ?? '');
		$to = (string)($status['to'] ?? '');
		$detail = (string)($status['detail'] ?? '');
		$code = (string)($status['code'] ?? '');

		return [
			'state' => $state,
			'phase' => (string)($status['phase'] ?? ''),
			'from' => $from,
			'to' => $to,
			'target' => (string)($status['target'] ?? ''),
			'method' => self::methodLabel((string)($status['method'] ?? '')),
			'started' => self::date((int)($status['started'] ?? 0)),
			'finished' => self::date((int)($status['finished'] ?? 0)),
			'duration' => isset($status['started'], $status['finished']) && (int)$status['finished'] > (int)$status['started'] ? self::duration((int)$status['finished'] - (int)$status['started']) : '',
			'snapshot' => (string)($status['snapshot'] ?? ''),
			'message' => self::resultMessage($code, $from, $to, $detail),
			'detail' => $detail,
			'success' => in_array($state, ['done', 'uptodate', 'restored'], true),
			'warning' => $code === 'extensions_failed',
		];
	}

	/** Text for the result codes of the service (froxlor-one-click-update, finish/fail). */
	private static function resultMessage(string $code, string $from, string $to, string $detail): string
	{
		return match ($code) {
			'' => '',
			'updated' => Lang::t('Froxlor was updated from %s to %s.', $from, $to),
			'db_updated' => Lang::t('The pending database update of Froxlor %s was completed.', $to),
			'restored' => Lang::t('Froxlor was put back to snapshot %s.', $detail),
			'extensions_failed' => Lang::t('Froxlor was updated from %s to %s, but the installer of at least one extension failed. Run it again by hand: %s', $from, $to, $detail),
			'no_update' => Lang::t('Froxlor was already up to date, nothing was changed.'),
			'check_failed' => Lang::t('The version check failed — the version server may be unreachable. Nothing was changed.'),
			'state_failed' => Lang::t('The installed version could not be read. Nothing was changed.'),
			'cron_busy' => Lang::t('A Froxlor cron job did not finish in time. Nothing was changed — try again later.'),
			'snapshot_failed' => Lang::t('The snapshot could not be taken, so the update did not start. Nothing was changed.'),
			'apt_no_candidate' => Lang::t('The package source does not offer a newer froxlor package yet. Nothing was changed — try again later.'),
			'db_pending' => Lang::t('The files were updated, but the database update did not complete. Open Froxlor\'s update page or run the update again.'),
			'not_updated' => Lang::t('The update did not change the installed version. Details are in the log.'),
			'update_failed' => Lang::t('The update command failed and the version did not change, but some files may have been replaced. Check the log; the snapshot can bring Froxlor back.'),
			'no_space' => Lang::t('There is not enough disk space for the update and the snapshot. Nothing was changed.'),
			'setup_failed' => Lang::t('The update service could not start: %s', $detail),
			'interrupted' => Lang::t('The update was interrupted (%s). Check the log; the snapshot can bring Froxlor back.', $detail),
			default => Lang::t('The update failed (%s).', $code),
		};
	}

	/**
	 * The steps with their state for the progress list.
	 *
	 * @return list<array{key:string, label:string, state:string}>  state: done|active|todo|failed
	 */
	private static function steps(?array $status): array
	{
		$labels = [
			'check' => Lang::t('Check for a newer version'),
			'wait' => Lang::t('Wait for Froxlor\'s cron jobs'),
			'snapshot' => Lang::t('Take a snapshot of files and database'),
			'update' => Lang::t('Install the new version'),
			'verify' => Lang::t('Check files and database'),
			'extensions' => Lang::t('Reinstall extensions'),
		];
		$phase = (string)($status['phase'] ?? '');
		if ($phase === 'database') {
			$phase = 'update';
		}
		$state = (string)($status['state'] ?? '');
		$index = array_search($phase, self::PHASES, true);
		$steps = [];
		foreach (self::PHASES as $i => $key) {
			if ($status === null || $phase === '') {
				$s = 'todo';
			} elseif ($phase === 'finished') {
				$s = $state === 'done' ? 'done' : 'todo';
			} elseif ($index === false || $i > $index) {
				$s = 'todo';
			} elseif ($i < $index) {
				$s = 'done';
			} else {
				$s = State::isRunning($status) ? 'active' : 'failed';
			}
			$steps[] = ['key' => $key, 'label' => $labels[$key], 'state' => $s];
		}

		return $steps;
	}

	private static function runnerView(?array $runner): ?array
	{
		if ($runner === null) {
			return null;
		}
		$reinstall = trim((string)($runner['reinstall'] ?? ''));

		return [
			'version' => (string)($runner['version'] ?? ''),
			'method' => self::methodLabel((string)($runner['method'] ?? '')),
			'snapshots' => (int)($runner['snapshots'] ?? 0),
			'reinstall' => $reinstall === '' ? [] : preg_split('/\s+/', $reinstall),
		];
	}

	private static function requestedBy(): ?array
	{
		$meta = State::requestMeta();
		if ($meta === null || !isset($meta['admin'], $meta['requested'])) {
			return null;
		}

		return ['admin' => (string)$meta['admin'], 'when' => self::date((int)$meta['requested'])];
	}

	private static function methodLabel(string $method): string
	{
		return match ($method) {
			'apt' => Lang::t('Debian package (apt)'),
			'archive' => Lang::t('Archive from froxlor.org (froxlor-cli froxlor:update)'),
			default => $method,
		};
	}

	private static function channelLabel(string $channel): string
	{
		return match ($channel) {
			'', 'stable' => Lang::t('stable'),
			'testing' => Lang::t('testing'),
			default => $channel,
		};
	}

	private static function date(int $ts): string
	{
		if ($ts <= 0) {
			return '';
		}

		return date(Lang::iso() === 'de' ? 'd.m.Y, H:i' : 'Y-m-d H:i', $ts);
	}

	private static function duration(int $seconds): string
	{
		if ($seconds < 0) {
			return '';
		}
		if ($seconds < 60) {
			return Lang::t('%d s', $seconds);
		}

		return Lang::t('%d min %d s', intdiv($seconds, 60), $seconds % 60);
	}

	private static function render(array $context): void
	{
		try {
			Lang::registerTwig(UI::twig());
			echo UI::twig()->render(self::TEMPLATE, $context);
		} catch (\Throwable $e) {
			// Template errors can contain paths and data: details go to the
			// PHP error log, the page only says that something went wrong.
			error_log('One-Click Update: rendering failed: ' . $e->getMessage());
			Response::dynamicError(Html::errorMessage(Lang::t('The page could not be displayed. Details are in the PHP error log.')));
		}
	}

	private static function flash(string $text, string $type = 'success'): void
	{
		CurrentUser::setField(self::FLASH, ['type' => $type, 'text' => $text]);
	}

	private static function takeFlash(): ?array
	{
		$flash = CurrentUser::getField(self::FLASH);
		if (!is_array($flash) || !isset($flash['text'])) {
			return null;
		}
		CurrentUser::setField(self::FLASH, null);

		return ['type' => $flash['type'] === 'danger' ? 'danger' : 'success', 'text' => (string)$flash['text']];
	}
}
