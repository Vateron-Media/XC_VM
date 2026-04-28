#!/bin/bash
set -euo pipefail

# PHP syntax checker for the XC_VM project.
# Scans all .php files under src/, excluding src/bin/* (third-party stubs).
# Exit code: 0 if no errors, 1 if any file has syntax errors.
#
# Usage:
#   ./tools/php_syntax_check.sh          # check all src/*.php
#   ./tools/php_syntax_check.sh src/domain/Device/EnigmaService.php  # check one file


SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"

ERRORS=0
CHECKED=0

check_file() {
    local file="$1"
    ((++CHECKED))

    if ! php_output="$(php -l "$file" 2>&1)"; then
        echo "$php_output"
        ((++ERRORS))
    fi
}

if [[ $# -gt 0 ]]; then
    for file in "$@"; do
        check_file "$file"
    done
else
    while IFS= read -r -d '' file; do
        check_file "$file"
    done < <(find "$PROJECT_ROOT/src" -name '*.php' -not -path '*/bin/*' -print0)
fi

echo "Checked $CHECKED files, $ERRORS errors"
exit $ERRORS