# Модульные тесты (PHPUnit)

Модульный пакет - это PHPUnit 10.5 поверх PHP 8.1, минимального уровня PHP, который поддерживает панель. Все, что для этого нужно, находится в репозитории:

|Файл|Роль|
| --- | --- |
| `tests/phpunit.phar` |Закрепленный двоичный файл PHPUnit (зафиксирован; CI запускает этот файл)|
| `tests/phpunit.xml.dist` |Конфигурация: набор `XC_VM Unit`, `memory_limit=512M`|
| `tests/bootstrap.php` |Загружает `src/vendor/autoload.php` и помощники `tests/Support/`, определяет `MAIN_HOME`, `PHP_BIN` и пути, используемые тестами|
| `tests/Support/TestDb.php` |База данных двойная, на MariaDB/MySQL|
| `tests/Support/InstallSchema.php` |Рабочий DDL: таблицы схемы установки и миграции|
| `tests/Unit/` |Тесты, по одному `<Thing>Test.php` на единицу измерения|

Для запуска тестов достаточно зафиксированного значения `src/vendor/`; `make dev-tools` - только для PHPStan и phpcs. Не заменяйте PHAR на PHPUnit 11: для этого нужен PHP 8.2.

## 1. Запустите тесты

Вам нужен PHP 8.1 с `pdo_mysql` и сервер MariaDB/MySQL (раздел 2). На компьютере без сервера:

```bash
make test-db                                                         # MariaDB in Docker, once per boot
make test                                                             # the whole suite (the same as the next line)
php tests/phpunit.phar -c tests/phpunit.xml.dist                     # the whole suite
php tests/phpunit.phar -c tests/phpunit.xml.dist --filter SomeTest   # one class or method
php tests/phpunit.phar -c tests/phpunit.xml.dist --display-skipped   # with each skip's reason
php tests/phpunit.phar -c tests/phpunit.xml.dist --debug --no-progress  # name each test as it runs
```

Выполните `php` явно, а не `./tests/phpunit.phar`: PHAR не содержит интерпретатора и выберет любое значение `php`, которое будет первым в `PATH`. Для покрытия требуется pcov или Xdebug: `php tests/phpunit.phar -c tests/phpunit.xml.dist --coverage-text`.

Что пропускает машина разработчика и как это сделать:

- **Redis.** About 140 tests start a throwaway `redis-server` of their own and need it in `PATH` (with the `redis` and `igbinary` PHP extensions): `apt install redis-server`.
- **Корень.** Некоторые из них проверяют, что делает процесс от имени root (права root, программа обновления, права агента на доступ к файлам), и запускаются только от имени root, как это делается в тестовом контейнере.
- **Расширение и реальные компоненты.** Раздел 4.

`TimeUtilsTest` предполагается, что часовой пояс PHP по умолчанию - UTC, как на панельном хосте, так и в CI. Без `date.timezone` PHP использует системный пояс, поэтому на компьютере, настроенном на другую зону, запустите пакет с `TZ=UTC` (или `-d date.timezone=UTC`).

CI (`.github/workflows/ci.yml`, job `PHPUnit`) выполняет ту же команду в PHP 8.1 с помощью сервиса `mariadb:11.4`.

## 2. База данных

Тесты выполняются на MariaDB/MySQL, запускается производство движка; нет SQLite или резервной копии в памяти. `TestDb` предоставляет каждому экземпляру свою собственную пустую схему (`xcvm_t<pid>_<n>`), удаляемую вместе с ней, и запускает сеанс в режиме `sql_mode`, который настраивает установщик (`NO_ENGINE_SUBSTITUTION`), так что тест видит приведения и усечения, которые видит живая панель.

Соединение происходит из трех переменных окружения:

|Переменная|По умолчанию|Пример (CI)|
| --- | --- | --- |
| `XCVM_TEST_DB_DSN` |`mysql:unix_socket=/run/mysqld/mysqld.sock` или `mysql:host=127.0.0.1;port=3306` без этого сокета| `mysql:host=127.0.0.1;port=3306` |
| `XCVM_TEST_DB_USER` | `root` | `root` |
| `XCVM_TEST_DB_PASS` |*(нет)*| `xcvm-test` |

Значения по умолчанию работают как есть на панельном хосте (root через сокет) и при `make test-db`, который запускает `mariadb:11.4`, запускается версия CI, при `127.0.0.1:3306` с данными в tmpfs и root без пароля. `make test-db-stop` удаляет его.

Любой другой сервер работает до тех пор, пока пользователь может создавать и удалять схемы `xcvm\_t%`; это все, что касается запуска. При первом `TestDb` запуске удаляются схемы, оставшиеся после аварийного запуска.

## 3. Написание тестов

Тестовый класс имеет значение `<Thing>Test` в `tests/Unit/<Thing>Test.php` и расширяет `PHPUnit\Framework\TestCase`. Назовите его в честь тестируемого класса, укажите допустимые входные данные, недопустимые входные данные, крайние случаи и побочные эффекты и запишите все, что выводит код, чтобы результат выполнения оставался чистым.

### База данных

Добавьте `TestDb` к тестируемому коду с помощью `DatabaseFactory::set()` или `setDb()` seam класса и создайте в нем таблицы, необходимые для тестирования. Предпочитайте рабочий DDL таблицам, написанным от руки, чтобы тест соответствовал всем столбцам, ключам и типам, которые есть в реальной установке.:

```php
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Tests\Support\InstallSchema;

protected function setUp(): void {
    $this->rDb = new TestDb();
    $this->rDb->exec(InstallSchema::table('cluster_reservations'));   // from src/bin/install/database.sql
    $this->rDb->exec(InstallSchema::migration('029_create_cluster_nodes'));
    DatabaseFactory::set($this->rDb);
}

protected function tearDown(): void {
    DatabaseFactory::reset();
}
```

- Написанный от руки DDL - это MySQL DDL (`INTEGER PRIMARY KEY AUTO_INCREMENT`, зарезервированные слова в кавычках). Не сокращайте рабочий DDL: его ключи, длины и типы соответствуют требованиям теста.
- `get_rows()` возвращает строки с примененным экранированием `Database`, как это делает производственный процесс чтения. Двоичные столбцы (подписи, ключи) должны считываться с помощью `get_raw_rows()`, как это делает `CommandBus`: при экранировании байты перезаписываются.
- `information_schema`, `DATABASE()`, `JSON_CONTAINS()` а остальные принадлежат серверу; не подделывайте их.

### Пути

Доступ к коду осуществляется через `MAIN_HOME` (`MAIN_HOME . 'migrations/database/up/'`), а не через `dirname(__DIR__, 2) . '/src/'`. В репозитории `MAIN_HOME` - это `src/`; на панельном хосте это корень развертывания, который сам содержит `tests/`, и один и тот же тест работает в обоих случаях.

### Дочерние процессы

- Запускайте PHP как `PHP_BINARY`, а не как `php`: у узла панели нет `php` в `PATH`.
- `proc_open()` с помощью массива `$env` заменяет родительское окружение: добавьте `TestDb::env()`, когда дочерний элемент откроет свое собственное соединение, и `TestDb::connect($rSchema)` достигнет родительской схемы.
- Дочерний элемент, который подделывает `\XC_VM` в `auto_prepend_file`, не может начать с реального загруженного `xcvm_core` (класс будет существовать дважды). Создайте свою команду из `xcvm_test_child_php()` (`tests/bootstrap.php`), которая передаст ему загруженный `php.ini` минус `xcvm_core`.

### Redis и другие сервисы

Используйте логику, а не симулируйте `\XC_VM`: `RedisManager::useConnector(static fn() => null)` как "Redis не работает" (замените ее на `useConnector(null)`). Тест, для которого требуется реальное значение `redis-server`, начинается с `PATH` и пропускается без него.

### Группа `skip-on-panel`

Помечайте тест `#[Group('skip-on-panel')]`, если он не может быть запущен в корневом каталоге развертывания (раздел 5).:

- он считывает файлы репозитория, которые не отправляются из корневого каталога развертывания (`Makefile`, `lb_configs/`, `tools/`), или сканирует дерево исходных текстов;
- он выполняется в отдельном процессе (`#[RunInSeparateProcess]`, `#[RunTestsInSeparateProcesses]`).

## 4. Вступительные тесты

Некоторым тестам требуется реальный компонент или конкретная сборка, и они пропускаются без него. Они запускаются в наборе по умолчанию всякий раз, когда установлен их переключатель:

|Переключатель|Бежит|Ценность|
| --- | --- | --- |
| `XCVM_BENCH=1` |`ClusterCryptoBenchTest`: синхронизация криптографии с целями производственного оборудования|—|
| `XCVM_TEST_NGINX` | `ClusterNginxConfigTest::testRealNginx` |двоичный файл nginx, например `/home/xc_vm/bin/nginx/sbin/nginx`|
| `XCVM_TEST_FPM` | `ClusterPoolTest::testRealPhpFpm*` |двоичный файл php-fpm, например `/home/xc_vm/bin/php/sbin/php-fpm`|
| `XCVM_AGENT_BIN` | `LbProvisionClusterTest::testWithTheRealAgent` |двоичный файл `xc_agent`|
| `XCVM_TEST_NETNS=1` |`DbAllowlistTest::testInANetworkNamespace` (реальные iptables)|корень, `unshare` и `iptables`|
| `XCVM_TEST_NETNS=1` |`AuditRootCronFirewallFlushTest` тесты сетевого пространства имен (реальные iptables и ipset для наборов блоков)|корень, `unshare`, `iptables` и `ipset`|
| `XCVM_CONFIG_DIR` | `ClusterExtensionIntegrationTest` |пустая директория во временном каталоге; требуется загрузить тестовые перехватчики `xcvm_core`|
| `XCVM_EXT_SO` | `ClusterDrTest::testWithTheRealExtension` |путь к тестовым перехватчикам `xcvm_core.so`|
| `XCVM_CLUSTER_API_REAL=1` |`ClusterApiTest` вместо реального расширения вместо его поддельного (с `XCVM_CONFIG_DIR`)|—|

Тестовые перехватчики `xcvm_core` встроены в репозиторий расширения (`XC_VM_CoreExtention`) с помощью `make build-dev`, в зависимости от PHP, в который оно будет загружаться: его `php-config` и заголовки определяют ABI. Он принимает поддельную лицензию, поэтому никогда не поставляется.

В нескольких тестах описывается узел *без* `xcvm_core` или со старым узлом (`LicenseGateTest::testFanoutAllowedFailsOpenWithoutExtension`, `CorePinTest::testAnOldExtensionRefusesCleanly`, ...): они пропускают то место, где загружено расширение, а тесты расширения пропускают то, где его нет. Ни один интерпретатор не выполняет оба варианта; в разделе 6 показаны проходы, которые это делают.

## 5. Запуск на узле панели управления

На панельном хостинге пакет работает с производственным стеком: встроенным PHP, собственным `php.ini` (загрузчик ionCube, OPcache, `xcvm_core`) и MariaDB для панели. Запустите его из копии корневого каталога развертывания, то есть из `src/` с `tests/` внутри него, но никогда из самого `/home/xc_vm`: `MAIN_HOME` - это будет оперативная установка, и некоторые тесты используют файлы времени выполнения, которые они там находят (например, хранилище одноразовых данных кластерной шины). Сохраняйте копию доступной для чтения каждому пользователю (`/opt`, а не `/root`): часть тестируемого кода передается пользователю агента и все равно должна загружать его классы.

```bash
mkdir -p /opt/xcvm_flat
rsync -a /path/to/XC_VM/src/ /opt/xcvm_flat/
rsync -a /path/to/XC_VM/tests /opt/xcvm_flat/
cd /opt/xcvm_flat
/home/xc_vm/bin/php/bin/php tests/phpunit.phar -c tests/phpunit.xml.dist --exclude-group skip-on-panel
```

Группа `skip-on-panel` содержит то, что не может быть запущено в ней:

- **Проверка хранилища.** Корень развертывания не содержит `Makefile`, `lb_configs/` или `tools/` и смешивает дерево исходных текстов с `tests/`, `backups/` и `tmp/`.
- **Тесты, изолированные от процесса.** PHPUnit выполняет изолированный тест для своего дочернего PHP на stdin, и связанный с PHP segfaults скрипт, считанный из stdin (также на `php -l`): ionCube Loader передает дескриптор файла в OPcache (`opcache.enable_cli=1`), который завершает работу с ошибкой. Это не влияет на именованный файл сценария, который используется в любой производственной точке входа.

Если установщик установил пароль root, предоставьте тестам их собственного пользователя, а не root:

```sql
CREATE USER 'xcvm_test'@'localhost' IDENTIFIED BY 'xcvm-test';
GRANT ALL PRIVILEGES ON `xcvm\_t%`.* TO 'xcvm_test'@'localhost';
```

и запустите с `XCVM_TEST_DB_USER=xcvm_test XCVM_TEST_DB_PASS=xcvm-test`.

## 6. Каждый тест, ни один не пропущен

Полный запуск занимает несколько проходов, потому что для некоторых тестов требуется расширение, а для других - его отсутствие. На панельном хосте или в контейнере `xcvm-test-install`, с копией deploy-root в `/opt/xcvm_flat`, проверкой git в `/opt/xcvm_repo` и тестовыми перехватчиками `xcvm_core.so`, созданными для его PHP:

|Проходить|Дерево| `php.ini` |Переключатели|Выбор|
| --- | --- | --- | --- | --- |
|Производственный штабель|развернуть root|производство|`XCVM_TEST_NGINX`, `XCVM_TEST_FPM`, `XCVM_AGENT_BIN`, `XCVM_TEST_NETNS=1`| `--exclude-group skip-on-panel` |
|Без расширения|развернуть root|производство за вычетом линии `xcvm_core`|—|в тестах пропущено "этот PHP загружает xcvm_core..."|
|Реальное расширение|развернуть root|изготовление с использованием тестовых крючков `.so` вместо `xcvm_core.so`| `XCVM_CONFIG_DIR=$(mktemp -d)` | `--filter ClusterExtensionIntegrationTest` |
|Аварийное восстановление|развернуть root|производство за вычетом линии `xcvm_core`| `XCVM_EXT_SO=<test-hooks .so>` | `--filter ClusterDrTest` |
|Контрольные показатели|развернуть root|производство| `XCVM_BENCH=1` | `--filter ClusterCryptoBenchTest` |
|Хранилище и изоляция|проверка git|производство плюс `opcache.enable_cli=0`|—| `--group skip-on-panel` |

Укажите PHP на вариант с `PHPRC=<dir holding php.ini>`; дочерние процессы наследуют его, в то время как опция `-d` не будет доступна для них. Тесты сравниваются с производственным оборудованием, поэтому более медленная машина завершает их без регрессии. Для проверки хранилища требуются `make` и `git` в контейнере.

`xcvm-test-install` содержит сценарий, который выполняет все шесть проходов:

```bash
docker exec xcvm-test-install /opt/run-all-tests.sh
```
