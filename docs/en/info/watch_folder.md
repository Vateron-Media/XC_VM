# 🎬 Watch Folder — Automatic Media Import

Watch Folder is an automated content import system. It monitors local directories (or rclone remotes) for new video files, parses their names to extract metadata (title, year, season, episode), looks up TMDB for cover art and descriptions, and creates movies/series records in the database — all without manual intervention.

---

## How it Works

```
┌──────────────────┐     ┌──────────────────┐     ┌──────────────────┐
│  Watch Folder    │────▶│   WatchCron       │────▶│  WatchItem       │
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
5. **Parallel processing** — each new file is dispatched to a `watch_item` command (via `shell_exec`), running up to `thread_count` items in parallel using `Multithread`.
6. **WatchItem** parses the filename using PTN or guessit (see parser docs below), resolves metadata via TMDB API, and inserts a record into `streams` (for movies) or `streams_series` + `streams_episodes` (for series).
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
| `auto_upgrade` | Replace existing lower-quality version if TMDB ID matches |
| `duplicate_tmdb` | Allow multiple imports with the same TMDB ID |
| `ffprobe_input` | Run ffprobe on source file to extract codec metadata |
| `extract_metadata` | Extract additional metadata from file |

### Global settings

| Setting | Where | Description |
|---------|-------|-------------|
| `tmdb_api_key` | Admin → Settings | **Required** — TMDB API key. Watch won't run without it |
| `fallback_parser` | Admin → Settings | Parser used when primary parser fails |
| `alternative_titles` | Admin → Settings | Search TMDB alternative titles |
| `max_genres` | Admin → Settings | Maximum genres to assign per item |

---

## Admin Panel & API

### Admin panel pages

| Page | Description |
|------|-------------|
| Watch Folder → List | View all configured watch folders with status |
| Watch Folder → Add | Create/edit a watch folder |
| Watch Folder → Settings | Global watch settings (parser, TMDB config) |
| Watch Folder → Logs | View scan output and errors |

### Admin API actions

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
sudo -u xc_vm /home/xc_vm/bin/php/bin/php /home/xc_vm/console.php cron:watch

# Force scan a specific folder (by ID)
sudo -u xc_vm /home/xc_vm/bin/php/bin/php /home/xc_vm/console.php cron:watch 5
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

### 🎥 Movies

| File Example | Parsed Data |
|-------------|-------------|
| `San Andreas 2015 720p WEB-DL x264 AAC-JYK.mkv` | Title: *San Andreas*, Year: 2015, Resolution: 720p, Video: x264, Audio: AAC, Group: JYK |
| `The Martian 2015 540p HDRip KORSUB x264 AAC2 0-FGT.mp4` | Title: *The Martian*, Year: 2015, Resolution: 540p, Video: x264, Audio: AAC2.0, Group: FGT |

### 📺 TV Shows

| File Example | Parsed Data |
|-------------|-------------|
| `friends.s02e01.720p.bluray-sujaidr.mkv` | Title: *Friends*, Season: 2, Episode: 1, Resolution: 720p, Format: bluray, Group: sujaidr |
| `Mr Robot S01E05 HDTV x264-KILLERS[ettv].mp4` | Title: *Mr Robot*, Season: 1, Episode: 5, Format: HDTV, Video: x264, Group: KILLERS |

---

## 2️⃣ guessit Parser

Guessit supports more complex file naming, including dot separators and multilingual titles.

### 🎥 Movies

| File Example | Parsed Data |
|-------------|-------------|
| `The.Matrix.1999.1080p.BluRay.x264.DTS-FGT.mkv` | Title: *The Matrix*, Year: 1999, Resolution: 1080p, Video: x264, Audio: DTS, Group: FGT |
| `Inception.2010.720p.BRRip.x264.AAC-ETRG.mkv` | Title: *Inception*, Year: 2010, Resolution: 720p, Video: x264, Audio: AAC, Group: ETRG |

### 📺 TV Shows

| File Example | Parsed Data |
|-------------|-------------|
| `Breaking.Bad.S03E07.720p.BluRay.x264-REWARD.mkv` | Title: *Breaking Bad*, Season: 3, Episode: 7, Resolution: 720p, Video: x264, Group: REWARD |
| `Game.of.Thrones.S05E09.1080p.WEB-DL.DD5.1.H.264-NTb.mkv` | Title: *Game of Thrones*, Season: 5, Episode: 9, Resolution: 1080p, Video: H.264, Audio: DD5.1, Group: NTb |

---

### 🔄 Fallback to Folder Name

If the file name does not contain the show title, enable **Fallback to Folder Name**:

| File Path Example | Parsed Data |
|-----------------|-------------|
| `/path/to/Show Name/S01E01 720p WEB-DL.mkv` | Title: *Show Name*, Season: 1, Episode: 1 |
| `/path/to/Show.Name/S01E01.720p.WEB-DL.mkv` | Title: *Show Name*, Season: 1, Episode: 1 |

#### 🗂 Season Folder Structure

If you want episodes sorted into season folders, the file name must contain the show title:

| File Path Example | Parsed Data |
|-----------------|-------------|
| `/path/to/Show Name/Season 01/Show Name S01E01 720p WEB-DL.mkv` | Title: *Show Name*, Season: 1, Episode: 1 |
| `/path/to/Show.Name/Season.01/Show.Name.S01E01.720p.WEB-DL.mkv` | Title: *Show Name*, Season: 1, Episode: 1 |

---

### 🌐 RTL Languages

For shows in RTL languages (Arabic, Hebrew, etc.):

- File name **should NOT contain the show title**  
- Enable **Fallback to Folder Name**  

| File Path Example | Parsed Data |
|-----------------|-------------|
| `/path/to/Show Name/S01E01 (year).mp4` | Title: *Show Name*, Season: 1, Episode: 1, Year: `year` |
| `/path/to/Show Name/Season 01/S01E01 (year).mp4` | Title: *Season 01*, Season: 1, Episode: 1, Year: `year` |

> ⚠️ Note: For RTL languages, the show title is taken only from the folder name.

---

### ✅ Summary

- **PTN Parser** — simple file names, local formats  
- **guessit Parser** — supports dot-separated names, multilingual titles, Fallback to folder name  
- **RTL Languages** — must use Fallback to folder name  
- **Season Folder Structure** — show title must be in the file name for correct sorting  

---

💡 **Tip:** Use consistent file and folder naming for accurate parsing and automatic season sorting.
