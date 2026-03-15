#!/bin/bash
# test_release.sh — сборка и запуск тестового контейнера для проверки установки XC_VM
#
# Использование:
#   ./tools/test-install/test_release.sh          — собрать и запустить
#   ./tools/test-install/test_release.sh build     — только собрать образ
#   ./tools/test-install/test_release.sh run       — запустить контейнер (интерактивно)
#   ./tools/test-install/test_release.sh install   — запустить и выполнить автоустановку
#   ./tools/test-install/test_release.sh clean     — удалить контейнер и образ
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
COMPOSE="docker compose -f $SCRIPT_DIR/docker-compose.yml"
CONTAINER="xcvm-test-install"
IMAGE="xcvm-test:latest"

cd "$PROJECT_ROOT"

# Проверяем наличие dist/XC_VM.zip
check_zip() {
    if [[ ! -f dist/XC_VM.zip ]]; then
        echo "ERROR: dist/XC_VM.zip not found."
        echo "Run 'make main' first to build the release archive."
        exit 1
    fi
    echo "Found: dist/XC_VM.zip ($(du -h dist/XC_VM.zip | cut -f1))"
}

cmd_build() {
    check_zip
    echo "==> Building test container..."
    $COMPOSE build
    echo "==> Build complete."
}

wait_for_container() {
    local retries=15
    local i=0
    while (( i < retries )); do
        if docker exec "$CONTAINER" systemctl is-system-running --wait 2>/dev/null | grep -qE 'running|degraded'; then
            return 0
        fi
        if ! docker ps --format '{{.Names}}' | grep -q "^${CONTAINER}$"; then
            echo "ERROR: Container exited unexpectedly."
            echo "Logs:"
            docker logs "$CONTAINER" 2>&1 | tail -30
            return 1
        fi
        sleep 2
        (( i++ ))
    done
    echo "WARNING: systemd did not reach 'running' in 30s, container may still be booting."
    return 0
}

cmd_run() {
    check_zip
    echo "==> Starting container with systemd..."
    docker run -d \
        --name "$CONTAINER" \
        --hostname xcvm-test \
        --privileged \
        --cgroupns=host \
        -v /sys/fs/cgroup:/sys/fs/cgroup:rw \
        -v "$PROJECT_ROOT/dist/XC_VM.zip:/opt/xcvm-install/XC_VM.zip:ro" \
        -p 8880:80 \
        -p 8443:443 \
        --stop-signal SIGRTMIN+3 \
        "$IMAGE"
    echo "==> Container started. Waiting for systemd init..."
    wait_for_container || exit 1

    echo ""
    echo "Container is running. You can:"
    echo "  docker exec -it $CONTAINER bash                     # shell"
    echo "  docker exec -it $CONTAINER /opt/xcvm-install/auto_install.sh  # auto-install"
    echo "  $0 install                                          # auto-install shortcut"
    echo "  $0 clean                                            # stop and remove"
}

cmd_install() {
    check_zip

    # Проверяем запущен ли контейнер
    if ! docker ps --format '{{.Names}}' | grep -q "^${CONTAINER}$"; then
        echo "==> Container not running, starting..."
        # Удаляем старый остановленный контейнер если есть
        docker rm -f "$CONTAINER" 2>/dev/null || true
        docker run -d \
            --name "$CONTAINER" \
            --hostname xcvm-test \
            --privileged \
            --cgroupns=host \
            -v /sys/fs/cgroup:/sys/fs/cgroup:rw \
            -v "$PROJECT_ROOT/dist/XC_VM.zip:/opt/xcvm-install/XC_VM.zip:ro" \
            -p 8880:80 \
            -p 8443:443 \
            --stop-signal SIGRTMIN+3 \
            "$IMAGE"
        echo "==> Waiting for systemd init..."
        wait_for_container || exit 1
    fi

    echo "==> Running auto-install inside container..."
    docker exec -it "$CONTAINER" /opt/xcvm-install/auto_install.sh
}

cmd_clean() {
    echo "==> Stopping and removing container..."
    docker rm -f "$CONTAINER" 2>/dev/null || true
    docker rmi "$IMAGE" 2>/dev/null || true
    echo "==> Cleaned."
}

cmd_logs() {
    docker exec "$CONTAINER" cat /var/log/xcvm_install.log 2>/dev/null || echo "No install log found."
}

case "${1:-install}" in
    build)   cmd_build ;;
    run)     cmd_run ;;
    install) cmd_build && cmd_run && cmd_install ;;
    clean)   cmd_clean ;;
    logs)    cmd_logs ;;
    *)
        echo "Usage: $0 {build|run|install|clean|logs}"
        exit 1
        ;;
esac
