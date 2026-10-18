#!/bin/bash
# xc_fanout keepalive supervisor (ADR 0002/0003, P2/G). Started from `service`
# boot() as: sudo -u xc_vm /home/xc_vm/bin/xc_fanout/run.sh &
#
# Runs UNCONDITIONALLY and re-checks for the daemon binary each pass (2s backoff),
# so a node that receives the daemon later — fanout_binary pulls it on first
# install / update / the RootSignals hourly self-heal — starts serving within ~2s
# WITHOUT a service restart (closes the fresh-install bootstrap gap; fanout_binary
# only pkills and relies on this loop to respawn). A crash is auto-restarted (the
# Restart=always equivalent, no second systemd unit). This script + the daemon run
# as xc_vm, so `stop`'s `pkill -u xc_vm` ends both and nothing respawns on shutdown.
#
# This file is git-tracked and deployed with the tree; fanout_binary only atomically
# renames the downloaded binary into this same dir, so it never clobbers this script.
# XCVM_FANOUT_HOME is for tests only (sudo passes none on a node), matching the
# agent supervisor's XCVM_AGENT_HOME.
SCRIPT="${XCVM_FANOUT_HOME:-/home/xc_vm}"
FANOUT_DIR="$SCRIPT/bin/xc_fanout"

# Single-instance guard. Hold an exclusive lock for the supervisor's whole
# lifetime: a second run.sh (a boot/keepalive race, or a manual start) fails to
# take the lock and exits at once — so there is never more than one supervisor,
# and since each supervisor runs exactly one daemon at a time (the loop blocks on
# it), never more than one xc_fanout daemon. `stop`'s `pkill -u xc_vm` closes
# fd 9 and frees the lock; the next `service boot` re-acquires it.
LOG="$FANOUT_DIR/xc_fanout.log"
echo "=== $(date '+%F %T') run.sh start attempt pid=$$ ppid=$PPID ===" >> "$LOG"
exec 9>"$FANOUT_DIR/run.lock"
if command -v flock >/dev/null 2>&1; then
  if ! flock -n 9; then
    echo "=== $(date '+%F %T') run.sh pid=$$ could NOT take flock (another supervisor holds it) — exiting ===" >> "$LOG"
    exit 0
  fi
fi
echo "=== $(date '+%F %T') run.sh pid=$$ acquired flock, supervising ===" >> "$LOG"

# Pick the NEWEST bundled ffmpeg that actually has the drawtext filter — some
# bundled builds (8.0/7.1) lack it despite libfreetype, and without drawtext the
# admin "send message" overlay (Phase E) silently no-ops; fall back to the newest
# if none have it (overlay off). -ffmpeg/-font power that drawtext banner: the
# daemon burns it onto a signalled viewer's HLS segment / TS window.
pick_ffmpeg() {
  local c
  for c in $(ls -d "$SCRIPT"/bin/ffmpeg_bin/*/ffmpeg 2>/dev/null | sort -Vr); do
    if "$c" -hide_banner -filters 2>/dev/null | grep -qw drawtext; then
      echo "$c"
      return
    fi
  done
  ls -d "$SCRIPT"/bin/ffmpeg_bin/*/ffmpeg 2>/dev/null | sort -V | tail -1
}

while true; do
  # Fanout switched off in the panel: FanoutMode writes this flag (from the
  # root cron, within a minute of the change) and stops the daemon, which lands
  # us here. Exit instead of respawning; the cron restarts this supervisor once
  # the flag is gone.
  if [ -f "$FANOUT_DIR/disabled" ]; then
    echo "=== $(date '+%F %T') supervisor pid=$$ exiting: fanout disabled in the panel ===" >> "$LOG"
    exit 0
  fi
  if [ -x "$FANOUT_DIR/xc_fanout" ]; then
    # Reap a daemon orphaned by a previous supervisor that died without it (the
    # child reparents to init and keeps holding the sockets). Our own daemon has
    # already exited by the time the loop returns here, so this only ever targets
    # such strays — closing the "two daemons fighting over the socket" gap before
    # we bind fresh.
    pkill -u xc_vm -x xc_fanout 2>/dev/null
    FF=$(pick_ffmpeg)
    # The segment gateway's socket (Phase 12), only for a daemon that knows the
    # flag: an older one would exit on it. Until the gateway answers there,
    # nginx sends /hls/ and /key/ to PHP.
    GW=()
    if "$FANOUT_DIR/xc_fanout" -h 2>&1 | grep -q -- '-gw-policy'; then
      GW=(-gw "$FANOUT_DIR/sockets/gw.sock" -gw-policy "$SCRIPT/tmp/gateway/policy.json")
    fi
    # Serve-only single-binary LB (Phase A): the Go front owns the node's public
    # HTTPS port and reverse-proxies non-serve requests to nginx on a local backend
    # port. Gated on the serve_only marker the install writes AND on the binary
    # knowing -front (an older daemon would exit on an unknown flag). The listen
    # address comes from config/cluster/front_addr (the node's https_broadcast_port,
    # written by the install), defaulting to :443.
    #
    # This supervisor runs as xc_vm, so it records the -front capability in
    # config/cluster/front_capable (its own dir) — RootSignalsCronJob (root) only
    # stat-checks that file and demotes nginx off :443 when present, rather than
    # executing this xc_vm-owned binary as root. Removing it (no -front) reverts
    # nginx to the public ssl listener, so the demote tracks the front's capability.
    FRONT=()
    if [ -f "$SCRIPT/config/cluster/serve_only" ] && "$FANOUT_DIR/xc_fanout" -h 2>&1 | grep -q -- '-front'; then
      touch "$SCRIPT/config/cluster/front_capable" 2>/dev/null
      FRONT_ADDR=$(cat "$SCRIPT/config/cluster/front_addr" 2>/dev/null); FRONT_ADDR=${FRONT_ADDR:-:443}
      FRONT=(-front "$FRONT_ADDR" -front-cert "$SCRIPT/bin/nginx/conf/server.crt" -front-key "$SCRIPT/bin/nginx/conf/server.key" -front-nginx "http://127.0.0.1:8080")
    else
      rm -f "$SCRIPT/config/cluster/front_capable" 2>/dev/null
    fi
    echo "=== $(date '+%F %T') supervisor pid=$$ spawning daemon (ffmpeg=$FF) ===" >> "$LOG"
    "$FANOUT_DIR/xc_fanout" \
      -sock "$FANOUT_DIR/sockets/http.sock" \
      -ctl "$FANOUT_DIR/sockets/control.sock" \
      -ffmpeg "${FF:-ffmpeg}" \
      -font "$SCRIPT/bin/free-sans.ttf" \
      "${GW[@]}" \
      "${FRONT[@]}" \
      >> "$LOG" 2>&1
    rc=$?
    echo "=== $(date '+%F %T') daemon EXITED rc=$rc (supervisor pid=$$) ===" >> "$LOG"
  fi
  sleep 2
done
