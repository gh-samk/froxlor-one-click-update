<?php

/**
 * One-Click Update for Froxlor 2.x: the admin page.
 *
 * Shows whether a newer Froxlor version exists and starts the update with
 * one click. The update itself runs as root in a separate service (see
 * system/froxlor-one-click-update); this page only requests it and shows its
 * progress.
 *
 * This file is purely additive — Froxlor does not know it and therefore does
 * not overwrite it during an update.
 */

const AREA = 'admin';
require __DIR__ . '/lib/init.php';

use Froxlor\Ext\OneClickUpdate\Page;
use Froxlor\UI\Response;

// Same requirement as Froxlor's own update pages.
if ((int)($userinfo['change_serversettings'] ?? 0) !== 1) {
	Response::redirectTo('admin_index.php');
}

Page::run($userinfo, $filename);
