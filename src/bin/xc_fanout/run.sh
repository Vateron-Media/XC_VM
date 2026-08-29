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
SCRIPT=/home/xc_vm
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
  if [ -x "$FANOUT_DIR/xc_fanout" ]; then
    # Reap a daemon orphaned by a previous supervisor that died without it (the
    # child reparents to init and keeps holding the sockets). Our own daemon has
    # already exited by the time the loop returns here, so this only ever targets
    # such strays — closing the "two daemons fighting over the socket" gap before
    # we bind fresh.
    pkill -u xc_vm -x xc_fanout 2>/dev/null
    FF=$(pick_ffmpeg)
    echo "=== $(date '+%F %T') supervisor pid=$$ spawning daemon (ffmpeg=$FF) ===" >> "$LOG"
    "$FANOUT_DIR/xc_fanout" \
      -sock "$FANOUT_DIR/sockets/http.sock" \
      -ctl "$FANOUT_DIR/sockets/control.sock" \
      -ffmpeg "${FF:-ffmpeg}" \
      -font "$SCRIPT/bin/free-sans.ttf" \
      >> "$LOG" 2>&1
    rc=$?
    echo "=== $(date '+%F %T') daemon EXITED rc=$rc (supervisor pid=$$) ===" >> "$LOG"
  fi
  sleep 2
done
