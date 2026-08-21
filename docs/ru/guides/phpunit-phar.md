# PHPUnit PHAR: Загрузка и запуск тестов

В этом руководстве показано, как запускать тесты проекта с фиксированным двоичным кодом PHP:

- PHP двоичный код: `/home/xc_vm/bin/php/bin/php`
- Двоичный файл PHPUnit: local `tools/.bin/phpunit.phar`

## Почему такая установка

`phpunit.phar` не включает PHP. Он всегда выполняется через интерпретатор PHP.

Если вы запустите `./phpunit.phar` напрямую, он может использовать другой `php` из `PATH`.
Используйте явный двоичный путь для обеспечения согласованности во время выполнения.

## 1. Проверить PHP

```bash
/home/xc_vm/bin/php/bin/php -v
```

## 2. Скачать PHPUnit PHAR

Этот проект привязан к PHP версии 8.1, поэтому используйте PHPUnit 10.

```bash
cd /home/xc_vm
mkdir -p tools/.bin
wget -O tools/.bin/phpunit.phar https://phar.phpunit.de/phpunit-10.phar
chmod +x tools/.bin/phpunit.phar
```

## 3. Проверьте PHPUnit

```bash
/home/xc_vm/bin/php/bin/php tools/.bin/phpunit.phar --version
```

## 4. Выполните все тесты

Конфигурационный файл проекта:

- `tests/phpunit.xml.dist`

Бежать:

```bash
/home/xc_vm/bin/php/bin/php tools/.bin/phpunit.phar -c tests/phpunit.xml.dist
```

## 5. Запустите один тестовый файл

```bash
/home/xc_vm/bin/php/bin/php tools/.bin/phpunit.phar -c tests/phpunit.xml.dist tests/Unit/GitHubReleasesTest.php
```

## 6. Показать, какой тест выполняется сейчас

Используйте режим отладки для печати текущего теста:

```bash
/home/xc_vm/bin/php/bin/php tools/.bin/phpunit.phar -c tests/phpunit.xml.dist --debug --no-progress
```

## 7. Дополнительно: Выход покрытия

Если установлено значение `xdebug` или `pcov`:

```bash
XDEBUG_MODE=coverage /home/xc_vm/bin/php/bin/php tools/.bin/phpunit.phar -c tests/phpunit.xml.dist --coverage-text
```

## Защитная записка

Не фиксируйте `phpunit.phar` в репозитории. Сохраняйте его локальным (`tools/.bin`) и обновляйте независимо.

## Связанные файлы

|Файл|Роль|
| --- | --- |
| `tools/.bin/phpunit.phar` |Закрепленный двоичный файл PHPUnit|
| `tests/phpunit.xml.dist` |Конфигурация PHPUnit|
| `tests/bootstrap.php` |Тестовый bootstrap (Composer автозагрузчик + константы)|
