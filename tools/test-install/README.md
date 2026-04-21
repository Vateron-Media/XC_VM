# Test Install Container

Тестовый Docker-контейнер Ubuntu 24.04 для проверки установки XC_VM из `dist/XC_VM.zip`.

## Требования

- Docker + Docker Compose
- Собранный `dist/XC_VM.zip` (через `make main`)

## Структура

```text
tools/test-install/
├── Dockerfile          # образ Ubuntu 24.04 + systemd + встроенный install-скрипт
├── docker-compose.yml  # runtime-конфиг (volumes, ports, privileged, cgroup)
├── test_release.sh     # управляющий скрипт (install / clean / logs / sync)
└── README.md
```

## Использование

```bash
# Собрать образ, запустить контейнер и выполнить установку (всё сразу)
./tools/test-install/test_release.sh

# Запустить на нестандартном host-порту (пример: HTTP 18080, HTTPS 18443)
XCVM_HTTP_PORT=18080 XCVM_HTTPS_PORT=18443 ./tools/test-install/test_release.sh

# Задать нестандартные внутренние порты панели (те, что пишет install в nginx)
XCVM_INSTALL_HTTP_PORT=8081 XCVM_INSTALL_HTTPS_PORT=4443 ./tools/test-install/test_release.sh

# Комбинированно: внешние и внутренние порты одновременно
XCVM_HTTP_PORT=18080 XCVM_HTTPS_PORT=18443 XCVM_INSTALL_HTTP_PORT=8081 XCVM_INSTALL_HTTPS_PORT=4443 ./tools/test-install/test_release.sh

# Удалить контейнер и образ
./tools/test-install/test_release.sh clean

# Посмотреть лог установки
./tools/test-install/test_release.sh logs

# Синхронизировать src/ в работающий контейнер
./tools/test-install/test_release.sh sync

# Войти в контейнер вручную
docker exec -it xcvm-test-install bash
```

## Что проверяется

Встроенный в Dockerfile install-скрипт:

1. Распаковывает `XC_VM.zip`
2. Запускает `python3 install` с автоматическими ответами на интерактивные вопросы
3. Проверяет наличие ключевых файлов после установки:
   - `/home/xc_vm/console.php`, `autoload.php`, `bootstrap.php`
   - `/home/xc_vm/config/config.ini`
   - `/home/xc_vm/bin/nginx/sbin/nginx`
   - `/home/xc_vm/bin/php/bin/php`
   - `/home/xc_vm/bin/redis/redis-server`

## Порты

- `XCVM_HTTP_PORT` (по умолчанию `8880`) → container `XCVM_INSTALL_HTTP_PORT` (или `80`)
- `XCVM_HTTPS_PORT` (по умолчанию `8443`) → container `XCVM_INSTALL_HTTPS_PORT` (или `443`)

Внутренние порты, которые настраивает `install` в контейнере:

- `XCVM_INSTALL_HTTP_PORT` (по умолчанию пусто → `80`)
- `XCVM_INSTALL_HTTPS_PORT` (по умолчанию пусто → `443`)

Важно: маппинг target-порта теперь синхронизирован автоматически с `XCVM_INSTALL_HTTP_PORT`/`XCVM_INSTALL_HTTPS_PORT`.

## Примечания

- Контейнер запускается с `--privileged` и systemd (PID 1) — необходимо для `systemctl`
- MariaDB устанавливается и настраивается внутри контейнера
