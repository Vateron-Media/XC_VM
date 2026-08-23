#!/bin/bash
# test_release.sh — управление тестовым контейнером XC_VM
#
# Использование:
#   ./tools/test-install/test_release.sh            — собрать и установить
#   ./tools/test-install/test_release.sh install    — то же самое
#   ./tools/test-install/test_release.sh clean      — удалить контейнер и образ
#   ./tools/test-install/test_release.sh logs       — показать лог установки
#   ./tools/test-install/test_release.sh sync       — синхронизировать src/ в контейнер
#
# Стрим-тест (ручная настройка → бэкап → повторяемый прогон, см. STREAMTEST.md):
#   streamtest-gen        — запустить генератор в контейнере (для ручной настройки панели)
#   streamtest-gen-stop   — остановить генератор
#   streamtest-backup     — снять дамп настроенной БД в fixtures/
#   streamtest            — восстановить дамп БД и прогнать checker (e2e)
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
COMPOSE="docker compose -f $SCRIPT_DIR/docker-compose.yml"
CONTAINER="xcvm-test-install"

check_zip() {
    if [[ ! -f "$PROJECT_ROOT/dist/XC_VM.zip" ]]; then
        echo "ERROR: dist/XC_VM.zip not found."
        echo "Run 'make main' first to build the release archive."
        exit 1
    fi
    echo "Found: dist/XC_VM.zip ($(du -h "$PROJECT_ROOT/dist/XC_VM.zip" | cut -f1))"
}

wait_for_container() {
    local i=0
    while (( i < 15 )); do
        if docker exec "$CONTAINER" systemctl is-system-running --wait 2>/dev/null | grep -qE 'running|degraded'; then
            return 0
        fi
        if ! docker ps --format '{{.Names}}' | grep -q "^${CONTAINER}$"; then
            echo "ERROR: Container exited unexpectedly."
            docker logs "$CONTAINER" 2>&1 | tail -30
            return 1
        fi
        sleep 2
        (( i++ ))
    done
    echo "WARNING: systemd did not reach 'running' in 30s."
    return 0
}

cmd_install() {
    check_zip
    docker rm -f "$CONTAINER" 2>/dev/null || true
    $COMPOSE up -d --build
    echo "==> Waiting for systemd init..."
    wait_for_container || exit 1
    echo "==> Running auto-install inside container..."
    docker exec -it \
        -e XCVM_INSTALL_HTTP_PORT="${XCVM_INSTALL_HTTP_PORT:-}" \
        -e XCVM_INSTALL_HTTPS_PORT="${XCVM_INSTALL_HTTPS_PORT:-}" \
        -e XCVM_INSTALL_DB_ROOT_PASSWORD="${XCVM_INSTALL_DB_ROOT_PASSWORD:-}" \
        -e XCVM_INSTALL_OVERWRITE_SYSCTL="${XCVM_INSTALL_OVERWRITE_SYSCTL:-Y}" \
        "$CONTAINER" /opt/xcvm-install/auto_install.sh
}

cmd_clean() {
    $COMPOSE down --rmi local 2>/dev/null || true
    echo "==> Cleaned."
}

cmd_logs() {
    docker exec "$CONTAINER" cat /var/log/xcvm_install.log 2>/dev/null || echo "No install log found."
}

cmd_sync() {
    if ! docker ps --format '{{.Names}}' | grep -q "^${CONTAINER}$"; then
        echo "ERROR: Container '$CONTAINER' is not running."
        echo "Run '$0 install' first."
        exit 1
    fi

    echo "==> Syncing src/ → $CONTAINER:/home/xc_vm"
    cd "$PROJECT_ROOT/src"
    tar cf - \
        --exclude='bin' \
        --exclude='config/config.ini' \
        --exclude='content' \
        --exclude='tmp' \
        --exclude='.gitkeep' \
        . | docker exec -i "$CONTAINER" tar xf - -C /home/xc_vm

    local count
    count=$(find . -type f \
        -not -path './bin/*' \
        -not -path './config/config.ini' \
        -not -path './content/*' \
        -not -path './tmp/*' \
        -not -name '.gitkeep' | wc -l)
    cd "$PROJECT_ROOT"

    echo "==> Synced ~$count files."
    echo "To apply: docker exec $CONTAINER systemctl restart xc_vm"
}

# The streamtest workflow (see STREAMTEST.md) never writes the DB directly.
# You configure the panel ONCE by hand (generator source → streams → bouquet →
# line), snapshot it with `streamtest-backup`, then `streamtest` restores that
# snapshot and runs the checker — repeatably, and re-restorable while you iterate.
FIXTURE="$SCRIPT_DIR/fixtures/streamtest-db.sql.gz"

require_container() {
    if ! docker ps --format '{{.Names}}' | grep -q "^${CONTAINER}$"; then
        echo "ERROR: Container '$CONTAINER' is not running. Run '$0 install' first."
        exit 1
    fi
}

require_sample() {
    if [[ ! -f "$PROJECT_ROOT/tools/test-stream-generator/sample.mp4" ]]; then
        echo "ERROR: tools/test-stream-generator/sample.mp4 not found (it is gitignored)."
        echo "The generator needs a short H.264/AAC .mp4 there. Provide one, then re-run."
        exit 1
    fi
}

# Start the generator inside the container and leave it running, so you can
# configure the panel by hand and watch the streams go live in the admin UI.
cmd_streamtest_gen() {
    require_container
    require_sample
    # Kill any prior generator in a SEPARATE exec — doing it inside the launch
    # `bash -c` would self-match (its own args contain stream_server.py) and kill
    # the wrapper before exec. pkill skips its own pid, so a standalone call is safe.
    docker exec "$CONTAINER" pkill -f 'test-stream-generator/stream_server.py' 2>/dev/null || true
    docker exec -d "$CONTAINER" bash -c '
        for v in 8.0 7.1 4.0; do
            [ -x /home/xc_vm/bin/ffmpeg_bin/$v/ffmpeg ] && FF=/home/xc_vm/bin/ffmpeg_bin/$v/ffmpeg && break
        done
        exec python3 /opt/xcvm-tools/test-stream-generator/stream_server.py \
            -i /opt/xcvm-tools/test-stream-generator/sample.mp4 \
            --port '"${XCVM_STREAM_PORT:-8088}"' --advertise-host 127.0.0.1 --ffmpeg "$FF" \
            >/tmp/xcvm-streamgen.log 2>&1'
    sleep 3
    local code admin
    code="$(docker exec "$CONTAINER" bash -c \
        'grep -oiE "Admin Access Code: *[A-Za-z0-9]+" /opt/xcvm-install/credentials.txt 2>/dev/null | grep -oE "[A-Za-z0-9]+$"' \
        2>/dev/null | tr -d "\r")"
    if [[ -n "$code" ]]; then
        admin="http://localhost:${XCVM_HTTP_PORT:-8880}/$code/"
    else
        admin="http://localhost:${XCVM_HTTP_PORT:-8880}/<access-code>/  (see '$0 logs')"
    fi
    echo "==> Generator running inside the container. Use these as stream sources in the panel:"
    echo "      MPEG-TS : http://127.0.0.1:${XCVM_STREAM_PORT:-8088}/stream.ts"
    echo "      HLS     : http://127.0.0.1:${XCVM_STREAM_PORT:-8088}/stream.m3u8"
    echo "    Panel admin: $admin"
    echo "    (the bare http://localhost:${XCVM_HTTP_PORT:-8880}/ returns 404 — the admin lives under the access code)"
    echo "    Next: configure per STREAMTEST.md, verify streams go live, then: $0 streamtest-backup"
    echo "    Stop the generator with: $0 streamtest-gen-stop"
}

cmd_streamtest_gen_stop() {
    require_container
    docker exec "$CONTAINER" pkill -f stream_server.py 2>/dev/null || true
    echo "==> Generator stopped."
}

# Snapshot the (manually configured) panel DB into the fixture.
cmd_streamtest_backup() {
    require_container
    mkdir -p "$SCRIPT_DIR/fixtures"
    echo "==> Dumping xc_vm database → $FIXTURE"
    docker exec "$CONTAINER" bash -c '
        PW=$(grep -m1 "^Password:" /root/credentials.txt | cut -d" " -f2 | tr -d "\r")
        exec mysqldump -u root -p"$PW" --single-transaction --routines --triggers xc_vm' \
        | gzip > "$FIXTURE"
    if [[ -s "$FIXTURE" ]]; then
        echo "==> Saved $(du -h "$FIXTURE" | cut -f1). Re-run '$0 streamtest' any time to restore + test."
    else
        echo "ERROR: backup is empty — check the container / credentials."; rm -f "$FIXTURE"; exit 1
    fi
}

# Restore the fixture and run the e2e checker.
cmd_streamtest() {
    require_container
    require_sample
    if [[ ! -f "$FIXTURE" ]]; then
        echo "ERROR: no DB snapshot at $FIXTURE."
        echo "Configure the panel first (see STREAMTEST.md), then run '$0 streamtest-backup'."
        exit 1
    fi

    echo "==> Restoring xc_vm database from $FIXTURE"
    docker cp "$FIXTURE" "$CONTAINER":/tmp/streamtest-db.sql.gz
    docker exec "$CONTAINER" bash -c '
        set -e
        PW=$(grep -m1 "^Password:" /root/credentials.txt | cut -d" " -f2 | tr -d "\r")
        gunzip -c /tmp/streamtest-db.sql.gz | mysql -u root -p"$PW" xc_vm
        rm -f /tmp/streamtest-db.sql.gz'

    local outdir="$SCRIPT_DIR/out"
    mkdir -p "$outdir"
    local ts out logdir
    ts="$(date +%Y%m%d-%H%M%S)"
    out="$outdir/streamtest-$ts.json"       # aggregate report
    logdir="$outdir/streamtest-$ts"         # per-stream logs

    echo "==> Running e2e stream test through the panel (a few minutes; ${STREAMTEST_DURATION:-120}s per stream)..."
    local rc=0
    docker exec \
        -e STREAMTEST_USER="${STREAMTEST_USER:-xcvmtest}" \
        -e STREAMTEST_PASS="${STREAMTEST_PASS:-xcvmtest}" \
        -e STREAMTEST_DURATION="${STREAMTEST_DURATION:-120}" \
        -e STREAMTEST_TOLERANCE="${STREAMTEST_TOLERANCE:-0}" \
        -e STREAMTEST_STALL_TIMEOUT="${STREAMTEST_STALL_TIMEOUT:-15}" \
        -e STREAMTEST_GEN_PORT="${XCVM_STREAM_PORT:-8088}" \
        -e STREAMTEST_OUT_DIR=/tmp/xcvm-streamlogs \
        "$CONTAINER" bash /opt/xcvm-tools/test-install/streamtest_in_container.sh > "$out" || rc=$?

    # Copy the per-stream JSON logs out of the container (one file per stream).
    if docker exec "$CONTAINER" bash -c '[ -d /tmp/xcvm-streamlogs ] && ls -A /tmp/xcvm-streamlogs' >/dev/null 2>&1; then
        mkdir -p "$logdir"
        docker cp "$CONTAINER":/tmp/xcvm-streamlogs/. "$logdir/" >/dev/null 2>&1 \
            && echo "==> Per-stream logs: $logdir/"
    fi

    echo "==> Aggregate report: $out"
    if [[ -s "$out" ]] && command -v python3 >/dev/null 2>&1; then
        python3 - "$out" <<'PY' || true
import json, sys
try:
    d = json.load(open(sys.argv[1]))
except Exception as e:
    print("   (no valid JSON report: %s)" % e); sys.exit()
s = d.get("summary", {})
print("   %s streams: %s healthy, %s failed" % (
    s.get("total", "?"), s.get("healthy", "?"), s.get("failed", "?")))
for st in d.get("streams", []):
    verdict = "OK" if st.get("healthy") else "FAIL " + "; ".join(st.get("errors", []))
    print("   - %-22s %-4s %s" % (st.get("name", "?"), st.get("mode", "?"), verdict))
PY
    fi

    if [[ $rc -eq 0 ]]; then echo "==> PASS"; else echo "==> FAIL (exit $rc)"; fi
    return $rc
}

case "${1:-install}" in
    install)             cmd_install ;;
    clean)               cmd_clean ;;
    logs)                cmd_logs ;;
    sync)                cmd_sync ;;
    streamtest-gen)      cmd_streamtest_gen ;;
    streamtest-gen-stop) cmd_streamtest_gen_stop ;;
    streamtest-backup)   cmd_streamtest_backup ;;
    streamtest)          cmd_streamtest ;;
    *)
        echo "Usage: $0 {install|clean|logs|sync|streamtest-gen|streamtest-gen-stop|streamtest-backup|streamtest}"
        exit 1
        ;;
esac

