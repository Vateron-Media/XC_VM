# Test Install Container

Тестовый Docker-контейнер Ubuntu 22.04 для проверки установки XC_VM из `dist/XC_VM.zip`.

## Требования

- Docker + Docker Compose
- Собранный `dist/XC_VM.zip` (через `make main`)

## Использование

```bash
# Собрать, запустить и установить (всё сразу)
./tools/test-install/test_release.sh

# Или по шагам:
./tools/test-install/test_release.sh build     # собрать образ
./tools/test-install/test_release.sh run       # запустить контейнер
./tools/test-install/test_release.sh install   # выполнить установку

# Войти в контейнер вручную
docker exec -it xcvm-test-install bash

# Посмотреть лог установки
./tools/test-install/test_release.sh logs

# Очистить
./tools/test-install/test_release.sh clean
```

## Что проверяется

Автоматический скрипт (`auto_install.sh`):

1. Распаковывает `XC_VM.zip`
2. Запускает `python3 install` с автоматическими ответами на интерактивные вопросы
3. Проверяет наличие ключевых файлов после установки:
   - `/home/xc_vm/status`, `console.php`, `autoload.php`, `bootstrap.php`
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

- Контейнер запускается с `--privileged` и systemd (PID 1) — необходимо для `systemctl`, `mount`, tmpfs
- sysctl НЕ перезаписывается (ответ N) — в контейнере это не работает
- MariaDB устанавливается и настраивается внутри контейнера
