# Parameter type-hint audit (cs-fix `null given` footgun)

`make cs-fix` (the Slevomat `ParameterTypeHint` fixer) once auto-inserted native
parameter types from `@param` docblocks across the codebase (commit `758a9cab`).
Many of those docblocks were wrong about nullability, so the added strict types
now **reject `null` the code actually receives at runtime** and fatal with:

```
TypeError: X::y(): Argument #N ($p) must be of type string, null given
```

`declare(strict_types)` does **not** help: passing `null` to a non-nullable
scalar param throws in coercive mode too, and `=1` only makes it stricter. The
only real fixes are: make the param nullable (`?string`), widen the type, or fix
the caller. See memory `reference_csfix_paramtypehint_footgun`.

## How to see the remaining (unverified) surface

Every file with a non-nullable scalar / `array` param is a potential crash site:

```bash
git grep -lP '\b(string|int|float|bool|array)\s+\$\w+(?=\s*[,\)])' -- 'src/**/*.php' \
  | grep -vE '/vendor/|/Infrastructure/Tmdb/lib/'
```

Baseline (this audit start): **237 files** — Core 83, Cli 64, Domain 37,
Public 29, Streaming 13, Infrastructure 10, Ministra 1.

## Process

Harden **per file**, highest-risk first (boot / cron / daemon paths in Core and
Cli that process nullable DB columns without user interaction). For each param
whose callers can pass `null`, make it nullable and correct its `@param`; when a
whole file's params are confirmed null-safe, tick it below. Do not leave any
defensive widening un-reviewed — the goal is correct nullability, not blanket
`?`. Consider disabling the `ParameterTypeHint` sniff in `build/phpcs.xml.dist`
while this is in progress so cs-fix does not re-add strict types.

## Verified / fixed

- `Core/Database/Database.php` — `normalizeHost(?string)`, constructor `?string`, `escape(?string)` + `(string)` cast.
- `Domain/Server/ServerRepository.php` — `getStreamingSimple/getProxySimple(?array $rPermissions)`.
- `Core/Process/ProcessManager.php` — all 9 pid checks `?int $pid` (+ `?string $exe`); guards already return for null.
- `Core/Storage/DropboxClient.php` — `GetFiles/Copy/Move` `string|object`, `GetMetadata(?string)`.
- `Domain/Bouquet/BouquetService.php` — `addItems/removeItems` accept `array|int|string`.
- `Domain/Epg/EpgService.php` — `searchRecursive` accepts `mixed`.
- `Core/Util/Encryption.php` — `generateUniqueCode(?string)`.

## Pending (high-risk first)

- [ ] Cli/CronJobs/* and Cli/Commands/* — daemon/cron paths reading nullable pid/DB columns
- [ ] Core/* remaining (Http, Cache, GeoIP, Proxy, Backup, Logging, …)
- [ ] Domain/* services
- [ ] Streaming/*, Infrastructure/*, Ministra/*
- [ ] Public/* (excluding public-page controllers per scope)
