# Changelog

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
