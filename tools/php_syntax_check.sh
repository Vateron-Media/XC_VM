#!/bin/bash
set -euo pipefail

# PHP syntax checker for the XC_VM project.
# Scans all .php files under src/, excluding src/bin/* (third-party stubs).
# Exit code: 0 if no errors, 1 if any file has syntax errors.
#
# Usage:
#   ./tools/php_syntax_check.sh          # check all src/*.php
#   ./tools/php_syntax_check.sh src/domain/Device/EnigmaService.php  # check one file

ERRORS=0
CHECKED=0

if [[ $# -gt 0 ]]; then
    for file in "$@"; do
        if ! php -l "$file" 2>&1 | grep -q "No syntax errors"; then
            php -l "$file"
            ERRORS=$((ERRORS + 1))
        fi
        CHECKED=$((CHECKED + 1))
    done
else
    while IFS= read -r -d '' file; do
        if ! php -l "$file" 2>&1 | grep -q "No syntax errors"; then
            php -l "$file"
            ERRORS=$((ERRORS + 1))
        fi
        CHECKED=$((CHECKED + 1))
    done < <(find src -name '*.php' -not -path 'src/bin/*' -print0)
fi

echo "Checked $CHECKED files, $ERRORS errors"
[[ "$ERRORS" -eq 0 ]]
