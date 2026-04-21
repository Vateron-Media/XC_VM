#!/bin/bash
# test_release.sh — управление тестовым контейнером XC_VM
#
# Использование:
#   ./tools/test-install/test_release.sh            — собрать и установить
#   ./tools/test-install/test_release.sh install    — то же самое
#   ./tools/test-install/test_release.sh clean      — удалить контейнер и образ
#   ./tools/test-install/test_release.sh logs       — показать лог установки
#   ./tools/test-install/test_release.sh sync       — синхронизировать src/ в контейнер
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

case "${1:-install}" in
    install) cmd_install ;;
    clean)   cmd_clean ;;
    logs)    cmd_logs ;;
    sync)    cmd_sync ;;
    *)
        echo "Usage: $0 {install|clean|logs|sync}"
        exit 1
        ;;
esac

