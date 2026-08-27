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
    FF=$(pick_ffmpeg)
    "$FANOUT_DIR/xc_fanout" \
      -sock "$FANOUT_DIR/sockets/http.sock" \
      -ctl "$FANOUT_DIR/sockets/control.sock" \
      -ffmpeg "${FF:-ffmpeg}" \
      -font "$SCRIPT/bin/free-sans.ttf" \
      >"$FANOUT_DIR/xc_fanout.log" 2>&1
  fi
  sleep 2
done
