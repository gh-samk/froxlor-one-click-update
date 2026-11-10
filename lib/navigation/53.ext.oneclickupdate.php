<?php

/**
 * Navigation entry of One-Click Update.
 *
 * Froxlor merges all *.php files from lib/navigation/, sorted and recursively
 * (PhpHelper::loadConfigArrayDir with array_merge_recursive). The entry is
 * appended to Froxlor's "Server" menu — no Froxlor file is touched. "53."
 * sorts the file after the core menu (00.froxlor.main.php).
 *
 * Froxlor prints menu labels unescaped (|raw); the label is a fixed text,
 * the badge only appears when Froxlor's own cached version check (the one
 * behind the version note in the top bar) knows of a newer version.
 */

use Froxlor\Ext\OneClickUpdate\Lang;
use Froxlor\Install\Update;

$ocuLabel = htmlspecialchars(Lang::t('Froxlor update'), ENT_QUOTES, 'UTF-8');
try {
	$ocuCheck = method_exists(Update::class, 'getUpdateCheckData') ? Update::getUpdateCheckData() : null;
	if ((int)($ocuCheck['data']['isnewerversion'] ?? 0) === 1) {
		$ocuLabel .= ' <span class="badge text-bg-warning ms-1">' . htmlspecialchars(Lang::t('new'), ENT_QUOTES, 'UTF-8') . '</span>';
	}
} catch (\Throwable $e) {
	// Without the badge.
}

return [
	'admin' => [
		'server' => [
			'elements' => [
				[
					'url' => 'admin_one_click_update.php',
					'label' => $ocuLabel,
					'required_resources' => 'change_serversettings',
				],
			],
		],
	],
];
