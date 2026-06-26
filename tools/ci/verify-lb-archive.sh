#!/usr/bin/env bash
#
# Security gate (plan blocker 1): the LoadBalancer archive must NOT contain
# privileged code. The LB build copies LB_DIRS and then removes LB_DIRS_TO_REMOVE
# / LB_FILES_TO_REMOVE. After the PascalCase rename (Фаза 1) a stale lowercase
# remove path would silently miss, leaking Admin/Reseller controllers, the
# user/device domain and cron jobs to an internet-facing DMZ node.
#
# This reproduces the Makefile's LB file selection from the real LB_* variables
# (no tarball needed) and asserts the sensitive trees are absent.
set -euo pipefail
cd "$(dirname "$0")/../.."

LB_DIRS=$(make -s print-LB_DIRS)
RM_DIRS=$(make -s print-LB_DIRS_TO_REMOVE)
RM_FILES=$(make -s print-LB_FILES_TO_REMOVE)

# Shipped manifest: tracked files under LB_DIRS, paths relative to src/.
manifest=$(for d in $LB_DIRS; do git ls-files "src/$d" 2>/dev/null; done | sed 's#^src/##')

# Apply directory removals.
if [ -n "${RM_DIRS// }" ]; then
	rm_re=$(printf '%s' "$RM_DIRS" | tr -s ' ' '|')
	manifest=$(printf '%s\n' "$manifest" | grep -Ev "^(${rm_re})/" || true)
fi
# Apply file removals.
for f in $RM_FILES; do
	manifest=$(printf '%s\n' "$manifest" | grep -vxF "$f" || true)
done

# Privileged paths that must never reach an LB (internet-facing) node. These are
# the genuinely admin/reseller/install-only trees and files. NOTE: LB legitimately
# ships most of Cli/Commands and Cli/CronJobs (it runs edge commands + crons like
# certbot/cache/cleanup), so only the specific privileged ones are listed — not
# the whole dirs. Each entry must be removed by the Makefile's LB_DIRS_TO_REMOVE /
# LB_FILES_TO_REMOVE; this asserts the removal actually took effect (catches a
# silent rm miss after a rename — security blocker 1).
SENSITIVE=(
	# Admin / reseller / player UI + the privileged domains (dir-level removes).
	"Public/Controllers/Admin"
	"Public/Controllers/Reseller"
	"Public/Controllers/Player"
	"Domain/User"
	"Domain/Device"
	# Install / provisioning commands and root-privileged cron jobs (file-level).
	"Cli/Commands/ServerInstallCommand.php"
	"Cli/Commands/LbInstallFlow.php"
	"Cli/Commands/ProxyInstallFlow.php"
	"Cli/Commands/MigrateCommand.php"
	"Cli/CronJobs/RootMysqlCronJob.php"
)

fail=0
for s in "${SENSITIVE[@]}"; do
	# Match a directory prefix ("$s/") or an exact file path ("$s").
	if printf '%s\n' "$manifest" | grep -qE "^${s}(/|$)"; then
		echo "LEAK: '${s}' would ship to the LB archive (privileged code)."
		printf '%s\n' "$manifest" | grep -E "^${s}(/|$)" | sed 's/^/    /' | head -5
		fail=1
	fi
done

if [ "$fail" -ne 0 ]; then
	echo "FAIL: LB archive contains privileged code — check Makefile LB_DIRS_TO_REMOVE/LB_FILES_TO_REMOVE."
	exit 1
fi
echo "OK: LB manifest excludes all privileged trees ($(printf '%s\n' "$manifest" | grep -c . ) files shipped)."
