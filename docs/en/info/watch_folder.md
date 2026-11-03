# Watch Folder — Automatic Media Import

Watch Folder is an automated content import system. It monitors local directories (or rclone remotes) for new video files, parses their names to extract metadata (title, year, season, episode), looks up TMDB for cover art and descriptions, and creates movies/series records in the database — all without manual intervention.

---

## How it Works

```
┌──────────────────┐     ┌──────────────────┐     ┌──────────────────┐
│  Watch Folder    │────▶│   WatchCron       │────▶│ VodItemImporter  │
│  (directory on   │     │  scans for new    │     │  parses filename │
│   disk / rclone) │     │  files, filters   │     │  queries TMDB    │
│                  │     │  already imported  │     │  creates DB row  │
└──────────────────┘     └──────────────────┘     └──────────────────┘
                                                          │
                                                          ▼
                                                   ┌──────────────────┐
                                                   │  Bouquet update  │
                                                   │  (auto-assign    │
                                                   │   to bouquets)   │
                                                   └──────────────────┘
```

### Step-by-step

1. **Admin creates a Watch Folder** in the admin panel (Watch Folder → Add) or via API (`create_watch_folder`). Configuration includes: directory path, content type (movie/series), target category, bouquets, parser settings, and the assigned server.
2. **Cron job `cron:watch`** runs periodically (controlled by `scan_offset` — seconds between scans). It queries `watch_folders` table for active folders where `last_run` exceeded the offset.
3. **File discovery** — the cron uses `find` for local directories or `rclone lsjson` for cloud/remote mounts. Files are filtered by allowed extensions (default: `mp4, mkv, avi, mpg, flv, 3gp, m4v, wmv, mov, ts`). Files already present in `streams.stream_source` are skipped.
4. **Stability check** — files modified less than 30 seconds ago are skipped (to avoid importing partially uploaded files).
5. **Parallel processing** — each new file is dispatched to core's `vod_import_item` command (via `shell_exec`), running up to `thread_count` items in parallel (`Multithread::pool`). Movies/Series → Import (M3U or folder) uses the same command, so manual import works without this module.
6. **VodItemImporter** (core, `Domain/Vod/VodItemImporter.php`) parses the filename using PTN or guessit (see parser docs below), resolves metadata via TMDB API, and inserts a record into `streams` (for movies) or `streams_series` + `streams_episodes` (for series). It reports each file's outcome as a `VodImportResultEvent`, which the module writes to `watch_logs`.
7. **Bouquet assignment** — imported items are automatically added to the configured bouquets.

---

## Configuration

### Watch Folder settings (per folder)

| Setting | Description |
|---------|-------------|
| `directory` | Local path to scan (e.g., `/mnt/media/movies/`) |
| `rclone_dir` | rclone remote path (alternative to local directory) |
| `type` | Content type: `movie` or `series` |
| `server_id` | Server that runs the scan |
| `category_id` | Target category for imported content |
| `bouquets` | Auto-assign to these bouquets |
| `fb_category_id` | Fallback category (if TMDB genre mapping fails) |
| `fb_bouquets` | Fallback bouquets |
| `allowed_extensions` | File extensions to scan (empty = default list) |
| `language` | Preferred TMDB language for metadata |
| `active` | Enable/disable this folder |

### Boolean options

| Option | Description |
|--------|-------------|
| `disable_tmdb` | Skip TMDB lookup — import file with parsed title only |
| `ignore_no_match` | Import even if TMDB returns no result |
| `auto_subtitles` | Auto-detect `.srt`, `.sub`, `.sbv` files next to video |
| `fallback_title` | Use folder name as title if parser can't extract it |
| `read_native` | Read native title from TMDB |
| `movie_symlink` | Create symlinks instead of referencing original path |
| `auto_encode` | Auto-encode imported content |
| `auto_upgrade` | Replace the existing copy when a bigger file with the same TMDB ID (and episode) turns up on the same server. The two files are compared on the server that holds them: a load balancer's folder scanned from MAIN is asked for their sizes, so the load balancer must run a version that gives them. A copy that is kept is logged as `DUPLICATE` |
| `duplicate_tmdb` | Allow multiple imports with the same TMDB ID |
| `ffprobe_input` | Run ffprobe on source file to extract codec metadata |
| `extract_metadata` | Extract additional metadata from file |

### Global settings

| Setting | Where | Description |
|---------|-------|-------------|
| `tmdb_api_key` | Admin → Settings | **Required** — TMDB API key. Watch won't run without it |
| `percentage_match` | Admin → Settings → VOD Import | Minimum title similarity a TMDB match needs |
| `fallback_parser` | Admin → Settings → VOD Import | Run the other parser when the primary one finds no match |
| `alternative_titles` | Admin → Settings → VOD Import | Search TMDB alternative titles |
| `tmdb_episode_by_title` | Admin → Settings → VOD Import | Find an episode by its TMDB title when the file names no episode number (off by default, see [Episodes Named by Title](#episodes-named-by-title)) |
| `max_genres` | Admin → Settings → VOD Import | Maximum genres to turn into categories and bouquets |
| Genre mapping | Admin → Settings → VOD Import | Category and bouquets per TMDB genre (`watch_categories`, core-owned). Applies to every import, Movies/Series → Import included |
| `thread_count` | Admin → Settings → VOD Import | Files imported at the same time, by scans and by Movies/Series → Import |
| `scan_seconds`, `max_items` | Watch Settings (module) | Scan interval, files per folder per scan |

---

## Admin Panel & API

### Admin panel pages

| Page | Description |
|------|-------------|
| Watch Folder → List | View all configured watch folders with status |
| Watch Folder → Add | Create/edit a watch folder |
| Watch Folder → Settings | Scan interval and files per scan (matching, parallel imports and genres: Settings → VOD Import) |
| Watch Folder → Logs | View scan output and errors |

### Admin API actions

The module registers these with core's `AdminApiRegistry`; without it they answer `Invalid action.`

| Action | Description |
|--------|-------------|
| `get_watch_folders` | List all watch folders |
| `get_watch_folder` | Get single folder by ID |
| `create_watch_folder` | Create new watch folder |
| `edit_watch_folder` | Update existing watch folder |
| `delete_watch_folder` | Delete watch folder |
| `reload_watch_folder` | Force immediate re-scan |
| `enable_watch` | Enable all watch folders |
| `disable_watch` | Disable all watch folders |
| `kill_watch` | Kill all running watch processes |

### CLI

```bash
# Normal cron execution (usually triggered automatically)
sudo -u xc_vm /home/xc_vm/console.php cron:watch

# Force scan a specific folder (by ID)
sudo -u xc_vm /home/xc_vm/console.php cron:watch 5
```

---

## Parsers

Two filename parsers are available. The parser extracts structured metadata (title, year, season, episode, resolution, codec) from the video filename.

### Choosing a parser

| Parser | Best for |
|--------|----------|
| **PTN** | Simple filenames with spaces: `San Andreas 2015 720p.mkv` |
| **guessit** | Dot-separated filenames: `The.Matrix.1999.1080p.BluRay.mkv` |

Set the primary parser per watch folder. The `fallback_parser` global setting is used when the primary parser returns no match.

---

## 1️⃣ PTN Parser

PTN parser supports parsing movie and TV show files with typical naming patterns.

### Movies

| File Example | Parsed Data |
|-------------|-------------|
| `San Andreas 2015 720p WEB-DL x264 AAC-JYK.mkv` | Title: *San Andreas*, Year: 2015, Resolution: 720p, Video: x264, Audio: AAC, Group: JYK |
| `The Martian 2015 540p HDRip KORSUB x264 AAC2 0-FGT.mp4` | Title: *The Martian*, Year: 2015, Resolution: 540p, Video: x264, Audio: AAC2.0, Group: FGT |

### TV Shows

| File Example | Parsed Data |
|-------------|-------------|
| `friends.s02e01.720p.bluray-sujaidr.mkv` | Title: *Friends*, Season: 2, Episode: 1, Resolution: 720p, Format: bluray, Group: sujaidr |
| `Mr Robot S01E05 HDTV x264-KILLERS[ettv].mp4` | Title: *Mr Robot*, Season: 1, Episode: 5, Format: HDTV, Video: x264, Group: KILLERS |

---

## 2️⃣ guessit Parser

Guessit supports more complex file naming, including dot separators and multilingual titles.

### Movies

| File Example | Parsed Data |
|-------------|-------------|
| `The.Matrix.1999.1080p.BluRay.x264.DTS-FGT.mkv` | Title: *The Matrix*, Year: 1999, Resolution: 1080p, Video: x264, Audio: DTS, Group: FGT |
| `Inception.2010.720p.BRRip.x264.AAC-ETRG.mkv` | Title: *Inception*, Year: 2010, Resolution: 720p, Video: x264, Audio: AAC, Group: ETRG |

### TV Shows

| File Example | Parsed Data |
|-------------|-------------|
| `Breaking.Bad.S03E07.720p.BluRay.x264-REWARD.mkv` | Title: *Breaking Bad*, Season: 3, Episode: 7, Resolution: 720p, Video: x264, Group: REWARD |
| `Game.of.Thrones.S05E09.1080p.WEB-DL.DD5.1.H.264-NTb.mkv` | Title: *Game of Thrones*, Season: 5, Episode: 9, Resolution: 1080p, Video: H.264, Audio: DD5.1, Group: NTb |

---

### Fallback to Folder Name

If the file name does not contain the show title, enable **Fallback to Folder Name**:

| File Path Example | Parsed Data |
|-----------------|-------------|
| `/path/to/Show Name/S01E01 720p WEB-DL.mkv` | Title: *Show Name*, Season: 1, Episode: 1 |
| `/path/to/Show.Name/S01E01.720p.WEB-DL.mkv` | Title: *Show Name*, Season: 1, Episode: 1 |

#### Season Folder Structure

If you want episodes sorted into season folders, the file name must contain the show title:

| File Path Example | Parsed Data |
|-----------------|-------------|
| `/path/to/Show Name/Season 01/Show Name S01E01 720p WEB-DL.mkv` | Title: *Show Name*, Season: 1, Episode: 1 |
| `/path/to/Show.Name/Season.01/Show.Name.S01E01.720p.WEB-DL.mkv` | Title: *Show Name*, Season: 1, Episode: 1 |

#### Episodes Named by Title

A series file with no episode number in its name is logged as `NO_MATCH`. With **Match Episodes by Title** on (Admin → Settings → VOD Import, off by default), a file whose own name gives the show and the season, such as `Show Name - S02 - The Long Night.mkv`, is compared with the episode titles of that TMDB season and takes the number of the one it matches. The season is never guessed: a file with no season in its name (`Season 02/The Long Night.mkv`) stays `NO_MATCH`, and so do the specials (season 0). A name with release tags (`Show.Name.S02.The.Long.Night.1080p.WEB-DL.mkv`) needs the guessit parser, which leaves them out of the title.

The match is strict, because a wrongly numbered episode is worse than a file left unmatched: the best title must be at least 65% similar and at least 10 points ahead of the next one. `The Finale (Part 1)` and `(Part 2)`, a title the season has twice, and a file that names two episodes are all refused. An episode numbered this way never replaces the file of one already imported, whatever **Auto-Upgrade** says, and it says so in its **Notes** (`Episode number matched by TMDb title: …`), which the search of the Episodes list finds; the watch log shows it as imported, like any other. The season's episode list is fetched once per show, season and language and reused by the other files of that season for an hour.

---

### RTL Languages

For shows in RTL languages (Arabic, Hebrew, etc.):

- File name **should NOT contain the show title**  
- Enable **Fallback to Folder Name**  

| File Path Example | Parsed Data |
|-----------------|-------------|
| `/path/to/Show Name/S01E01 (year).mp4` | Title: *Show Name*, Season: 1, Episode: 1, Year: `year` |
| `/path/to/Show Name/Season 01/S01E01 (year).mp4` | Title: *Season 01*, Season: 1, Episode: 1, Year: `year` |

> ⚠️ Note: For RTL languages, the show title is taken only from the folder name.

---

### Summary

- **PTN Parser** — simple file names, local formats  
- **guessit Parser** — supports dot-separated names, multilingual titles, Fallback to folder name  
- **RTL Languages** — must use Fallback to folder name  
- **Season Folder Structure** — show title must be in the file name for correct sorting  

---

💡 **Tip:** Use consistent file and folder naming for accurate parsing and automatic season sorting.

## Download VOD from an M3U

Movies → Import and Series → Import accept an uploaded M3U/M3U8 file or a public HTTP/HTTPS playlist URL (up to 10 MiB). Use **Load titles for selection** to select individual entries. Without a preview, all entries are imported. Series entries should include season/episode information in their titles, as with the existing importer.

Enable **Download VOD files before importing**, then use the folder button to choose an existing writable folder on the destination server. The folder must be within that server's Scan Roots. The destination server is automatically included among the VOD servers.

Each selected direct media-file URL downloads on the destination server before its catalog entry is created. The downloaded source is stored as `s:<server>:<path>`. Categories and bouquets (including existing genre mappings/fallbacks), container, native-frame reading, symlink/subtitle options, transcoding profile and the automatic encode option continue through the normal VOD import. Direct Source and Direct Proxy are disabled for downloaded local files. A selected transcoding profile is also enabled for downloaded series episodes.

Downloads share the import concurrency setting (**Settings → VOD Import → thread_count**) and have a six-hour limit per file. Filenames contain a sanitized title and a source-URL hash. A download is published only after a successful HTTP response and a complete nonempty transfer; failures leave no catalog entry or partial media file, and existing files are never overwritten. A completed download can be reused by a later import. With Watch Folder enabled, import outcomes appear in its usual logs; the download/import itself does not require that module.

This downloads direct media files, such as MP4 or MKV. HLS playlists and live-stream recording are not supported by this option. The destination node must run a version that supports the `vod_download` system action.

The HTTP fetcher rejects private, loopback, link-local, metadata, multicast and reserved addresses. It validates all resolved A/AAAA addresses, pins them for the connection, and repeats the check for every redirect. Relative media links resolve against the final playlist URL. If the previewed titles or URLs change, the import asks you to reload the preview rather than silently omitting selected entries.

Download limits are configurable on the import form: the defaults are **20 GiB per file** and **100 GiB per import**, capped at 100/1000 GiB. The destination node enforces the shared import budget across its workers, including responses without Content-Length, and retains at least **1 GiB free** while writing. Budgets reserve bytes in blocks of up to 1 MiB, so failed transfers may consume a little more budget than their final partial size. The form confirms **queue acceptance**, not completed downloads or catalog imports; it remains open with that explicit message.

Migration `102_add_vod_import_source_hash.sql` adds a nullable original-source SHA-256 identity and a unique key per VOD type. It prevents concurrent or repeated M3U imports from creating duplicate catalog rows, including after title or destination changes. Both fresh-install schema and rollback migration are included. Existing entries without a recorded identity retain their current behavior.

A durable receipt beside each downloaded file records its job identity, device/inode and size before publication. It recovers a completed file if the worker stops between publishing the hard link and recording completion, and survives temporary metadata expiry. Temporary job/status/worker-lock files and inactive budgets expire after 24 hours; active worker locks and their budgets are preserved. Abandoned queued jobs fail after a 60-second startup grace period so they can be retried. Missing RPC responses are retried until a terminal worker response or the overall deadline.
