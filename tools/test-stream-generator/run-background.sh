#!/usr/bin/env bash
#
# Launch stream_server.py (the XC_VM test stream generator) detached in the
# background so it keeps running after you close the shell / SSH session.
#
#   ./run-background.sh [start|stop|status|restart] [-- extra stream_server.py args]
#
# Extra args are forwarded to stream_server.py, e.g.:
#   ./run-background.sh start -- --advertise-host 192.168.110.251 --port 8088
#
# State lives next to this script: stream_server.pid and stream_server.log.
#
set -euo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SERVER="$DIR/stream_server.py"
PIDFILE="$DIR/stream_server.pid"
LOGFILE="$DIR/stream_server.log"

PYTHON="$(command -v python3 || true)"
[ -n "$PYTHON" ] || { echo "python3 not found in PATH" >&2; exit 1; }

# Pick the ffmpeg to drive the generator, in order of preference:
#   1. the bundled XUI ffmpeg 4.0 (FFMPEG_BIN_40) — the build the panel itself
#      defaults to and the one this generator's drawtext filters target
#      (newer bundled builds break: 7.x rejects the `%{pts:hms}` clock syntax,
#      and 8.0 is dynamically linked against libs missing from the base system,
#      e.g. libogg.so.0, so it exits 127);
#   2. any other bundled build that at least runs `-version`;
#   3. whatever `ffmpeg` is on PATH.
# stream_server.py also accepts --ffmpeg to override this.
runs() { [ -x "$1" ] && "$1" -version >/dev/null 2>&1; }
pick_ffmpeg() {
    local c
    if runs /home/xc_vm/bin/ffmpeg_bin/4.0/ffmpeg; then
        echo /home/xc_vm/bin/ffmpeg_bin/4.0/ffmpeg; return 0
    fi
    for c in $(ls -1d /home/xc_vm/bin/ffmpeg_bin/*/ffmpeg 2>/dev/null | sort -Vr); do
        runs "$c" && { echo "$c"; return 0; }
    done
    command -v ffmpeg || true
}
FFMPEG="$(pick_ffmpeg)"

is_running() { [ -f "$PIDFILE" ] && kill -0 "$(cat "$PIDFILE" 2>/dev/null)" 2>/dev/null; }

start() {
    if is_running; then
        echo "already running (pid $(cat "$PIDFILE"))"
        return 0
    fi
    [ -n "$FFMPEG" ] || { echo "ffmpeg not found (no bundled build and none in PATH)" >&2; exit 1; }
    # New session (setsid) + stdin from /dev/null so it fully detaches from the
    # terminal/SSH channel. The wrapper records the real python PID (== the new
    # session/process-group leader), which stop() uses to kill the whole group.
    setsid bash -c 'echo $$ > "$1"; shift; exec "$@"' _ "$PIDFILE" \
        "$PYTHON" "$SERVER" --ffmpeg "$FFMPEG" "$@" </dev/null >"$LOGFILE" 2>&1 &
    sleep 1
    if is_running; then
        echo "started (pid $(cat "$PIDFILE"))"
        echo "log:    $LOGFILE"
        echo "ffmpeg: $FFMPEG"
        grep -E 'TS \(LLOD\)|HLS |M3U list|Index' "$LOGFILE" 2>/dev/null || true
    else
        echo "failed to start — see $LOGFILE" >&2
        tail -n 20 "$LOGFILE" 2>/dev/null || true
        exit 1
    fi
}

stop() {
    if ! is_running; then echo "not running"; rm -f "$PIDFILE"; return 0; fi
    local pid; pid="$(cat "$PIDFILE")"
    # Kill the whole process group (python + its per-client ffmpeg children).
    kill -TERM "-$pid" 2>/dev/null || kill -TERM "$pid" 2>/dev/null || true
    for _ in $(seq 1 20); do is_running || break; sleep 0.5; done
    if is_running; then kill -KILL "-$pid" 2>/dev/null || kill -KILL "$pid" 2>/dev/null || true; fi
    rm -f "$PIDFILE"
    echo "stopped"
}

status() {
    if is_running; then
        echo "running (pid $(cat "$PIDFILE"))"
    else
        echo "stopped"
    fi
}

cmd="${1:-start}"
[ $# -gt 0 ] && shift || true
[ "${1:-}" = "--" ] && shift || true

case "$cmd" in
    start)   start "$@" ;;
    stop)    stop ;;
    restart) stop; start "$@" ;;
    status)  status ;;
    *) echo "usage: $0 [start|stop|status|restart] [-- extra stream_server.py args]" >&2; exit 2 ;;
esac
