FROM ubuntu:24.04

ENV DEBIAN_FRONTEND=noninteractive
ENV container=docker
ENV LC_ALL=C
ENV TZ=UTC

# systemd — необходим для инсталлятора (systemctl enable/start xc_vm)
RUN apt-get update && apt-get install -y --no-install-recommends \
    systemd systemd-sysv dbus cron \
    lsb-release sudo python3 wget curl unzip \
    iproute2 net-tools apt-utils ca-certificates gnupg \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Убираем только действительно ненужные в контейнере юниты
# НЕ трогаем sysinit.target.wants (journald, tmpfiles, sysctl — нужны)
RUN rm -f /lib/systemd/system/multi-user.target.wants/* \
    /lib/systemd/system/local-fs.target.wants/* \
    /lib/systemd/system/sockets.target.wants/*udev* \
    /lib/systemd/system/sockets.target.wants/*initctl* \
    /lib/systemd/system/anaconda.target.wants/* \
    /lib/systemd/system/plymouth* \
    /lib/systemd/system/systemd-update-utmp*

# Включаем cron.service обратно (удалён вместе с multi-user.target.wants/*)
RUN systemctl enable cron.service

STOPSIGNAL SIGRTMIN+3

WORKDIR /opt/xcvm-install

# Скрипт автоматической установки (инлайн — отдельный файл не нужен)
RUN <<'INSTALL_SCRIPT' cat > /opt/xcvm-install/auto_install.sh
#!/bin/bash
# Автоматическая установка XC_VM в контейнере
# XC_VM.zip монтируется через volume из dist/XC_VM.zip
set -e

validate_port() {
    local port="$1"
    if [[ -z "$port" ]]; then
        return 0
    fi
    if [[ "$port" =~ ^[0-9]+$ ]] && (( port >= 1 && port <= 65535 )); then
        return 0
    fi
    echo "ERROR: invalid port '$port' (expected 1..65535)"
    exit 1
}

cd /opt/xcvm-install

# 1. Распаковываем
unzip -o XC_VM.zip

# 2. Подготавливаем ответы для интерактивного install
# Порты инсталлятора (внутри контейнера):
#   XCVM_INSTALL_HTTP_PORT / XCVM_INSTALL_HTTPS_PORT
# Если переменные пустые, используются дефолты install (80/443).
ROOT_PASSWORD_INPUT="${XCVM_INSTALL_DB_ROOT_PASSWORD:-}"
HTTP_PORT_INPUT="${XCVM_INSTALL_HTTP_PORT:-}"
HTTPS_PORT_INPUT="${XCVM_INSTALL_HTTPS_PORT:-}"
SYSCTL_INPUT="${XCVM_INSTALL_OVERWRITE_SYSCTL:-Y}"

validate_port "$HTTP_PORT_INPUT"
validate_port "$HTTPS_PORT_INPUT"

printf '%s\n%s\n%s\n%s\n' \
    "$ROOT_PASSWORD_INPUT" \
    "$HTTP_PORT_INPUT" \
    "$HTTPS_PORT_INPUT" \
    "$SYSCTL_INPUT" \
    | python3 install 2>&1 | tee /var/log/xcvm_install.log
INSTALL_SCRIPT
RUN chmod +x /opt/xcvm-install/auto_install.sh

# Точка входа — systemd (PID 1), установка запускается через: docker exec ... auto_install.sh
CMD ["/sbin/init"]
