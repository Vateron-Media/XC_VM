#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/../.."

check() {
	local name=$1 files=$2 expected=$3 actual
	actual=$(printf '%s\n' "$files" | bash tools/ci/classify-ci-paths.sh)
	if [ "$actual" != "$expected" ]; then
		printf '%s\nexpected:\n%s\nactual:\n%s\n' "$name" "$expected" "$actual" >&2
		exit 1
	fi
}

none=$'phpstan=false\nstyle=false\ngates=false\ncomposer=false\nphpunit=false\nagent=false'
all=$'phpstan=true\nstyle=true\ngates=true\ncomposer=true\nphpunit=true\nagent=true'

check docs $'docs/en/README.md\nmkdocs.yml\n.deploy-merged-tickets' "$none"
check unit-test 'tests/Unit/BouquetMapEntryTest.php' $'phpstan=false\nstyle=false\ngates=false\ncomposer=false\nphpunit=true\nagent=false'
check cluster-code 'src/Core/Cluster/AgentClient.php' $'phpstan=true\nstyle=true\ngates=true\ncomposer=false\nphpunit=true\nagent=true'
check public-asset 'src/Public/assets/admin/js/main.js' $'phpstan=false\nstyle=false\ngates=true\ncomposer=false\nphpunit=true\nagent=false'
check phpstan-config 'build/phpstan.dist.neon' $'phpstan=true\nstyle=false\ngates=false\ncomposer=false\nphpunit=false\nagent=false'
check style-config 'build/phpcs.xml.dist' $'phpstan=false\nstyle=true\ngates=false\ncomposer=false\nphpunit=false\nagent=false'
check test-bootstrap 'tests/bootstrap.php' $'phpstan=false\nstyle=false\ngates=false\ncomposer=false\nphpunit=true\nagent=true'
check composer 'src/composer.json' "$all"
check unknown 'new-project-surface.toml' "$all"

echo 'CI path classifier: OK'
