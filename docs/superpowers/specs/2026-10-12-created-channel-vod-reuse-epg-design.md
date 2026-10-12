# Created Channels: reuse encoded VOD + generate EPG — design

Date: 2026-10-12
Status: design (approved in chat; pending spec review)

## Goal

Rework the "Created Channels" feature (`streams.type = 3`, `type_key = created_live`) so that:

1. It can **reuse VOD that is already inserted and encoded** — pick existing movies/episodes and play their already-encoded files instead of re-adding/re-encoding the original source.
2. It **generates an EPG** for the channel from the playlist: one programme per item, `title` = movie name, length = movie runtime, `start` = the time the item plays, projected across the loop.

Confirmed scope decisions:
- Reuse = reference the VOD **stream id**, resolve to its encoded file, **copy-remux** (no re-encode) by default; transcode only on demand. *(good)*
- EPG = **full option**: write the per-stream cache (player_api / Ministra / Enigma2) **and** feed the `xmltv.php` full guide. *(full option)*
- Keep the **ordered-playlist + wall-clock loop** model; **no** day-part / fixed-slot scheduler. *(no day part)*

## Current mechanism (as-is)

- A created channel stores an **ordered playlist** in `streams.stream_source` (JSON array of source strings: `s:<serverId>:<path>` or a local path). The selection UI (`Views/admin/created_channel.php`, saved by `Domain/Stream/ChannelService::process`) offers three "Selection Types" in `movie_properties.type`: `0` series, `1` file-browser, `2` VOD selection. Type 0/2 resolve each picked VOD/episode to its **original source** (`streams.stream_source[0]`), not its encoded output.
- Build (`console.php created <id>` → `Cli/Commands/CreatedCommand`): for each not-yet-built source, symlink (if `movie_symlink`) or **re-encode** to `CREATED_PATH/<id>_<md5>.ts`; write the concat list `CREATED_PATH/<id>_.list`; ffprobe each entry into `CREATED_PATH/<id>_.info` (cumulative timeline `{position, filename, path, seconds, start, finish}`); record built sources in `streams_servers.cchannel_rsources`.
- Stream (`StreamProcess::startStream`): ffmpeg `-safe 0 -f concat -i <id>_.list` → HLS (same output path as live). The PHP monitor (`MonitorCommand`, `type == 3` branch) loops it against wall clock: `elapsed = time() - stream_started`; resume offset is computed at **per-file** granularity (trimmed list `<id>_.tlist`, no intra-file seek); `stream_started` is re-anchored to `time() - offset` on restart.
- Delivered to clients exactly as `live` (`PlaylistGenerator`, player_api, Ministra).
- VOD facts: a movie = `streams.type = 2`; encoded file = `VOD_PATH/<id>.<target_container>`; metadata in `streams.movie_properties` (`name`, `plot`/`description`, `release_date`, and **`duration_secs`** which after encoding holds the real probed runtime). Recordings already set a movie's `stream_source` to its own `VOD_PATH` file — "VOD file as source" is an established pattern.
- EPG facts: programmes live in `epg_data (epg_id, channel_id, start, end, lang, title, description)`; a stream links via `streams.epg_id` + `streams.channel_id`; **no programmatic EPG writer exists** — `epg_data` is written only by `EpgCronJob` from XMLTV. Players read a per-stream igbinary cache `EPG_PATH/stream_<id>` that `EpgCronJob` builds (for any stream with `epg_id`+`channel_id`) and sweeps orphans from. `xmltv.php` serves pre-built per-bouquet files `EpgCronJob::buildXmltv` produces from `epg_data`. Times are unix.

## Requirements

- R1 Admin can add existing VOD (movies and series episodes) to a created channel by reference.
- R2 A referenced VOD plays from its already-encoded file; the build does not re-encode it (copy-remux to `.ts` for concat), unless the admin selects a transcode profile or the codecs are concat-incompatible.
- R3 The channel has an EPG: one programme per playlist item, `title` = VOD name, `description` = plot, duration = item runtime, start = projected play time; the guide repeats as the playlist loops and covers a forward window.
- R4 EPG reaches both the per-stream cache (player_api/Ministra/Enigma2) and the full `xmltv.php` guide.
- R5 Non-VOD items (file-browser paths) keep working; their programme is best-effort (title = filename, no description).
- R6 No regression to existing created channels (ordered loop, per-server build state, mass edit).

## Design

### D1. Playlist item model (reference VOD by id)

Today a playlist entry is an opaque source string. Introduce a typed entry so an item can be a **VOD reference** while staying backward compatible:

- Keep `streams.stream_source` as the ordered list, but allow an entry of the form **`vod:<streamId>`** (new) alongside the existing `s:<serverId>:<path>` and bare-path forms.
- `ChannelService::process` (type-2 "VOD Selection") stores `vod:<id>` entries (the picked movie/episode stream ids) instead of resolving to the underlying source path. Type-0 "Series" (`SeriesService::generatePlaylist`) likewise emits `vod:<episodeStreamId>`. Type-1 file-browser is unchanged (bare/`s:` paths).
- A new resolver `StreamProcess::resolveChannelSource` (extend the existing one, `:479`) maps `vod:<id>` → the VOD's encoded file: `VOD_PATH/<id>.<target_container>` on the server(s) that hold it. On a multi-server channel, resolve per node (the VOD must be encoded on that node, or the channel build fetches/needs it there — see Edge E4).

Rationale: storing the id (not a path) is what lets the EPG generator pull `movie_properties` for the programme, and lets the build pick the **encoded** output rather than re-encoding the source.

### D2. Build: copy-remux encoded VOD (`CreatedCommand`)

- For a `vod:<id>` entry, the build resolves to `VOD_PATH/<id>.<ext>` and produces the concat `.ts` by **copy-remux**: `ffmpeg -i <vodfile> -c copy -f mpegts <id>_<md5>.ts` (reuses the encode; fast; no quality loss). This is effectively the existing `transcode_profile_id = 0` path but guaranteed `-c copy` and pointed at the encoded output.
- If the admin picked a real transcode profile, honour it (re-encode as today). If `movie_symlink` applies and the container is already mpegts-concat-safe, symlink as today.
- **Concat compatibility (prototyped 2026-10-12):** `ffmpeg -f concat -c copy -f mpegts` does **not** error on mixed codecs/resolutions — mpegts carries a mid-stream change and ffmpeg exits clean (verified: h264+mpeg2 and 720p+480p both produced a valid joined `.ts`). So incompatibility is **not** detectable from ffmpeg's exit. The real failure is downstream: a mid-playlist **codec or resolution change within one HLS variant breaks most players**. Therefore the build computes a per-item A/V **fingerprint** via ffprobe — `vcodec,width,height,pix_fmt` and `acodec,channels,sample_rate` — and a **canonical target** (the channel's transcode profile if set, else the dominant fingerprint among the items). Items whose fingerprint matches the target are **copy-remuxed** (reuse the encode); items that differ are **transcoded** to the target, so the final HLS is uniform. The build already ffprobes each item for `_.info`, so the fingerprint data is in hand. Log per-item copy-vs-transcode in `progress_info`.
- Build state (`cchannel_rsources` diffing, incremental rebuild) is unchanged — the entry key is just `vod:<id>` now.

### D3. EPG linkage (reuse the existing join)

- Seed one reserved EPG **source** row: `epg (epg_name = 'Created Channels', epg_file = '', ...)` — a sentinel source with no XMLTV file. Reserve its id (migration; store in a setting or look up by a sentinel `epg_file`).
- On channel save, set `streams.epg_id = <reserved>` and `streams.channel_id = 'created_<streamId>'`. This makes the channel a normal `(epg_id, channel_id)` participant, so the **existing** per-stream cache build and `xmltv.php` build pick it up with no change to those readers.

### D4. EPG generator (new)

A new service `Domain/Epg/CreatedChannelEpg` (name TBD) produces programmes for one created channel:

- Inputs: the channel's `_.info`/`cc_info` timeline (`{seconds, start, finish}` per item, cumulative; total cycle = last `finish`), `streams_servers.stream_started` (virtual cycle anchor, re-anchored by the monitor), and the item→VOD-id mapping (from the `vod:<id>` entries, aligned to `_.info` positions).
- Algorithm (fill window `[now - back, now + ahead]`, e.g. back = 1h, ahead = `epg.days_keep` days or a setting, default 48h):
  - `total = last.finish`; `anchor = stream_started`.
  - Find the first cycle boundary ≤ window start; for each cycle `k` and each item `i`: `start = anchor + k*total + item[i].start`; `end = start + item[i].seconds`; emit if it overlaps the window.
  - Programme fields: `title` = VOD `movie_properties.name` (fallback `stream_display_name`, then filename for non-VOD), `description` = `movie_properties.plot`/`description` (empty for non-VOD), `lang` = channel `epg_lang` or default.
- Degenerate cases: no `_.info` yet (never built) or `stream_started = 0` (not started) → no programmes (channel shows no guide until it is built and running). Zero-duration items are skipped.

### D5. EPG storage + refresh integration (`EpgCronJob` + build/start)

- The generator writes `epg_data` rows under `(epg_id = reserved, channel_id = 'created_<id>')`.
- Integrate regeneration so rows stay fresh as the loop advances and `stream_started` changes:
  - In `EpgCronJob::execute`, add a step (before the per-stream cache build at its step 6): for each created channel, **delete then regenerate** its reserved-source `epg_data` rows for the window. Because the reserved source has no `epg_file`, it is skipped by the XMLTV import loop; this new step owns it. This runs daily (and on `forceEpg`).
  - Also regenerate on channel **(re)build** and on **start/restart** (where `stream_started` is set), so the guide reflects the current anchor promptly — call the generator from `CreatedCommand` (after writing `_.info`) and from the monitor's start path (or a lightweight hook after `stream_started` changes).
- The per-stream cache build (`EPG_PATH/stream_<id>`) then serves the channel — **but it currently filters `WHERE type = 1`** (`EpgCronJob.php:172`), which excludes `created_live` (type 3). **Change required:** broaden that query to include created channels (e.g. `type IN (1, 3)`). Orphan-sweep already leaves it alone (the stream exists with the linkage).
- **Skip the sentinel in XMLTV import:** the import loop joins `streams → epg` by `epg_id` (`EpgCronJob.php:101-103`), so once a created channel links to the reserved source it enters that loop and would try `new EPG('')` on the empty `epg_file` and log a parse failure. Guard the loop to skip the reserved source (empty `epg_file` / id == reserved); the new generator step owns its `epg_data`.
- `days_keep` for the reserved source: set so the regenerate-each-run step is authoritative (we delete+rebuild its rows each run; don't rely on age-based cleanup).

### D6. Serving (no reader changes expected)

- `player_api` (`get_epg`/`get_simple_data_table`/`get_short_epg`), Ministra/Stalker, Enigma2 and the admin grid all read `EPG_PATH/stream_<id>` — populated by D5, so they work with no change.
- `xmltv.php` serves `EpgCronJob::buildXmltv` output built from `epg_data`. **Confirmed (2026-10-12):** `buildXmltv` selects channels with `SELECT … FROM streams WHERE epg_id IS NOT NULL AND channel_id IS NOT NULL` — **no type filter** (`EpgCronJob.php:252/254`) — so a type-3 created channel is included; `writeProgrammes` then reads `epg_data` for those `epg_id`s (`:336`), which includes the reserved source's generated rows. So the full guide works with no change to the XMLTV build.

### D7. Admin UI

- "VOD Selection" (type 2) in `created_channel.php` already lists movies/episodes via `StreamToolsAjaxController::reviewSelection`; change the review list to carry **stream ids** and submit `vod:<id>` entries (today it submits resolved source paths).
- Optional: show that a VOD item will be "reused (copy)" vs a profile re-encode, and surface the generated EPG window. Minimal change otherwise — same ordered `<select>` reorder UI.
- Series (type 0) and file-browser (type 1) UIs unchanged except type-0 now emits `vod:` episode refs.

## Edge cases & caveats

- E1 **Per-file loop granularity** (existing, unchanged): resume after restart snaps to a file boundary (no intra-file seek), so the live position can jump up to one item's length; EPG is therefore accurate to the movie boundary, not second-exact across restarts. Acceptable for a movie channel; documented.
- E2 **VOD not yet encoded / broken**: a `vod:<id>` whose `VOD_PATH` file is missing (`to_analyze`/broken) must be skipped (or the channel build waits); don't put a non-existent file in the concat list. Build logs it.
- E3 **VOD re-encoded or deleted later**: the channel references by id; if the VOD is deleted, the item drops on next build and the EPG regenerates. A dangling `vod:<id>` is handled like E2.
- E4 **Multi-server** (decided): the VOD-selection UI only offers VODs that are present (encoded) on the channel's server set; the channel build requires the encoded file on each hosting node and skips/warns otherwise. No cross-node fetch. Documented as a constraint.
- E5 **Mixed codecs** (copy-concat): see D2 — per-item transcode fallback when copy-concat can't join; this is the main correctness risk.
- E6 **Timezone**: reuse `EpgCronJob`'s existing TZ handling (`force_epg_timezone`); times are unix, so no new TZ logic.
- E7 **Window drift / regen cost**: regenerating all created channels' programmes each EPG run is O(channels × items × cycles-in-window); bounded and cheap. On start/build we regenerate just the one channel.

## Out of scope

- Day-part / fixed-slot scheduling (explicitly excluded). The only timeline remains `cc_info` cumulative durations + `stream_started`.
- Intra-file seek / second-exact loop resync.
- Changing how non-created live streams get EPG.

## Testing

- Unit: the timeline-projection generator (given `_.info` + `stream_started` + item→VOD map → expected programmes over a window, including loop wrap, window edges, zero-duration skip, non-VOD fallback title).
- Unit: `ChannelService` stores `vod:<id>` entries for type 0/2; resolver maps `vod:<id>` → `VOD_PATH/<id>.<ext>`.
- Unit: `CreatedCommand` builds a `vod:` item via copy-remux (command shape `-c copy -f mpegts`), not re-encode, when no profile is set.
- Integration (DB): generator writes `epg_data` under the reserved source; `EpgCronJob` builds `EPG_PATH/stream_<id>` from it; `get_short_epg` returns the programmes.
- Regression: existing file-browser / series created channels still build and loop; mass edit unaffected.
- Manual/live (cannot be done in CI here): build a created channel from 2–3 encoded VODs, confirm it streams, loops, and the guide shows each movie with correct title/length/time in player_api and `xmltv.php`.

## Risks

- Copy-concat codec compatibility (E5) — **prototyped (2026-10-12), approach settled:** ffmpeg won't flag a mismatch, so the build must proactively fingerprint each item and transcode non-matching ones to a canonical target (D2). Remaining risk is only in getting the fingerprint comparison complete (e.g. field rate, SAR, audio layout) so no mismatch slips through to HLS; keep the comparison strict and default to transcode when unsure.
- `xmltv.php` inclusion of the reserved-source programmes (D6) — **confirmed:** `buildXmltv` has no type filter and `writeProgrammes` reads `epg_data` by `epg_id`, so created channels are included. No residual risk.
- Per-stream EPG cache (D5) — the cache build filters `type = 1` today; the fix (`type IN (1,3)`) must not disturb how existing live streams are selected. Low risk, one query.
- Keeping `stream_started` and the EPG anchor in sync across monitor restarts (D5) — the generator must read the same anchor the monitor uses, or the guide drifts from playback.
