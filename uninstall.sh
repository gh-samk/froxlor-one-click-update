#!/usr/bin/env bash
#
# Removes One-Click Update.
#
#   ./uninstall.sh /var/www/froxlor            remove files and the service
#   ./uninstall.sh /var/www/froxlor --purge    also remove config, logs and snapshots
#
# Without --purge, /etc/froxlor-one-click-update/, the logs in
# /var/lib/froxlor-one-click-update/ and the snapshots in
# /var/backups/froxlor-one-click-update/ stay, so a reinstall picks up where
# it left off and a snapshot can still be restored by hand (see README).
#
# Run as root. Files inside the Froxlor directory are deleted as the owner of
# that directory (see install.sh and scripts/privileges.sh).

set -euo pipefail
umask 022

NAME=froxlor-one-click-update
TARGET="${1:-}"
PURGE=0
[[ "${2:-}" == "--purge" ]] && PURGE=1

if [[ -z "$TARGET" ]] || [[ ! -f "$TARGET/lib/init.php" ]]; then
	echo "Usage: ./uninstall.sh <froxlor-directory> [--purge]" >&2
	exit 1
fi
if [[ $EUID -ne 0 ]]; then
	echo "ERROR: run as root." >&2
	exit 1
fi
TARGET="$(cd "${TARGET%/}" && pwd -P)"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"

FILES=(
	"admin_one_click_update.php"
	"lib/navigation/53.ext.oneclickupdate.php"
	"lib/Froxlor/Ext/OneClickUpdate"
	"templates/OneClickUpdate"
)

# shellcheck source=scripts/privileges.sh
. "$HERE/scripts/privileges.sh"

bad="$(untrusted_component "$HERE/uninstall.sh" "${SUDO_UID:-}")"
if [[ -n "$bad" ]]; then
	echo "ERROR: the package is not safe to run as root: $bad is a link or" >&2
	echo "owned/writable by another user. Clone it outside any web root (e.g. /opt)." >&2
	exit 1
fi

# The service first: no update may start while the files go away. A running
# update is waited for (systemctl stop waits for the oneshot to finish).
echo "Stopping the update service"
if command -v systemctl >/dev/null 2>&1; then
	systemctl disable --now "$NAME.path" >/dev/null 2>&1 || true
	systemctl stop "$NAME.service" >/dev/null 2>&1 || true
	rm -f "/etc/systemd/system/$NAME.path" "/etc/systemd/system/$NAME.service"
	systemctl daemon-reload
	systemctl reset-failed "$NAME.service" >/dev/null 2>&1 || true
fi
rm -f "/usr/local/sbin/$NAME"
rm -rf "/usr/local/lib/$NAME"
# The request directory is writable by the panel: remove it, but never
# follow anything in it (rm -rf does not follow links).
rm -rf "/var/lib/$NAME/request" "/var/lib/$NAME/runner.json"

as_owner() { "$@"; }
OWNER_UID="$(file_uid "$TARGET")"
OWNER_GID="$(file_gid "$TARGET")"
if [[ "$OWNER_UID" != "0" ]]; then
	as_owner() { drop_to "$OWNER_UID" "$OWNER_GID" "$@"; }
else
	# Root deletes itself only where nobody else can plant links.
	for path in "${FILES[@]}"; do
		unsafe="$(untrusted_component "$(dirname "$TARGET/$path")")"
		if [[ -n "$unsafe" && -e "$TARGET/$path" ]]; then
			echo "ERROR: $TARGET belongs to root, but $unsafe is a link or owned/writable" >&2
			echo "by another user or group. Remove the files by hand." >&2
			exit 1
		fi
	done
fi

# A symbolic link among the parent directories would redirect rm -rf
# elsewhere. The last component itself may be a link: rm removes the link,
# not its target.
parents_safe() {
	local rel="$1" current="$TARGET" part
	IFS='/' read -r -a parts <<<"$(dirname "$rel")"
	for part in "${parts[@]}"; do
		[[ "$part" == "." ]] && continue
		current="$current/$part"
		[[ -L "$current" ]] && return 1
	done
	return 0
}

echo "Removing files from Froxlor"
for path in "${FILES[@]}"; do
	if [[ -e "$TARGET/$path" || -L "$TARGET/$path" ]]; then
		if ! parents_safe "$path"; then
			echo "  SKIPPED (symbolic link in the path): $path" >&2
			continue
		fi
		as_owner rm -rf -- "${TARGET:?}/$path" </dev/null
		echo "  removed: $path"
	fi
done
# lib/Froxlor/Ext/ is shared with other extensions: only remove it when empty.
if [[ -d "$TARGET/lib/Froxlor/Ext" && ! -L "$TARGET/lib/Froxlor/Ext" ]] &&
	[[ -z "$(ls -A "$TARGET/lib/Froxlor/Ext" 2>/dev/null)" ]]; then
	as_owner rmdir "$TARGET/lib/Froxlor/Ext" </dev/null >/dev/null 2>&1 || true
fi

if [[ -d "$TARGET/cache" && ! -L "$TARGET/cache" ]]; then
	as_owner find "$TARGET/cache" -mindepth 1 -maxdepth 1 -type d -exec rm -rf {} + </dev/null 2>/dev/null || true
fi

if [[ $PURGE -eq 1 ]]; then
	echo "Removing config, logs and snapshots"
	rm -rf "/etc/${NAME:?}" "/var/lib/${NAME:?}" "/var/backups/${NAME:?}"
fi

echo
echo "Done."
if [[ $PURGE -eq 0 ]]; then
	echo "Kept: /etc/$NAME/, /var/lib/$NAME/logs/ and the snapshots in /var/backups/$NAME/"
	echo "(remove them with --purge)."
fi
