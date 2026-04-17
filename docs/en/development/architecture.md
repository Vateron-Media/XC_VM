# Architecture Overview (EN)

> Sync-ID: `architecture-sync-2026-04-17`
> Language: English
> Canonical runtime details: [ARCHITECTURE.md](../../../ARCHITECTURE.md)
> Current migration roadmap: [MIGRATION.md](../../../MIGRATION.md)

## Purpose

This page is the English architecture mirror for contributors.
It tracks the same practical state as the Russian architecture docs: mixed legacy + modernized runtime, non-finished migration, and explicit module/runtime boundaries.

## Current Architecture in One View

- Project type: structured PHP monolith
- Runtime model: mixed (`ServiceContainer` + constructor injection + legacy globals)
- Core flow: `public/index.php` for panel pages, plus compatibility paths for API/streaming
- Streaming: still depends on `www/stream/init.php` in the active hot path
- Modules: CLI integration is active, web boot integration is incomplete
- Build model: MAIN (full) and LB (streaming-focused subset)

## Source Tree Roles

- `src/core/`: infrastructure primitives (DB, HTTP, Events, Config, Logging, Container)
- `src/domain/`: business contexts (Stream, Vod, Line, User, Server, Auth, etc.)
- `src/streaming/`: delivery/auth/protection path for stream endpoints
- `src/public/`: front controller, routes, controllers, templates, assets
- `src/cli/`: command and cron execution layer
- `src/modules/`: optional extension layer
- `src/infrastructure/legacy/`: remaining compatibility code not yet eliminated
- `src/www/`: legacy runtime surface still required by part of production traffic

## Known Gaps (Synchronized with RU)

1. Full constructor injection is still a target, not an everywhere-enforced rule.
2. `www/` cannot be removed safely yet because nginx/runtime/service tooling still depend on it.
3. `ModuleLoader::bootAll()` is not connected to `public/index.php`, so web module lifecycle is partial.
4. Some public controllers still delegate to legacy/procedural handlers.

## Contributor Rules

1. Treat `ARCHITECTURE.md` and `MIGRATION.md` as operational source-of-truth for runtime and migration sequence.
2. Do not document impossible states (for example, "legacy removed") before code and infra actually match.
3. Keep EN and RU architecture pages synchronized under the same Sync-ID.
4. Never expose secrets, API keys, or internal credentials in frontend/client-facing docs or examples.

## Synchronization Checklist

- Update [docs/en/development/architecture.md](architecture.md) and [docs/ru/development/architecture.md](../../ru/development/architecture.md) in the same commit.
- Keep `Sync-ID` and update date aligned.
- If runtime changes significantly, update this page and the root [ARCHITECTURE.md](../../../ARCHITECTURE.md).