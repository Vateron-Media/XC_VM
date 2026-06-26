# CLAUDE.md

Whenever you find a new rule that applies to this project, add it to this file.

## Project

PHP library (`Detection\MobileDetect`) for detecting mobile devices/tablets via User-Agent strings and HTTP headers. Requires PHP >= 8.0. PSR-12 code style.

## Branch & Release

- Active branch: `4.8.x`. Always rebase into `4.8.x` (not `main` or `master`).
- On new tag: update `@version` docblock + `$VERSION` property in `src/MobileDetect.php`, and `version` in `MobileDetect.json`.

## Commands

```bash
# Tests
vendor/bin/phpunit -v -c tests/phpunit.xml
vendor/bin/phpunit -v -c tests/phpunit.xml --filter testMethodName

# Lint + static analysis
vendor/bin/phpcs
vendor/bin/php-cs-fixer fix
vendor/bin/phpstan analyse --memory-limit=1G --level 3 src tests

# Benchmark
vendor/bin/phpbench run tests/benchmark/MobileDetectBench.php --retry-threshold=1 --iterations=10 --revs=1000 --report=aggregate --tag=baseline
vendor/bin/phpbench run tests/benchmark/MobileDetectBench.php --ref=baseline --retry-threshold=1 --iterations=10 --revs=1000 --report=aggregate
```

## Architecture

```
src/
  MobileDetect.php          # Core class: regex patterns, isMobile(), isTablet(), is(), version(), magic isXXXX() via __call
  MobileDetectStandalone.php # Non-Composer wrapper (loads standalone/ autoloader)
  Cache/Cache.php            # In-memory PSR-16 cache with TTL
  Exception/                 # MobileDetectException + error codes
tests/
  providers/vendors/*.php    # UA fixture arrays per vendor (Apple, Samsung, etc.)
  UserAgentTest.php          # Data-driven tests from vendor fixtures
  MobileDetectGeneralTest.php # Core logic tests
  CacheTest.php, MobileDetectWithCacheTest.php, MobileDetectExceptionTest.php
  benchmark/MobileDetectBench.php
```

## Key Patterns

- Detection results are cached via PSR-16 (`CacheInterface`). Default: in-memory `Cache`. Cache keys use `sha1` by default.
- `$_SERVER` HTTP headers auto-initialized unless `autoInitOfHttpHeaders` config is `false`.
- CloudFront headers (`HTTP_CLOUDFRONT_IS_MOBILE_VIEWER`, etc.) are recognized for AWS detection.
- Magic `isXXXX()` calls dispatch through `__call` -> `is()` -> `match()` against static regex arrays (`$phoneDevices`, `$tabletDevices`, `$operatingSystems`, `$browsers`).
