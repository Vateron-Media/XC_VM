# Test stream generator

Generates a synthetic, ever-changing test pattern (**no input file**) and serves
it as looping, HTTP-served streams you can paste into the panel as a live stream
source — for end-to-end testing of the panel's streaming pipeline, including
**LLOD** (the Low-Latency On-Demand processor in `src/Cli/Commands/LlodCommand.php`).

The picture is drawn live by ffmpeg from `testsrc2` (a moving colour test
pattern) with overlays: a large running **stopwatch** (elapsed), the real
**wall-clock** time (compare it to your own clock to eyeball end-to-end latency),
a frame counter, and two boxes sweeping across the frame. Audio is a low 1 kHz
tone. Everything is paced in real time, so the panel sees a never-ending "live"
channel.

## Requirements

- Python 3.7+ (standard library only — no `pip install`)
- `ffmpeg` available in `PATH` (or pass `--ffmpeg /path/to/ffmpeg`)
- A TrueType font for the on-screen clock/labels — autodetected (DejaVu /
  Liberation / `fc-match`). If none is found it falls back to `testsrc` (v1),
  whose built-in timestamp still gives a running timer plus the moving boxes.

## Usage

```bash
cd tools/test-stream-generator
./stream_server.py
```

On start it prints the URLs to paste into the panel, e.g.:

```
  TS (LLOD): http://192.168.1.50:8088/stream.ts
  HLS      : http://192.168.1.50:8088/stream.m3u8
  M3U list : http://192.168.1.50:8088/playlist.m3u
  Index    : http://192.168.1.50:8088/
```

Stop with `Ctrl+C`.

### Options

| Flag                | Default     | Description                                                        |
| ------------------- | ----------- | ------------------------------------------------------------------ |
| `--host`            | `0.0.0.0`   | Bind address.                                                      |
| `--port`            | `8088`      | Bind port.                                                         |
| `--advertise-host`  | autodetect  | Host/IP printed in the URLs (set this if the panel is on another host). |
| `--size`            | `1280x720`  | Frame size `WxH`.                                                  |
| `--fps`             | `25`        | Frame rate.                                                        |
| `--font`            | autodetect  | Path to a `.ttf` for the on-screen clock/labels.                  |
| `--max-clients`     | `32`        | Max concurrent `/stream.ts` **readers** (all share the one TS encoder). Extra connections get `503`. |
| `--verbose`         | off         | Log every HTTP request (off by default to keep long runs quiet).  |
| `--ffmpeg`          | `ffmpeg`    | ffmpeg binary to use.                                              |

```bash
./stream_server.py --size 1920x1080 --fps 30
./stream_server.py --font /usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf
```

## Endpoints

| URL              | Content-Type                      | Use                                                   |
| ---------------- | --------------------------------- | ----------------------------------------------------- |
| `/stream.ts`     | `video/mp2t`                      | Continuous MPEG-TS — **the endpoint for testing LLOD**. Served from one shared, always-running encoder, so every open — including repeated on-demand pulls — attaches instantly with no new ffmpeg. |
| `/stream.m3u8`   | `application/vnd.apple.mpegurl`   | Live HLS (sliding-window playlist + `.ts` segments).  |
| `/playlist.m3u`  | `audio/x-mpegurl`                 | M3U channel list with both URLs, for bulk import.     |
| `/`              | `text/html`                       | Index page listing every URL.                         |

## Adding a stream in the panel

1. Admin → **Streams** → Add/Edit stream.
2. Paste a URL into the **stream source** field (`stream_source[]` in
   `src/Public/Views/admin/stream.php`). You can add several sources.
3. Save and start the stream.

### Testing LLOD specifically

1. Use the **`/stream.ts`** URL as the stream source (LLOD requires an upstream
   that responds with `Content-Type: video/mp2t`, which this endpoint does).
2. Enable the **LLOD** flag on the stream (`streams.llod = 1`).
3. Start the stream and confirm the panel writes rolling 4-second segments to
   `/home/xc_vm/content/streams/{id}_{segment}.ts` and the client can play it.

### Bulk import

Add `http://HOST:8088/playlist.m3u` as a playlist source to register both the
TS and HLS test channels at once.

## Notes

- Each `/stream.ts` client gets its own ffmpeg; the HLS output is shared by a
  single background ffmpeg that auto-restarts if it dies.
- HLS segments live in a temp directory (bounded by `delete_segments`) that is
  removed on exit.
- **Long-run safety** (fine to leave running for days): concurrent `/stream.ts`
  pulls are capped (`--max-clients`), each client socket has a write timeout +
  TCP keepalive so a stalled/half-open peer is dropped instead of pinning an
  ffmpeg, and per-request access logging is off unless `--verbose`. The only
  cosmetic quirk over multi-day runs is the MPEG-TS 33-bit timestamp wrap
  (~26.5 h), which ffmpeg handles.
- This is a **development/testing** tool. Do not expose it to the public
  internet.
