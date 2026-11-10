# One-Click Update for Froxlor 2.x

Update [Froxlor](https://froxlor.org) from the panel: when a new version is
out, one click on **Update now** — with a snapshot of Froxlor's files and
database taken first, live progress on the page, and a one-line way back.

**Version 1.0.0** · Froxlor 2.3.x (tested with a real update from 2.3.13 to
2.3.17) · PHP 8.1 or newer · systemd · GPL-2.0-or-later

Froxlor tells admins when a new version exists and suggests running
`bin/froxlor-cli froxlor:update` in a root shell. Its own web updater is off
by default (`enable_webupdate`) and runs as the panel user. One-Click Update
keeps the root shell out of the routine: the panel page leaves a request, a
small root service runs exactly the steps an admin would type, and the page
shows what happens.

Admins with the right to change server settings find the page under
**Server › Froxlor update**; the menu entry carries a **new** badge as soon
as Froxlor's own version check knows of a newer release.

## Contents

* [How it works](#how-it-works)
* [Requirements](#requirements)
* [Installation](#installation)
* [Configuration](#configuration)
* [Update and uninstall](#update-and-uninstall)
* [Going back to a snapshot](#going-back-to-a-snapshot)
* [Debian package installations](#debian-package-installations)
* [Languages](#languages)
* [Security model](#security-model)
* [Design decisions](#design-decisions)
* [Tests](#tests)
* [Layout](#layout)

## How it works

```
 panel (PHP, panel user)                       root
 ───────────────────────                       ────
 admin_one_click_update.php
   "Update now" ──► /var/lib/froxlor-one-click-update/request/trigger
                         │
                         ▼  systemd: froxlor-one-click-update.path (PathExists)
                    froxlor-one-click-update.service
                         │  /usr/local/sbin/froxlor-one-click-update run
                         ▼
   status.json, logs/ ◄── 1 check     froxlor:update --check-only
   (read by the page,     2 wait      no Froxlor cron job running / starting
    refreshed every 3 s)  3 snapshot  Froxlor dir + Froxlor database
                          4 update    froxlor:update -A   (or apt, see below)
                          5 verify    files and database at the same, new version
                          6 extensions  re-run install.sh of listed extensions,
                                        clear the Twig cache
                          7 prune     keep the newest N snapshots, 20 logs
```

1. **Check.** `froxlor-cli froxlor:update --check-only` — Froxlor's own
   version check against froxlor.org, with the update channel from Froxlor's
   settings. Nothing newer: the run ends and the page keeps showing the
   report of the last real update. With the apt method the package source is
   asked here too, before any snapshot. Then the free space is checked
   (twice the Froxlor directory plus 100 MB next to it, the directory's size
   for the snapshot).
2. **Wait.** Froxlor's cron runs every five minutes and first checks that
   files and database have the same version. For the few seconds between
   unpacking and the database update they do not. The service waits until no
   Froxlor cron job holds a lock (`/run/lock/froxlor_*.lock` with a live
   process) and the next five-minute mark is at least 45 seconds away.
   After 15 minutes of a running cron job it gives up without changing
   anything. The same check runs again right before the update, after the
   snapshot.
3. **Snapshot** to `/var/backups/froxlor-one-click-update/<time>-<version>/`:
   `files.tar.gz` (the Froxlor directory without `cache/` and `updates/`) and
   `database.sql.gz` (`mariadb-dump`/`mysqldump` of Froxlor's database only;
   customer databases are not affected by a Froxlor update). If the snapshot
   fails, the update does not start.
4. **Update.** `froxlor-cli froxlor:update -A`: download from froxlor.org,
   SHA-256 check, unpack, PHP-FPM reload for the panel, database update — the
   command Froxlor itself recommends. For Froxlor installed from the Debian
   package see [below](#debian-package-installations).
5. **Verify.** The version in `lib/Froxlor/Froxlor.php` and the version
   recorded in the database must match and differ from the starting version
   (if only the database was behind, matching is enough). If the update
   command failed and the version did not change, the page says that files
   may be partly replaced — Froxlor's unpacking does not report partial
   failures — and points to the snapshot.
6. **Extensions.** Every package listed in `REINSTALL` gets its `install.sh`
   run again (as other Froxlor extensions ask for after an update), then the
   Twig cache is cleared. A failing installer does not undo the update; the
   page reports which one to run by hand.
7. **Prune.** The newest `SNAPSHOTS` snapshots and 20 logs are kept.

While the update runs, the page shows the steps and the live log, and
reloads itself once the run is over. Closing the page does not matter.

**Nothing in Froxlor is patched.** Inside the Froxlor directory the extension
consists of new files only:

| What | How | Where in Froxlor |
|---|---|---|
| Page | `admin_one_click_update.php` | new file in the Froxlor root |
| Menu entry | all `*.php` in `lib/navigation/` are merged; the entry is appended to Froxlor's "Server" menu | `lib/init.php` → `PhpHelper::loadConfigArrayDir` |
| Classes | composer PSR-4: `Froxlor\` → `lib/Froxlor/` | `composer.json` |
| Template | the Twig loader is rooted in `templates/` | `lib/Froxlor/UI/Panel/UI.php` |
| Version check | `AutoUpdate::checkVersion()`, result stored in Froxlor's own update-check cache, so the version note in the top bar agrees | `lib/Froxlor/Install/AutoUpdate.php` |

Because Froxlor's update only overwrites and adds its own files, the
extension's files survive the update it performs.

## Requirements

* Froxlor 2.3.x, installed from the release archive or the Debian package.
  No version is enforced.
* PHP 8.1 or newer on the command line and for the panel, with `pdo_mysql`
  on the command line.
* systemd, util-linux (`flock`, `setpriv`, `setsid`), GNU tar, gzip.
* `mariadb-dump` or `mysqldump` (package `mariadb-client` or
  `default-mysql-client`) for snapshots; `mariadb`/`mysql` for a restore.
* Internet access from the server to `version.froxlor.org` and
  `autoupdate.froxlor.org` (or to your apt mirror).

## Installation

Clone the repository **outside any web root** (for example to `/usr/local/src`),
then run one command as root:

```bash
git clone https://github.com/gh-samk/froxlor-one-click-update.git /usr/local/src/froxlor-one-click-update
/usr/local/src/froxlor-one-click-update/install.sh /var/www/froxlor
```

The installer

* copies the page, menu entry, classes and template into Froxlor — as the
  owner of the Froxlor directory, never as root;
* installs the service script to `/usr/local/sbin/froxlor-one-click-update`,
  its helper to `/usr/local/lib/froxlor-one-click-update/`, and the units
  `froxlor-one-click-update.path` / `.service`, and enables the path unit;
* writes `/etc/froxlor-one-click-update/config` and creates
  `/var/lib/froxlor-one-click-update/` (see [Security model](#security-model)
  for the permissions);
* reads the group the panel's PHP runs as from Froxlor's settings (as
  Froxlor's own cron does for file ownership).

Only look at what would happen:

```bash
./install.sh /var/www/froxlor --dry-run
```

## Configuration

`/etc/froxlor-one-click-update/config` (parsed, not sourced; installer
options in brackets — a later `install.sh` run without options keeps the
values):

| Setting | Default | Meaning |
|---|---|---|
| `FROXLOR_DIR` | the installer's argument | the installation to update |
| `PANEL_GID` | from Froxlor's settings (`--panel-group=NAME`) | group of the panel's PHP: may create the request, read status and logs |
| `METHOD` | `auto` (`--method=`) | `archive` = `froxlor-cli froxlor:update -A`; `apt` = Debian package; `auto` = `apt` if Froxlor's files belong to the package `froxlor`, else `archive` |
| `SNAPSHOTS` | `3` (`--snapshots=N`) | snapshots kept; `0` = no snapshot before updates |
| `REINSTALL` | the other `froxlor-*` packages next to this one on first install (`--reinstall="DIR …"`) | extension packages whose `install.sh` runs after every update; each must be root-owned and not writable by others |

On a server with the extensions in `/usr/local/src/froxlor-project-view`,
`/usr/local/src/froxlor-agency-theme`, … the first `install.sh` run lists them
in `REINSTALL` by itself.

## Update and uninstall

Update this package:

```bash
cd /usr/local/src/froxlor-one-click-update && git pull && ./install.sh /var/www/froxlor
```

Remove it:

```bash
./uninstall.sh /var/www/froxlor            # files and service; config, logs and snapshots stay
./uninstall.sh /var/www/froxlor --purge    # everything
```

## Going back to a snapshot

On the server, as root:

```bash
froxlor-one-click-update snapshots
froxlor-one-click-update restore 20261110-103222-2.3.16
```

`restore` asks for the snapshot name as confirmation, then

1. unpacks the files next to the Froxlor directory,
2. imports the database dump into Froxlor's database (customer databases are
   not touched),
3. swaps the directories: the current one stays as
   `/var/www/froxlor.replaced-<time>` until you remove it.

If the import fails, the files are left as they were. `froxlor-one-click-update
status` prints the last run.

## Debian package installations

If Froxlor came from the Debian package (`deb.froxlor.org`), its files belong
to dpkg. `froxlor:update` would overwrite them with the release archive while
dpkg still records the old version — and a later `apt upgrade` installs the
package's files again, which can be **older** than what the archive brought
(for example when the archive is already at a version the package repository
has not published yet). Files and database would then disagree.

So `METHOD=auto` updates such installations through apt:
`apt-get update`, `apt-get install --only-upgrade froxlor` (non-interactive,
keeping changed config files), then `froxlor-cli froxlor:update --database
-A`. If the package source has no newer package yet, the run ends without
changes and the page says so. To use the archive anyway, install with
`--method=archive`.

## Languages

The page is English and German: German for users whose language is German,
English for everybody else (the same rule as the other extensions). Texts
live in `lib/Froxlor/Ext/OneClickUpdate/lang/de.php`.

## Security model

The panel's PHP user is less trusted than root. The design keeps everything
it controls away from decisions root makes:

* **The panel only creates a file.** `request/` is the one place it may
  write (`root:<panel group>`, mode 0770). The service never reads anything
  in there; it only unlinks `request/trigger` — as its very first step, so a
  run that cannot start does not make systemd restart it over and over. A
  link planted as trigger is removed, not followed. What the service does is
  fixed by its own root-owned config, not by the request. A request that is
  not picked up within two minutes is shown as stuck, with the command to
  check the units.
* **Status and logs are root-owned** (`status.json`, `runner.json`, `logs/`,
  mode 0640, group = panel group): the panel can read them, not change them.
  Log file names from `status.json` are only opened if they match the
  service's own pattern.
* **The service script, its helper and the config live outside the Froxlor
  directory**, root-owned. The config is parsed, never sourced, and refused
  if it or a directory above it is writable by someone else.
* **Froxlor's `lib/userdata.inc.php` is parsed, never executed** by the
  helper. The file is opened first and checked through the open handle
  (regular file, same inode as the path), so swapping in a link to a
  root-only file is caught; parse errors name the token type and line, never
  its text, because they end up in a log the panel can read. The database
  password goes to `mariadb-dump` through a mode-0600 options file in a
  root-only directory, never on a command line; values with line breaks are
  refused, so they cannot add options (such as a client plugin) to that file.
* **The run lock** is `/var/lib/froxlor-one-click-update/run.lock`, in the
  root-owned state directory — not in the world-writable `/run/lock`, where
  any local user could create and hold it.
* **What runs as root is what Froxlor already runs as root.** The update
  itself executes `bin/froxlor-cli` as root, which loads PHP code from the
  Froxlor directory — writable by the panel user. That is the same trust
  Froxlor's own cron (`froxlor-cli froxlor:cron` as root, every five
  minutes) and the manual `froxlor:update` already require; One-Click Update
  adds no new way for the panel user to influence root beyond "run the update
  now".
* **Fake Froxlor lock files** in the world-writable `/run/lock` can only make
  the service wait: only regular files are read, at most 512 bytes, with a
  timeout.
* **Snapshots** are root-only (`/var/backups/froxlor-one-click-update/`,
  mode 0700): they contain Froxlor's database including password hashes.
* **The page** requires `change_serversettings`, like Froxlor's own update
  pages. The start button is a POST with Froxlor's CSRF token; all output is
  escaped and inside `v-pre` (Froxlor mounts Vue on the whole page), so a
  `{{ … }}` in a log line stays text. The request is logged in Froxlor's
  system log.
* **Extension installers** listed in `REINSTALL` run as root only if they and
  every directory above them are root-owned and not writable by others.
* **`restore`** refuses to work if the directory above Froxlor is writable by
  anyone but root (it unpacks and renames there), and waits for Froxlor's
  cron like an update.

## Design decisions

* **A systemd path unit instead of Froxlor's cron.** The cron runs every five
  minutes and inside Froxlor's own process; an update started from there
  would replace the code of the process that runs it. A separate oneshot
  service starts within a second and runs in a process of its own.
* **Snapshot before every update, on by default.** A Froxlor update changes
  files and database schema together; going back needs both. A snapshot
  takes about 5 MB plus the compressed Froxlor database.
* **apt for Debian package installations** (see above), the archive for
  everything else.
* **No automatic updates.** The page makes updating a decision of one click,
  not a schedule. A cron line calling `froxlor-one-click-update run` would do
  it, but is deliberately not set up.
* **No rollback button in the panel.** Restoring replaces the directory the
  panel itself runs from; that belongs in a root shell, with a confirmation.
* **Only Bootstrap classes**, no stylesheet: the page follows the light/dark
  variant of every theme.

## Tests

Without Froxlor:

```bash
php tests/lang.php        # every text has a German entry, placeholders match
php tests/dbconfig.php    # userdata.inc.php parser: no execution, no content in errors, no links, no option injection
```

With an unpacked Froxlor (only its `vendor/` is used, for Twig):

```bash
php tests/templates.php /path/to/froxlor
```

renders the page in both languages for every state (update available,
service missing, up to date, check failed, running, requested, failed,
extensions failed) with `strict_variables`, and checks escaping, the CSRF
field and the live-refresh script.

End-to-end, **on a disposable system only** — it really updates Froxlor and
rolls it back: install an older Froxlor 2.3.x (for example the release archive
of an older version, installed with `bin/froxlor-cli froxlor:install`) in a
container or VM, install this extension, then as root

```bash
OCU_DISPOSABLE=1 tests/service.sh /var/www/froxlor
```

It checks the failure paths that must leave Froxlor untouched (no dump tool,
a running cron job, fake lock files, apt without a newer package), the
single-run lock, a broken config (request consumed, reason reported), the
interrupted-run marker, that a linked trigger is not followed, a real update
with snapshot, a run with nothing to do, a database that is behind its
files, and the restore. The systemd units themselves can be checked with
`systemd-analyze verify` and by creating `request/trigger` as the panel user.

## Layout

```
admin_one_click_update.php                 the page
lib/navigation/53.ext.oneclickupdate.php   menu entry (Server › Froxlor update)
lib/Froxlor/Ext/OneClickUpdate/
    Page.php                               request handling, status for display
    State.php                              status files and the update request
    VersionCheck.php                       Froxlor's version check, cached
    Html.php                               safe error messages for Froxlor's error page
    Lang.php, lang/de.php                  English/German texts
templates/OneClickUpdate/update.html.twig  the page
system/
    froxlor-one-click-update               the root service script (run/status/snapshots/restore)
    helper.php, DbConfig.php               versions, status files, database options (root side)
    froxlor-one-click-update.path/.service systemd units
scripts/privileges.sh                      privilege helpers (installer and service)
tests/                                     see above
```

## License

GPL-2.0-or-later, like Froxlor. See `LICENSE`.

Copyright © 2026 Samuel Kümmel
