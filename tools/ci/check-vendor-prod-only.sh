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
# This inspects GIT-TRACKED files, so it is correct even inside a CI job that has
# already run `composer install` (which writes dev packages into the working
# tree but not into the index).
set -euo pipefail
cd "$(dirname "$0")/../.."

lock="src/composer.lock"
[ -f "$lock" ] || { echo "[ERROR] $lock not found (it must be committed)." >&2; exit 1; }

# Names of require-dev packages (and their dev-only transitive deps) from the lock.
mapfile -t devpkgs < <(php -r '
	$j = json_decode(file_get_contents($argv[1]), true);
	foreach ($j["packages-dev"] ?? [] as $p) echo $p["name"], "\n";
' "$lock")

leaked=()
for p in "${devpkgs[@]}"; do
	[ -n "$p" ] || continue
	if [ -n "$(git ls-files "src/vendor/$p")" ]; then
		leaked+=("$p")
	fi
done

if [ "${#leaked[@]}" -gt 0 ]; then
	echo "[FAIL] dev packages are committed under src/vendor/:" >&2
	printf '   %s\n' "${leaked[@]}" >&2
	echo "" >&2
	echo "Re-commit a production-only vendor:" >&2
	echo "   (cd src && composer install --no-dev) && git add src/vendor && git commit" >&2
	exit 1
fi

echo "OK: committed src/vendor/ is production-only (${#devpkgs[@]} dev packages in lock, none tracked)."
