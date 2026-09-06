#!/usr/bin/env python3
"""
stream_check.py — verify live-stream delivery and visualise the result.

A single dependency-free (pure Python 3 stdlib) tool. It both *checks* a stream
(or a whole .m3u playlist) and *renders* the resulting JSON as static SVG charts
— no ffmpeg/ffprobe, no third-party packages: it parses the bytes itself.

Three subcommands:

  check <url>          Verify one stream — HLS .m3u8 or MPEG-TS .ts (e.g. an
                       XC_VM /play/<token>/ts endpoint). Type auto-detected.
                       --live turns it into a colored real-time dashboard.

  playlist <path|url>  Test every stream in an .m3u channel list and emit ONE
                       JSON document with a per-stream verdict plus a per-second
                       time-series ('the graph as JSON'). --out-dir also writes a
                       separate JSON log per stream.

  graph <inputs...>    Render the JSON produced by `check`/`playlist` (per-stream
                       log files or an aggregate report) as standalone SVG charts.

Two independent queues are checked, depending on the stream type:

  * HLS (.m3u8): the media-playlist segment queue. EXT-X-MEDIA-SEQUENCE must
    advance monotonically and contiguously (no rewind, no skipped segments that
    slid off before we read them), no EXT-X-DISCONTINUITY, and every newly
    appearing segment must be downloadable.

  * MPEG-TS (.ts / XC_VM /play/<token>/ts): the transport packet queue. Each
    PID carries a 4-bit continuity_counter that must increment by one per
    payload packet; a break means lost / duplicated / reordered packets. Sync
    loss and delivery stalls are reported too.

Run:
    python3 tools/stream-check/stream_check.py check    <url> [options]
    python3 tools/stream-check/stream_check.py playlist <path|url> [options]
    python3 tools/stream-check/stream_check.py graph    <inputs...> [options]
    # the file is executable, so this also works:
    tools/stream-check/stream_check.py check <url>

Exit code (check/playlist): 0 = all healthy, 2 = a stream had a problem,
1 = usage error. `graph` returns 0 on success, 1 on error.

Examples:
    stream_check.py check "http://host/live/user/pass/1.m3u8" --duration 60
    stream_check.py check "http://host/play/<token>/ts" --json
    stream_check.py check "http://host/live/user/pass/1.m3u8" --live --prebuffer 5
    stream_check.py playlist playlist.m3u --out-dir logs/
    stream_check.py graph logs/ --combined --out-dir graphs/

Tip: QUOTE URLs that contain '&' (e.g. …?output=hls&key=live) or the shell
splits the command and backgrounds it.

@package XC_VM_Tools
@license AGPL-3.0
"""

import os
import sys
import time
import json
import math
import colorsys
import argparse
import threading
import urllib.request
import urllib.error
from urllib.parse import urljoin

DEFAULT_UA = "Mozilla/5.0 (X11; Linux x86_64) stream_check/1.0"


# ─────────────────────────────── shared ──────────────────────────────

def slugify(name, fallback="stream"):
    """Filesystem-safe slug for a per-stream log/graph filename."""
    out = [c if (c.isalnum() or c in "-_.") else "_" for c in (name or "")]
    return ("".join(out).strip("._") or fallback)[:80]


# ─────────────────────────────── HTTP ────────────────────────────────

def http_open(url, ua, timeout=15, headers=None):
    """Open a URL (redirects followed by urllib), returning the response."""
    h = {"User-Agent": ua}
    if headers:
        h.update(headers)
    req = urllib.request.Request(url, headers=h)
    return urllib.request.urlopen(req, timeout=timeout)


def http_get(url, ua, timeout=15):
    """Fetch a whole body. Returns (status, final_url, bytes) or raises."""
    resp = http_open(url, ua, timeout)
    body = resp.read()
    return resp.status, resp.geturl(), body


# ───────────────────────────── detection ─────────────────────────────

def detect_kind(url, ua):
    """Peek at the stream to decide HLS vs TS. Returns (kind, final_url, head)."""
    resp = http_open(url, ua, timeout=15)
    final = resp.geturl()
    head = resp.read(4096)
    resp.close()
    if head.lstrip()[:7] == b"#EXTM3U":
        return "hls", final, head
    # A raw TS stream begins with sync byte 0x47 (possibly after a few bytes).
    return "ts", final, head


# ─────────────────────────────── HLS ─────────────────────────────────

def parse_media_playlist(text):
    """Extract sequence, target duration, segment URIs and flags from a media
    playlist. Returns a dict."""
    media_seq = 0
    target = 0
    seg_uris = []
    disc_count = 0
    endlist = False
    is_master = False
    variant_uris = []
    lines = text.replace("\r\n", "\n").split("\n")
    for i, line in enumerate(lines):
        line = line.strip()
        if not line:
            continue
        if line.startswith("#EXT-X-STREAM-INF"):
            is_master = True
            for j in range(i + 1, len(lines)):
                nxt = lines[j].strip()
                if nxt and not nxt.startswith("#"):
                    variant_uris.append(nxt)
                    break
        elif line.startswith("#EXT-X-MEDIA-SEQUENCE:"):
            media_seq = int(line.split(":", 1)[1] or 0)
        elif line.startswith("#EXT-X-TARGETDURATION:"):
            try:
                target = float(line.split(":", 1)[1])
            except ValueError:
                target = 0
        elif line.startswith("#EXT-X-DISCONTINUITY") and not line.startswith("#EXT-X-DISCONTINUITY-SEQUENCE"):
            disc_count += 1
        elif line.startswith("#EXT-X-ENDLIST"):
            endlist = True
        elif not line.startswith("#"):
            seg_uris.append(line)
    return {
        "is_master": is_master,
        "variant_uris": variant_uris,
        "media_seq": media_seq,
        "target": target,
        "seg_uris": seg_uris,
        "disc_count": disc_count,
        "endlist": endlist,
    }


def resolve_master(url, text, ua):
    """If the playlist is a master, follow its first variant to a media
    playlist. Returns (media_url, media_text)."""
    pl = parse_media_playlist(text)
    if not pl["is_master"]:
        return url, text
    if not pl["variant_uris"]:
        raise RuntimeError("master playlist has no variants")
    media_url = urljoin(url, pl["variant_uris"][0])
    _, final, body = http_get(media_url, ua)
    return final, body.decode("utf-8", "replace")


def check_hls(url, ua, duration, report):
    report["mode"] = "hls"
    _, final_url, body = http_get(url, ua)
    text = body.decode("utf-8", "replace")
    media_url, text = resolve_master(final_url, text, ua)
    report["details"]["media_playlist"] = media_url

    highest_index = None       # global index of the newest segment seen
    seg_ok = 0
    seg_fail = 0
    gaps = []                  # skipped segment indices (queue break)
    rewinds = 0
    disc_events = 0
    stalls = 0
    polls = 0
    last_change = time.time()
    prev_signature = None

    deadline = time.time() + duration
    while time.time() < deadline:
        polls += 1
        try:
            _, _, raw = http_get(media_url, ua, timeout=15)
            pl = parse_media_playlist(raw.decode("utf-8", "replace"))
        except Exception as e:
            report["errors"].append("playlist fetch failed: %s" % e)
            seg_fail += 1
            time.sleep(1)
            continue

        base = pl["media_seq"]
        signature = (base, tuple(pl["seg_uris"]))

        # Stall: playlist has not advanced for > 3 target durations.
        if signature == prev_signature:
            if time.time() - last_change > max(6.0, pl["target"] * 3 or 18):
                stalls += 1
                report["errors"].append(
                    "stall: playlist has not advanced for %ds" % int(time.time() - last_change))
                last_change = time.time()  # avoid repeating every poll
        else:
            last_change = time.time()
            prev_signature = signature

        if pl["disc_count"] > disc_events:
            report["errors"].append("EXT-X-DISCONTINUITY present (timeline break)")
        disc_events = max(disc_events, pl["disc_count"])

        # Assign a global index to each segment: media_seq + position.
        for pos, uri in enumerate(pl["seg_uris"]):
            idx = base + pos
            if highest_index is None:
                highest_index = idx - 1  # so the first segment counts as "new"

            if idx <= highest_index:
                # Already seen (still in the sliding window) — check for rewind.
                continue

            # Queue-break checks on a freshly appearing segment.
            if idx > highest_index + 1:
                skipped = list(range(highest_index + 1, idx))
                gaps.extend(skipped)
                report["errors"].append(
                    "queue gap: segments %s never appeared (deleted before read)"
                    % (skipped if len(skipped) <= 5 else "%d..%d" % (skipped[0], skipped[-1])))

            # Download & verify the new segment.
            seg_url = urljoin(media_url, uri)
            try:
                st, _, seg = http_get(seg_url, ua, timeout=15)
                if st == 200 and seg:
                    # Optional TS-level sanity: first byte should be a sync byte.
                    if seg[:1] == b"\x47" or b"\x47" in seg[:376]:
                        seg_ok += 1
                    else:
                        seg_ok += 1  # non-TS segment container; still counts as delivered
                else:
                    seg_fail += 1
                    report["errors"].append("segment %d unfetchable (HTTP %s)" % (idx, st))
            except Exception as e:
                seg_fail += 1
                report["errors"].append("segment %d fetch error: %s" % (idx, e))

            highest_index = idx

        # Detect a hard rewind (media sequence went backwards vs the newest seen).
        if highest_index is not None and base > 0 and base > highest_index + 1:
            rewinds += 1

        if pl["endlist"]:
            report["details"]["endlist"] = True
            break

        time.sleep(max(1.0, (pl["target"] or 4) / 2.0))

    d = report["details"]
    d["polls"] = polls
    d["segments_delivered"] = seg_ok
    d["segments_failed"] = seg_fail
    d["queue_gaps"] = len(gaps)
    d["rewinds"] = rewinds
    d["discontinuities"] = disc_events
    d["stalls"] = stalls
    # Queue intact = ordered, contiguous, no dropped/rewound segments, no timeline
    # break, every referenced segment fetchable. Stalls are separate.
    queue_intact = (seg_fail == 0 and len(gaps) == 0 and rewinds == 0
                    and disc_events == 0 and seg_ok > 0)
    d["queue_intact"] = queue_intact
    report["healthy"] = queue_intact and stalls == 0


# ─────────────────────────────── TS ──────────────────────────────────

PACKET = 188
SYNC = 0x47
NULL_PID = 0x1FFF


def check_ts(url, ua, duration, head, report, stall_timeout=15.0, tolerance=0):
    report["mode"] = "ts"
    resp = http_open(url, ua, timeout=15)

    last_cc = {}          # pid -> last continuity_counter
    cc_errors = {}        # pid -> count of continuity breaks
    dup_packets = 0
    pid_packets = {}      # pid -> packet count
    total_packets = 0
    sync_errors = 0
    error_indicator = 0
    total_bytes = 0
    stalls = 0
    aligned = False       # first sync-byte lock is setup, not an error

    buf = bytearray(head)  # bytes already peeked during detection
    total_bytes += len(head)
    deadline = time.time() + duration
    last_data = time.time()

    try:
        while time.time() < deadline:
            try:
                chunk = resp.read(65536)
            except Exception as e:
                report["errors"].append("read error: %s" % e)
                break
            now = time.time()
            if not chunk:
                # EOF or no data.
                if now - last_data > stall_timeout:
                    stalls += 1
                    report["errors"].append("stall: no data for >%ds" % int(stall_timeout))
                    break
                time.sleep(0.1)
                continue
            if now - last_data > stall_timeout:
                stalls += 1
                report["errors"].append("stall: gap of %.1fs in delivery" % (now - last_data))
            last_data = now
            total_bytes += len(chunk)
            buf.extend(chunk)

            # Process whole 188-byte packets; keep alignment on the sync byte.
            i = 0
            n = len(buf)
            while i + PACKET <= n:
                if buf[i] != SYNC:
                    # Lost alignment: hunt for the next sync byte. The very first
                    # lock is initial alignment (peeked bytes may start mid-packet)
                    # and is not counted as a stream error.
                    j = buf.find(b"\x47", i + 1)
                    if j == -1:
                        i = n
                        break
                    if aligned:
                        sync_errors += 1
                    i = j
                    continue
                aligned = True

                pkt = buf[i:i + PACKET]
                i += PACKET
                total_packets += 1

                tei = (pkt[1] & 0x80) >> 7
                pid = ((pkt[1] & 0x1F) << 8) | pkt[2]
                afc = (pkt[3] >> 4) & 0x03
                cc = pkt[3] & 0x0F

                if pid == NULL_PID:
                    continue
                pid_packets[pid] = pid_packets.get(pid, 0) + 1
                if tei:
                    error_indicator += 1
                    continue

                has_payload = afc in (1, 3)
                # discontinuity_indicator lives in the adaptation field flags.
                disc_flag = False
                if afc in (2, 3) and pkt[4] > 0:
                    disc_flag = bool(pkt[5] & 0x80)

                if pid in last_cc:
                    prev = last_cc[pid]
                    if disc_flag:
                        pass  # signalled discontinuity — not a queue break
                    elif not has_payload:
                        # CC must NOT change when there is no payload.
                        if cc != prev:
                            cc_errors[pid] = cc_errors.get(pid, 0) + 1
                    else:
                        expected = (prev + 1) & 0x0F
                        if cc == prev:
                            dup_packets += 1  # allowed single duplicate
                        elif cc != expected:
                            cc_errors[pid] = cc_errors.get(pid, 0) + 1
                last_cc[pid] = cc

            # Drop consumed bytes, keep the remainder.
            del buf[:i]
    finally:
        try:
            resp.close()
        except Exception:
            pass

    elapsed = max(0.1, min(duration, time.time() - (deadline - duration)))
    total_cc_err = sum(cc_errors.values())
    d = report["details"]
    d["bytes"] = total_bytes
    d["packets"] = total_packets
    d["kbit_s"] = int(total_bytes * 8 / 1000 / elapsed)
    d["sync_errors"] = sync_errors
    d["error_indicator_packets"] = error_indicator
    d["duplicate_packets"] = dup_packets
    d["continuity_errors"] = total_cc_err
    d["continuity_errors_by_pid"] = {("0x%04X" % p): c for p, c in sorted(cc_errors.items())}
    d["pids"] = {("0x%04X" % p): c for p, c in sorted(pid_packets.items())}
    d["stalls"] = stalls
    if total_cc_err:
        report["errors"].append("%d continuity-counter break(s) — the packet queue is breaking"
                                % total_cc_err)
    if sync_errors:
        report["errors"].append("%d sync-byte loss event(s)" % sync_errors)
    # "Queue intact" is about ordering/loss (CC + sync); stalls are a separate
    # delivery-smoothness signal. Both must be clean for overall health.
    # --tolerance allows N transient breaks (rare source glitches relayed by
    # `-c copy`) before the queue is declared broken.
    queue_breaks = total_cc_err + sync_errors
    queue_intact = (total_packets > 0 and queue_breaks <= tolerance)
    d["queue_breaks"] = queue_breaks
    d["queue_intact"] = queue_intact
    report["healthy"] = queue_intact and stalls == 0


# ────────────────────────────── output ───────────────────────────────

def print_human(r):
    print("Source : %s" % r["url"])
    print("Mode   : %s" % r["mode"])
    d = r["details"]
    if r["mode"] == "hls":
        print("Media  : %s" % d.get("media_playlist", "?"))
        print("Polls  : %s" % d.get("polls", 0))
        print("Segments delivered : %s (failed %s)" % (d.get("segments_delivered", 0), d.get("segments_failed", 0)))
        print("Queue gaps         : %s" % d.get("queue_gaps", 0))
        print("Rewinds            : %s" % d.get("rewinds", 0))
        print("Discontinuities    : %s" % d.get("discontinuities", 0))
        print("Stalls             : %s" % d.get("stalls", 0))
    else:
        print("Throughput : %s kbit/s   packets %s   bytes %s" % (d.get("kbit_s", 0), d.get("packets", 0), d.get("bytes", 0)))
        print("Continuity errors  : %s  %s" % (d.get("continuity_errors", 0), d.get("continuity_errors_by_pid") or ""))
        print("Sync loss / TEI    : %s / %s" % (d.get("sync_errors", 0), d.get("error_indicator_packets", 0)))
        print("Duplicate packets  : %s" % d.get("duplicate_packets", 0))
        print("Stalls             : %s" % d.get("stalls", 0))
        print("PIDs               : %s" % (d.get("pids") or {}))
    for e in r["errors"]:
        print("  ! %s" % e)
    print("QUEUE    : %s" % ("OK — intact" if d.get("queue_intact") else "BROKEN"))
    print("DELIVERY : %s" % ("OK" if d.get("stalls", 0) == 0 else "%d stall(s)" % d.get("stalls", 0)))
    print("OVERALL  : %s" % ("HEALTHY" if r["healthy"] else "PROBLEM"))


# ─────────────────────────── live dashboard ──────────────────────────
#
# A virtual player model: the playhead advances at wall-clock rate, content is
# "received" as data arrives. For TS the received timeline comes from PCR (the
# stream clock); for HLS from the segments' EXTINF durations. Buffered playtime
# ("cache") = received_seconds - played_seconds. If it hits zero the playhead
# freezes (rebuffer). The dashboard draws a colored buffer-depth graph over time,
# the playhead, and how much is left in cache.

ANSI = {
    "reset": "\033[0m", "dim": "\033[2m", "bold": "\033[1m",
    "green": "\033[92m", "yellow": "\033[93m", "red": "\033[91m",
    "cyan": "\033[96m", "blue": "\033[94m", "grey": "\033[90m",
    "home": "\033[H", "clear": "\033[2J", "clreol": "\033[K",
    "hide": "\033[?25l", "show": "\033[?25h",
}
SPARK = "▁▂▃▄▅▆▇█"


def colors(enabled):
    return ANSI if enabled else {k: "" for k in ANSI}


def parse_extinf(text):
    """Segment durations in playlist order (aligned with parse_media_playlist's
    seg_uris)."""
    durs = []
    for line in text.replace("\r\n", "\n").split("\n"):
        line = line.strip()
        if line.startswith("#EXTINF:"):
            val = line.split(":", 1)[1].split(",", 1)[0]
            try:
                durs.append(float(val))
            except ValueError:
                durs.append(0.0)
    return durs


class LiveModel:
    def __init__(self):
        self.lock = threading.Lock()
        self.start = time.time()
        self.mode = "?"
        self.total_bytes = 0
        self.last_data = time.time()
        self.kbit_s = 0
        self.cc_errors = 0
        self.sync_errors = 0
        self.stopped = False
        self.error = None
        # TS: received media seconds from PCR
        self.received_media = 0.0
        self.have_pcr = False
        # HLS: downloaded segments (idx, dur, downloaded)
        self.segments = []
        self.hls_gaps = 0
        self.hls_disc = 0

    def received_seconds(self):
        if self.mode == "ts":
            return self.received_media
        return sum(s["dur"] for s in self.segments if s["downloaded"])


def ts_reader(url, ua, model, stop):
    try:
        resp = http_open(url, ua, timeout=15)
    except Exception as e:
        with model.lock:
            model.error = "open failed: %s" % e
            model.stopped = True
        return
    buf = bytearray()
    aligned = False
    prev_cc = {}
    prev_pcr = [None]
    win_bytes = 0
    win_t = time.time()
    try:
        while not stop.is_set():
            try:
                chunk = resp.read(65536)
            except Exception:
                break
            if not chunk:
                time.sleep(0.05)
                continue
            now = time.time()
            win_bytes += len(chunk)
            local_cc = local_sync = 0
            pcr_seen = []
            buf.extend(chunk)
            i, n = 0, len(buf)
            while i + PACKET <= n:
                if buf[i] != SYNC:
                    j = buf.find(b"\x47", i + 1)
                    if j == -1:
                        i = n
                        break
                    if aligned:
                        local_sync += 1
                    i = j
                    continue
                aligned = True
                pkt = buf[i:i + PACKET]
                i += PACKET
                pid = ((pkt[1] & 0x1F) << 8) | pkt[2]
                afc = (pkt[3] >> 4) & 0x03
                cc = pkt[3] & 0x0F
                if pid == NULL_PID:
                    continue
                if afc in (2, 3) and pkt[4] > 0 and (pkt[5] & 0x10):
                    b = pkt[6:12]
                    base = (b[0] << 25) | (b[1] << 17) | (b[2] << 9) | (b[3] << 1) | (b[4] >> 7)
                    pcr_seen.append(base / 90000.0)
                if pkt[1] & 0x80:  # transport_error_indicator
                    continue
                has_payload = afc in (1, 3)
                disc = bool(afc in (2, 3) and pkt[4] > 0 and (pkt[5] & 0x80))
                if pid in prev_cc:
                    p = prev_cc[pid]
                    if disc:
                        pass
                    elif not has_payload:
                        if cc != p:
                            local_cc += 1
                    else:
                        if cc != p and cc != ((p + 1) & 0x0F):
                            local_cc += 1
                prev_cc[pid] = cc
            del buf[:i]
            with model.lock:
                model.total_bytes += len(chunk)
                model.last_data = now
                model.cc_errors += local_cc
                model.sync_errors += local_sync
                for pcr in pcr_seen:
                    if prev_pcr[0] is not None:
                        d = pcr - prev_pcr[0]
                        if 0 < d < 10:
                            model.received_media += d
                    prev_pcr[0] = pcr
                    model.have_pcr = True
                if now - win_t >= 1.0:
                    model.kbit_s = int(win_bytes * 8 / 1000 / (now - win_t))
                    win_bytes = 0
                    win_t = now
    finally:
        try:
            resp.close()
        except Exception:
            pass
        with model.lock:
            model.stopped = True


def hls_poller(media_url, ua, model, stop):
    highest = [None]
    while not stop.is_set():
        try:
            _, _, raw = http_get(media_url, ua, timeout=15)
            text = raw.decode("utf-8", "replace")
            pl = parse_media_playlist(text)
            durs = parse_extinf(text)
        except Exception:
            time.sleep(1)
            continue
        target = pl["target"] or 4.0
        base = pl["media_seq"]
        with model.lock:
            model.hls_disc = max(model.hls_disc, pl["disc_count"])
        for pos, uri in enumerate(pl["seg_uris"]):
            idx = base + pos
            if highest[0] is None:
                highest[0] = idx - 1
            if idx <= highest[0]:
                continue
            if idx > highest[0] + 1:
                with model.lock:
                    model.hls_gaps += idx - (highest[0] + 1)
            dur = durs[pos] if pos < len(durs) else target
            seg = {"idx": idx, "dur": dur, "downloaded": False}
            try:
                st, _, body = http_get(urljoin(media_url, uri), ua, timeout=15)
                if st == 200 and body:
                    seg["downloaded"] = True
                    with model.lock:
                        model.total_bytes += len(body)
                        model.last_data = time.time()
                        model.kbit_s = int(len(body) * 8 / 1000 / max(0.5, dur))
            except Exception:
                pass
            with model.lock:
                model.segments.append(seg)
            highest[0] = idx
        time.sleep(max(1.0, target / 2))


def _buf_color(c, frac):
    return c["green"] if frac >= 0.5 else c["yellow"] if frac >= 0.2 else c["red"]


def render_frame(c, m, buffer_ahead, played, state, target, hist, rebuffers):
    """Build the dashboard as a list of lines (each cleared to EOL)."""
    up = int(time.time() - m["start"])
    lines = []

    def L(s=""):
        lines.append(s + c["clreol"])

    L(c["bold"] + c["cyan"] + "  STREAM QUEUE / BUFFER MONITOR" + c["reset"]
      + c["grey"] + "   %s   up %02d:%02d" % (m["mode"].upper(), up // 60, up % 60) + c["reset"])
    L(c["grey"] + "  " + (m["url"][:76]) + c["reset"])
    L()

    # Buffer-depth graph over time (colored sparkline).
    spark = ""
    for v in hist:
        frac = max(0.0, min(1.0, v / target)) if target > 0 else 0.0
        ch = SPARK[min(len(SPARK) - 1, int(frac * (len(SPARK) - 1) + 0.5))]
        spark += _buf_color(c, frac) + ch
    spark += c["reset"]
    L("  cache buffer (s), last %ds:" % len(hist))
    L("  " + spark)
    L()

    # Current buffer gauge.
    frac = max(0.0, min(1.0, buffer_ahead / target)) if target > 0 else 0.0
    width = 30
    filled = int(round(frac * width))
    gauge = _buf_color(c, frac) + "█" * filled + c["grey"] + "░" * (width - filled) + c["reset"]
    L("  IN CACHE : [%s] %s%5.1fs%s / %ds" % (gauge, _buf_color(c, frac), buffer_ahead, c["reset"], int(target)))

    # Playhead / state.
    st_color = {"PLAYING": c["green"], "PREBUFFER": c["yellow"], "BUFFERING": c["red"]}.get(state, c["grey"])
    L("  PLAYING  : %s%-10s%s  head %02d:%02d   received %02d:%02d"
      % (st_color, state, c["reset"], int(played) // 60, int(played) % 60,
         int(m["received"]) // 60, int(m["received"]) % 60))
    L()

    # HLS: segments remaining in cache ahead of the playhead.
    if m["mode"] == "hls":
        segs = sorted((s for s in m["segments"] if s["downloaded"]), key=lambda s: s["idx"])
        run = 0.0
        ahead = []
        for s in segs:
            end = run + s["dur"]
            if end > played:  # not fully played yet
                ahead.append(s)
            run = end
        blocks = ""
        for s in ahead[:20]:
            w = max(1, int(round(s["dur"])))
            blocks += c["green"] + "█" * w + c["grey"] + "|" + c["reset"]
        L("  segments in cache: %s%d%s  (%.1fs)" % (c["bold"], len(ahead), c["reset"], sum(s["dur"] for s in ahead)))
        L("  " + (blocks or (c["grey"] + "(none)" + c["reset"])))
        L()

    # Delivery / queue health.
    age = time.time() - m["last_data"]
    recv_c = c["green"] if age < 3 else c["red"]
    L("  rate %s%d kbit/s%s   received %.1f MB   last data %s%.1fs ago%s"
      % (c["cyan"], m["kbit_s"], c["reset"], m["total_bytes"] / 1048576.0, recv_c, age, c["reset"]))
    q_ok = (m["cc_errors"] == 0 and m["sync_errors"] == 0 and m["hls_gaps"] == 0)
    qc = c["green"] if q_ok else c["red"]
    L("  QUEUE %s%s%s   cc:%d sync:%d gaps:%d disc:%d   rebuffers:%s%d%s"
      % (qc, "OK" if q_ok else "BREAKING", c["reset"],
         m["cc_errors"], m["sync_errors"], m["hls_gaps"], m["hls_disc"],
         (c["green"] if rebuffers == 0 else c["red"]), rebuffers, c["reset"]))
    L()
    L(c["grey"] + "  Ctrl-C to quit" + c["reset"])
    return "\n".join(lines)


def run_live(url, ua, args):
    tty = sys.stdout.isatty()
    c = colors(not args.no_color and tty)
    try:
        kind, final_url, _head = detect_kind(url, ua)
    except Exception as e:
        print("cannot open stream: %s" % e)
        return 2

    model = LiveModel()
    model.mode = kind
    model.url = url
    stop = threading.Event()

    if kind == "ts":
        worker = threading.Thread(target=ts_reader, args=(final_url, ua, model, stop), daemon=True)
    else:
        try:
            _, _, body = http_get(final_url, ua)
            media_url, _ = resolve_master(final_url, body.decode("utf-8", "replace"), ua)
        except Exception as e:
            print("cannot start HLS: %s" % e)
            return 2
        worker = threading.Thread(target=hls_poller, args=(media_url, ua, model, stop), daemon=True)
    worker.start()

    prebuffer = args.prebuffer
    target = args.buffer_target
    playing = False
    played = 0.0
    rebuffers = 0
    starving = False
    hist = [0.0] * 60
    last_tick = time.time()

    if tty:
        sys.stdout.write(c["hide"] + c["clear"])
    deadline = model.start + args.duration if args.duration > 0 else None
    tick = 0
    try:
        while True:
            now = time.time()
            dt = now - last_tick
            last_tick = now
            with model.lock:
                recv = model.received_seconds()
                snap = {
                    "start": model.start, "mode": model.mode, "url": model.url,
                    "total_bytes": model.total_bytes, "last_data": model.last_data,
                    "kbit_s": model.kbit_s, "cc_errors": model.cc_errors,
                    "sync_errors": model.sync_errors, "hls_gaps": model.hls_gaps,
                    "hls_disc": model.hls_disc, "received": recv,
                    "segments": list(model.segments),
                }
                stopped = model.stopped
                err = model.error

            if not playing and recv >= prebuffer:
                playing = True
            state = "PLAYING" if playing else "PREBUFFER"
            if playing:
                if recv - played > 1e-6:
                    played += dt
                    if played > recv:
                        played = recv
                    starving = False
                else:
                    state = "BUFFERING"
                    if not starving:
                        rebuffers += 1
                        starving = True
            buffer_ahead = max(0.0, recv - played)

            # update history once per ~1s of ticks (4 ticks/s → every 4th)
            tick += 1
            if tick % 4 == 0:
                hist.append(buffer_ahead)
                hist = hist[-60:]

            frame = render_frame(c, snap, buffer_ahead, played, state, target, hist, rebuffers)
            if tty:
                sys.stdout.write(c["home"] + frame + "\n")
            else:
                sys.stdout.write(frame + "\n" + "-" * 40 + "\n")
            sys.stdout.flush()

            if err:
                print("\n" + err)
                break
            if stopped and buffer_ahead <= 0:
                break
            if deadline and now >= deadline:
                break
            time.sleep(0.25)
    except KeyboardInterrupt:
        pass
    finally:
        stop.set()
        if tty:
            sys.stdout.write(c["show"] + c["reset"] + "\n")
    return 0


# ─────────────────────────── m3u playlist batch ──────────────────────────
#
# Parse an .m3u channel list (EXTINF name + URL pairs), test each stream for a
# fixed duration, and emit ONE JSON document with a per-stream verdict plus a
# per-second time-series (the "graph" from the live dashboard, as data).

def parse_m3u(text):
    """Extract channel entries from an .m3u/.m3u8 CHANNEL list (not a media
    playlist): pairs of `#EXTINF:<dur> <attrs>,<name>` + the following URL line.
    Bare URL lines (no EXTINF) are accepted too. Returns [{"name","url","attrs"}]."""
    entries = []
    name, attrs = None, ""
    for raw in text.replace("\r\n", "\n").replace("\r", "\n").split("\n"):
        line = raw.strip()
        if not line:
            continue
        if line.startswith("#EXTINF:"):
            rest = line[len("#EXTINF:"):]
            if "," in rest:
                meta, nm = rest.rsplit(",", 1)
                name, attrs = nm.strip(), meta.strip()
            else:
                name, attrs = None, rest.strip()
        elif line.startswith("#"):
            continue  # other tags (#EXTM3U, #EXTVLCOPT, …)
        else:
            entries.append({"name": name or line, "url": line, "attrs": attrs})
            name, attrs = None, ""
    return entries


def load_playlist_source(src, ua):
    """Read an .m3u from a local file path or an http(s) URL."""
    if src.startswith("http://") or src.startswith("https://"):
        _, _, body = http_get(src, ua)
        return body.decode("utf-8", "replace")
    with open(src, "r", encoding="utf-8", errors="replace") as f:
        return f.read()


def run_batch_stream(url, name, ua, duration, args):
    """Headless per-stream test used by the `playlist` subcommand. Drives the same
    LiveModel + reader worker as the live dashboard, but instead of rendering ANSI
    it samples the metrics once per second into a JSON time-series and derives a
    queue verdict from the accumulated counters. One connection per stream."""
    rec = {"name": name, "url": url, "mode": "?", "duration": duration,
           "healthy": False, "details": {}, "samples": [], "errors": []}
    try:
        kind, final_url, _head = detect_kind(url, ua)
    except Exception as e:
        rec["errors"].append("cannot open: %s" % e)
        return rec

    model = LiveModel()
    model.mode = kind
    model.url = url
    rec["mode"] = kind
    stop = threading.Event()
    if kind == "ts":
        worker = threading.Thread(target=ts_reader, args=(final_url, ua, model, stop), daemon=True)
    else:
        try:
            _, _, body = http_get(final_url, ua)
            media_url, _ = resolve_master(final_url, body.decode("utf-8", "replace"), ua)
        except Exception as e:
            rec["errors"].append("cannot start HLS: %s" % e)
            return rec
        worker = threading.Thread(target=hls_poller, args=(media_url, ua, model, stop), daemon=True)
    worker.start()

    played, rebuffers, max_gap = 0.0, 0, 0.0
    playing = starving = stalled = False
    deadline = model.start + duration
    last_tick = time.time()
    next_sample = model.start
    try:
        while time.time() < deadline:
            now = time.time()
            dt = now - last_tick
            last_tick = now
            with model.lock:
                recv = model.received_seconds()
                kbit, last_data = model.kbit_s, model.last_data
                cc, sync = model.cc_errors, model.sync_errors
                gaps, disc = model.hls_gaps, model.hls_disc
                stopped, err = model.stopped, model.error
            gap = now - last_data
            max_gap = max(max_gap, gap)
            if gap > args.stall_timeout:
                stalled = True
            if not playing and recv >= args.prebuffer:
                playing = True
            state = "PLAYING" if playing else "PREBUFFER"
            if playing:
                if recv - played > 1e-6:
                    played = min(recv, played + dt)
                    starving = False
                else:
                    state = "BUFFERING"
                    if not starving:
                        rebuffers += 1
                        starving = True
            buffer_ahead = max(0.0, recv - played)
            if now >= next_sample:
                rec["samples"].append({
                    "t": round(now - model.start, 1), "kbit_s": kbit,
                    "buffer_s": round(buffer_ahead, 1), "received_s": round(recv, 1),
                    "state": state, "cc_errors": cc, "sync_errors": sync,
                    "gaps": gaps, "disc": disc,
                })
                next_sample += 1.0
            if err:
                rec["errors"].append(err)
                break
            if stopped and buffer_ahead <= 0:
                break
            time.sleep(0.25)
    finally:
        stop.set()

    with model.lock:
        got = (model.total_bytes > 0) if kind == "ts" \
            else any(s["downloaded"] for s in model.segments)
        rec["details"] = {
            "kbit_s": model.kbit_s, "total_bytes": model.total_bytes,
            "cc_errors": model.cc_errors, "sync_errors": model.sync_errors,
            "hls_gaps": model.hls_gaps, "hls_disc": model.hls_disc,
            "received_s": round(model.received_seconds(), 1),
            "rebuffers": rebuffers, "max_data_gap_s": round(max_gap, 1),
            "stalled": stalled,
        }
    d = rec["details"]
    queue_breaks = d["cc_errors"] + d["sync_errors"] + d["hls_gaps"] + d["hls_disc"]
    # HLS segment length is often INTENTIONALLY variable (panels randomise it to
    # defeat ISP traffic-pattern analysis), so a long inter-segment delivery gap
    # is not a fault by itself — judge HLS by real playback starvation (a buffer
    # that actually drained → rebuffer). A continuous TS byte stream has no such
    # excuse: a gap beyond --stall-timeout is a genuine stall.
    playback_ok = (rebuffers == 0) if kind == "hls" else (not stalled)
    rec["healthy"] = bool(got and queue_breaks <= args.tolerance
                          and playback_ok and not rec["errors"])
    return rec


def run_playlist(args):
    duration = args.duration if args.duration is not None else 120
    try:
        text = load_playlist_source(args.playlist, args.ua)
    except Exception as e:
        print("cannot read playlist: %s" % e, file=sys.stderr)
        return 1
    entries = parse_m3u(text)
    if not entries:
        print("no streams found in playlist", file=sys.stderr)
        return 1
    if args.out_dir:
        try:
            os.makedirs(args.out_dir, exist_ok=True)
        except OSError as e:
            print("cannot create --out-dir %s: %s" % (args.out_dir, e), file=sys.stderr)
            return 1
    streams = []
    for i, e in enumerate(entries, 1):
        print("[%d/%d] %s — testing %ss …" % (i, len(entries), e["name"], duration),
              file=sys.stderr, flush=True)
        rec = run_batch_stream(e["url"], e["name"], args.ua, duration, args)
        print("      %s (%s)" % ("OK" if rec["healthy"] else "FAIL",
                                 "; ".join(rec["errors"]) or rec["mode"]),
              file=sys.stderr, flush=True)
        # Per-stream log file (written as each stream finishes, so logs appear live).
        if args.out_dir:
            fname = "%02d-%s.json" % (i, slugify(e["name"]))
            fpath = os.path.join(args.out_dir, fname)
            try:
                with open(fpath, "w", encoding="utf-8") as fh:
                    json.dump(rec, fh, indent=2, ensure_ascii=False)
                print("      → %s" % fpath, file=sys.stderr, flush=True)
            except OSError as err:
                print("      (could not write %s: %s)" % (fpath, err), file=sys.stderr)
        streams.append(rec)
    healthy = sum(1 for s in streams if s["healthy"])
    out = {
        "generated_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
        "duration_per_stream": duration,
        "streams": streams,
        "summary": {"total": len(streams), "healthy": healthy,
                    "failed": len(streams) - healthy},
    }
    # Interactive terminal → a short human summary (the full JSON dump floods the
    # console). Redirected/piped stdout (a file, the harness) → the full JSON, so
    # machine consumers keep working unchanged.
    if sys.stdout.isatty():
        s = out["summary"]
        print("%d streams: %d healthy, %d failed  (%ss each)"
              % (s["total"], s["healthy"], s["failed"], duration))
        for st in out["streams"]:
            extra = (" — " + "; ".join(st["errors"])) if st["errors"] else ""
            print("  %-4s %-4s %s%s"
                  % ("OK" if st["healthy"] else "FAIL", st["mode"], st["name"], extra))
        if args.out_dir:
            print("per-stream JSON → %s/" % args.out_dir)
    else:
        print(json.dumps(out, indent=2, ensure_ascii=False))
    return 0 if healthy == len(streams) else 2


# ═══════════════════════════ SVG grapher ═════════════════════════════
#
# Render the JSON produced by `check`/`playlist` (per-stream log files or an
# aggregate report) into standalone SVG images you can eyeball and compare side
# by side. For every stream it draws the "graph" from the live dashboard as a
# static image: throughput (kbit/s) over time, the buffered-seconds curve on a
# second axis, shaded bands where the virtual player was not PLAYING, and red
# ticks where the segment/packet queue broke (CC/sync/gap/discontinuity).

# ── palette (light theme; good for embedding / diffing as an image) ──
BG = "#ffffff"
INK = "#374151"
MUTED = "#6b7280"
GRID = "#e5e7eb"
BITRATE = "#2563eb"
BITRATE_FILL = "#2563eb"       # drawn with BITRATE_FILL_OPACITY (see below)
BITRATE_FILL_OPACITY = 0.13
BUFFER = "#16a34a"
ERRTICK = "#dc2626"
NOTPLAY = "#f59e0b"            # amber band: virtual player not PLAYING
NOTPLAY_OPACITY = 0.13
# NOTE: keep every colour a 6-digit hex. Some SVG renderers (including the one
# in the IDE preview panel) do not understand 8-digit #rrggbbaa notation and
# fall back to BLACK — which floods the plot with the area/band fills. Express
# translucency with a separate fill-opacity attribute instead.
OK_BG = "#16a34a"
FAIL_BG = "#dc2626"

# Golden-ratio hue stepping — gives a UNIQUE, well-separated colour for every
# stream at any count (no palette cycling): consecutive streams land far apart
# on the wheel and no two hues coincide. Fixed saturation/lightness keep every
# line readable on a white ground.
_GOLDEN = 0.618033988749895


def distinct_colors(n):
    cols = []
    h = 0.11  # starting hue (a pleasant blue-ish green); arbitrary but stable
    for _ in range(max(0, n)):
        r, g, b = colorsys.hls_to_rgb(h % 1.0, 0.45, 0.68)
        cols.append("#%02x%02x%02x" % (round(r * 255), round(g * 255), round(b * 255)))
        h += _GOLDEN
    return cols


def esc(s):
    return (str(s).replace("&", "&amp;").replace("<", "&lt;")
            .replace(">", "&gt;").replace('"', "&quot;"))


def nice_max(v):
    """Round an axis maximum up to a clean 1/2/2.5/5 * 10^n value."""
    if v is None or v <= 0:
        return 1.0
    exp = math.floor(math.log10(v))
    base = 10 ** exp
    for m in (1, 2, 2.5, 5, 10):
        if v <= m * base:
            return m * base
    return 10 * base


def fmt(v):
    """Compact number for axis labels."""
    if v >= 100 or float(v).is_integer():
        return str(int(round(v)))
    return ("%.1f" % v).rstrip("0").rstrip(".")


# ──────────────────────────── collect input ──────────────────────────

def collect_records(paths):
    """Expand a mix of files and directories into a flat list of per-stream
    record dicts. Directories are scanned for *.json (non-recursive); the same
    file reached twice (e.g. a directory plus one of its files) is read once."""
    recs = []
    seen = set()
    for p in paths:
        files = []
        if os.path.isdir(p):
            files = [os.path.join(p, f) for f in sorted(os.listdir(p))
                     if f.lower().endswith(".json")]
        elif os.path.isfile(p):
            files = [p]
        else:
            print("skip (not found): %s" % p, file=sys.stderr)
        for f in files:
            key = os.path.realpath(f)
            if key in seen:
                continue
            seen.add(key)
            try:
                with open(f, encoding="utf-8") as fh:
                    data = json.load(fh)
            except Exception as e:
                print("skip (bad JSON) %s: %s" % (f, e), file=sys.stderr)
                continue
            if isinstance(data, dict) and isinstance(data.get("streams"), list):
                recs.extend(data["streams"])
            elif isinstance(data, dict) and ("samples" in data or "name" in data):
                recs.append(data)
            else:
                print("skip (unrecognized) %s" % f, file=sys.stderr)
    return recs


# ─────────────────────────── SVG primitives ──────────────────────────

def _line(x1, y1, x2, y2, stroke, w=1, dash=None):
    d = ' stroke-dasharray="%s"' % dash if dash else ""
    return ('<line x1="%.1f" y1="%.1f" x2="%.1f" y2="%.1f" stroke="%s" '
            'stroke-width="%s"%s/>' % (x1, y1, x2, y2, stroke, w, d))


def _text(x, y, s, size=12, fill=INK, anchor="start", weight="normal"):
    return ('<text x="%.1f" y="%.1f" font-family="system-ui,Segoe UI,Roboto,'
            'sans-serif" font-size="%s" font-weight="%s" fill="%s" '
            'text-anchor="%s">%s</text>'
            % (x, y, size, weight, fill, anchor, esc(s)))


def _polyline(points, stroke, w=2, fill="none", fill_opacity=None):
    pts = " ".join("%.1f,%.1f" % (x, y) for x, y in points)
    fo = ' fill-opacity="%s"' % fill_opacity if fill_opacity is not None else ""
    return ('<polyline points="%s" fill="%s"%s stroke="%s" stroke-width="%s" '
            'stroke-linejoin="round" stroke-linecap="round"/>'
            % (pts, fill, fo, stroke, w))


# ──────────────────────────── per-stream ─────────────────────────────

def _plot_frame(parts, x0, y0, plot_w, plot_h, xmax, ymax, y_label, y_ticks=5):
    """Axes, horizontal grid + left y ticks, x ticks. Returns nothing."""
    parts.append('<rect x="%.1f" y="%.1f" width="%.1f" height="%.1f" fill="none" '
                 'stroke="%s"/>' % (x0, y0, plot_w, plot_h, GRID))
    for i in range(y_ticks + 1):
        yv = ymax * i / y_ticks
        yy = y0 + plot_h - (plot_h * i / y_ticks)
        if i:
            parts.append(_line(x0, yy, x0 + plot_w, yy, GRID, 1))
        parts.append(_text(x0 - 8, yy + 4, fmt(yv), 11, MUTED, "end"))
    parts.append(_text(x0 - 8, y0 - 10, y_label, 11, MUTED, "end"))
    # x ticks (~6)
    steps = 6
    for i in range(steps + 1):
        tv = xmax * i / steps
        xx = x0 + plot_w * i / steps
        parts.append(_line(xx, y0 + plot_h, xx, y0 + plot_h + 4, GRID, 1))
        parts.append(_text(xx, y0 + plot_h + 18, fmt(tv) + "s", 11, MUTED, "middle"))


def render_stream_svg(rec, width=920, height=460):
    name = rec.get("name", "stream")
    mode = rec.get("mode", "?")
    healthy = bool(rec.get("healthy"))
    det = rec.get("details") or {}
    samples = rec.get("samples") or []
    errors = rec.get("errors") or []
    duration = rec.get("duration") or (samples[-1].get("t", 0) if samples else 0)

    pad_l, pad_r, pad_t, pad_b = 66, 66, 96, 52
    x0, y0 = pad_l, pad_t
    plot_w = width - pad_l - pad_r
    plot_h = height - pad_t - pad_b

    ts = [s.get("t", 0) or 0 for s in samples]
    kbit = [s.get("kbit_s", 0) or 0 for s in samples]
    buf = [s.get("buffer_s", 0) or 0 for s in samples]
    xmax = max(duration or 0, max(ts) if ts else 0, 1)
    kmax = nice_max(max(kbit) if kbit else 1)
    bmax = nice_max(max(buf) if buf else 1)

    def px(t):
        return x0 + (t / xmax) * plot_w

    def pyk(v):
        return y0 + plot_h - (min(v, kmax) / kmax) * plot_h

    def pyb(v):
        return y0 + plot_h - (min(v, bmax) / bmax) * plot_h

    p = []
    p.append('<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="%d" '
             'viewBox="0 0 %d %d" font-family="system-ui,sans-serif">'
             % (width, height, width, height))
    p.append('<rect width="%d" height="%d" fill="%s"/>' % (width, height, BG))

    # ── header ──
    p.append(_text(24, 34, name, 18, INK, "start", "600"))
    p.append(_text(24, 56, "mode: %s · window: %ss" % (mode, fmt(duration)), 12, MUTED))
    badge = "OK" if healthy else "FAIL"
    bg = OK_BG if healthy else FAIL_BG
    bw = 52
    p.append('<rect x="%d" y="20" width="%d" height="24" rx="5" fill="%s"/>'
             % (width - 24 - bw, bw, bg))
    p.append(_text(width - 24 - bw / 2, 37, badge, 13, "#ffffff", "middle", "700"))

    # stats subtitle
    stat = ("avg %s kbit/s · recv %ss · cc %s · sync %s · gaps %s · disc %s · %s"
            % (fmt(det.get("kbit_s", 0) or 0), fmt(det.get("received_s", 0) or 0),
               det.get("cc_errors", 0), det.get("sync_errors", 0),
               det.get("hls_gaps", 0), det.get("hls_disc", 0),
               "stalled" if det.get("stalled") else "no stall"))
    p.append(_text(24, 76, stat, 12, MUTED))

    if not samples:
        msg = "no samples" + (" — " + "; ".join(errors) if errors else "")
        p.append(_text(width / 2, height / 2, msg, 14, FAIL_BG, "middle"))
        p.append("</svg>")
        return "".join(p)

    # ── not-PLAYING shaded bands ──
    run_start = None
    prev = None
    for s in samples:
        playing = s.get("state") == "PLAYING"
        t = s.get("t", 0) or 0
        if not playing and run_start is None:
            run_start = t
        if playing and run_start is not None:
            p.append('<rect x="%.1f" y="%.1f" width="%.1f" height="%.1f" fill="%s" fill-opacity="%s"/>'
                     % (px(run_start), y0, max(1, px(t) - px(run_start)), plot_h, NOTPLAY, NOTPLAY_OPACITY))
            run_start = None
        prev = t
    if run_start is not None:
        p.append('<rect x="%.1f" y="%.1f" width="%.1f" height="%.1f" fill="%s" fill-opacity="%s"/>'
                 % (px(run_start), y0, max(1, px(prev) - px(run_start)), plot_h, NOTPLAY, NOTPLAY_OPACITY))

    _plot_frame(p, x0, y0, plot_w, plot_h, xmax, kmax, "kbit/s")

    # right (buffer) axis ticks
    for i in range(6):
        bv = bmax * i / 5
        yy = y0 + plot_h - (plot_h * i / 5)
        p.append(_text(x0 + plot_w + 8, yy + 4, fmt(bv), 11, BUFFER, "start"))
    p.append(_text(x0 + plot_w + 8, y0 - 10, "buffer s", 11, BUFFER, "start"))

    # ── bitrate area + line ──
    bpts = [(px(s.get("t", 0) or 0), pyk(s.get("kbit_s", 0) or 0)) for s in samples]
    area = ([(x0, y0 + plot_h)] + bpts + [(bpts[-1][0], y0 + plot_h)])
    p.append(_polyline(area, "none", 0, BITRATE_FILL, BITRATE_FILL_OPACITY))
    p.append(_polyline(bpts, BITRATE, 2))

    # ── buffer line (secondary axis) ──
    if any(buf):
        p.append(_polyline([(px(s.get("t", 0) or 0), pyb(s.get("buffer_s", 0) or 0))
                            for s in samples], BUFFER, 2))

    # ── queue-break markers (where a counter increased) ──
    prevc = None
    for s in samples:
        cur = (s.get("cc_errors", 0) or 0, s.get("sync_errors", 0) or 0,
               s.get("gaps", 0) or 0, s.get("disc", 0) or 0)
        if prevc is not None and any(c > q for c, q in zip(cur, prevc)):
            xx = px(s.get("t", 0) or 0)
            p.append(_line(xx, y0, xx, y0 + plot_h, ERRTICK, 1, "3,3"))
        prevc = cur

    # ── legend ──
    lx, ly = x0, height - 16
    p.append(_line(lx, ly - 4, lx + 22, ly - 4, BITRATE, 3))
    p.append(_text(lx + 28, ly, "kbit/s", 11, INK))
    lx += 92
    p.append(_line(lx, ly - 4, lx + 22, ly - 4, BUFFER, 3))
    p.append(_text(lx + 28, ly, "buffer s", 11, INK))
    lx += 100
    p.append(_line(lx + 11, ly - 12, lx + 11, ly + 2, ERRTICK, 1, "3,3"))
    p.append(_text(lx + 28, ly, "queue break", 11, INK))

    p.append("</svg>")
    return "".join(p)


# ──────────────────────────── comparison ─────────────────────────────

def render_comparison_svg(recs, width=980, height=520):
    withdata = [r for r in recs if r.get("samples")]
    pad_l, pad_r, pad_t, pad_b = 66, 20, 64, 52
    x0, y0 = pad_l, pad_t
    plot_w = width - pad_l - pad_r
    plot_h = height - pad_t - pad_b - 20 * ((len(recs) + 2) // 3)

    xmax = max([max((s.get("t", 0) or 0) for s in r["samples"]) for r in withdata] + [1])
    kmax = nice_max(max([max((s.get("kbit_s", 0) or 0) for s in r["samples"])
                         for r in withdata] + [1]))

    def px(t):
        return x0 + (t / xmax) * plot_w

    def pyk(v):
        return y0 + plot_h - (min(v, kmax) / kmax) * plot_h

    p = []
    p.append('<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="%d" '
             'viewBox="0 0 %d %d" font-family="system-ui,sans-serif">'
             % (width, height, width, height))
    p.append('<rect width="%d" height="%d" fill="%s"/>' % (width, height, BG))
    p.append(_text(24, 34, "Bitrate comparison — %d streams" % len(recs), 17, INK,
                   "start", "600"))

    _plot_frame(p, x0, y0, plot_w, plot_h, xmax, kmax, "kbit/s")

    cols = distinct_colors(len(recs))
    for i, r in enumerate(recs):
        color = cols[i]
        samples = r.get("samples") or []
        if samples:
            p.append(_polyline([(px(s.get("t", 0) or 0), pyk(s.get("kbit_s", 0) or 0))
                                for s in samples], color, 2))

    # legend grid below the plot
    ly = y0 + plot_h + 40
    col_w = plot_w / 3
    for i, r in enumerate(recs):
        color = cols[i]
        cx = x0 + (i % 3) * col_w
        cy = ly + (i // 3) * 20
        p.append(_line(cx, cy - 4, cx + 22, cy - 4, color, 3))
        verdict = "OK" if r.get("healthy") else "FAIL"
        vcol = OK_BG if r.get("healthy") else FAIL_BG
        label = "%s" % (r.get("name", "stream"))
        p.append(_text(cx + 28, cy, label, 11, INK))
        p.append(_text(cx + col_w - 24, cy, verdict, 11, vcol, "end", "700"))

    p.append("</svg>")
    return "".join(p)


def run_graph(args):
    recs = collect_records(args.inputs)
    if not recs:
        print("no stream records found", file=sys.stderr)
        return 1

    try:
        os.makedirs(args.out_dir, exist_ok=True)
    except OSError as e:
        print("cannot create --out-dir %s: %s" % (args.out_dir, e), file=sys.stderr)
        return 1

    for i, rec in enumerate(recs, 1):
        svg = render_stream_svg(rec, args.width, args.height)
        fname = "%02d-%s.svg" % (i, slugify(rec.get("name", "stream")))
        fpath = os.path.join(args.out_dir, fname)
        with open(fpath, "w", encoding="utf-8") as fh:
            fh.write(svg)
        print("wrote %s" % fpath)

    if args.combined and recs:
        svg = render_comparison_svg(recs)
        fpath = os.path.join(args.out_dir, "comparison.svg")
        with open(fpath, "w", encoding="utf-8") as fh:
            fh.write(svg)
        print("wrote %s" % fpath)

    return 0


# ─────────────────────────────── check ───────────────────────────────

def run_check(args):
    duration = args.duration if args.duration is not None else 30

    if args.live:
        args.duration = duration
        return run_live(args.url, args.ua, args)

    report = {"url": args.url, "mode": "?", "healthy": False, "details": {}, "errors": []}
    try:
        kind, final_url, head = detect_kind(args.url, args.ua)
        if kind == "hls":
            check_hls(final_url, args.ua, duration, report)
        else:
            check_ts(final_url, args.ua, duration, head, report, args.stall_timeout, args.tolerance)
    except urllib.error.HTTPError as e:
        report["errors"].append("HTTP %s: %s" % (e.code, e.reason))
    except Exception as e:
        report["errors"].append("fatal: %s" % e)

    if args.json:
        print(json.dumps(report, indent=2, ensure_ascii=False))
    else:
        print_human(report)

    return 0 if report["healthy"] else 2


# ─────────────────────────────── main ────────────────────────────────

def build_parser():
    ap = argparse.ArgumentParser(
        prog="stream_check.py",
        description="Verify live-stream delivery (HLS / MPEG-TS) and render the "
                    "result as SVG. Dependency-free, pure Python 3 stdlib.")
    sub = ap.add_subparsers(dest="command", metavar="{check,playlist,graph}")

    # ── check ──
    c = sub.add_parser("check", help="verify one stream (or watch it live)")
    c.add_argument("url", help="stream URL (HLS .m3u8 or MPEG-TS .ts). "
                               "QUOTE URLs containing '&'")
    c.add_argument("--duration", type=int, default=None,
                   help="seconds to observe (default 30)")
    c.add_argument("--stall-timeout", type=float, default=15.0,
                   help="TS: gap in delivery (s) counted as a stall; keep it above the "
                        "segment duration so normal per-segment bursts aren't flagged "
                        "(default 15)")
    c.add_argument("--tolerance", type=int, default=0,
                   help="max transient queue breaks (CC + sync loss) tolerated before the "
                        "queue is declared BROKEN; useful to ignore rare source glitches "
                        "(default 0)")
    c.add_argument("--json", action="store_true", help="machine-readable output")
    c.add_argument("--ua", default=DEFAULT_UA, help="User-Agent header to send")
    c.add_argument("--live", action="store_true",
                   help="live colored dashboard: received segments, playhead, and buffered "
                        "cache (seconds) over time")
    c.add_argument("--prebuffer", type=float, default=10.0,
                   help="live: seconds to buffer before the virtual playhead starts (default 10)")
    c.add_argument("--buffer-target", type=float, default=30.0,
                   help="live: full-scale of the cache-buffer graph in seconds (default 30)")
    c.add_argument("--no-color", action="store_true", help="disable ANSI colors")

    # ── playlist ──
    pl = sub.add_parser("playlist", help="test every stream in an .m3u channel list → JSON")
    pl.add_argument("playlist", metavar="PATH|URL",
                    help="an .m3u channel list (local file or http[s] URL)")
    pl.add_argument("--out-dir", metavar="DIR",
                    help="also write a separate JSON log per stream into DIR "
                         "(one file '<NN>-<name>.json' each, written as that stream finishes)")
    pl.add_argument("--duration", type=int, default=None,
                    help="seconds to observe each stream (default 120)")
    pl.add_argument("--stall-timeout", type=float, default=15.0,
                    help="TS: gap in delivery (s) counted as a stall (default 15)")
    pl.add_argument("--tolerance", type=int, default=0,
                    help="max transient queue breaks tolerated before a stream is FAIL (default 0)")
    pl.add_argument("--prebuffer", type=float, default=10.0,
                    help="seconds to buffer before the virtual playhead starts (default 10)")
    pl.add_argument("--ua", default=DEFAULT_UA, help="User-Agent header to send")

    # ── graph ──
    g = sub.add_parser("graph", help="render check/playlist JSON as SVG charts")
    g.add_argument("inputs", nargs="+", help="JSON files and/or directories")
    g.add_argument("--out-dir", default="graphs", help="output directory (default: graphs)")
    g.add_argument("--combined", action="store_true",
                   help="also write comparison.svg overlaying every stream's bitrate")
    g.add_argument("--width", type=int, default=920)
    g.add_argument("--height", type=int, default=460)

    return ap


def main():
    ap = build_parser()
    args = ap.parse_args()

    if args.command == "check":
        return run_check(args)
    if args.command == "playlist":
        return run_playlist(args)
    if args.command == "graph":
        return run_graph(args)

    ap.print_help()
    return 1


if __name__ == "__main__":
    sys.exit(main())
