# Streaming Diagnostics & Tooling

A standalone tool verifies that a stream delivers correctly — that segments arrive in order and the delivery queue does not break. It is independent of the request path; for that, see the [Streaming Subsystem](streaming-subsystem.md).

---

## `tools/stream-check/stream_queue_check.py` (Python, stdlib only)

Standalone monitor for **segment/packet queue integrity** with an optional **live buffer dashboard**. Auto-detects HLS vs MPEG-TS.

```bash
python3 tools/stream-check/stream_queue_check.py "<url>" --duration 30        # batch check
python3 tools/stream-check/stream_queue_check.py "<url>" --json               # cron / monitoring
python3 tools/stream-check/stream_queue_check.py "<url>" --live --duration 0  # live dashboard
```

What "queue intact" means per stream type:

| Stream | Queue check |
| --- | --- |
| HLS (`.m3u8`) | `EXT-X-MEDIA-SEQUENCE` monotonic and contiguous (no dropped or rewound segments), no `EXT-X-DISCONTINUITY`, every newly appearing segment downloadable. Master playlists are resolved to their first variant. |
| MPEG-TS (`.ts`, `/play/<token>/ts`) | per-PID `continuity_counter` (lost / duplicated / reordered packets = queue break), sync-byte loss, transport-error indicator, and delivery stalls. |

Key options:

| Flag | Purpose |
| --- | --- |
| `--duration N` | seconds to observe (`0` = until Ctrl-C in `--live`) |
| `--tolerance N` | allow N transient queue breaks before reporting `BROKEN` (ignores rare source glitches relayed by `-c copy`) |
| `--stall-timeout S` | delivery gap counted as a stall; keep it above the segment duration (default 15) |
| `--live` | colored TUI dashboard (below) |
| `--prebuffer S` / `--buffer-target S` | live: virtual-player prebuffer and buffer-graph scale |
| `--json` / `--no-color` | machine output / disable ANSI |

Exit code: `0` healthy, `2` queue problem or stall, `1` usage.

### Live dashboard (`--live`)

Models a virtual player: the playhead advances at wall-clock rate while content is "received". For **TS** the received timeline comes from **PCR** (the stream clock); for **HLS** from the segments' `EXTINF` durations. Buffered playtime ("cache") = received − played; if it reaches zero the playhead freezes (a rebuffer event).

```text
  STREAM QUEUE / BUFFER MONITOR   TS   up 00:22
  cache buffer (s), last 60s:
  ▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▁▄▄▄▇▇▇▆▆▆▅▅▅▄▄▇▇▇▆▆▆▅   <- burst-then-drain = delivery sawtooth
  IN CACHE : [█████████████████░░░░░░░░░░░░░]  11.6s / 20s
  PLAYING  : PLAYING     head 00:18   received 00:29
  rate 1000 kbit/s   received 4.1 MB   last data 7.0s ago
  QUEUE OK   cc:0 sync:0 gaps:0 disc:0   rebuffers:0
```

The buffer graph and gauge are colored green (healthy) / yellow (low) / red (starving). For HLS a row of blocks shows the segments still in cache ahead of the playhead.

> **Note — delivery pacing.** Live client delivery is now handled by the
> `xc_fanout` daemon (see [Streaming Subsystem → Daemon delivery](streaming-subsystem.md#daemon-delivery-xc_fanout)), which pulls each source once and fans it out over a unix socket. `stream_queue_check.py --live` visualises the buffer behaviour a real player would see against the delivered stream.

---

## Related files

| File | Purpose |
| --- | --- |
| `tools/stream-check/stream_queue_check.py` | queue-integrity monitor + live buffer dashboard |
