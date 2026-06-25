#!/usr/bin/env bash
#
# Strip Composer dev dependencies from a STAGED release tree.
#
# The committed src/vendor/ contains require-dev packages (PHPStan, PHP-CS-Fixer
# and their transitive deps — ~33 MB) so they are available for local dev / CI.
# They must NOT ship to production: besides the disk weight, PHPStan's files-
# autoload registers PHPStan\PharAutoloader at runtime on every request.
#
# This runs against the staged deploy root produced by `make main` / `make lb`
# (the TEMP_DIR that becomes the archive), NOT against the repo. It removes every
# dev package directory and regenerates the autoloader with --no-dev, so the
# shipped vendor/ resolves only the production packages. The committed
# src/vendor/ is left untouched.
#
# Usage: strip-dev-vendor.sh <staged-deploy-root>
set -euo pipefail

root="${1:?usage: strip-dev-vendor.sh <staged-deploy-root>}"
repo="$(cd "$(dirname "$0")/../.." && pwd)"

if [ ! -d "$root/vendor" ]; then
	echo "   no vendor/ in $root — nothing to strip"
	exit 0
fi
if [ ! -f "$root/vendor/composer/installed.json" ]; then
	echo "   no vendor/composer/installed.json — skipping dev-dep strip"
	exit 0
fi

if ! command -v composer >/dev/null 2>&1; then
	echo "[ERROR] composer not found — cannot strip dev dependencies from the" >&2
	echo "        release vendor/. Install Composer on the build host (the" >&2
	echo "        committed src/vendor/ otherwise ships PHPStan/PHP-CS-Fixer)." >&2
	exit 1
fi

# `composer dump-autoload` needs composer.json; the LB copy step does not stage
# it, so provide it from the repo (harmless metadata, fine to ship).
if [ ! -f "$root/composer.json" ]; then
	cp "$repo/src/composer.json" "$root/composer.json"
fi

before="$(du -sh "$root/vendor" | cut -f1)"

# Remove every dev package directory listed in installed.json. (Trailing newline
# on every line so `while read` does not skip the final package.)
php -r 'foreach (json_decode(file_get_contents($argv[1]), true)["dev-package-names"] ?? [] as $p) echo $p, "\n";' \
	"$root/vendor/composer/installed.json" | while IFS= read -r pkg; do
	[ -n "$pkg" ] && rm -rf "$root/vendor/${pkg:?}"
done

# Remove the dev packages' bin shims (vendor/bin/phpstan, php-cs-fixer, ...) —
# they'd be dead now that the packages are gone. Driven by each dev package's
# declared `bin`, so prod binaries (if any are ever added) are preserved.
php -r '$j = json_decode(file_get_contents($argv[1]), true); $dev = array_flip($j["dev-package-names"] ?? []); foreach ($j["packages"] as $p) { if (isset($dev[$p["name"]])) foreach ((array)($p["bin"] ?? []) as $b) echo basename($b), "\n"; }' \
	"$root/vendor/composer/installed.json" | while IFS= read -r bin; do
	[ -n "$bin" ] && rm -f "$root/vendor/bin/${bin}"
done

# Drop the now-empty vendor dirs left behind (e.g. vendor/phpstan/, vendor/bin/).
find "$root/vendor" -depth -mindepth 1 -type d -empty -delete

# Regenerate the classmap/autoload files without the dev autoload entries
# (this is what drops the PHPStan PharAutoloader files-autoload).
( cd "$root" && composer dump-autoload --no-dev --no-interaction --quiet )

echo "   release vendor/ pruned: ${before} -> $(du -sh "$root/vendor" | cut -f1)"
