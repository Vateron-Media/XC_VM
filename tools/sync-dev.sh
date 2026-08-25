#!/usr/bin/env bash
# =============================================================================
# sync-dev.sh — deploy the files changed by local commits to a live XC_VM box
# =============================================================================
#
# NAME
#   sync-dev.sh — incremental code deploy from a git commit range to an XC_VM
#   server, without building a release archive.
#
# SYNOPSIS
#   tools/sync-dev.sh [RANGE] [--working] [--cache] [--restart] [--dry-run]
#
# DESCRIPTION
#   Takes a range of local commits, works out which files under src/ they
#   changed, and copies the FULL current content of those files to the target
#   server's install root (default /home/xc_vm/). Files the commits DELETED are
#   removed on the server; renames delete the old path and copy the new one.
#   After copying, every touched file is chown'd to xc_vm:xc_vm. Optionally the
#   settings cache is rebuilt and/or the panel is restarted.
#
#   This is a developer convenience for iterating against a running box — NOT a
#   release mechanism. It never bumps versions, runs DB migrations, touches
#   binaries, or edits per-server config. For a real upgrade use the release
#   archive + the panel updater (see the Makefile and src/update).
#
# HOW IT WORKS
#   * Scope. Only paths under src/ are deployed. src/ is the deploy root and
#     maps 1:1 to REMOTE_ROOT (src/Core/X.php -> /home/xc_vm/Core/X.php).
#     Repo-root build tooling (Makefile, tools/, install/, tests/) is never
#     pushed, and neither are files outside src/.
#   * File set. `git diff --name-status --find-renames <RANGE> -- src` decides
#     the work: A/M/T -> copy, D -> delete, R/C -> delete old + copy new. A path
#     re-added by a later commit in the range is copied, never deleted.
#   * Content. File CONTENT comes from the working tree (i.e. HEAD after a clean
#     commit), so files land whole — this is a file sync, not a patch apply. A
#     listed file missing from the working tree is skipped with a warning.
#   * Transfer. One tar stream (tar -C src -cf - -T <list> | ssh 'tar -x') plus
#     a single chown; deletions are one `rm -f`. All SSH multiplexes over ONE
#     ControlMaster socket (/tmp/xcvm-dev-cm-<server>) so repeated connections
#     do not trip fail2ban on the box.
#   * Watermark. On success the synced HEAD sha is written to .dev-sync-state so
#     a later no-argument run continues from there (see ARGUMENTS).
#
# ARGUMENTS
#   RANGE   A git revision range "A..B", or a single ref REF (treated as
#           "REF..HEAD"). When omitted:
#             - if .dev-sync-state holds a valid commit -> "<that>..HEAD"
#             - otherwise                               -> "HEAD~1..HEAD"
#           Examples of a ref: a tag (2.4.1), a branch, or a sha (fc0826ef).
#
# OPTIONS
#   --working   Also include uncommitted changes to tracked files (diff vs HEAD),
#               on top of RANGE. Handy for testing edits before committing.
#   --cache     After copying, rebuild the settings cache on the server
#               (console.php cron:cache 1). Needed when a settings-shaped change
#               must take effect without waiting for the cache cron.
#   --restart   After copying, restart the panel (service restart) so OPcache
#               reloads the new code. Needed when OPcache does not revalidate by
#               mtime. NOTE: a restart is slow — see CAVEATS.
#   --dry-run   Print the copy/delete plan and exit without contacting the
#               server or changing anything. Always safe.
#   -h, --help  Print this header and exit.
#
# ENVIRONMENT (all optional)
#   DEV_SERVER    Target host/IP.               Default: 89.163.212.59
#   DEV_SSH_USER  SSH user.                     Default: root
#   DEV_SSH_PASS  SSH password. If unset, your SSH keys / agent are used
#                 (preferred). When set, sshpass must be installed.
#   REMOTE_ROOT   Install root on the server.   Default: /home/xc_vm
#
# EXAMPLES
#   # Preview what commits since tag 2.4.1 would deploy — no side effects:
#   DEV_SERVER=10.0.0.5 tools/sync-dev.sh 2.4.1 --dry-run
#
#   # Deploy everything since the 2.4.1 release and reload code:
#   DEV_SERVER=10.0.0.5 tools/sync-dev.sh 2.4.1 --cache --restart
#
#   # Deploy just the last commit (default when .dev-sync-state is absent):
#   DEV_SERVER=10.0.0.5 tools/sync-dev.sh
#
#   # Push a specific range using a password instead of an SSH key:
#   DEV_SERVER=10.0.0.5 DEV_SSH_PASS='***' tools/sync-dev.sh fc0826ef..HEAD
#
#   # Try local edits that aren't committed yet:
#   DEV_SERVER=10.0.0.5 tools/sync-dev.sh --working --restart
#
# REQUIREMENTS
#   Local : bash 4+, git, tar, ssh (and sshpass only if DEV_SSH_PASS is used).
#           Run from anywhere inside the repo (the script cd's to the git root).
#   Remote: tar, xargs, an XC_VM install at REMOTE_ROOT, and sudo for --restart.
#
# EXIT STATUS
#   0  success (or a dry-run, or nothing to do).
#   1  bad environment (e.g. DEV_SSH_PASS set but sshpass missing).
#   >1 a git/ssh/tar step failed (set -e propagates the first failure).
#   Note: when launched under an external `timeout`, a kill shows as 143
#   (128+SIGTERM) even if the server-side work already finished — verify the
#   box rather than trusting the wrapper's exit code.
#
# CAVEATS
#   * --restart runs `service restart`, which can take well over a minute
#     (PHP-FPM recycle + cache warmup). Run the whole command in the background
#     if your shell/agent enforces a short foreground timeout; the server-side
#     work is not interrupted by the local wrapper being killed.
#   * No DB, no binaries, no per-server config. If a change needs a migration or
#     a new binary, this script will NOT deliver it — use a proper release.
#   * .dev-sync-state is a single repo-local watermark shared across targets. If
#     you sync several different servers, pass RANGE explicitly instead of
#     relying on the implicit "since last sync". Keep it out of git (.gitignore).
#   * Deletions are driven purely by the git range; files deleted on the server
#     by other means are not reconciled. This is a one-way push, never a pull.
# =============================================================================
set -euo pipefail

SERVER="${DEV_SERVER:-89.163.212.59}"
SSH_USER="${DEV_SSH_USER:-root}"
REMOTE_ROOT="${REMOTE_ROOT:-/home/xc_vm}"
STATE_FILE=".dev-sync-state"
CM_SOCK="/tmp/xcvm-dev-cm-${SERVER}"

WORKING=0 DO_CACHE=0 DO_RESTART=0 DRY=0 RANGE=""
for arg in "$@"; do
	case "$arg" in
		--working) WORKING=1 ;;
		--cache)   DO_CACHE=1 ;;
		--restart) DO_RESTART=1 ;;
		--dry-run) DRY=1 ;;
		-h|--help) awk 'NR==1{next} /^set -euo pipefail/{exit} {print}' "$0"; exit 0 ;;
		*)         RANGE="$arg" ;;
	esac
done

cd "$(git rev-parse --show-toplevel)"

# ── SSH plumbing: one reused ControlMaster socket (fail2ban-safe) ─────────────
SSH_OPTS=(-o StrictHostKeyChecking=accept-new -o UserKnownHostsFile="$HOME/.ssh/known_hosts"
	-o ControlMaster=auto -o ControlPath="$CM_SOCK" -o ControlPersist=600 -o ConnectTimeout=15)
if [ -n "${DEV_SSH_PASS:-}" ]; then
	command -v sshpass >/dev/null || { echo "DEV_SSH_PASS set but sshpass not installed" >&2; exit 1; }
	SSH=(sshpass -p "$DEV_SSH_PASS" ssh "${SSH_OPTS[@]}")
else
	SSH=(ssh "${SSH_OPTS[@]}")
fi
sshcmd() { "${SSH[@]}" "$SSH_USER@$SERVER" "$@"; }

# ── Resolve the commit range ─────────────────────────────────────────────────
if [ -z "$RANGE" ]; then
	if [ -f "$STATE_FILE" ] && git rev-parse -q --verify "$(cat "$STATE_FILE")^{commit}" >/dev/null 2>&1; then
		RANGE="$(cat "$STATE_FILE")..HEAD"
	else
		RANGE="HEAD~1..HEAD"
	fi
elif [[ "$RANGE" != *..* ]]; then
	RANGE="$RANGE..HEAD"                     # single ref -> from there to HEAD
fi
HEAD_SHA="$(git rev-parse HEAD)"

echo "==> dev sync  server=$SERVER  range=$RANGE  working=$WORKING"

# ── Collect changed files (status-aware): copy set + delete set ──────────────
declare -A COPY=() DEL=()
ingest() {                                  # reads `git diff --name-status` lines
	local status f1 f2
	while IFS=$'\t' read -r status f1 f2; do
		[ -n "$status" ] || continue
		case "$status" in
			R*|C*) [ -n "${f1:-}" ] && DEL["$f1"]=1; [ -n "${f2:-}" ] && COPY["$f2"]=1 ;;
			D)     DEL["$f1"]=1 ;;
			*)     COPY["$f1"]=1 ;;           # A / M / T / etc.
		esac
	done
}
ingest < <(git diff --name-status --find-renames "$RANGE" -- src)
[ "$WORKING" = 1 ] && ingest < <(git diff --name-status --find-renames HEAD -- src)

# A path resurrected by a later commit must not be deleted.
for p in "${!COPY[@]}"; do unset 'DEL[$p]'; done

# ── Build transfer lists (paths relative to src/) ────────────────────────────
COPYLIST="$(mktemp)"; DELLIST="$(mktemp)"
trap 'rm -f "$COPYLIST" "$DELLIST"' EXIT
for p in "${!COPY[@]}"; do
	[[ "$p" == src/* ]] || continue
	rel="${p#src/}"
	if [ -f "$p" ]; then printf '%s\n' "$rel" >> "$COPYLIST"
	else echo "  ! skip (missing in working tree): $p" >&2; fi
done
for p in "${!DEL[@]}"; do
	[[ "$p" == src/* ]] || continue
	printf '%s\n' "$REMOTE_ROOT/${p#src/}" >> "$DELLIST"
done
sort -o "$COPYLIST" "$COPYLIST"; sort -o "$DELLIST" "$DELLIST"

nCopy=$(wc -l < "$COPYLIST"); nDel=$(wc -l < "$DELLIST")
echo "==> to copy: $nCopy   to delete: $nDel"
[ "$nCopy" -gt 0 ] && sed 's/^/    + /' "$COPYLIST"
[ "$nDel"  -gt 0 ] && sed 's/^/    - /' "$DELLIST"

if [ "$DRY" = 1 ]; then echo "==> dry-run, nothing sent."; exit 0; fi
[ "$nCopy" = 0 ] && [ "$nDel" = 0 ] && { echo "==> nothing to do."; exit 0; }

# ── Open the master connection once ──────────────────────────────────────────
sshcmd true

# ── Copy full files: single tar stream src/ -> REMOTE_ROOT/ ──────────────────
if [ "$nCopy" -gt 0 ]; then
	echo "==> copying $nCopy file(s)..."
	tar -C src -cf - -T "$COPYLIST" | sshcmd "tar -C '$REMOTE_ROOT' -xf -"
	# ownership: everything the panel touches must be xc_vm:xc_vm
	sed "s#^#$REMOTE_ROOT/#" "$COPYLIST" | sshcmd "xargs -r -d '\n' chown xc_vm:xc_vm"
fi

# ── Apply deletions ──────────────────────────────────────────────────────────
if [ "$nDel" -gt 0 ]; then
	echo "==> removing $nDel file(s)..."
	sshcmd "xargs -r -d '\n' rm -f" < "$DELLIST"
fi

# ── Optional: settings cache rebuild / panel restart ─────────────────────────
if [ "$DO_CACHE" = 1 ]; then
	echo "==> rebuilding settings cache..."
	sshcmd "sudo -u xc_vm $REMOTE_ROOT/bin/php/bin/php $REMOTE_ROOT/console.php cron:cache 1" || true
fi
if [ "$DO_RESTART" = 1 ]; then
	echo "==> restarting panel..."
	sshcmd "sudo $REMOTE_ROOT/service restart" || true
fi

# ── Record watermark for the next incremental sync ───────────────────────────
printf '%s\n' "$HEAD_SHA" > "$STATE_FILE"
echo "==> done. synced up to $HEAD_SHA"
