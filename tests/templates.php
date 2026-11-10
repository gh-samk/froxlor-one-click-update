<?php

/**
 * Parses and renders the One-Click Update template with Froxlor's own Twig
 * library — without a running Froxlor:
 *
 *   php tests/templates.php /path/to/froxlor
 *
 * Only vendor/autoload.php of the given Froxlor installation is used (for
 * Twig); nothing is written there. Froxlor's base layout is replaced by a
 * minimal stub. Rendering runs with strict_variables, in German and in
 * English, for every state of the page, so a renamed or misspelled variable
 * fails here instead of silently rendering empty in production.
 */

if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit(1);
}

$froxlor = rtrim($argv[1] ?? '', '/');
if ($froxlor === '' || !is_file($froxlor . '/vendor/autoload.php')) {
	fwrite(STDERR, "Usage: php tests/templates.php /path/to/froxlor\n");
	exit(2);
}
require $froxlor . '/vendor/autoload.php';
require __DIR__ . '/../lib/Froxlor/Ext/OneClickUpdate/Lang.php';
require __DIR__ . '/../lib/Froxlor/Ext/OneClickUpdate/Html.php';

use Froxlor\Ext\OneClickUpdate\Lang;

$stubs = new \Twig\Loader\ArrayLoader([
	'Froxlor/userarea.html.twig' => '{% block heading %}{% endblock %}{% block actions %}{% endblock %}{% block content %}{% endblock %}',
]);
$loader = new \Twig\Loader\ChainLoader([$stubs, new \Twig\Loader\FilesystemLoader(__DIR__ . '/../templates')]);
$twig = new \Twig\Environment($loader, ['cache' => false, 'autoescape' => 'html', 'strict_variables' => true]);
$twig->addFunction(new \Twig\TwigFunction('linker', static fn(array $p): string => 'admin_' . $p['section'] . '.php'));
$twig->addGlobal('csrf_token', 'token');
Lang::registerTwig($twig);

$failures = 0;
$check = static function (bool $ok, string $text) use (&$failures): void {
	printf("  %s %s\n", $ok ? 'OK    ' : 'FAILED', $text);
	if (!$ok) {
		$failures++;
	}
};

$steps = static function (string $active): array {
	$keys = ['check', 'wait', 'snapshot', 'update', 'verify', 'extensions'];
	$i = array_search($active, $keys, true);
	$out = [];
	foreach ($keys as $n => $key) {
		$out[] = ['key' => $key, 'label' => ucfirst($key), 'state' => $i === false ? 'todo' : ($n < $i ? 'done' : ($n === $i ? 'active' : 'todo'))];
	}
	return $out;
};
$runner = ['version' => '1.0.0', 'method' => 'Archive', 'snapshots' => 3, 'reinstall' => ['/usr/local/src/froxlor-project-view', '/usr/local/src/froxlor-agency-theme']];
$lastRun = [
	'state' => 'done', 'phase' => 'finished', 'from' => '2.3.16', 'to' => '2.3.17', 'target' => '2.3.17', 'method' => 'Archive',
	'started' => '10.11.2026, 12:00', 'finished' => '10.11.2026, 12:01', 'duration' => '1 min 4 s', 'snapshot' => '20261110-120000-2.3.16',
	'message' => 'Froxlor was updated from 2.3.16 to 2.3.17.', 'detail' => '', 'success' => true, 'warning' => false,
];
$base = [
	'current' => '2.3.16',
	'channel' => 'stable',
	'check' => ['ok' => true, 'newer' => true, 'version' => '2.3.17', 'info' => '', 'link' => 'https://example.org/release', 'error' => '', 'checked' => 0],
	'installed' => true,
	'runner' => $runner,
	'running' => false,
	'pending' => false,
	'stale' => false,
	'stuck' => false,
	'run' => null,
	'requested_by' => null,
	'log' => '',
	'steps' => $steps(''),
	'flash' => null,
];
$cases = [
	'update available' => $base,
	'update available, service missing' => array_merge($base, ['installed' => false, 'runner' => null]),
	'update available, snapshots off' => array_merge($base, ['runner' => array_merge($runner, ['snapshots' => 0, 'reinstall' => []])]),
	'up to date, last run' => array_merge($base, [
		'current' => '2.3.17', 'check' => array_merge($base['check'], ['newer' => false, 'version' => '', 'link' => '', 'info' => 'You have the latest version']),
		'run' => $lastRun, 'requested_by' => ['admin' => 'admin', 'when' => '10.11.2026, 11:59'], 'log' => "12:00:01 start\n12:00:02 {{ 7*7 }} <b>x</b>\n",
		'flash' => ['type' => 'success', 'text' => '<b>{{ 7*7 }}</b>'],
	]),
	'check failed' => array_merge($base, ['check' => array_merge($base['check'], ['ok' => false, 'newer' => false, 'error' => 'Version-check currently unavailable <script>'])]),
	'running' => array_merge($base, [
		'check' => null, 'running' => true,
		'run' => array_merge($lastRun, ['state' => 'running', 'phase' => 'snapshot', 'to' => '', 'finished' => '', 'duration' => '', 'success' => false, 'message' => '']),
		'steps' => $steps('snapshot'), 'log' => "12:00:01 Snapshot </pre><script>alert(1)</script>",
	]),
	'requested, not started yet' => array_merge($base, ['check' => null, 'running' => true, 'pending' => true, 'steps' => $steps('')]),
	'failed run, stale' => array_merge($base, [
		'stale' => true,
		'run' => array_merge($lastRun, ['state' => 'failed', 'success' => false, 'message' => 'The update failed (x).', 'snapshot' => '']),
	]),
	'request stuck' => array_merge($base, ['stuck' => true]),
	'extensions failed' => array_merge($base, ['run' => array_merge($lastRun, ['warning' => true, 'message' => 'Froxlor was updated, but …'])]),
];

echo "Syntax\n";
foreach (glob(__DIR__ . '/../templates/OneClickUpdate/*.twig') as $file) {
	try {
		$twig->parse($twig->tokenize(new \Twig\Source(file_get_contents($file), basename($file))));
		$check(true, basename($file));
	} catch (\Throwable $e) {
		$check(false, basename($file) . ': ' . $e->getMessage());
	}
}

foreach (['en', 'de'] as $language) {
	Lang::set($language);
	echo "Rendering ($language)\n";
	foreach ($cases as $name => $vars) {
		try {
			$html = $twig->render('OneClickUpdate/update.html.twig', $vars);
			$check(true, $name);
		} catch (\Throwable $e) {
			$check(false, $name . ': ' . $e->getMessage());
			continue;
		}
		$check(!preg_match('/%[sd]/', strip_tags($html)), "$name: no unreplaced placeholders");
		$check(substr_count($html, '<div v-pre') === 3, "$name: heading, actions and content are inside v-pre");
		$form = str_contains($html, 'name="action" value="start"');
		if ($name === 'update available') {
			$check($form && str_contains($html, 'name="csrf_token" value="token"') && !str_contains($html, 'disabled'), 'update button with CSRF token, enabled');
			$check(str_contains($html, 'href="https://example.org/release"') && str_contains($html, 'rel="noopener noreferrer"'), 'release notes link');
			$check(!str_contains($html, '<script>'), 'no live-refresh script when nothing runs');
		}
		if ($name === 'update available, service missing') {
			$check($form && preg_match('/<button type="submit" class="btn btn-primary btn-lg"\s+disabled>/', $html) === 1, 'button disabled without the service');
		}
		if ($name === 'up to date, last run') {
			$check(!$form, 'no update button when up to date');
			$check(!str_contains($html, '<b>') && str_contains($html, '&lt;b&gt;'), 'flash and log are escaped');
			$check(str_contains($html, 'froxlor-one-click-update restore 20261110-120000-2.3.16'), 'restore command for the snapshot');
		}
		if ($name === 'check failed') {
			$check(!$form && str_contains($html, '&lt;script&gt;'), 'error shown escaped, no button');
		}
		if ($name === 'running') {
			$check(!$form, 'no update button while running');
			$check(substr_count($html, '<script>') === 1 && !str_contains($html, '<script>alert'), 'one live-refresh script, log escaped');
			$check(str_contains($html, '"admin_one_click_update.php?page=progress"'), 'progress URL in the script');
			$check(substr_count($html, 'data-state="done"') === 2 && substr_count($html, 'data-state="active"') === 1, 'steps: two done, one active');
		}
		if ($name === 'request stuck') {
			$check($form && str_contains($html, 'systemctl status froxlor-one-click-update.path'), 'stuck request: hint and the button again');
		}
		if ($name === 'failed run, stale') {
			$check(str_contains($html, 'fa-circle-xmark') && $form, 'failed run shown, update still possible');
		}
	}
}

// Froxlor's own alert box prints the message with |raw: an error message
// with text from outside must stay inert.
$alertbox = $froxlor . '/templates/Froxlor/misc/alertbox.html.twig';
if (is_file($alertbox)) {
	echo "Error page\n";
	$alert = new \Twig\Environment(new \Twig\Loader\ArrayLoader(['a' => file_get_contents($alertbox)]));
	$alert->addFunction(new \Twig\TwigFunction('lng', static fn($k) => 'Back'));
	$html = $alert->render('a', [
		'type' => 'danger', 'btntype' => 'light', 'heading' => 'Error', 'redirect_link' => '', 'alert_info' => '',
		'alert_msg' => \Froxlor\Ext\OneClickUpdate\Html::errorMessage('<img src=x onerror=alert(1)> {{ 7*7 }}'),
	]);
	$check(!str_contains($html, '<img') && !str_contains($html, '{{') && str_contains($html, '<span v-pre>'), 'text in an error message is inert in Froxlor\'s alert box');
}

echo $failures === 0 ? "All checks passed.\n" : "$failures check(s) failed.\n";
exit($failures === 0 ? 0 : 1);
