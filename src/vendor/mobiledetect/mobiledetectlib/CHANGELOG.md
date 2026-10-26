# Change log

# 4.11.0

## Security
- [x] **GHSA-mgj4-qjmw-v56v** — the bundled `Detection\Cache\Cache` is now bounded (default 1000 entries, FIFO eviction). Prevents unbounded in-memory growth when one `MobileDetect` instance is reused across many distinct User-Agents in a long-running PHP runtime (RoadRunner, Laravel Octane, FrankenPHP worker mode, Swoole, ReactPHP, queue workers). **Not applicable** to classic PHP-FPM / mod_php deployments — the cache dies with the request. Custom PSR-16 adapters (Redis, APCu, Memcached, Filesystem) are out of scope; their eviction policy is the operator's responsibility.

## Added
- [x] `Detection\Cache\Cache::__construct(int $maxEntries = Cache::DEFAULT_MAX_ENTRIES)` — tune the in-memory cap via `new MobileDetect(new Cache($n))`.
- [x] `Cache::DEFAULT_MAX_ENTRIES` constant (1000) and `Cache::getMaxEntries()` accessor.

## Changed
- [x] `README-EXAMPLES.md` "Long-Running Processes" — worker example now uses `clear()` (was `evictExpired()`, which is a no-op against fresh entries under the default 86 400 s TTL); added explicit note framing in-memory cache bounding as a systems-level concern with the bundled cap, and pointing operators at their own adapter's eviction for custom PSR-16 backends.
- [x] `Cache::evictExpired()` docblock — clarified that it bounds by *expiration*, not by *cardinality*. Method behavior is unchanged.

# 4.10.0

## Changed
- [x] `Detection\Cache\Cache` method signatures widened to be Liskov-compatible with `psr/simple-cache` v1, v2, and v3 simultaneously. Resolves [#989](https://github.com/serbanghita/Mobile-Detect/issues/989) — the class no longer fatals at load time on hosts where another package has already registered an older `CacheInterface` (common in WordPress stacks).
- [x] `composer.json`: `psr/simple-cache` constraint widened to `^1.0 || ^2.0 || ^3.0`.
- [x] **Minimum PHP version raised to 8.2** in `composer.json` (was `>=8.0`). PHP 8.0 and 8.1 are both end-of-life and had already been dropped from CI in 4.9.0 because `phpbench/phpbench: 1.6.1` requires PHP ^8.2.

## Added
- [x] `psr16-compat` CI matrix that verifies Cache remains LSP-compatible with every supported major of `psr/simple-cache` (1.x, 2.x, 3.x).

## BC note
- Subclasses of `Detection\Cache\Cache` that overrode `get`/`set`/`has`/`delete`/`getMultiple`/`setMultiple`/`deleteMultiple` (or protected `checkKey`) with narrowed parameter types (e.g. `function get(string $key, …)`) will fatal at class load on this version. Drop the scalar type from the override, or widen to `mixed`, to restore LSP compatibility.

# 4.9.0

## Added
- [x] Lenovo: broad `Lenovo TB` prefix match for modern tablets (#1013).
- [x] Samsung: 2025 tablet models (Tab S11, S10 Lite, A11).
- [x] `MobileDetect::VERSION_TYPE_STRING` and `VERSION_TYPE_FLOAT` constants promoted to `public` (#991).

## Changed
- [x] Consistent late static binding for subclass extensibility (#1012).
- [x] Dropped PHP 8.0 and 8.1 from the CI matrix.

## Fixed
- [x] PHP 8.4 compatibility: explicit type hints where the engine now requires them.
- [x] `Cache::getTimestamp()` method name typo (was `getTimeStamp`) (#1007).
- [x] Version regex now accepts multi-char pre-release suffixes.
- [x] Pinned composer dependencies to exact versions.
- [x] GitHub Actions workflow actions updated to their latest versions.

# 4.8.10

## Fixed
- [x] `Cache::has()` now properly checks TTL expiration before returning `true` (PSR-16 compliance fix). Previously, `has()` returned `true` for expired items.

## Added
- [x] `Cache::evictExpired()` method to manually clean up expired cache entries. Useful for long-running processes (CLI scripts, workers, daemons) to prevent memory growth.
- [x] Expanded test coverage for `Cache` class: added 17 new tests covering all if/else branches including custom defaults, DateInterval TTL, key validation edge cases, and expiration scenarios.
- [x] `README-EXAMPLES.md` with comprehensive usage examples including long-running processes, framework integration, and debugging.

## Changed
- [x] `Cache::has()` now deletes expired items on check (lazy cleanup, consistent with `get()` behavior).

# 4.8.09

- [x] `sha1` is now the default fn for encoding cache keys. Using `base64` [was causing problems](https://github.com/serbanghita/Mobile-Detect/issues/974#issuecomment-2531597903) in Laravel.

# 4.8.08

- [x] fix for missing psr/cache prod dependency 
- [bug] latest 4.8.07 cause site error Call to a member function get() on false #974
- [x] fix for Docker build not installing dev dependencies

# 4.8.07 (broken in composer, please skip)

- [x] fix cache and generate short cache key (#971)
- [x] Added configuration cacheKeyFn which allows for using a custom cache key creation fn.
- [x] Use Client Hints Sec-CH-UA-Mobile header to detect mobile (#962)
- [x] added Huawei (phone, OS - HarmonyOS, browser) detection (#952)
- [x] Bugfix: Allow Injection of Any PSR Cache Interface (#966)
- [x] PHP 8.4 - implicit nulls are deprecated (#960)


# 4.8.03
- [x] added optional `$config` to MobileDetect constructor.
- [x] added `autoInitOfHttpHeaders` configuration which is by default `true`. This enabled the old behavior from `3.x` and `2.x` that allows automatic detection of HTTP headers and User Agent from $_SERVER.
- [x] refactored internal CloudFront related methods and the way `setHttpHeaders` work. It no longer falls back on `$_SERVER`. The method still calls `setUserAgent` in case `HTTP_USER_AGENT` and friends are present.
- [x] added `maximumUserAgentLength` to the `$config`, by default the limit is `500`.

# 4.8.02
- [x] new user agents
- [x] Samsung Galaxy Tab S6 Lite #919 
- [x] Samsung Galaxy Tab S8 series #912

# 4.8.01

- [x] PHP 8.x only.
- [x] PSR-16 cache support.
- [x] Constructor accepts `CacheFactory` class where you can inject your own PSR-6 Cache interfaces.
- [x] You need to explicitly `setUserAgent("...")` or `setUserAgentHeaders([...])` otherwise an exception is being thrown.
- [x] `scripts/` folder no longer included in the git tag release archive.
- [x] added performance tests
- [x] regexes can be arrays of strings or strings


# 2023

Launched 4.8.xx which contains PHP 8.x support, refactorings and external Cache support.

## 2022

In December 2022 we released the version for PHP7.
Mobile Detect was split into two dev branches: `2.8.x` which will support PHP5, but is deprecated and
`3.74.x` which supports PHP >= 7.3

## 2013

In August 2013 the library has 1800+ stargazers and support for: composer, PHPUnit tests, PSR standards and a new webpage http://mobiledetect.net

# 2012

Throughout 2012 the script has been updated constantly, and we have received tons of feedback and requests.
In July 2012 we moved the repository from Google Code to GitHub in order to quickly accommodate the frequent updates and to involve more people.

## 2011

In December 2011 it received a major update from the first version, an important number of issues were fixed, then 2.0 was launched. 
The new version marks a new mindset and also featuring tablet detection.

## 2009

The first version of the script was developed in 2009, and it was hosted at https://code.google.com/p/php-mobile-detect/, it was a small project with around 30 stars. 
(Original blog post by Victor: http://victorstanciu.ro/detectarea-platformelor-mobile-in-php/)
