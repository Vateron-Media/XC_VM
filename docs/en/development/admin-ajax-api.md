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
    $this->requireXhr();
    $this->gate('adv', 'database');
    // … call a domain service …
    $this->ok();
}
```

Gate an action on the permission of the page that shows its button (here the Cache
page, `database`), so a group that cannot open the page cannot run its actions. A
refusal that has a reason to give carries it in `message`:
`$this->fail(['message' => …])`.

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
| `PackageAjaxController` | Packages, bouquets, groups, categories |
| `ActiveCodeAjaxController` | Activation code generation, batch actions, export |
| `ModuleAjaxController` | Modules table row actions |
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

### Permission gates

Gates that the action name does not give away (all `adv` permissions):

| Action | Permission |
| --- | --- |
| `regenerate_cache`, `enable_cache`, `disable_cache`, `enable_handler`, `disable_handler`, `clear_redis` | `database` |
| `report` (CSV/JSON export) | `database` |
| `clear_logs` | The permission of the log page that `type` names: `lines_logs` → `client_request_log`, `lines_activity` → `connection_logs`, `streams_errors` → `stream_errors`, `users_credits_logs` → `credits_log`, `users_logs` → `reg_userlog`, `panel_logs` → `panel_logs`. Any other `type` fails. |
| `download_panel_logs` | `panel_logs`. The table is emptied once its rows are collected. |
| `get_epg`, `get_programme`, `provider_streams`, `provider_import_epg` | `streams` |
| `multi` | By `type`, for example `line` → `edit_user`, `series` → `edit_series`, `active_code` → `edit_user` or `mass_edit_lines` |
| `generate_active_codes` | `add_user` |
| `active_codes_batch_action` | `edit_user` or `mass_edit_lines` |
| `active_codes_export_txt` | `users` |
| `module` | `settings` |

### Rules beyond the gate

Some actions pass the gate and still answer `{"result":false}`:

- **`group`** with `sub` = `is_admin` or `is_reseller` (plus `value` and `group_id`) sets
  that flag under the rules of the group form. `value` is stored as 0 or 1. A group that
  cannot be deleted keeps its flags. Only a full administrator (group 1, or an
  administrator group with an empty permission list) changes an administrator group or
  makes a group an administrator group.
- **`package`** with `sub` = `is_trial` or `is_official` (plus `value` and `package_id`) sets
  that flag, and no other flag is set this way. `value` must read as on or off (`0`, `1`,
  `true`, `false`, `on`, `off`, `yes`, `no`) and is stored as 0 or 1. A missing or other
  `value`, or a `package_id` that does not exist, answers `{"result":false}`.
- **`reg_user`** and **`adjust_credits`** leave an administrator's account alone unless the
  caller is a full administrator.
- **`reinstall_server`** answers `{"result":false,"message":"…"}` when the load balancer
  would install in cluster mode 2 while the Redis connection handler is on. The server is
  not marked as being installed, so it stays in rotation. **`enable_handler`** answers the
  same shape while a node is in mode 2.

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
