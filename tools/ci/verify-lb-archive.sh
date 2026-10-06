#!/usr/bin/env bash
#
# Security gate (plan blocker 1): the LoadBalancer archive must NOT contain
# privileged code. The LB build copies LB_DIRS and then removes LB_DIRS_TO_REMOVE
# / LB_FILES_TO_REMOVE. After the PascalCase rename (Фаза 1) a stale lowercase
# remove path would silently miss, leaking Admin/Reseller controllers, the
# user/device domain and cron jobs to an internet-facing DMZ node.
#
# This reproduces the Makefile's LB file selection from the real LB_* variables
# (no tarball needed) and asserts that:
#   - every LB list entry still matches a tracked path under src/ (STALE) of the
#     kind its list expects (WRONG-LIST),
#   - the sensitive trees are absent (LEAK),
#   - every script lb_configs/nginx.conf executes still ships (MISSING),
#   - neither update's deleted-files list names a shipped file (DELETES-SHIPPED):
#     the LB list must miss every file of the LB manifest, and the committed
#     src/migrations/deleted_files.txt (applied by MAIN and LB updates after the
#     new tree is unpacked) must name no tracked file.
set -euo pipefail
cd "$(dirname "$0")/../.."

LB_DIRS=$(make -s print-LB_DIRS)
ROOT_FILES=$(make -s print-LB_ROOT_FILES)
RM_DIRS=$(make -s print-LB_DIRS_TO_REMOVE)
RM_FILES=$(make -s print-LB_FILES_TO_REMOVE)
KEEP_DIRS=$(make -s print-LB_KEEP_ON_UPDATE)
NGINX_CONF="$(make -s print-CONFIG_DIR)/nginx.conf"
NGINX_CONF="${NGINX_CONF#./}"
INDEX_PHP="src/Public/index.php"
MAIN_DIR=$(make -s print-MAIN_DIR)
MAIN_DIR="${MAIN_DIR#./}"

fail=0

# An entry that matches no tracked path copies or strips nothing, so a rename
# silently turns its strip rule into a no-op (how the old www/* entries went
# stale while Public/stream/auth.php and Public/admin/api.php kept shipping).
# An entry in the wrong list is just as silent or worse: lb_copy_files copies
# LB_DIRS by "<dir>/" prefix and strips LB_DIRS_TO_REMOVE with rm -rf, but copies
# LB_ROOT_FILES with cp and strips LB_FILES_TO_REMOVE with rm -f, which skips a
# directory. So a *DIRS* entry must be a tracked directory and a *FILES* entry a
# tracked file.
check_entries() {
	local list=$1 kind=$2 p tracked
	shift 2
	for p in "$@"; do
		tracked=$(git --literal-pathspecs ls-files -- "src/$p")
		if [ -z "$tracked" ]; then
			echo "STALE: '${p}' in ${list} matches no tracked path under src/ (renamed or removed?)."
			fail=1
		elif [ "$kind" = dir ] && [ "$tracked" = "src/$p" ]; then
			echo "WRONG-LIST: '${p}' in ${list} is a file, but ${list} takes directories."
			fail=1
		elif [ "$kind" = file ] && [ "$tracked" != "src/$p" ]; then
			echo "WRONG-LIST: '${p}' in ${list} is a directory, but ${list} takes files."
			fail=1
		fi
	done
}
check_entries LB_DIRS dir $LB_DIRS
check_entries LB_ROOT_FILES file $ROOT_FILES
check_entries LB_DIRS_TO_REMOVE dir $RM_DIRS
check_entries LB_FILES_TO_REMOVE file $RM_FILES
check_entries LB_KEEP_ON_UPDATE dir $KEEP_DIRS

# Shipped manifest, paths relative to src/: the tracked files under LB_DIRS and
# the LB_ROOT_FILES, minus what lb_copy_files strips (rm -rf of each
# LB_DIRS_TO_REMOVE path, rm -f of each LB_FILES_TO_REMOVE file).
manifest=$(
	for d in $LB_DIRS; do git ls-files -- "src/$d/"; done | sed 's#^src/##'
	for f in $ROOT_FILES; do git ls-files -- "src/$f" | sed 's#^src/##'; done
)
manifest=$(awk -v dirs="$RM_DIRS" -v files="$RM_FILES" '
	BEGIN { n = split(dirs, d, " "); m = split(files, f, " "); for (i = 1; i <= m; i++) rmf[f[i]] = 1 }
	$0 in rmf { next }
	{ for (i = 1; i <= n; i++) if ($0 == d[i] || index($0, d[i] "/") == 1) next; print }
' <<< "$manifest")

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
	"Public/Controllers/PlayerV2"
	"Domain/User"
	"Domain/Device"
	# The cluster API server (MAIN side of MAIN <-> LB); LBs only ever call it.
	"Domain/Cluster"
	"Public/cluster"
	"Cli/Commands/ClusterInitCommand.php"
	"Cli/Commands/ClusterExportKeysCommand.php"
	"Cli/Commands/ClusterImportKeysCommand.php"
	"Cli/Commands/ClusterDbAllowlistCommand.php"
	"Cli/Commands/ClusterRotateStreamSecretCommand.php"
	"Cli/Commands/ClusterRotateCredentialsCommand.php"
	"Cli/Commands/ClusterLockdownCommand.php"
	"Cli/Commands/ClusterRotateSignKeyCommand.php"
	"Cli/Commands/ClusterStripCredentialsCommand.php"
	"Cli/Commands/ClusterRotateDbPasswordCommand.php"
	"Cli/Commands/ClusterMainDataplaneCommand.php"
	"Cli/Commands/ClusterEnrolCodeCommand.php"
	"Cli/Commands/ClusterEnrolApproveCommand.php"
	"Cli/Commands/ClusterPoolsCommand.php"
	"Cli/Commands/ClusterNginxCommand.php"
	"Cli/Commands/ClusterEndpointCommand.php"
	# MAIN's nginx include for the cluster API (lb_configs/nginx.conf has no route).
	"bin/nginx/conf/cluster_locations.conf"
	"Cli/Commands/ServerEnrolCommand.php"
	"Cli/Commands/ClusterReenrolCommand.php"
	"Cli/Commands/SshSession.php"
	# Admin / reseller APIs and the MAIN-only endpoints (the LB nginx routes none
	# of them; auth.php and probe.php need the stripped Domain/User).
	"Public/admin/api.php"
	"Public/admin/proxy_api.php"
	"Public/stream/auth.php"
	"Public/stream/probe.php"
	"Public/Controllers/Api/AdminApiController.php"
	"Public/Controllers/Api/AdminAPIWrapper.php"
	"Public/Controllers/Api/ActiveCodeApiController.php"
	"Public/Controllers/Api/ResellerRestApiController.php"
	"Public/Controllers/Api/ResellerAPIWrapper.php"
	"Infrastructure/ResellerApiDispatcher.php"
	"Infrastructure/ResellerTableRenderer.php"
	# Install / provisioning / schema commands and MAIN-only or root-privileged
	# cron jobs (file-level).
	"Cli/Commands/ServerInstallCommand.php"
	"Cli/Commands/ServerSyncOpensslExtraCommand.php"
	"Cli/Commands/LbInstallFlow.php"
	"Cli/Commands/ProxyInstallFlow.php"
	"Cli/Commands/MigrateCommand.php"
	"Cli/Commands/DbMigrateCommand.php"
	"Cli/Commands/ModuleMigrateCommand.php"
	"Cli/Commands/CacheHandlerCommand.php"
	"Cli/migration_logic.php"
	"Cli/CronJobs/RootMysqlCronJob.php"
	"Cli/CronJobs/CacheEngineCronJob.php"
)

for s in "${SENSITIVE[@]}"; do
	# Match a directory prefix ("$s/") or an exact file path ("$s"). A here-string,
	# not a pipe: under pipefail an early `grep -q` exit can SIGPIPE the writer.
	if grep -qE "^${s}(/|$)" <<< "$manifest"; then
		echo "LEAK: '${s}' would ship to the LB archive (privileged code)."
		printf '%s\n' "$manifest" | grep -E "^${s}(/|$)" | sed 's/^/    /' | head -5
		fail=1
	fi
done

# Whatever the LB nginx executes must ship, or a strip entry breaks a route the
# LB serves: every SCRIPT_FILENAME, Public/<scope>/<handler>.php for each handler
# the /stream/ and /admin/ gateway locations accept (the gateways 404 a handler
# whose file is missing), and the controller Public/index.php dispatches for each
# XC_API value (below).
routed=$(grep -oE 'SCRIPT_FILENAME /home/xc_vm/[^;[:space:]]+' "$NGINX_CONF" | sed 's#^SCRIPT_FILENAME /home/xc_vm/##' | sort -u || true)
if [ -z "$routed" ]; then
	echo "MISSING: no SCRIPT_FILENAME /home/xc_vm/... found in ${NGINX_CONF} (update this gate)."
	fail=1
fi
for scope in stream admin; do
	location_re='^[[:space:]]*location ~ \^/'"${scope}"'/\(([a-z_|]+)\)\$ \{'
	handlers=$(sed -nE "\#${location_re}#{s#${location_re}.*#\1#p;q;}" "$NGINX_CONF")
	if [ -z "$handlers" ]; then
		echo "MISSING: no 'location ~ ^/${scope}/(...)\$' gateway found in ${NGINX_CONF} (update this gate)."
		fail=1
		continue
	fi
	for h in ${handlers//|/ }; do
		routed+=$'\n'"Public/${scope}/${h}.php"
	done
done

# XC_API: Public/index.php maps each name to a controller class in $rApiEndpoints
# ('internal' is the /api command channel MAIN uses on LBs). A location that sets
# `XC_API $1` passes the alternatives of its first capture group. Each name must
# resolve, through $rApiEndpoints and the file's `use` imports, to a controller
# that ships; an unknown name fails closed.
api_names=$(awk '
	/^[[:space:]]*location[[:space:]]/ { loc = $0 }
	/^[[:space:]]*fastcgi_param[[:space:]]+XC_API[[:space:]]/ {
		v = $3; sub(/;.*/, "", v)
		if (v != "$1") { print v; next }
		if (!match(loc, /\(([a-z0-9_|]+)\)/)) { print "?"; next }
		n = split(substr(loc, RSTART + 1, RLENGTH - 2), a, "|")
		for (i = 1; i <= n; i++) print a[i]
	}' "$NGINX_CONF" | sort -u)
if [ -z "$api_names" ]; then
	echo "MISSING: no 'fastcgi_param XC_API' found in ${NGINX_CONF} (update this gate)."
	fail=1
fi
for n in $api_names; do
	# sed quits after the first match (no `| head`: under pipefail it can SIGPIPE sed).
	class=""
	if [[ "$n" =~ ^[a-z0-9_]+$ ]]; then
		entry_re="^[[:space:]]*'${n}'[[:space:]]*=>[[:space:]]*\\[([A-Za-z0-9_]+)::class.*"
		class=$(sed -nE "/${entry_re}/{s/${entry_re}/\1/p;q;}" "$INDEX_PHP")
	fi
	fqcn=""
	if [ -n "$class" ]; then
		use_re="^use (XcVm(\\\\[A-Za-z0-9_]+)*\\\\${class});.*"
		fqcn=$(sed -nE "/${use_re}/{s/${use_re}/\1/p;q;}" "$INDEX_PHP")
	fi
	if [ -z "$fqcn" ]; then
		echo "MISSING: ${NGINX_CONF} sets XC_API '${n}', which ${INDEX_PHP} does not map to an imported controller (update this gate)."
		fail=1
		continue
	fi
	path=${fqcn#XcVm\\}
	routed+=$'\n'"${path//\\//}.php"
done
# What Public/index.php needs besides the controller to serve an XC_API request.
API_DEPENDENCIES=(
	"Public/Controllers/Api/BaseApiController.php"
	"Infrastructure/Bootstrap/StreamingRequestBootstrap.php"
	"Infrastructure/Bootstrap/WebApiBootstrap.php"
)
for r in "${API_DEPENDENCIES[@]}"; do
	routed+=$'\n'"$r"
done

while IFS= read -r r; do
	[ -n "$r" ] || continue
	if ! grep -qxF "$r" <<< "$manifest"; then
		echo "MISSING: ${NGINX_CONF} routes to '${r}' but the LB manifest does not ship it."
		fail=1
	fi
done <<< "$routed"

# The LB update deletes every file the archive's migrations/deleted_files.txt
# lists (MigrationRunner::runFileCleanup()), so lb_delete_files_list must never
# list a file the archive ships. A git deletion whose path is tracked again, or a
# list rule that drifts from lb_copy_files, would delete live code from every
# installed LB.
delete_dir=$(mktemp -d)
trap 'rm -rf "$delete_dir"' EXIT
if ! make -s lb_delete_files_list TEMP_DIR="$delete_dir" >/dev/null; then
	echo "DELETES-SHIPPED: 'make lb_delete_files_list' failed, so the LB deleted-files list could not be checked."
	fail=1
elif [ -f "$delete_dir/migrations/deleted_files.txt" ]; then
	while IFS= read -r r; do
		[ -n "$r" ] || continue
		echo "DELETES-SHIPPED: the LB update would delete '${r}', which the LB archive ships."
		fail=1
	done <<< "$(grep -xF -f <(printf '%s\n' "$manifest") "$delete_dir/migrations/deleted_files.txt" || true)"
fi

# MigrationRunner::runFileCleanup() deletes every listed path after the new
# release is unpacked, so a file that was deleted and later restored (still
# listed from an older generate_deleted_files run) would vanish from MAIN too.
if [ -f "${MAIN_DIR}/migrations/deleted_files.txt" ]; then
	# The entries as runFileCleanup() reads them: trimmed (a CRLF or a stray space
	# names the same file there), without comments and blank lines, the last line
	# with or without a newline.
	entries=$(sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//' -e '/^#/d' -e '/^$/d' "${MAIN_DIR}/migrations/deleted_files.txt")
	# One git call for the whole list: a git process per entry took 19 s on the
	# 4,692 entries of the committed list. Entry by entry only to name what that
	# call found, or when it failed.
	if [ -n "$entries" ] && { ! tracked=$(printf '%s\n' "$entries" | sed 's#^#src/#' | tr '\n' '\0' | xargs -0 git ls-files -- 2>/dev/null) || [ -n "$tracked" ]; }; then
		while IFS= read -r r; do
			if git ls-files --error-unmatch "src/${r}" >/dev/null 2>&1; then
				echo "DELETES-SHIPPED: ${MAIN_DIR}/migrations/deleted_files.txt lists '${r}', which is tracked in src/, so every MAIN and LB update would delete it."
				fail=1
			fi
		done <<< "$entries"
	fi
fi

if [ "$fail" -ne 0 ]; then
	echo "FAIL: fix the Makefile LB lists, ${NGINX_CONF} or src/migrations/deleted_files.txt (STALE: entry matches nothing; WRONG-LIST: a file in a directory list or the reverse; LEAK: privileged code ships; MISSING: a routed script is stripped; DELETES-SHIPPED: an update deletes a shipped file)."
	exit 1
fi
echo "OK: LB manifest has no stale entries, excludes all privileged trees, ships every routed script and deletes none of them on update ($(printf '%s\n' "$manifest" | grep -c . ) files shipped)."
