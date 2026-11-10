#!/usr/bin/env bash
#
# Installs One-Click Update into an existing Froxlor 2.x installation.
#
#   ./install.sh /var/www/froxlor
#   ./install.sh /var/www/froxlor --dry-run          write nothing, only show
#
# Options (all optional; kept in /etc/froxlor-one-click-update/config, so a
# later run without options keeps them):
#   --method=auto|archive|apt   how Froxlor is updated (default auto: apt if
#                               Froxlor came as a Debian package, otherwise
#                               "froxlor-cli froxlor:update")
#   --snapshots=N               snapshots kept before updates (default 3, 0 = off)
#   --reinstall="DIR DIR …"     extension packages whose install.sh runs after
#                               every update (default on first install: the
#                               other froxlor-* packages next to this one)
#   --panel-group=NAME          group the panel's PHP runs as (default: read
#                               from Froxlor's settings)
#
# Two parts are installed:
#   - In the Froxlor directory, only new files (page, menu entry, template).
#     Written as the owner of that directory, never as root (see
#     scripts/privileges.sh). No Froxlor file is modified.
#   - Outside, as root: the service script /usr/local/sbin/froxlor-one-click-update,
#     its helper in /usr/local/lib/froxlor-one-click-update/, the systemd units
#     froxlor-one-click-update.path/.service, the config in
#     /etc/froxlor-one-click-update/ and the state directory
#     /var/lib/froxlor-one-click-update/.
# There are no database tables and no Froxlor settings. Run the script again
# after updating this package; Froxlor updates done through One-Click Update
# need nothing (the files are not touched by them).

set -euo pipefail
umask 022

NAME=froxlor-one-click-update
SBIN=/usr/local/sbin/$NAME
LIBDIR=/usr/local/lib/$NAME
CONFDIR=/etc/$NAME
CONFIG=$CONFDIR/config
STATE=/var/lib/$NAME
UNIT_DIR=/etc/systemd/system

TARGET="${1:-}"
shift || true

DRY_RUN=0
OPT_METHOD="" OPT_SNAPSHOTS="" OPT_REINSTALL="" OPT_REINSTALL_SET=0 OPT_GROUP=""
for opt in "$@"; do
	case "$opt" in
		--dry-run) DRY_RUN=1 ;;
		--method=*) OPT_METHOD="${opt#*=}" ;;
		--snapshots=*) OPT_SNAPSHOTS="${opt#*=}" ;;
		--reinstall=*) OPT_REINSTALL="${opt#*=}"; OPT_REINSTALL_SET=1 ;;
		--panel-group=*) OPT_GROUP="${opt#*=}" ;;
		*) echo "Unknown option: $opt" >&2; exit 1 ;;
	esac
done

if [[ -z "$TARGET" ]]; then
	cat >&2 <<'USAGE'
Usage: ./install.sh <froxlor-directory> [--dry-run] [--method=auto|archive|apt]
                    [--snapshots=N] [--reinstall="DIR …"] [--panel-group=NAME]

Example:
    ./install.sh /var/www/froxlor
USAGE
	exit 1
fi

case "$OPT_METHOD" in "" | auto | archive | apt) ;; *) echo "ERROR: --method must be auto, archive or apt." >&2; exit 1 ;; esac
if [[ -n "$OPT_SNAPSHOTS" && ! "$OPT_SNAPSHOTS" =~ ^[0-9]+$ ]]; then
	echo "ERROR: --snapshots must be a number." >&2
	exit 1
fi
if [[ "$OPT_REINSTALL" == *[\"\$\`\\]* ]]; then
	echo "ERROR: --reinstall must not contain quotes, \$, \` or backslashes." >&2
	exit 1
fi

TARGET="${TARGET%/}"
if [[ ! -f "$TARGET/lib/init.php" ]] || [[ ! -d "$TARGET/templates/Froxlor" ]] || [[ ! -f "$TARGET/bin/froxlor-cli" ]]; then
	echo "ERROR: '$TARGET' does not look like a Froxlor installation." >&2
	exit 1
fi
TARGET="$(cd "$TARGET" && pwd -P)"

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
VERSION="$(cat "$HERE/VERSION" 2>/dev/null || echo "unknown")"

if [[ $EUID -ne 0 && $DRY_RUN -eq 0 ]]; then
	echo "ERROR: run as root — the update service is installed outside the Froxlor directory." >&2
	exit 1
fi

# PHP 8.1 or newer: the extension uses PHP 8 features. Froxlor 2.3 itself
# still allows 7.4, so this is checked here and not left to a parse error.
if ! command -v php >/dev/null 2>&1; then
	echo "ERROR: php not found. One-Click Update requires PHP 8.1 or newer." >&2
	exit 1
fi
PHP_ID="$(php -r 'echo PHP_VERSION_ID;' 2>/dev/null || echo 0)"
if [[ ! "$PHP_ID" =~ ^[0-9]+$ ]] || ((PHP_ID < 80100)); then
	echo "ERROR: PHP 8.1 or newer is required (found: $(php -r 'echo PHP_VERSION;' 2>/dev/null || echo unknown))." >&2
	exit 1
fi
if ! php -r 'exit(extension_loaded("pdo_mysql") ? 0 : 1);'; then
	echo "ERROR: the PHP command line lacks pdo_mysql (needed to read Froxlor's version from its database)." >&2
	exit 1
fi
for tool in systemctl flock tar gzip setsid; do
	if ! command -v "$tool" >/dev/null 2>&1; then
		echo "ERROR: $tool not found. One-Click Update needs systemd and util-linux." >&2
		exit 1
	fi
done
if ! command -v mariadb-dump >/dev/null 2>&1 && ! command -v mysqldump >/dev/null 2>&1; then
	echo "WARNING: neither mariadb-dump nor mysqldump found. Updates will refuse to run"
	echo "         while snapshots are on — install mariadb-client (or default-mysql-client)."
fi

# Exactly these paths belong to the extension inside Froxlor. Listed one by
# one instead of a blanket sync: this way the script can never overwrite or
# delete anything that belongs to Froxlor.
FILES=(
	"admin_one_click_update.php"
	"lib/navigation/53.ext.oneclickupdate.php"
	"lib/Froxlor/Ext/OneClickUpdate/"
	"templates/OneClickUpdate/"
)
SYSTEM_FILES=(system/froxlor-one-click-update system/helper.php system/DbConfig.php system/compat.php scripts/privileges.sh
	system/froxlor-one-click-update.path system/froxlor-one-click-update.service)
for path in "${FILES[@]}" "${SYSTEM_FILES[@]}"; do
	if [[ ! -e "$HERE/${path%/}" ]] || [[ -L "$HERE/${path%/}" ]]; then
		echo "ERROR: $path is missing from the package." >&2
		exit 1
	fi
done

# shellcheck source=scripts/privileges.sh
. "$HERE/scripts/privileges.sh"

# as_owner CMD...: run CMD as the user that does the writing in the Froxlor
# directory (see header).
as_owner() { "$@"; }
if [[ $EUID -eq 0 ]]; then
	# Root runs this package: it must not live where someone else can change it.
	bad="$(untrusted_component "$HERE/install.sh" "${SUDO_UID:-}")"
	if [[ -n "$bad" ]]; then
		echo "ERROR: the package is not safe to run as root: $bad is a link or" >&2
		echo "owned/writable by another user. Clone it outside any web root (e.g. /opt)." >&2
		exit 1
	fi
	OWNER_UID="$(file_uid "$TARGET")"
	OWNER_GID="$(file_gid "$TARGET")"
	if [[ "$OWNER_UID" != "0" ]]; then
		as_owner() { drop_to "$OWNER_UID" "$OWNER_GID" "$@"; }
		echo "Writing as uid $OWNER_UID (owner of $TARGET)"
	else
		# Root-owned: root writes itself only if nobody else can write anywhere
		# on the way — every component from / down to each target's parent,
		# and every directory below an existing target directory.
		unsafe=""
		for path in "${FILES[@]}"; do
			parent="$(dirname "$TARGET/${path%/}")"
			[[ -e "$parent" ]] || parent="$TARGET"
			unsafe="$(untrusted_component "$parent")"
			[[ -n "$unsafe" ]] && break
			if [[ -d "$TARGET/${path%/}" && ! -L "$TARGET/${path%/}" ]]; then
				unsafe="$(find "$TARGET/${path%/}" \( -type l -o \( -type d \( ! -user 0 -o -perm -0002 -o \( -perm -0020 ! -group 0 \) \) \) \) -print -quit 2>/dev/null)"
				[[ -n "$unsafe" ]] && break
			fi
		done
		if [[ -n "$unsafe" ]]; then
			echo "ERROR: $TARGET belongs to root, but $unsafe" >&2
			echo "is a link or owned/writable by another user or group. Root does not write" >&2
			echo "where a less privileged user can plant links. Either give the whole Froxlor" >&2
			echo "directory to the panel's system user (the installer then writes as that" >&2
			echo "user), or make every directory on the way owned by root and not" >&2
			echo "group/world-writable." >&2
			exit 1
		fi
		echo "Writing as root ($TARGET is root-owned and not writable by others)"
	fi
fi

# A target whose path inside the Froxlor directory contains a symbolic link is
# refused: nothing in the package is a link, so any link there was put there
# by someone else. Checked for every target before anything is written, so a
# refusal leaves no half installation behind.
check_path() {
	local rel="${1%/}" current="$TARGET" part
	IFS='/' read -r -a parts <<<"$rel"
	for part in "${parts[@]}"; do
		current="$current/$part"
		if [[ -L "$current" ]]; then
			echo "ERROR: $current is a symbolic link — refusing to write through it." >&2
			exit 1
		fi
	done
	if [[ -d "$current" ]] && [[ -n "$(find "$current" -type l -print -quit)" ]]; then
		echo "ERROR: $current contains a symbolic link — refusing to write into it." >&2
		exit 1
	fi
}
for path in "${FILES[@]}"; do
	check_path "$path"
done

# --- Settings: existing config first, then command line, then defaults ----

conf_get() {
	local line value
	[[ -f "$CONFIG" ]] || return 0
	line="$(grep -E "^$1=" "$CONFIG" 2>/dev/null | tail -n 1)" || true
	[[ -n "$line" ]] || return 0
	value="${line#*=}"
	value="${value%\"}"
	value="${value#\"}"
	printf '%s' "$value"
}

METHOD="${OPT_METHOD:-$(conf_get METHOD)}"
METHOD="${METHOD:-auto}"
SNAPSHOTS="${OPT_SNAPSHOTS:-$(conf_get SNAPSHOTS)}"
SNAPSHOTS="${SNAPSHOTS:-3}"

if [[ $OPT_REINSTALL_SET -eq 1 ]]; then
	REINSTALL="$OPT_REINSTALL"
elif [[ -f "$CONFIG" ]] && grep -qE '^REINSTALL=' "$CONFIG"; then
	REINSTALL="$(conf_get REINSTALL)"
else
	# First install: the other extension packages next to this one (for
	# example /usr/local/src/froxlor-project-view next to /usr/local/src/froxlor-one-click-update).
	REINSTALL=""
	for dir in "$(dirname "$HERE")"/froxlor-*/; do
		dir="${dir%/}"
		[[ "$dir" == "$HERE" || -L "$dir" ]] && continue
		[[ -f "$dir/install.sh" && -f "$dir/VERSION" ]] || continue
		[[ "$dir" == *[[:space:]\"\$\`\\]* ]] && continue
		REINSTALL="${REINSTALL:+$REINSTALL }$dir"
	done
fi

PANEL_GROUP="$OPT_GROUP"
if [[ -z "$PANEL_GROUP" ]]; then
	PANEL_GROUP="$(php "$HERE/system/helper.php" panel-group "$TARGET" 2>/dev/null || true)"
fi
if [[ -z "$PANEL_GROUP" ]]; then
	echo "ERROR: could not read the panel's group from Froxlor's settings. Pass it with" >&2
	echo "       --panel-group=NAME (the group the panel's PHP runs as)." >&2
	exit 1
fi
if [[ "$PANEL_GROUP" =~ ^[0-9]+$ ]]; then
	PANEL_GID="$PANEL_GROUP"
else
	PANEL_GID="$(getent group "$PANEL_GROUP" | cut -d: -f3 || true)"
fi
if [[ ! "$PANEL_GID" =~ ^[0-9]+$ ]] || [[ "$PANEL_GID" == 0 ]]; then
	echo "ERROR: group '$PANEL_GROUP' does not exist on this system (or is root's group)." >&2
	exit 1
fi

echo "One-Click Update $VERSION"
echo "Target:      $TARGET"
echo "Panel group: $PANEL_GROUP (gid $PANEL_GID)"
echo "Method:      $METHOD"
echo "Snapshots:   $SNAPSHOTS"
echo "Reinstall:   ${REINSTALL:-(none)}"
[[ $DRY_RUN -eq 1 ]] && echo "(dry run, nothing is written)"
echo

# --- Files inside Froxlor --------------------------------------------------

# Copied with tar instead of rsync: tar is part of every base system, rsync is
# often missing on lean servers. The archive is read by the calling user and
# unpacked as the owner of the Froxlor directory, without taking over owners
# or modes from the repository.
echo "Files in Froxlor"
for path in "${FILES[@]}"; do
	if [[ $DRY_RUN -eq 1 ]]; then
		echo "  would copy: $path"
		continue
	fi
	(cd "$HERE" && tar cf - "${path%/}") |
		as_owner tar -C "$TARGET" --no-same-owner --no-same-permissions -xf -
	echo "  $path"
done

# --- The service (root) ----------------------------------------------------

echo
echo "Update service"
if [[ $DRY_RUN -eq 1 ]]; then
	echo "  would install: $SBIN, $LIBDIR/, $UNIT_DIR/$NAME.path, $UNIT_DIR/$NAME.service"
	echo "  would write:   $CONFIG (if missing), $STATE/"
	echo
	echo "Dry run finished."
	exit 0
fi

install -d -o 0 -g 0 -m 0755 "$LIBDIR"
install -o 0 -g 0 -m 0644 "$HERE/system/helper.php" "$LIBDIR/helper.php"
install -o 0 -g 0 -m 0644 "$HERE/system/DbConfig.php" "$LIBDIR/DbConfig.php"
install -o 0 -g 0 -m 0644 "$HERE/system/compat.php" "$LIBDIR/compat.php"
install -o 0 -g 0 -m 0644 "$HERE/scripts/privileges.sh" "$LIBDIR/privileges.sh"
tmp="$(mktemp)"
sed "s|@VERSION@|$VERSION|g" "$HERE/system/$NAME" >"$tmp"
install -o 0 -g 0 -m 0755 "$tmp" "$SBIN"
rm -f "$tmp"
echo "  $SBIN"
echo "  $LIBDIR/"

# The config is parsed (never sourced) by the service; values are plain.
install -d -o 0 -g 0 -m 0755 "$CONFDIR"
tmp="$(mktemp)"
cat >"$tmp" <<EOF
# One-Click Update for Froxlor — read by $SBIN.
# Changes take effect with the next update; run install.sh again to refresh
# what the panel shows.

# The Froxlor installation to update.
FROXLOR_DIR="$TARGET"

# Group the panel's PHP runs as (numeric). It may create the update request
# and read status and logs, nothing else.
PANEL_GID="$PANEL_GID"

# auto    = apt if Froxlor's files belong to the Debian package "froxlor",
#           otherwise archive
# archive = froxlor-cli froxlor:update -A (download from froxlor.org)
# apt     = apt-get install --only-upgrade froxlor, then the database update
METHOD="$METHOD"

# Snapshots (Froxlor directory + its database) kept in
# /var/backups/$NAME/. 0 = no snapshot before updates.
SNAPSHOTS="$SNAPSHOTS"

# Extension packages whose install.sh runs after every update (space-separated
# directories; they must be root-owned and not writable by others).
REINSTALL="$REINSTALL"
EOF
install -o 0 -g 0 -m 0644 "$tmp" "$CONFIG"
rm -f "$tmp"
echo "  $CONFIG"

install -d -o 0 -g 0 -m 0755 "$STATE"
install -d -o 0 -g "$PANEL_GID" -m 0770 "$STATE/request"
install -d -o 0 -g "$PANEL_GID" -m 0750 "$STATE/logs"
echo "  $STATE/"

for unit in path service; do
	sed "s|@STATE@|$STATE|g" "$HERE/system/$NAME.$unit" >"$UNIT_DIR/$NAME.$unit"
	chmod 0644 "$UNIT_DIR/$NAME.$unit"
done
systemctl daemon-reload
systemctl enable --now "$NAME.path" >/dev/null
echo "  $NAME.path active"

# What the panel shows about the service. The method is the one the service
# would use now.
detected="$METHOD"
if [[ "$METHOD" == auto ]]; then
	detected=archive
	if command -v dpkg-query >/dev/null 2>&1 && dpkg-query -S "$TARGET/lib/Froxlor/Froxlor.php" 2>/dev/null | grep -q '^froxlor:'; then
		detected=apt
	fi
fi
php "$LIBDIR/helper.php" status-set "$STATE/runner.json" "$PANEL_GID" --reset \
	version="$VERSION" method="$detected" snapshots="$SNAPSHOTS" reinstall="${REINSTALL:--}" \
	froxlor_dir="$TARGET" installed="$(date +%s)" >/dev/null
echo "  update method: $detected"

# Twig caches compiled templates; without clearing, a change would run
# against the old compiled version. find does not follow symbolic links.
if [[ -d "$TARGET/cache" && ! -L "$TARGET/cache" ]]; then
	if as_owner find "$TARGET/cache" -mindepth 1 -maxdepth 1 -type d -exec rm -rf {} + </dev/null 2>/dev/null; then
		echo "  Twig cache cleared"
	else
		echo "  WARNING: could not clear $TARGET/cache completely — clear it by hand."
	fi
fi

echo
echo "Done. Admins with the right to change server settings find the page under"
echo "Server > Froxlor update."
if [[ "$detected" == apt ]]; then
	echo
	echo "Froxlor's files belong to the Debian package 'froxlor', so updates run through"
	echo "apt. Pass --method=archive to use 'froxlor-cli froxlor:update' instead."
fi
