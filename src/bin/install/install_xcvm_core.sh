#!/bin/bash
#
# Install the xcvm_core PHP extension into the bundled PHP, from the binaries
# repo tree (bin/xcvm_extention). The runtime bundle does not carry it, and
# console.php cannot boot without it, so a fresh install (MAIN by the
# installer, a load balancer by LbInstallFlow over SSH) runs this once;
# `console.php xcvm_core` keeps it current afterwards.
#
#   install_xcvm_core.sh [owner] [repo] [bin_dir] [ext_dir]
#
# ext_dir defaults to the PHP's own; update_binaries.sh passes a staged
# bundle's, which the PHP does not report from outside its install path.
set -euo pipefail

OWNER="${1:-Vateron-Media}"
REPO="${2:-XC_VM_Binaries}"
BIN_DIR="${3:-/home/xc_vm/bin}"
PHP="${BIN_DIR%/}/php/bin/php"
BASE="https://raw.githubusercontent.com/${OWNER}/${REPO}/main/bin/xcvm_extention"

ASSET="xcvm_core-php$("$PHP" -n -r 'echo PHP_MAJOR_VERSION, ".", PHP_MINOR_VERSION;').tar.gz"
EXT_DIR="${4:-$("$PHP" -n -r 'echo PHP_EXTENSION_DIR;')}"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

curl -fsSL --retry 3 "${BASE}/SHA256SUMS" -o "$TMP/SHA256SUMS"
curl -fsSL --retry 3 "${BASE}/${ASSET}" -o "$TMP/$ASSET"
(cd "$TMP" && grep " ${ASSET}\$" SHA256SUMS | sha256sum -c --quiet -)
tar -xzf "$TMP/$ASSET" -C "$TMP" ./xcvm_core.so
install -m 0555 "$TMP/xcvm_core.so" "$EXT_DIR/xcvm_core.so"

if ! "$PHP" -n -d "extension=$EXT_DIR/xcvm_core.so" -r 'exit(class_exists("XC_VM") ? 0 : 1);' > /dev/null 2>&1; then
    rm -f "$EXT_DIR/xcvm_core.so"
    echo "xcvm_core from ${ASSET} does not load into ${PHP}" >&2
    exit 1
fi
echo "xcvm_core installed: $EXT_DIR/xcvm_core.so (${ASSET})"
