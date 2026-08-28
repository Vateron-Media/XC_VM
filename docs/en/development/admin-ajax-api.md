# Admin AJAX API (`?action=`)

The admin panel's non-page JSON endpoints are reached as `./api?action=<name>`
(the front controller's `api` page). Every action is handled by a dedicated
PSR-4 controller under `XcVm\Public\Controllers\Admin\Ajax`, registered as an API
route in `src/Public/routes/admin.php` and dispatched by
`Router::dispatchApi()`.

> These endpoints replaced the legacy `src/Public/Views/admin/api.php` — a single
> ~4985-line flat chain of `if (action == 'x') { … exit(); }` blocks. It was
> extracted action-by-action into the controllers below and retired; only an
> unknown or removed action still reaches the thin `AjaxController` fallback.

---

## Dispatch order

`src/Public/index.php` runs API dispatch **before** page dispatch for the `api`
page, because the legacy `AjaxController` page handler exits internally:

```text
./api?action=search
  -> Router::dispatchApi('search')      # registered Admin\Ajax controller — wins
       (falls through only if no api route matches)
  -> Router::dispatch('api')            # AjaxController fallback -> {"result":false}
```

A registered action never reaches the fallback; an unregistered one does, and the
fallback answers `{"result":false}` (guarded to AJAX-only, like the actions
themselves). Admin authentication is already enforced by
`AdminScopeBootstrap::boot()` before any of this runs.

Registration looks like:

```php
// src/Public/routes/admin.php
$router->api('search', [SearchAjaxController::class, 'search']);
$router->api('regenerate_cache', [CacheAjaxController::class, 'regenerate']);
```

---

## `BaseAjaxController`

File: `src/Public/Controllers/Admin/Ajax/BaseAjaxController.php`

An `abstract` base that emits JSON only (no layout/templates), so it does **not**
extend `BaseAdminController`. It provides the scaffolding every action reuses:

| Method | Purpose |
| --- | --- |
| `ok(array $extra = [])` | Emit `{"result":true}` (+ extra keys) and end the request |
| `fail(array $extra = [])` | Emit `{"result":false}` (+ extra keys) and end the request |
| `gate(string $type, string $key)` | `Authorization::check()` gate; on failure emits `{"result":false}` and stops |
| `gateAny(array $checks)` | OR-gate: passes if any `[type, key]` check succeeds, else fails |
| `requireXhr()` | Reject non-AJAX requests unless debug mode (`PHP_ERRORS`) is on |
| `json(array $data, int $flags = 0)` | Raw JSON body with the correct `Content-Type`, then exit |

A typical action collapses the legacy `check → … → echo json_encode(); exit;`
idiom into a few readable lines:

```php
public function regenerate(): never {
    $this->gate('adv', 'manage_streams');
    // … call a domain service …
    $this->ok();
}
```

### Shared line/device state — `LineStateTrait`

`src/Public/Controllers/Admin/Ajax/LineStateTrait.php` carries the enable /
disable / ban / unban / kill logic shared by the line, MAG and Enigma2 device
controllers. It is a trait (not a base class) because those controllers already
extend `BaseAjaxController`; it declares `@phpstan-require-extends
BaseAjaxController` and abstract `ok()`/`fail()` stubs so static analysis and the
IDE resolve the inherited helpers.

---

## The controllers

Each controller groups a cohesive set of actions (its class docblock lists them):

| Controller | Area |
| --- | --- |
| `CacheAjaxController` | Cache regenerate/enable/disable, Redis clear, handlers |
| `ServerAjaxController` | Server add/edit/delete and ops |
| `StreamAjaxController` / `StreamToolsAjaxController` | Stream start/stop/restart/purge, lists, reviews |
| `PackageAjaxController` | Packages/bouquets |
| `UserAjaxController` | Users, lines, resellers |
| `DeviceAjaxController` | MAG / Enigma2 devices |
| `EpgAjaxController` | EPG sources and mappings |
| `StatsAjaxController` | Stats and graphs |
| `BlocklistAjaxController` | Blocklists / security |
| `BackupAjaxController` | Backups, logs, reports |
| `ProviderAjaxController` | Provider (DataTables) endpoints |
| `MultiAjaxController` | Bulk (`multi`) actions over selected IDs |
| `SearchAjaxController` | Global fuzzy search (see below) |
| `MiscAjaxController` | Remaining small actions |

---

## Global search — structured JSON contract

`SearchAjaxController::search()` (`?action=search`) is a fuzzy full-text search
across lines, MAG/Enigma2 devices, users, streams (live/VOD/created
channels/radio/episodes) and series. It returns **structured data**, not
server-rendered HTML: the client renders each result into a card. Permission
checks, status resolution and category/server lookups stay server-side; only
markup lives in the browser.

### Envelope

```jsonc
{ "result": true, "total_count": 12, "items": [ Item, … ] }
```

An empty search returns a single `no_results` item for parity with the styled
Select2 dropdown.

### Item

```jsonc
{
  "id":     "streams#512",         // stable identity (kept for Select2)
  "url":    "stream_view?id=512",  // primary navigation target
  "text":   "CNN HD",              // plain label (Select2 matching)
  "entity": "stream",              // stream|movie|channel|radio|episode|series|user|line|mag|enigma
  "data":   { … }                  // entity-specific payload
}
```

Every `data.actions[]` entry is **self-describing**, so the client needs no
per-action logic — it maps `kind` to an existing global helper:

| `kind` | Client call |
| --- | --- |
| `navigate` | `navigate(target)` |
| `api` | `searchAPI(entity, id, sub)` |
| `fingerprint` | `modalFingerprint(id, context)` |
| `credits` | `addCredits(id)` |

`enabled: false` renders a disabled button. Stream status codes (`-1…10`) are
resolved server-side exactly as before; labels/variants derive from the existing
`$rSearchStatusArray` constant, so it stays the single source of truth.

### Client renderer

`src/Public/assets/admin/js/search.js` (`renderSearchItem(item)`) dispatches by
`item.entity` to per-entity card builders and wires the self-describing actions.
It is loaded before `common.js`, whose Select2 quick-search `templateResult`
calls it (with a loading-state guard) instead of consuming a server `html`
field.

> This changes only the **render path**, not search matching. The DB gather
> (batched `MATCH … AGAINST` full-text with score sorting and IN-clause lookups)
> is unchanged. If live streams are missing from results, rebuild the stale
> `streams` FULLTEXT index on the database (`ALTER TABLE streams ENGINE=InnoDB;`)
> — a runtime concern, not a code path.

---

## Related files

| File | Purpose |
| --- | --- |
| `src/Public/Controllers/Admin/Ajax/BaseAjaxController.php` | JSON scaffolding (ok/fail/gate/requireXhr/json) |
| `src/Public/Controllers/Admin/Ajax/LineStateTrait.php` | Shared line/device state actions |
| `src/Public/Controllers/Admin/Ajax/*AjaxController.php` | Per-area action controllers |
| `src/Public/Controllers/Admin/AjaxController.php` | Fallback for unknown actions (`{"result":false}`) |
| `src/Public/routes/admin.php` | `$router->api(...)` registrations |
| `src/Public/assets/admin/js/search.js` | Client-side search card renderer |
