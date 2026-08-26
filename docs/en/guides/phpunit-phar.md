# PHPUnit PHAR: Download and Run Tests

This guide shows how to run project tests with a fixed PHP binary:

- PHP binary: `/home/xc_vm/bin/php/bin/php` (the bundled PHP **on a VDS**)
- PHPUnit binary: local `tools/.bin/phpunit.phar`

> **Local vs VDS.** The commands here use the VDS bundled PHP. When running on your **own
> machine** (see [Development Workflow](dev-workflow.md)), use your local PHP 8.1 instead:
> `php tools/.bin/phpunit.phar -c tests/phpunit.xml.dist`. CI runs the same suite through the
> committed config, so a green local run should match CI.

## Why this setup

`phpunit.phar` does not include PHP. It always runs through a PHP interpreter.

If you run `./phpunit.phar` directly, it may use another `php` from `PATH`.
Use the explicit binary path to keep runtime consistent.

## Test layout & conventions

Tests live **outside** `src/`, under `tests/`:

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

Conventions: a test class is `<Thing>Test` in `tests/Unit/<Thing>Test.php`, extends
`PHPUnit\Framework\TestCase`. `bootstrap.php` wires the **Composer autoloader** (so
`XcVm\…` classes resolve — this needs `src/vendor/`, i.e. run `make dev-tools` first) and
defines the runtime constants tests rely on. A minimal test:

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

> If classes fail to load (`Class "XcVm\…" not found`), `src/vendor/` is missing the autoloader —
> run `make dev-tools`.

## 1. Check PHP

```bash
/home/xc_vm/bin/php/bin/php -v
```

## 2. Download PHPUnit PHAR

This project is pinned to PHP 8.1, so use **PHPUnit 10** (10.5). Do not fetch `phpunit-11.phar` — PHPUnit 11 requires PHP 8.2+ and will refuse to run on 8.1.

```bash
cd /home/xc_vm
mkdir -p tools/.bin
wget -O tools/.bin/phpunit.phar https://phar.phpunit.de/phpunit-10.phar
chmod +x tools/.bin/phpunit.phar
```

## 3. Verify PHPUnit

```bash
/home/xc_vm/bin/php/bin/php tools/.bin/phpunit.phar --version
```

## 4. Run all tests

Project config file:

- `tests/phpunit.xml.dist`

Run:

```bash
/home/xc_vm/bin/php/bin/php tools/.bin/phpunit.phar -c tests/phpunit.xml.dist
```

## 5. Run a single test file

```bash
/home/xc_vm/bin/php/bin/php tools/.bin/phpunit.phar -c tests/phpunit.xml.dist tests/Unit/GitHubReleasesTest.php
```

## 6. Show which test is running now

Use debug mode to print the currently executing test:

```bash
/home/xc_vm/bin/php/bin/php tools/.bin/phpunit.phar -c tests/phpunit.xml.dist --debug --no-progress
```

## 7. Optional: Coverage output

If `xdebug` or `pcov` is installed:

```bash
XDEBUG_MODE=coverage /home/xc_vm/bin/php/bin/php tools/.bin/phpunit.phar -c tests/phpunit.xml.dist --coverage-text
```

## Security note

Do not commit `phpunit.phar` into the repository. Keep it local (`tools/.bin`) and update independently.

## Related files

| File | Role |
| --- | --- |
| `tools/.bin/phpunit.phar` | Pinned PHPUnit binary |
| `tests/phpunit.xml.dist` | PHPUnit configuration |
| `tests/bootstrap.php` | Test bootstrap (Composer autoloader + constants) |
