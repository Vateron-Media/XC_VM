#!/usr/bin/env bash
#
# Gate: the committed src/vendor/ must be PRODUCTION-ONLY.
#
# vendor/ is committed and shipped (the deploy path has no Composer), so it must
# contain only the production dependencies — generated with
#   cd src && composer install --no-dev
# Dev tools (PHPStan, PHP-CS-Fixer + their transitive deps) are installed locally
# and in CI via `composer install`; they must never be committed.
#
# Two checks, both against the GIT INDEX (not the working tree), so the gate is
# correct even in a CI job that has already run `composer install` (which writes
# dev packages + a dev-flavoured installed.json into the working tree only):
#   1. no dev package directory is tracked under src/vendor/;
#   2. the committed vendor/composer/installed.json lists no dev package.
# (.gitignore blocks dev package dirs from being added; this is the hard
# guarantee, and it also catches a dev-flavoured installed.json — which is a
# tracked file that .gitignore cannot protect.)
set -euo pipefail
cd "$(dirname "$0")/../.."

lock="src/composer.lock"
ij="src/vendor/composer/installed.json"
[ -f "$lock" ] || { echo "[ERROR] $lock not found (it must be committed)." >&2; exit 1; }

# --- Check 1: no dev package directory tracked ---------------------------------
mapfile -t devpkgs < <(php -r '
	foreach (json_decode(file_get_contents($argv[1]), true)["packages-dev"] ?? [] as $p) echo $p["name"], "\n";
' "$lock")

leaked=()
for p in "${devpkgs[@]}"; do
	[ -n "$p" ] || continue
	[ -n "$(git ls-files "src/vendor/$p")" ] && leaked+=("$p")
done

if [ "${#leaked[@]}" -gt 0 ]; then
	echo "[FAIL] dev packages are committed under src/vendor/:" >&2
	printf '   %s\n' "${leaked[@]}" >&2
	echo "Re-commit a prod-only vendor: (cd src && composer install --no-dev) && git add src/vendor" >&2
	exit 1
fi

# --- Check 2: committed installed.json lists no dev package --------------------
# Read the INDEX copy (falls back to the working file when not staged/committed).
ij_dev="$(git show ":$ij" 2>/dev/null || cat "$ij")"
bad="$(printf '%s' "$ij_dev" | php -r '
	$ij   = json_decode(stream_get_contents(STDIN), true);
	$lock = json_decode(file_get_contents($argv[1]), true);
	$devset = array_flip(array_map(fn($p) => $p["name"], $lock["packages-dev"] ?? []));
	$bad = [];
	foreach ($ij["packages"] ?? [] as $p) if (isset($devset[$p["name"]])) $bad[] = $p["name"];
	foreach ($ij["dev-package-names"] ?? [] as $n) $bad[] = $n;
	echo implode(" ", array_unique($bad));
' "$lock")"

if [ -n "$bad" ]; then
	echo "[FAIL] committed $ij lists dev packages: $bad" >&2
	echo "Re-commit a prod-only vendor: (cd src && composer install --no-dev) && git add src/vendor" >&2
	exit 1
fi

echo "OK: committed src/vendor/ is production-only (${#devpkgs[@]} dev packages in lock, none tracked, none in installed.json)."
