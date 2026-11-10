# Changelog

## 1.1.0

- Extension compatibility check after every update: the service loads the
  menu (as admin and customer), the settings, the extensions' table lists
  and classes, scans the extensions' PHP files for the Froxlor classes,
  methods, constants and functions they use, and compiles every Twig
  template, including the templates and macros they refer to. It runs
  before the update too, so only new problems are reported. The page lists
  them, with the snapshot to go back to; nothing is rolled back
  automatically. The check runs as the owner of the Froxlor directory.
- `froxlor-one-click-update check` runs the check on demand.
- The last run shows whether the extensions were checked.
- Fixed: a status text with invalid UTF-8 (for example a cut-off path)
  emptied `status.json`; such bytes are now replaced.

## 1.0.0

Initial public release.

- Page Server › Froxlor update: installed and available version (Froxlor's
  own version check and channel), release notes link, **Update now**.
- Root service started by a systemd path unit: version check, wait for
  Froxlor's cron, snapshot of Froxlor's files and database, update
  (`froxlor-cli froxlor:update -A`, or apt for Debian package
  installations), verification, re-run of listed extension installers.
- Live progress and log on the page; report of the last run with the
  snapshot and the command to go back.
- `froxlor-one-click-update status|snapshots|restore` for the root shell.
- English and German user interface.
