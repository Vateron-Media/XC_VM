# Unit Tests (PHPUnit)

The unit suite is PHPUnit 10.5 over PHP 8.1, the minimum PHP the panel supports. Everything it needs is in the repository:

| File | Role |
| --- | --- |
| `tests/phpunit.phar` | The pinned PHPUnit binary (committed; CI runs this one) |
| `tests/phpunit.xml.dist` | Configuration: suite `XC_VM Unit`, `memory_limit=512M` |
| `tests/bootstrap.php` | Loads `src/vendor/autoload.php` and the `tests/Support/` helpers, defines `MAIN_HOME`, `PHP_BIN` and the paths tests use |
| `tests/Support/TestDb.php` | The database double, on MariaDB/MySQL |
| `tests/Support/InstallSchema.php` | Production DDL: the install schema's tables and the migrations |
| `tests/Unit/` | The tests, one `<Thing>Test.php` per unit |

The committed production `src/vendor/` is enough to run the tests; `make dev-tools` is only for PHPStan and phpcs. Do not swap the PHAR for PHPUnit 11: it needs PHP 8.2.

## 1. Run the tests

You need PHP 8.1 with `pdo_mysql`, and a MariaDB/MySQL server (section 2). On a machine without one:

```bash
make test-db                                                         # MariaDB in Docker, once per boot
make test                                                             # the whole suite (the same as the next line)
php tests/phpunit.phar -c tests/phpunit.xml.dist                     # the whole suite
php tests/phpunit.phar -c tests/phpunit.xml.dist --filter SomeTest   # one class or method
php tests/phpunit.phar -c tests/phpunit.xml.dist --display-skipped   # with each skip's reason
php tests/phpunit.phar -c tests/phpunit.xml.dist --debug --no-progress  # name each test as it runs
```

Run `php` explicitly rather than `./tests/phpunit.phar`: the PHAR carries no interpreter and would pick whatever `php` comes first in `PATH`. Coverage needs pcov or Xdebug: `php tests/phpunit.phar -c tests/phpunit.xml.dist --coverage-text`.

What a dev machine skips, and how to bring it in:

- **Redis.** About 140 tests start a throwaway `redis-server` of their own and need it in `PATH` (with the `redis` and `igbinary` PHP extensions): `apt install redis-server`.
- **Root.** A handful check what a process does as root (the root crons, the updater, the agent's file rights) and run only as root, as they do in the test container.
- **The extension and real components.** Section 4.

`TimeUtilsTest` assumes PHP's default time zone is UTC, as on a panel host and in CI. Without `date.timezone` PHP takes the system's zone, so on a machine set to another zone run the suite with `TZ=UTC` (or `-d date.timezone=UTC`).

CI (`.github/workflows/ci.yml`, job `PHPUnit`) runs the same command on PHP 8.1 with a `mariadb:11.4` service.

## 2. Database

The tests run on MariaDB/MySQL, the engine production runs; there is no SQLite or in-memory fallback. `TestDb` gives every instance an empty schema of its own (`xcvm_t<pid>_<n>`), dropped with it, and runs the session in the `sql_mode` the installer configures (`NO_ENGINE_SUBSTITUTION`), so a test sees the coercions and truncations a live panel sees.

The connection comes from three environment variables:

| Variable | Default | Example (CI) |
| --- | --- | --- |
| `XCVM_TEST_DB_DSN` | `mysql:unix_socket=/run/mysqld/mysqld.sock`, or `mysql:host=127.0.0.1;port=3306` without that socket | `mysql:host=127.0.0.1;port=3306` |
| `XCVM_TEST_DB_USER` | `root` | `root` |
| `XCVM_TEST_DB_PASS` | *(none)* | `xcvm-test` |

The defaults work as-is on a panel host (root over the socket) and with `make test-db`, which starts `mariadb:11.4`, the version CI runs, on `127.0.0.1:3306` with its data in tmpfs and root without a password. `make test-db-stop` removes it.

Any other server works as long as the user may create and drop the `xcvm\_t%` schemas; that is all a run touches. The first `TestDb` of a run drops the schemas a crashed run left behind.

## 3. Writing tests

A test class is `<Thing>Test` in `tests/Unit/<Thing>Test.php` and extends `PHPUnit\Framework\TestCase`. Name it after the class under test, cover valid input, invalid input, edge cases and side effects, and capture anything the code prints so the run's output stays clean.

### The database

Hand a `TestDb` to the code under test through `DatabaseFactory::set()` or the class's `setDb()` seam, and build the tables a test needs in it. Prefer production DDL to hand-written tables, so a test meets every column, key and type a real install has:

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

- Hand-written DDL is MySQL DDL (`INTEGER PRIMARY KEY AUTO_INCREMENT`, back-quoted reserved words). Do not trim production DDL down: its keys, lengths and types are what the test is there to meet.
- `get_rows()` returns rows with `Database`'s escaping applied, as production's reads do. Binary columns (signatures, keys) must be read with `get_raw_rows()`, as `CommandBus` does: the escaping rewrites bytes.
- `information_schema`, `DATABASE()`, `JSON_CONTAINS()` and the rest are the server's own; do not fake them.

### Paths

Reach the code through `MAIN_HOME` (`MAIN_HOME . 'migrations/database/up/'`), never through `dirname(__DIR__, 2) . '/src/'`. In the repository `MAIN_HOME` is `src/`; on a panel host it is the deploy root, which holds `tests/` itself, and the same test then works in both.

### Child processes

- Start PHP as `PHP_BINARY`, not `php`: a panel host has no `php` in `PATH`.
- `proc_open()` with an `$env` array replaces the parent's environment: add `TestDb::env()` when the child opens its own connection, and `TestDb::connect($rSchema)` there reaches the parent's schema.
- A child that fakes `\XC_VM` in an `auto_prepend_file` cannot start with the real `xcvm_core` loaded (the class would exist twice). Build its command from `xcvm_test_child_php()` (`tests/bootstrap.php`), which hands it the loaded `php.ini` minus `xcvm_core`.

### Redis and other services

Use the seams rather than faking `\XC_VM`: `RedisManager::useConnector(static fn() => null)` for "Redis is down" (reset it with `useConnector(null)`). A test that needs a real `redis-server` starts its own from `PATH` and skips without one.

### The `skip-on-panel` group

Tag a test `#[Group('skip-on-panel')]` when it cannot run in a deploy root (section 5):

- it reads repository files a deploy root does not ship (`Makefile`, `lb_configs/`, `tools/`), or scans the source tree;
- it runs in a separate process (`#[RunInSeparateProcess]`, `#[RunTestsInSeparateProcesses]`).

## 4. Opt-in tests

Some tests need a real component or a particular build and skip without it. They run in the default suite whenever their switch is set:

| Switch | Runs | Value |
| --- | --- | --- |
| `XCVM_BENCH=1` | `ClusterCryptoBenchTest`: crypto timing against the production-hardware targets | — |
| `XCVM_TEST_NGINX` | `ClusterNginxConfigTest::testRealNginx` | an nginx binary, e.g. `/home/xc_vm/bin/nginx/sbin/nginx` |
| `XCVM_TEST_FPM` | `ClusterPoolTest::testRealPhpFpm*` | a php-fpm binary, e.g. `/home/xc_vm/bin/php/sbin/php-fpm` |
| `XCVM_AGENT_BIN` | `LbProvisionClusterTest::testWithTheRealAgent` | an `xc_agent` binary |
| `XCVM_TEST_NETNS=1` | `DbAllowlistTest::testInANetworkNamespace` (real iptables) | root, `unshare` and `iptables` |
| `XCVM_TEST_NETNS=1` | `AuditRootCronFirewallFlushTest`'s network-namespace tests (real iptables, and ipset for the blocks' sets) | root, `unshare`, `iptables` and `ipset` |
| `XCVM_CONFIG_DIR` | `ClusterExtensionIntegrationTest` | an empty directory under the temp dir; needs a test-hooks `xcvm_core` loaded |
| `XCVM_EXT_SO` | `ClusterDrTest::testWithTheRealExtension` | the path of a test-hooks `xcvm_core.so` |
| `XCVM_CLUSTER_API_REAL=1` | `ClusterApiTest` against the real extension instead of its fake (with `XCVM_CONFIG_DIR`) | — |

A test-hooks `xcvm_core` is built in the extension's repository (`XC_VM_CoreExtention`) with `make build-dev`, against the PHP it will load into: its `php-config` and headers decide the ABI. It accepts a fake licence, so it never ships.

A few tests describe a node *without* `xcvm_core` or with an old one (`LicenseGateTest::testFanoutAllowedFailsOpenWithoutExtension`, `CorePinTest::testAnOldExtensionRefusesCleanly`, …): they skip where the extension is loaded, and the extension tests skip where it is not. No single interpreter runs both; section 6 shows the passes that do.

## 5. Run on a panel host

On a panel host the suite runs with the production stack: the bundled PHP, its own `php.ini` (ionCube Loader, OPcache, `xcvm_core`) and the panel's MariaDB. Run it from a copy of the deploy root, that is `src/` with `tests/` inside it, never from `/home/xc_vm` itself: `MAIN_HOME` would be the live install, and some tests use the runtime files they find there (the cluster bus's nonce store, for one). Keep the copy readable by every user (`/opt`, not `/root`): some code under test drops to the agent's user and must still load its classes.

```bash
mkdir -p /opt/xcvm_flat
rsync -a /path/to/XC_VM/src/ /opt/xcvm_flat/
rsync -a /path/to/XC_VM/tests /opt/xcvm_flat/
cd /opt/xcvm_flat
/home/xc_vm/bin/php/bin/php tests/phpunit.phar -c tests/phpunit.xml.dist --exclude-group skip-on-panel
```

The `skip-on-panel` group holds what cannot run there:

- **Repository checks.** A deploy root ships no `Makefile`, `lb_configs/` or `tools/`, and mixes the source tree with `tests/`, `backups/` and `tmp/`.
- **Process-isolated tests.** PHPUnit hands an isolated test to its child PHP on stdin, and the bundled PHP segfaults on a script read from stdin (also on `php -l`): ionCube Loader passes the file handle on to OPcache (`opcache.enable_cli=1`), which crashes on it. A named script file, as every production entry point uses, is not affected.

If the installer set a root password, give the tests their own user rather than root:

```sql
CREATE USER 'xcvm_test'@'localhost' IDENTIFIED BY 'xcvm-test';
GRANT ALL PRIVILEGES ON `xcvm\_t%`.* TO 'xcvm_test'@'localhost';
```

and run with `XCVM_TEST_DB_USER=xcvm_test XCVM_TEST_DB_PASS=xcvm-test`.

## 6. Every test, none skipped

A complete run takes several passes, because some tests need the extension and others need it absent. On a panel host or the `xcvm-test-install` container, with the deploy-root copy in `/opt/xcvm_flat`, a git checkout in `/opt/xcvm_repo` and a test-hooks `xcvm_core.so` built for its PHP:

| Pass | Tree | `php.ini` | Switches | Selection |
| --- | --- | --- | --- | --- |
| Production stack | deploy root | production | `XCVM_TEST_NGINX`, `XCVM_TEST_FPM`, `XCVM_AGENT_BIN`, `XCVM_TEST_NETNS=1` | `--exclude-group skip-on-panel` |
| Without the extension | deploy root | production minus the `xcvm_core` line | — | the tests skipped "this PHP loads an xcvm_core…" |
| Real extension | deploy root | production with the test-hooks `.so` in place of `xcvm_core.so` | `XCVM_CONFIG_DIR=$(mktemp -d)` | `--filter ClusterExtensionIntegrationTest` |
| Disaster recovery | deploy root | production minus the `xcvm_core` line | `XCVM_EXT_SO=<test-hooks .so>` | `--filter ClusterDrTest` |
| Benchmarks | deploy root | production | `XCVM_BENCH=1` | `--filter ClusterCryptoBenchTest` |
| Repository and isolation | git checkout | production plus `opcache.enable_cli=0` | — | `--group skip-on-panel` |

Point PHP at a variant with `PHPRC=<dir holding php.ini>`; child processes inherit it, where a `-d` option would not reach them. The benchmarks compare against production hardware, so a slower machine fails them without a regression. The repository checks need `make` and `git` in the container.

`xcvm-test-install` carries a script that runs all six passes:

```bash
docker exec xcvm-test-install /opt/run-all-tests.sh
```
