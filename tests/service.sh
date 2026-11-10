#!/usr/bin/env bash
#
# End-to-end test of the update service on a DISPOSABLE Froxlor installation.
#
#   OCU_DISPOSABLE=1 tests/service.sh /var/www/froxlor
#
# Requirements: run as root on a throwaway machine or container where an
# older Froxlor 2.3.x is installed (for example the official archive of an
# older release unpacked to /var/www/froxlor and installed with
# "bin/froxlor-cli froxlor:install <file>"), One-Click Update installed with
# install.sh, internet access to froxlor.org, and no systemd needed (the
# test calls the service script directly, as the path unit would).
#
# What it does — it REALLY updates Froxlor and rolls it back again:
#   - failure paths that must leave Froxlor untouched (snapshot impossible,
#     cron job running, fake lock files, apt without a newer package)
#   - concurrency (a second run while one is running) and the interrupted-run
#     marker (ExecStopPost)
#   - the request file is only removed, never followed
#   - a real update to the newest version, then a run with nothing to do
#   - restore of the snapshot (back to the starting version)

set -uo pipefail

FROXLOR="${1:-}"
if [[ "${OCU_DISPOSABLE:-}" != 1 || -z "$FROXLOR" || $EUID -ne 0 ]]; then
	echo "Usage (as root, on a disposable system only): OCU_DISPOSABLE=1 $0 <froxlor-dir>" >&2
	exit 2
fi

NAME=froxlor-one-click-update
STATE=/var/lib/$NAME
CONF=/etc/$NAME/config
TOOL=/usr/local/sbin/$NAME
failures=0
check() {
	if eval "$2"; then
		printf '  OK      %s\n' "$1"
	else
		printf '  FAILED  %s\n' "$1"
		failures=$((failures + 1))
	fi
}
field() { php -r '$d = json_decode((string)@file_get_contents($argv[1]), true); echo is_array($d) ? ($d[$argv[2]] ?? "") : "";' "$STATE/status.json" "$1"; }
version() { grep -oP "const VERSION = '\K[^']+" "$FROXLOR/lib/Froxlor/Froxlor.php"; }
db_version() { php "/usr/local/lib/$NAME/helper.php" state "$FROXLOR" | php -r 'echo json_decode(stream_get_contents(STDIN), true)["db_version"];'; }
run() { OCU_NO_CRON_WINDOW=1 "$TOOL" run >/dev/null 2>&1; }

START="$(version)"
cp -p "$CONF" /tmp/ocu-config.bak
echo "Froxlor $START in $FROXLOR"

echo "Snapshot impossible (no dump tool) -> nothing changes"
snapshots_before="$(find /var/backups/$NAME -mindepth 1 -maxdepth 1 -type d 2>/dev/null | wc -l)"
hidden=()
for tool in mariadb-dump mysqldump; do
	path="$(command -v "$tool" || true)"
	if [[ -n "$path" ]]; then
		chmod -x "$path"
		hidden+=("$path")
	fi
done
run
check "result snapshot_failed" '[[ "$(field code)" == snapshot_failed ]]'
check "version unchanged" '[[ "$(version)" == "$START" ]]'
check "no half snapshot left" '[[ "$(find /var/backups/$NAME -mindepth 1 -maxdepth 1 -type d 2>/dev/null | wc -l)" == "$snapshots_before" ]]'
for path in "${hidden[@]}"; do chmod +x "$path"; done

echo "Froxlor cron job running -> waits, then gives up, nothing changes"
sleep 300 &
sleeper=$!
printf '{"job":"tasks","startts":%s,"pid":%s}' "$(date +%s)" "$sleeper" >/run/lock/froxlor_tasks.lock
OCU_CRON_WAIT=10 OCU_NO_CRON_WINDOW=1 "$TOOL" run >/dev/null 2>&1
check "result cron_busy" '[[ "$(field code)" == cron_busy ]]'
check "version unchanged" '[[ "$(version)" == "$START" ]]'
kill "$sleeper" 2>/dev/null
rm -f /run/lock/froxlor_tasks.lock

echo "Fake lock files (FIFO, link, dead pid) do not block"
mkfifo /run/lock/froxlor_fifo.lock
ln -s /etc/shadow /run/lock/froxlor_link.lock
printf '{"pid":999999}' >/run/lock/froxlor_dead.lock
sed -i 's/^SNAPSHOTS=.*/SNAPSHOTS="0"/' "$CONF"
sed -i 's/^METHOD=.*/METHOD="apt"/' "$CONF"
# apt that knows no newer froxlor package (stubs first in PATH).
mkdir -p /tmp/ocu-stubs
printf '#!/bin/sh\nexit 0\n' >/tmp/ocu-stubs/apt-get
# Like apt, the stub answers in German unless LC_ALL=C is set.
printf '#!/bin/sh\nif [ "$LC_ALL" = C ]; then echo "froxlor:\n  Installed: 1.0\n  Candidate: 1.0"; else echo "froxlor:\n  Installiert: 1.0\n  Installationskandidat: 1.0"; fi\n' >/tmp/ocu-stubs/apt-cache
printf '#!/bin/sh\necho 1.0\n' >/tmp/ocu-stubs/dpkg-query
chmod +x /tmp/ocu-stubs/*
for stub in apt-get apt-cache dpkg-query; do
	[[ -e "/usr/local/bin/$stub" ]] || ln -s "/tmp/ocu-stubs/$stub" "/usr/local/bin/$stub"
done
timeout 60 env OCU_CRON_WAIT=10 OCU_NO_CRON_WINDOW=1 "$TOOL" run >/dev/null 2>&1
check "not blocked by fake locks, apt path: apt_no_candidate" '[[ "$(field code)" == apt_no_candidate ]]'
check "SNAPSHOTS=0 leaves existing snapshots alone" '[[ "$(find /var/backups/$NAME -mindepth 1 -maxdepth 1 -type d 2>/dev/null | wc -l)" == "$snapshots_before" ]]'
check "apt answer read in any language (candidate found)" 'grep -q "installed 1.0, available 1.0" "$STATE/logs/$(field log)"'
check "version unchanged" '[[ "$(version)" == "$START" ]]'
for stub in apt-get apt-cache dpkg-query; do
	[[ "$(readlink "/usr/local/bin/$stub" 2>/dev/null)" == "/tmp/ocu-stubs/$stub" ]] && rm -f "/usr/local/bin/$stub"
done
rm -rf /tmp/ocu-stubs /run/lock/froxlor_fifo.lock /run/lock/froxlor_link.lock /run/lock/froxlor_dead.lock
cp -p /tmp/ocu-config.bak "$CONF"

echo "Second run while one is running"
exec 8>"$STATE/run.lock"
flock -n 8
# shellcheck disable=SC2034 # read by check (eval)
out="$("$TOOL" run 2>&1)"
check "refused: another run" '[[ "$out" == *"Another run is in progress"* ]]'
exec 8>&-

echo "Broken config: request consumed, reason reported"
mv "$CONF" /tmp/ocu-config.moved
touch "$STATE/request/trigger"
run
check "trigger removed although the run could not start" '[[ ! -e "$STATE/request/trigger" ]]'
check "result setup_failed, readable for the panel" '[[ "$(field code)" == setup_failed && "$(stat -c %g "$STATE/status.json")" == "$(stat -c %g "$STATE/request")" ]]'
mv /tmp/ocu-config.moved "$CONF"

echo "Interrupted run is marked (ExecStopPost)"
php "/usr/local/lib/$NAME/helper.php" status-set "$STATE/status.json" -1 state=running phase=update >/dev/null
SERVICE_RESULT=timeout "$TOOL" service-stopped
check "state failed, code interrupted" '[[ "$(field state)" == failed && "$(field code)" == interrupted && "$(field detail)" == timeout ]]'

echo "The request file is removed, never followed"
touch /tmp/ocu-victim
ln -sf /tmp/ocu-victim "$STATE/request/trigger"
run
check "trigger removed" '[[ ! -e "$STATE/request/trigger" && ! -L "$STATE/request/trigger" ]]'
check "link target untouched" '[[ -f /tmp/ocu-victim ]]'
rm -f /tmp/ocu-victim

echo "Real update"
check "updated" '[[ "$(field code)" == updated || "$(field code)" == extensions_failed ]]'
NEW="$(version)"
check "files newer than $START ($NEW)" '[[ "$NEW" != "$START" ]]'
check "database at $NEW" '[[ "$(db_version)" == "$NEW" ]]'
SNAP="$(field snapshot)"
check "snapshot $SNAP with files and database" '[[ -s /var/backups/$NAME/$SNAP/files.tar.gz && -s /var/backups/$NAME/$SNAP/database.sql.gz ]]'
check "snapshot directory root-only" '[[ "$(stat -c %a /var/backups/$NAME/$SNAP)" == 700 ]]'
check "log readable by the panel group only" '[[ "$(stat -c %a "$STATE/logs/$(field log)")" == 640 ]]'
check "no client options file left" '[[ -z "$(find /var/backups/$NAME -name ".client-*")" ]]'

echo "Nothing to do"
run
check "previous report kept, check time noted" '[[ "$(field code)" == updated || "$(field code)" == extensions_failed ]] && [[ -n "$(field checked)" ]]'

echo "Only the database is behind"
cnf="$(mktemp)"
db="$(php "/usr/local/lib/$NAME/helper.php" client-options "$FROXLOR" "$cnf")"
"$(command -v mariadb || command -v mysql)" --defaults-extra-file="$cnf" -e "UPDATE panel_settings SET value = '$START' WHERE settinggroup = 'panel' AND varname = 'version'" "$db"
rm -f "$cnf"
run
check "result db_updated" '[[ "$(field code)" == db_updated ]]'
check "database at $NEW again" '[[ "$(db_version)" == "$NEW" ]]'

echo "Restore"
OCU_NO_CRON_WINDOW=1 "$TOOL" restore "$SNAP" --yes >/dev/null
check "files back at $START" '[[ "$(version)" == "$START" ]]'
check "database back at $START" '[[ "$(db_version)" == "$START" ]]'
check "status says restored" '[[ "$(field code)" == restored ]]'
check "replaced directory kept" '[[ -n "$(ls -d "$FROXLOR".replaced-* 2>/dev/null)" ]]'

echo
if ((failures == 0)); then
	echo "All checks passed."
else
	echo "$failures check(s) failed."
fi
exit $((failures > 0))
