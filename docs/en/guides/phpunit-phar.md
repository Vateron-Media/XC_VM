# PHPUnit PHAR: Download and Run Tests

This guide shows how to run project tests with a fixed PHP binary:

- PHP binary: `/home/xc_vm/bin/php/bin/php`
- PHPUnit binary: local `tools/.bin/phpunit.phar`

## Why this setup

`phpunit.phar` does not include PHP. It always runs through a PHP interpreter.

If you run `./phpunit.phar` directly, it may use another `php` from `PATH`.
Use the explicit binary path to keep runtime consistent.

## 1. Check PHP

```bash
/home/xc_vm/bin/php/bin/php -v
```

## 2. Download PHPUnit PHAR

This project is pinned to PHP 8.1, so use PHPUnit 10.

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
