# Stream check tools

Dependency-free (pure Python 3 stdlib) tools to **verify** a live stream's
delivery and **visualise** the result. No ffmpeg/ffprobe needed — the checker
parses the bytes itself.

```text
tools/stream-check/
├── stream_queue_check.py   # verify a stream (or a whole .m3u playlist) → JSON
├── stream_graph.py         # render the checker's JSON as static SVG charts
└── README.md
```

They pair with `tools/test-stream-generator/` (a test source) and the e2e
harness in `tools/test-install/` (see `tools/test-install/STREAMTEST.md`).

## `stream_queue_check.py` — queue-integrity checker

Watches a stream for a while and reports whether its "queue" stays intact:

- **HLS** (`.m3u8`): media-playlist segment queue — `EXT-X-MEDIA-SEQUENCE` must
  advance contiguously, no `EXT-X-DISCONTINUITY`, every new segment downloadable.
- **MPEG-TS** (`.ts`, e.g. an XC_VM `/play/<token>/ts`): per-PID
  `continuity_counter`, sync loss and delivery stalls.

Type is auto-detected. Health is judged per mode — TS fails on a delivery stall,
HLS fails on real buffer starvation (rebuffers), because HLS segment length is
often intentionally variable.

```bash
# single stream
python3 tools/stream-check/stream_queue_check.py 'http://host/stream.ts' --duration 120 --json
python3 tools/stream-check/stream_queue_check.py 'http://host/stream.m3u8' --live      # ANSI dashboard

# a whole .m3u playlist → aggregate JSON (+ per-stream files)
python3 tools/stream-check/stream_queue_check.py --playlist 'url_or_path.m3u' --out-dir logs/
```

Key options: `--duration` (default 120), `--tolerance` (transient CC/sync breaks
allowed), `--stall-timeout` (TS), `--playlist`, `--out-dir` (per-stream JSON),
`--live`, `--json`. Run with `-h` for the full list.

> **Quote URLs** that contain `&` (e.g. `…?output=hls&key=live`) — otherwise the
> shell splits the command on `&` and backgrounds it.

Exit code: `0` all healthy, `2` a stream failed, `1` usage error.

## `stream_graph.py` — static SVG charts

Turns the checker's JSON (per-stream files or an aggregate report) into
standalone **SVG** images for eyeballing and side-by-side comparison — throughput
over time, buffered-seconds curve, non-PLAYING bands, queue-break marks, and an
OK/FAIL header. `--combined` overlays every stream's bitrate on one chart with a
unique colour per stream.

```bash
# a folder of per-stream logs, plus a combined comparison
python3 tools/stream-check/stream_graph.py logs/ --combined --out-dir graphs/

# individual files / an aggregate report (mix freely; duplicates de-duped)
python3 tools/stream-check/stream_graph.py logs/01-*.json report.json
```

Input: any mix of `*.json` files and directories. Options: `--out-dir`
(default `graphs`), `--combined`, `--width`, `--height`. SVG opens in any
browser/IDE and converts to PNG with `rsvg-convert`/`inkscape` if needed.
