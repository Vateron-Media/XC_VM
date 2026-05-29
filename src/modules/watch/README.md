# Watch module

Automatic folder watcher for media files.

## Overview

The `watch` module scans configured folders (local paths or remote locations via `rclone`) for new movie and TV episode files, attempts to identify them using release parsers and TMDb, and imports matching items into the system as `streams` (movies) or `streams_series`/`streams_episodes` (TV). It can also update existing streams (upgrade source), add items to bouquets, enqueue auto-encoding, and download artwork.

## Key features

- Periodic scanning via CLI cron command `cron:watch`.
- Per-file processing performed by the `watch_item` command.
- TMDb matching and metadata import (posters, backdrop, cast, genres, runtime, trailers).
- Support for local directories and `rclone` remote folders.
- Automatic addition to bouquets and categories based on genres and module settings.
- Options: auto-upgrade, auto-encode (queue), extract metadata via ffprobe, subtitle handling.

## Entry points

- CLI: `php console.php cron:watch` — run scheduled scan. Optional folder ID: `php console.php cron:watch <folder_id>` to force a single folder.
- CLI: `php console.php watch_item "<base64(json)>"` — process a single payload (used by cron to dispatch jobs).
- HTTP API actions: `enable_watch`, `disable_watch`, `kill_watch`, `folder` (delete/force) exposed by the module router.

## Configuration and settings

- Requires a valid TMDb API key in settings (`tmdb_api_key`).
- Important settings available via `SettingsManager`:
  - `percentage_match` — minimal similarity for TMDb match
  - `parse_type` — `guessit` or `ptn` release parser
  - `download_images` — whether to download poster/backdrop images
  - `auto_encode` — queue imported items for encoding
  - `thread_count`, `scan_seconds`, `max_items`, `max_genres`, `alternative_titles`, `fallback_parser`

## Temporary files and caches

- Uses `WATCH_TMP_PATH` for caches and coordination files: `movie_<tmdb>.cache`, `series_<tmdb>.cache`, `*.bouquet`, `*.wpid`, `lock_<id>`.

## Logs

- Processing results and errors are recorded in the `watch_logs` table.

## Notes

- The module integrates with other services: `StreamProcess`, `ImageUtils`, and `TMDB` client libraries.
- Be cautious when enabling `auto_upgrade` and `auto_encode`; they may modify existing streams or enqueue heavy encoding jobs.

For details, inspect the module source files in this folder: `WatchCron.php`, `WatchCronJob.php`, `WatchItem.php`, `WatchItemCommand.php`, `WatchService.php`, and `WatchController.php`.
