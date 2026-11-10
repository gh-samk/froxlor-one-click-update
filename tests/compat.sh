#!/usr/bin/env bash
#
# Test of the compatibility check (system/compat.php) on a DISPOSABLE Froxlor
# installation with One-Click Update installed.
#
#   OCU_DISPOSABLE=1 tests/compat.sh /var/www/froxlor
#
# 1. The installed extensions pass (or at least: the check runs).
# 2. A planted, deliberately broken extension "OcuBroken" is found: broken
#    menu file, missing parent class, missing static method / class /
#    constant / function / callback, a missing parent template, an unknown
#    Twig filter, a missing macro — while guarded uses are not reported.
# 3. A fatal PHP error (incompatible method signature) stops the check with
#    a line that names the class.
# 4. "froxlor-one-click-update check" reports the result.
# The planted files are removed again.

# Variables like out and rc are read by check (eval).
# shellcheck disable=SC2034
set -uo pipefail

FROXLOR="${1:-}"
if [[ "${OCU_DISPOSABLE:-}" != 1 || -z "$FROXLOR" || $EUID -ne 0 ]]; then
	echo "Usage (as root, on a disposable system only): OCU_DISPOSABLE=1 $0 <froxlor-dir>" >&2
	exit 2
fi

NAME=froxlor-one-click-update
LIB=/usr/local/lib/$NAME
UID_OWNER="$(stat -c %u "$FROXLOR")"
GID_OWNER="$(stat -c %g "$FROXLOR")"
failures=0
check() {
	if eval "$2"; then
		printf '  OK      %s\n' "$1"
	else
		printf '  FAILED  %s\n' "$1"
		failures=$((failures + 1))
	fi
}
compat() { setpriv --reuid="$UID_OWNER" --regid="$GID_OWNER" --init-groups php "$LIB/compat.php" "$FROXLOR" 2>&1; }

PLANTED=(
	"$FROXLOR/lib/navigation/99.ext.ocubroken.php"
	"$FROXLOR/lib/Froxlor/Ext/OcuBroken"
	"$FROXLOR/templates/OcuBroken"
)
cleanup() { rm -rf -- "${PLANTED[@]}"; }
trap cleanup EXIT
cleanup

echo "Installed extensions"
out="$(compat)"
rc=$?
printf '%s\n' "$out" | sed 's/^/    /'
check "check runs (exit 0 or 1, not aborted)" '[[ $rc == 0 || $rc == 1 ]] && [[ "$out" != *ABORT* ]]'
check "all areas reported" '[[ "$out" == *"PASS navigation"* && "$out" == *"PASS templates"* && "$out" == *"PASS references"* ]]'
BASELINE="$out"

echo "Planted broken extension"
as_owner() { setpriv --reuid="$UID_OWNER" --regid="$GID_OWNER" --init-groups "$@"; }
as_owner mkdir -p "$FROXLOR/lib/Froxlor/Ext/OcuBroken" "$FROXLOR/templates/OcuBroken"
as_owner tee "$FROXLOR/lib/navigation/99.ext.ocubroken.php" >/dev/null <<'PHP'
<?php
return ['admin' => ['ocubroken' => ['label' => \Froxlor\Gone\Missing::label(), 'elements' => []]]];
PHP
as_owner tee "$FROXLOR/lib/Froxlor/Ext/OcuBroken/Child.php" >/dev/null <<'PHP'
<?php
namespace Froxlor\Ext\OcuBroken;

class Child extends \Froxlor\Gone\Base
{
}
PHP
as_owner tee "$FROXLOR/lib/Froxlor/Ext/OcuBroken/Uses.php" >/dev/null <<'PHP'
<?php
namespace Froxlor\Ext\OcuBroken;

use Froxlor\Froxlor;
use Froxlor\Settings;
use Froxlor\UI\Callbacks\Text;

#[\Attribute]
final class Uses
{
	public function run(#[\SensitiveParameter] string $secret): array
	{
		$ok = Settings::Get('panel.standardlanguage') . Froxlor::VERSION . lng('menue.main.main');
		$gone = Settings::GetGone();
		$thing = new \Froxlor\Gone\Thing();
		$const = Froxlor::NO_CONST;
		\Froxlor\gone_function();
		fastcgi_finish_request();
		ocu_helper_from_another_file();
		if (function_exists('ocu_guarded')) {
			ocu_guarded();
		}
		if (class_exists(\Froxlor\Gone\Optional::class)) {
			\Froxlor\Gone\Optional::run();
		}
		$fine = static fn() => strlen($secret);

		return [[Text::class, 'boolean'], [Text::class, 'gone'], $ok, $gone, $thing, $const, $fine];
	}
}
PHP
# Code that must NOT be reported: it cannot fail, or is written for several
# Froxlor versions.
as_owner tee "$FROXLOR/lib/Froxlor/Ext/OcuBroken/Fine.php" >/dev/null <<'PHP'
<?php
namespace Froxlor\Ext\OcuBroken {
	use Froxlor\Settings;

	final class Fine
	{
		public function run(object $e, string $m): mixed
		{
			if ($e instanceof \Froxlor\Gone\OldException) {
				return \Froxlor\Gone\Name::class;
			}
			Settings::$m('x');

			return Settings::Get('panel.standardlanguage');
		}
	}
}
PHP
as_owner tee "$FROXLOR/lib/Froxlor/Ext/OcuBroken/Versions.php" >/dev/null <<'PHP'
<?php
namespace Froxlor\Ext\OcuBroken;

use Froxlor\Froxlor;
use Froxlor\Settings;

final class Versions
{
	public static function get(): mixed
	{
		return version_compare(Froxlor::VERSION, '9.0', '>=') ? Settings::GetFuture() : Settings::Get('x');
	}
}
PHP
as_owner tee "$FROXLOR/templates/OcuBroken/fine-base.html.twig" >/dev/null <<'TWIG'
{% macro inherited(x) %}{{ x }}{% endmacro %}
TWIG
as_owner tee "$FROXLOR/templates/OcuBroken/fine-child.html.twig" >/dev/null <<'TWIG'
{% extends "OcuBroken/fine-base.html.twig" %}
TWIG
as_owner tee "$FROXLOR/templates/OcuBroken/fine.html.twig" >/dev/null <<'TWIG'
{% import "OcuBroken/fine-child.html.twig" as m %}
{{ m.inherited(1) }}
{{ include("OcuBroken/optional.html.twig", ignore_missing = true) }}
{{ source("OcuBroken/optional.html.twig", ignore_missing = true) }}
{% macro row(x) %}{% import _self as m %}{{ m.cell(x) }}{% endmacro %}
{% macro cell(x) %}{{ x }}{% endmacro %}
TWIG
as_owner tee "$FROXLOR/templates/OcuBroken/parent.html.twig" >/dev/null <<'TWIG'
{% extends "Froxlor/gone.html.twig" %}
TWIG
as_owner tee "$FROXLOR/templates/OcuBroken/filter.html.twig" >/dev/null <<'TWIG'
{{ value|ocu_gone_filter }}
TWIG
as_owner tee "$FROXLOR/templates/OcuBroken/macro.html.twig" >/dev/null <<'TWIG'
{% import "Froxlor/user/dashboard-item.html.twig" as d %}
{{ d.ditem('a', 1, 1) }}
{{ d.gone() }}
{% include "Froxlor/gone-include.html.twig" ignore missing %}
TWIG

out="$(compat)"
rc=$?
printf '%s\n' "$out" | grep -v '^PASS' | sed 's/^/    /'
check "exit code 1" '[[ $rc == 1 ]]'
check "broken menu file" '[[ "$out" == *"FAIL navigation: Error: Class \"Froxlor\\Gone\\Missing\" not found"* ]]'
check "missing parent class" '[[ "$out" == *"FAIL class Froxlor\\Ext\\OcuBroken\\Child:"* ]]'
check "missing static method" '[[ "$out" == *"Uses.php:14: Froxlor\\Settings::GetGone() does not exist"* ]]'
check "missing class (new)" '[[ "$out" == *"Uses.php:15: class Froxlor\\Gone\\Thing does not exist"* ]]'
check "missing class constant" '[[ "$out" == *"constant Froxlor\\Froxlor::NO_CONST does not exist"* ]]'
check "missing Froxlor function" '[[ "$out" == *"function Froxlor\\gone_function() does not exist"* ]]'
check "other functions not checked" '[[ "$out" != *fastcgi_finish_request* && "$out" != *ocu_helper_from_another_file* ]]'
check "missing callback" '[[ "$out" == *"callback Froxlor\\UI\\Callbacks\\Text::gone() does not exist"* ]]'
check "guarded uses not reported" '[[ "$out" != *ocu_guarded* && "$out" != *Optional* ]]'
check "existing uses not reported" '[[ "$out" != *"Settings::Get()"* && "$out" != *"Text::boolean"* && "$out" != *"lng()"* && "$out" != *"strlen"* ]]'
check "missing parent template" '[[ "$out" == *"FAIL template OcuBroken/parent.html.twig: line 1: template \"Froxlor/gone.html.twig\" does not exist"* ]]'
check "unknown Twig filter" '[[ "$out" == *"FAIL template OcuBroken/filter.html.twig: SyntaxError"*"ocu_gone_filter"* ]]'
check "missing macro, existing macro fine" '[[ "$out" == *"macro gone() is not defined"* && "$out" != *"macro ditem"* ]]'
check "include ... ignore missing not reported" '[[ "$out" != *gone-include* ]]'
check "no false alarms: instanceof, ::class, dynamic call, braced namespace" '[[ "$out" != *Fine.php* && "$out" != *OldException* ]]'
check "no false alarms: code for several Froxlor versions" '[[ "$out" != *GetFuture* ]]'
check "no false alarms: inherited macro, ignore_missing, _self import" '[[ "$out" != *fine.html.twig* && "$out" != *fine-child* ]]'
check "output ends with DONE" '[[ "$(printf "%s\n" "$out" | tail -n 1)" == DONE ]]'
check "installed extensions unchanged" '[[ -z "$(comm -13 <(printf "%s\n" "$BASELINE" | grep ^FAIL | sort) <(printf "%s\n" "$out" | grep ^FAIL | grep -v -e OcuBroken -e ocubroken -e "Froxlor.Gone" | sort))" ]]'

echo "Fatal error"
cleanup
as_owner mkdir -p "$FROXLOR/lib/Froxlor/Ext/OcuBroken"
as_owner tee "$FROXLOR/lib/Froxlor/Ext/OcuBroken/Fatal.php" >/dev/null <<'PHP'
<?php
namespace Froxlor\Ext\OcuBroken;

final class Fatal implements \Countable
{
	public function count(int $required): string
	{
		return '';
	}
}
PHP
out="$(compat)"
rc=$?
printf '%s\n' "$out" | grep -v '^PASS' | sed 's/^/    /'
check "exit code 1" '[[ $rc == 1 ]]'
check "fatal error names the class" '[[ "$out" == *"FAIL class Froxlor\\Ext\\OcuBroken\\Fatal: PHP fatal error:"* && "$out" == *ABORT* ]]'

echo "Command: $NAME check"
cleanup
out="$("/usr/local/sbin/$NAME" check 2>&1)"
rc=$?
check "check command: exit code like the check" '[[ ($rc == 0 && "$out" == *"No problems found"*) || ($rc == 1 && "$out" == *FAIL*) ]]'

echo "Froxlor directory owned by root, files by the panel user"
chown 0 "$FROXLOR"
out="$("/usr/local/sbin/$NAME" check 2>&1)"
rc=$?
chown "$UID_OWNER" "$FROXLOR"
check "not run as root: exit 2, reason named" '[[ $rc == 2 && "$out" == *"not run as root"* ]]'

echo
if ((failures == 0)); then
	echo "All checks passed."
else
	echo "$failures check(s) failed."
fi
exit $((failures > 0))
