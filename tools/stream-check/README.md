# Stream check tool

A single dependency-free (pure Python 3 stdlib) tool to **verify** a live
stream's delivery and **visualise** the result. No ffmpeg/ffprobe needed — it
parses the bytes itself.

```text
tools/stream-check/
├── stream_check.py   # verify a stream / playlist → JSON, and render JSON → SVG
└── README.md
```

Everything lives in one master script with three subcommands:

- **`check`** — verify one stream (or watch it live) → human/JSON report.
- **`playlist`** — test every stream in an `.m3u` channel list → aggregate JSON (+ per-stream files).
- **`graph`** — render the JSON from `check`/`playlist` as static SVG charts.

It pairs with `tools/test-stream-generator/` (a test source) and the e2e
harness in `tools/test-install/` (see `tools/test-install/STREAMTEST.md`).

## What it checks

Two independent queues are checked, depending on the stream type (auto-detected):

- **HLS** (`.m3u8`): media-playlist segment queue — `EXT-X-MEDIA-SEQUENCE` must
  advance contiguously, no `EXT-X-DISCONTINUITY`, every new segment downloadable.
- **MPEG-TS** (`.ts`, e.g. an XC_VM `/play/<token>/ts`): per-PID
  `continuity_counter`, sync loss and delivery stalls.

Health is judged per mode — TS fails on a delivery stall, HLS fails on real
buffer starvation (rebuffers), because HLS segment length is often intentionally
variable.

> **Quote URLs** that contain `&` (e.g. `…?output=hls&key=live`) — otherwise the
> shell splits the command on `&` and backgrounds it.

## `check` — single-stream checker

```bash
# one stream → report (exit 0 healthy / 2 problem)
python3 tools/stream-check/stream_check.py check 'http://host/stream.ts' --duration 120 --json
python3 tools/stream-check/stream_check.py check 'http://host/stream.m3u8' --live      # ANSI dashboard
```

Key options: `--duration` (default 30), `--tolerance` (transient CC/sync breaks
allowed), `--stall-timeout` (TS), `--json`, `--live` (+ `--prebuffer`,
`--buffer-target`, `--no-color`). Run `check -h` for the full list.

Exit code: `0` healthy, `2` a queue/delivery problem, `1` usage error.

## `playlist` — batch checker

```bash
# a whole .m3u playlist → aggregate JSON (+ per-stream files)
python3 tools/stream-check/stream_check.py playlist 'url_or_path.m3u' --out-dir logs/
```

Tests each stream for `--duration` seconds (default 120), emits one JSON document
with a per-stream verdict and a per-second time-series ("the graph as JSON").
`--out-dir` also writes a separate `<NN>-<name>.json` per stream as it finishes.
Other options: `--tolerance`, `--stall-timeout`, `--prebuffer`, `--ua`.

An interactive terminal gets a short summary; redirected/piped stdout gets the
full JSON. Exit code: `0` all healthy, `2` a stream failed, `1` usage error.

## `graph` — static SVG charts

Turns the checker's JSON (per-stream files or an aggregate report) into
standalone **SVG** images for eyeballing and side-by-side comparison — throughput
over time, buffered-seconds curve, non-PLAYING bands, queue-break marks, and an
OK/FAIL header. `--combined` overlays every stream's bitrate on one chart with a
unique colour per stream.

```bash
# a folder of per-stream logs, plus a combined comparison
python3 tools/stream-check/stream_check.py graph logs/ --combined --out-dir graphs/

# individual files / an aggregate report (mix freely; duplicates de-duped)
python3 tools/stream-check/stream_check.py graph logs/01-*.json report.json
```

Input: any mix of `*.json` files and directories. Options: `--out-dir`
(default `graphs`), `--combined`, `--width`, `--height`. SVG opens in any
browser/IDE and converts to PNG with `rsvg-convert`/`inkscape` if needed.
