# Test Install Container

Тестовый Docker-контейнер Ubuntu 24.04 для проверки установки XC_VM из `dist/XC_VM.zip`.

## Требования

- Docker + Docker Compose
- Собранный `dist/XC_VM.zip` (через `make main`)

## Структура

```
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

| Host  | Container | Назначение |
|-------|-----------|-----------|
| 8880  | 80        | HTTP      |
| 8443  | 443       | HTTPS     |

## Примечания

- Контейнер запускается с `--privileged` и systemd (PID 1) — необходимо для `systemctl`
- MariaDB устанавливается и настраивается внутри контейнера
