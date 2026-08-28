# Search JSON contract

Goal: the `search` endpoint returns **structured data**, not server-rendered HTML, so the new design (and any future client) renders cards itself. Permission checks, status resolution, category/server lookups stay server-side; only markup moves to the client.

Endpoint: `?action=search` ({@see \XcVm\Public\Controllers\Admin\Ajax\SearchAjaxController}). The client renderer is `src/Public/assets/admin/js/search.js` (`renderSearchItem(item)`), wired into the Select2 quick-search box in `common.js`.

## Envelope

```jsonc
{ "result": true, "total_count": 12, "items": [ Item, … ] }
```

## Item (common)

```jsonc
{
  "id":     "streams#512",       // stable identity (kept for Select2)
  "url":    "stream_view?id=512",// primary navigation target
  "text":   "CNN HD",            // plain label (kept for Select2 matching)
  "entity": "stream",            // stream|movie|channel|radio|episode|series|user|line|mag|enigma
  "data":   { … }                // entity-specific payload (below)
}
```

Every `data.actions[]` entry is **self-describing** so the client needs no per-action logic:

```jsonc
{ "kind": "navigate",    "target": "movie?id=512", "icon": "mdi-pencil", "title": "Edit" }
{ "kind": "api",         "entity": "stream", "sub": "stop", "icon": "mdi-stop", "title": "Stop", "enabled": true }
{ "kind": "fingerprint", "id": 512, "context": "stream", "icon": "mdi-fingerprint", "enabled": true }
{ "kind": "credits",     "id": 5, "icon": "mdi-coin", "title": "Add credits" }
```
`kind` → client call: `navigate(target)` · `searchAPI(entity,id,sub)` · `modalFingerprint(id,context)` · `addCredits(id)`. `enabled:false` renders a disabled button.

## data by entity

### stream / movie / channel / radio / episode
```jsonc
{
  "layout": "live" | "vod",
  "title": "CNN HD", "title_link": "stream_view?id=512" | null,
  "category": "News (+2)",
  "server": "EU-1 (+1)" | "",
  "image": { "url": "cnn.png", "size": 96 },        // live 96 (stream_icon) / vod 512 (movie_image)
  "badge": { "text": "STREAM", "variant": "success" },
  "connections": 128, "connections_link": "live_connections?stream_id=512",
  "status": { "kind": "uptime",   "text": "01h 02m 03s" }
          | { "kind": "progress", "percent": 42 }
          | { "kind": "status",   "code": 5, "label": "DIRECT", "variant": "purple" },
  "rating": { "stars_full": 3, "half": true, "empty": 1, "year": "2021" } | null,  // movies
  "actions": [ Action, … ]
}
```

### series
```jsonc
{ "title": "Breaking Bad", "category": "Drama", "image": {"url":"cover.jpg","size":512},
  "rating": {"stars_full":4,"half":false,"empty":1,"year":"2008"},
  "badge": {"text":"TV SERIES","variant":"danger"}, "seasons": 5, "episodes": 62,
  "actions": [ … ] }
```

### user
```jsonc
{ "username":"reseller1", "group":"Resellers", "owner":"admin", "is_reseller":true, "credits":1000,
  "status":{"label":"Active","variant":"info"}, "users_count":12, "lines_count":340,
  "badge":{"text":"USER","variant":"warning"}, "actions":[ … ] }
```

### line / mag / enigma
```jsonc
{ "title": "line123" | "AA:BB:CC:…",   // username for line, mac for device
  "device_type": "mag" | "enigma" | null,
  "status": {"label":"Active"|"Banned"|"Disabled","variant":"info"|"danger"|"warning"},
  "owner": "admin", "expires": "2025-01-01 00:00:00" | null,
  "last_active": {"online":true,"stream_id":512,"stream_name":"CNN","online_for":"01h 00m 00s"}
              | {"online":false,"date":"2024-12-01 10:00:00"|null},
  "connections": 5, "flags": {"restreamer":false,"trial":true},
  "badge": {"variant":"pink"}, "actions":[ … ] }
```

## Status codes (streams)
Server resolves `$rActualStatus` (-1…10) exactly as today; label + variant are derived from the existing `$rSearchStatusArray` constant, so they stay the single source of truth. Codes `1` (running → uptime) and `6` (created-channel encode → progress) are special-cased into the `uptime` / `progress` kinds.
