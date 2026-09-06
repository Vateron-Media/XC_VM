#!/bin/bash
# streamtest_in_container.sh — runs INSIDE the xcvm test container.
#
# Assumes the panel has ALREADY been configured (manually, once) and that
# configuration has been captured with `test_release.sh streamtest-backup` and
# restored by the host wrapper before this script runs. See STREAMTEST.md.
#
# This script does NOT touch the database. It only:
#   1. rebuilds the file caches the streaming path reads (cron:cache, cron:cache_engine)
#   2. launches tools/test-stream-generator as the upstream source (bundled ffmpeg)
#   3. fetches the panel's OUTPUT m3u for the configured test line
#   4. runs stream_check.py playlist against the panel output
#
# JSON report → STDOUT (captured by the host wrapper); progress → STDERR.
# Exit code = the checker's (0 = all healthy, 2 = a stream failed, 3 = setup error).
set -uo pipefail

log() { echo "[streamtest] $*" >&2; }

TOOLS=/opt/xcvm-tools
XC=/home/xc_vm
PHP="$XC/bin/php/bin/php"
CONSOLE="$XC/console.php"
USER_NAME="${STREAMTEST_USER:-xcvmtest}"
USER_PASS="${STREAMTEST_PASS:-xcvmtest}"
DURATION="${STREAMTEST_DURATION:-120}"
TOLERANCE="${STREAMTEST_TOLERANCE:-0}"
STALL_TIMEOUT="${STREAMTEST_STALL_TIMEOUT:-15}"
GEN_PORT="${STREAMTEST_GEN_PORT:-8088}"
OUT_DIR="${STREAMTEST_OUT_DIR:-/tmp/xcvm-streamlogs}"  # per-stream JSON logs (copied out by the host)
GEN="http://127.0.0.1:$GEN_PORT"

# ── HTTP GET via python3 (always present; avoids a curl/wget dependency) ──
http_get() {  # http_get <url> <out-file>  → 0 on success
    python3 - "$1" "$2" <<'PY'
import sys, urllib.request
url, out = sys.argv[1], sys.argv[2]
try:
    data = urllib.request.urlopen(url, timeout=10).read()
except Exception as e:
    sys.stderr.write("fetch fail: %s\n" % e)
    sys.exit(1)
with open(out, "wb") as fh:
    fh.write(data)
PY
}

as_xcvm() {  # run a command as the xc_vm service user when possible
    if command -v sudo >/dev/null 2>&1 && id xc_vm >/dev/null 2>&1; then
        sudo -u xc_vm "$@"
    else
        "$@"
    fi
}

# ── locate bundled ffmpeg (only present after the panel is installed) ──
FFMPEG=""
for v in 8.0 7.1 4.0; do
    if [[ -x "$XC/bin/ffmpeg_bin/$v/ffmpeg" ]]; then
        FFMPEG="$XC/bin/ffmpeg_bin/$v/ffmpeg"
        break
    fi
done
[[ -n "$FFMPEG" ]] || { log "ERROR: no bundled ffmpeg under $XC/bin/ffmpeg_bin (is the panel installed?)"; exit 3; }
log "ffmpeg: $FFMPEG"

SAMPLE="$TOOLS/test-stream-generator/sample.mp4"
[[ -f "$SAMPLE" ]] || { log "ERROR: sample.mp4 not found at $SAMPLE (is tools/ mounted?)"; exit 3; }

# ── rebuild the file caches from the (restored) DB ──
log "cache: cron:cache"
as_xcvm "$PHP" "$CONSOLE" cron:cache        >&2 2>&1 || log "warn: cron:cache rc=$?"
log "cache: cron:cache_engine"
as_xcvm "$PHP" "$CONSOLE" cron:cache_engine >&2 2>&1 || log "warn: cron:cache_engine rc=$?"

# ── launch the upstream generator (background; killed on exit) ──
# Kill any prior generator and WAIT for the port to actually free before binding
# (a just-terminated process can hold the socket briefly → "Address already in use").
pkill -f 'test-stream-generator/stream_server.py' 2>/dev/null || true
for _ in $(seq 1 12); do
    python3 -c "import socket,sys
s=socket.socket()
try:
    s.bind(('0.0.0.0', $GEN_PORT)); s.close()
except OSError:
    sys.exit(1)" 2>/dev/null && break
    sleep 0.5
done
log "generator: starting on :$GEN_PORT (advertise 127.0.0.1)"
nohup python3 "$TOOLS/test-stream-generator/stream_server.py" \
    -i "$SAMPLE" --port "$GEN_PORT" --advertise-host 127.0.0.1 --ffmpeg "$FFMPEG" \
    >/tmp/xcvm-streamgen.log 2>&1 &
GEN_PID=$!
trap 'kill "$GEN_PID" 2>/dev/null; pkill -f stream_server.py 2>/dev/null' EXIT

ready=0
for _ in $(seq 1 15); do
    if http_get "$GEN/playlist.m3u" /tmp/xcvm-gen-check.m3u 2>/dev/null; then ready=1; break; fi
    sleep 1
done
[[ $ready -eq 1 ]] || { log "ERROR: generator not ready"; sed 's/^/[gen] /' /tmp/xcvm-streamgen.log >&2; exit 3; }
log "generator: ready"

# ── give on-demand/monitored streams a moment to warm (cron:streams starts them) ──
sleep "${STREAMTEST_WARMUP:-8}"

# ── fetch the panel's OUTPUT m3u for the configured test line ──
PL=/tmp/xcvm-panel.m3u
fetched=0
for url in \
    "http://127.0.0.1/get.php?username=$USER_NAME&password=$USER_PASS&type=m3u_plus&output=ts" \
    "http://127.0.0.1/api/playlist?username=$USER_NAME&password=$USER_PASS&type=m3u_plus&output=ts" \
    "http://127.0.0.1/playlist/$USER_NAME/$USER_PASS/m3u_plus"; do
    if http_get "$url" "$PL" 2>/dev/null && head -1 "$PL" 2>/dev/null | grep -q '#EXTM3U'; then
        log "playlist: fetched via ${url%%\?*}"
        fetched=1
        break
    fi
done
if [[ $fetched -ne 1 ]]; then
    log "ERROR: could not fetch a valid panel playlist for line '$USER_NAME'."
    log "The restored backup must contain a line with THESE credentials and a non-empty bouquet."
    log "If your line uses a different name, pass it explicitly, e.g.:"
    log "    STREAMTEST_USER=<line> STREAMTEST_PASS=<pass> ./test_release.sh streamtest"
    log "See STREAMTEST.md."
    log "last response head:"; head -5 "$PL" 2>/dev/null | sed 's/^/[body] /' >&2
    exit 3
fi
log "panel playlist:"; sed 's/^/[streamtest]   /' "$PL" >&2

# ── run the checker against the panel output (aggregate JSON → stdout,
#    per-stream JSON logs → $OUT_DIR, which the host copies out) ──
rm -rf "$OUT_DIR" 2>/dev/null; mkdir -p "$OUT_DIR"
log "checker: testing each stream for ${DURATION}s (tolerance=${TOLERANCE}, stall-timeout=${STALL_TIMEOUT}s); per-stream logs → $OUT_DIR"
python3 "$TOOLS/stream-check/stream_check.py" playlist "$PL" \
    --duration "$DURATION" --tolerance "$TOLERANCE" \
    --stall-timeout "$STALL_TIMEOUT" --out-dir "$OUT_DIR"
rc=$?
log "checker: exit $rc"
exit $rc
