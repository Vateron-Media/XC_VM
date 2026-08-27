# PHPUnit PHAR: Загрузка и запуск тестов

В этом руководстве показано, как запускать тесты проекта с фиксированным двоичным кодом PHP:

- PHP двоичный файл: `/home/xc_vm/bin/php/bin/php` (связанный PHP **на VDS**)
- Двоичный файл PHPUnit: local `tools/.bin/phpunit.phar`

> **Local vs VDS.** The commands here use the VDS bundled PHP. When running on your **own
> компьютер** (см. [Рабочий процесс разработки](dev-workflow.md)), вместо этого используйте свой локальный PHP 8.1:
> `php tools/.bin/phpunit.phar -c tests/phpunit.xml.dist`. CI запускает тот же набор данных через
> зафиксированная конфигурация, поэтому зеленый локальный запуск должен соответствовать CI.

## Почему такая установка

`phpunit.phar` не включает PHP. Он всегда выполняется через интерпретатор PHP.

Если вы запустите `./phpunit.phar` напрямую, он может использовать другой `php` из `PATH`.
Используйте явный двоичный путь для обеспечения согласованности во время выполнения.

## Схема тестирования и соглашения

Тесты проходят в режиме реального времени **снаружи** `src/`, в режиме `tests/`:

```text
tests/
├── phpunit.xml.dist        # config: suite "XC_VM Unit", bootstrap=bootstrap.php (PHPUnit 10.5)
├── bootstrap.php           # locates vendor/autoload.php + defines constants (MAIN_HOME, PHP_BIN, paths)
├── Support/                # test helpers (e.g. TestDb.php)
└── Unit/                   # the "XC_VM Unit" suite — one *Test.php per unit
    ├── CoreEnumTest.php
    ├── EventDispatcherTest.php
    └── ...
```

Условные обозначения: тестовый класс равен `<Thing>Test` в `tests/Unit/<Thing>Test.php`, расширяет
`PHPUnit\Framework\TestCase`. `bootstrap.php` подключает **Composer автозагрузчик** (таким образом
`XcVm\…` классы разрешают — для этого требуется `src/vendor/`, т.е. сначала запустить `make dev-tools`) и
определяет константы среды выполнения, на которые опираются тесты. Минимальный тест:

```php
<?php
namespace XcVm\Tests\Unit;

use PHPUnit\Framework\TestCase;
use XcVm\Core\Enum\BootContext;

final class MyThingTest extends TestCase
{
    public function testItWorks(): void
    {
        self::assertSame('admin', BootContext::Admin->value);
    }
}
```

> Если классы не загружаются (`Class "XcVm\…" not found`), то в `src/vendor/` отсутствует автозагрузчик —
> запустите `make dev-tools`.

## 1. Проверить PHP

```bash
/home/xc_vm/bin/php/bin/php -v
```

## 2. Скачать PHPUnit PHAR

Этот проект привязан к PHP 8.1, поэтому используйте **Модуль PHP 10** (10.5). Не извлекайте `phpunit-11.phar` — для PHPUnit 11 требуется PHP 8.2+, и он откажется запускаться на 8.1.

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
