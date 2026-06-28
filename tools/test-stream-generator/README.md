# Test stream generator

Turns a single `.mp4` file into looping, HTTP-served streams you can paste into
the panel as a live stream source — for end-to-end testing of the panel's
streaming pipeline, including **LLOD** (the Low-Latency On-Demand processor in
`src/Cli/Commands/LlodCommand.php`).

The MP4 is looped forever and paced in real time, so the panel sees a
never-ending "live" channel.

## Requirements

- Python 3.7+ (standard library only — no `pip install`)
- `ffmpeg` available in `PATH` (or pass `--ffmpeg /path/to/ffmpeg`)

## Usage

```bash
cd tools/test-stream-generator
./stream_server.py -i sample.mp4
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
| `-i, --input`       | (required)  | Source `.mp4` file.                                                |
| `--host`            | `0.0.0.0`   | Bind address.                                                      |
| `--port`            | `8088`      | Bind port.                                                         |
| `--advertise-host`  | autodetect  | Host/IP printed in the URLs (set this if the panel is on another host). |
| `--encode`          | `copy`      | `copy` = remux (fast, needs an H.264/AAC mp4). `h264` = re-encode any codec. |
| `--ffmpeg`          | `ffmpeg`    | ffmpeg binary to use.                                              |

If playback is broken with the default `copy` mode (e.g. the mp4 is HEVC/AC3),
re-run with `--encode h264`.

## Endpoints

| URL              | Content-Type                      | Use                                                   |
| ---------------- | --------------------------------- | ----------------------------------------------------- |
| `/stream.ts`     | `video/mp2t`                      | Continuous MPEG-TS — **the endpoint for testing LLOD**. |
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

- Each `/stream.ts` client gets its own ffmpeg process; the HLS output is shared
  by a single background ffmpeg that auto-restarts if it dies.
- HLS segments live in a temp directory that is removed on exit.
- This is a **development/testing** tool. Do not expose it to the public
  internet.
