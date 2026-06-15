# PHPUnit PHAR: скачивание и запуск тестов

Эта инструкция показывает, как запускать тесты проекта через фиксированный PHP-бинарь:

- PHP-бинарь: `/home/xc_vm/bin/php/bin/php`
- PHPUnit-бинарь: локальный `tools/.bin/phpunit.phar`

## Почему так

`phpunit.phar` не содержит PHP внутри и всегда запускается через интерпретатор PHP.

Если запускать `./phpunit.phar` напрямую, может использоваться другой `php` из `PATH`.
Чтобы исключить ошибки окружения, используйте явный путь к бинарю.

## 1. Проверить PHP

```bash
/home/xc_vm/bin/php/bin/php -v
```

## 2. Скачать PHPUnit PHAR

Проект использует PHP 8.1, поэтому используйте PHPUnit 10.

```bash
cd /home/xc_vm
mkdir -p tools/.bin
wget -O tools/.bin/phpunit.phar https://phar.phpunit.de/phpunit-10.phar
chmod +x tools/.bin/phpunit.phar
```

## 3. Проверить запуск PHPUnit

```bash
/home/xc_vm/bin/php/bin/php tools/.bin/phpunit.phar --version
```

## 4. Запустить все тесты

Конфиг проекта:

- `tests/phpunit.xml.dist`

Запуск:

```bash
/home/xc_vm/bin/php/bin/php tools/.bin/phpunit.phar -c tests/phpunit.xml.dist
```

## 5. Запустить один тестовый файл

```bash
/home/xc_vm/bin/php/bin/php tools/.bin/phpunit.phar -c tests/phpunit.xml.dist tests/Unit/GitHubReleasesTest.php
```

## 6. Показать, какой тест выполняется сейчас

Для подробного вывода текущего выполняемого теста используйте режим отладки:

```bash
/home/xc_vm/bin/php/bin/php tools/.bin/phpunit.phar -c tests/phpunit.xml.dist --debug --no-progress
```

## 7. Опционально: вывод покрытия

Если установлен `xdebug` или `pcov`:

```bash
XDEBUG_MODE=coverage /home/xc_vm/bin/php/bin/php tools/.bin/phpunit.phar -c tests/phpunit.xml.dist --coverage-text
```

## Заметка по безопасности

Не храните `phpunit.phar` в git-репозитории. Оставляйте его локально (`tools/.bin`) и обновляйте отдельно.
