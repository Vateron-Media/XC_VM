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

# Скрипт автоматической установки (подаёт ответы на интерактивные вопросы)
COPY auto_install.sh /opt/xcvm-install/auto_install.sh
RUN chmod +x /opt/xcvm-install/auto_install.sh

# Точка входа — systemd (PID 1), установка запускается вручную или через скрипт
CMD ["/sbin/init"]
