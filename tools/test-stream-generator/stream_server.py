#!/usr/bin/env python3
"""
XC_VM test stream generator.

Generates a synthetic, ever-changing test pattern (NO input file required) and
serves it as looping, HTTP-served streams that can be pasted straight into the
panel as a live stream source:

    /stream.ts      Continuous MPEG-TS (Content-Type: video/mp2t).
                    This is the endpoint for testing LLOD (the panel's
                    Low-Latency On-Demand processor, src/Cli/Commands/LlodCommand.php,
                    which validates the upstream Content-Type is video/mp2t).
    /stream.m3u8    Live HLS playlist + rolling .ts segments (sliding window).
                    Use as a normal live-stream source.
    /playlist.m3u   M3U channel list referencing the URLs above, for bulk import.
    /               Human-readable index listing every URL.

The picture is generated live by ffmpeg from `testsrc2` (a moving colour test
pattern) with overlays: a large running STOPWATCH (elapsed), the real WALL-CLOCK
time (handy for measuring end-to-end latency — compare the on-screen time to your
own clock), a frame counter, and two boxes sweeping across the frame. Audio is a
low 1 kHz tone. Everything is paced in real time (-re), so the panel sees a
never-ending "live" channel. Only the Python 3 standard library + ffmpeg are
required (no pip packages, no media files).

If no usable TrueType font is found, it falls back to `testsrc` (v1), whose
built-in timestamp still gives a running timer plus the moving boxes.

Examples:
    ./stream_server.py
    ./stream_server.py --host 0.0.0.0 --port 8088
    ./stream_server.py --size 1920x1080 --fps 30
    ./stream_server.py --font /usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf

Stop with Ctrl+C.
"""

import argparse
import atexit
import os
import re
import shutil
import signal
import socket
import subprocess
import sys
import tempfile
import threading
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

# MPEG-TS packets are 188 bytes; read/forward in packet-aligned chunks.
TS_PACKET = 188
TS_CHUNK = TS_PACKET * 64  # ~12 KiB

# Long-run safety: cap concurrent /stream.ts pulls (each spawns an ffmpeg) and
# bound socket writes so a stalled/half-open client cannot pin an ffmpeg + FDs
# indefinitely. Both are overridable from the CLI.
DEFAULT_MAX_TS_CLIENTS = 32
TS_WRITE_TIMEOUT = 30  # seconds a write may stall before the client is dropped

# Sliding-window HLS so the playlist never grows unbounded.
HLS_SEGMENT_TIME = 4
HLS_LIST_SIZE = 6

# 1 kHz test tone (like SMPTE bars), kept quiet.
AUDIO_SRC = "sine=frequency=1000:sample_rate=48000"
AUDIO_VOLUME = "0.2"

# Common TrueType font locations (Linux distros + macOS) probed when the user
# does not pass --font. First match wins.
FONT_CANDIDATES = [
    "/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf",
    "/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf",
    "/usr/share/fonts/dejavu/DejaVuSans-Bold.ttf",
    "/usr/share/fonts/dejavu/DejaVuSans.ttf",
    "/usr/share/fonts/TTF/DejaVuSans.ttf",
    "/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf",
    "/usr/share/fonts/liberation/LiberationSans-Regular.ttf",
    "/usr/share/fonts/truetype/freefont/FreeSans.ttf",
    "/Library/Fonts/Arial.ttf",
    "/System/Library/Fonts/Supplemental/Arial.ttf",
]

CONFIG = {}  # populated in main()


# --------------------------------------------------------------------------- #
# ffmpeg command builders (synthetic source)
# --------------------------------------------------------------------------- #
def _base_video():
    """lavfi video source spec. testsrc2 (rich moving pattern) when a font is
    available for overlays; testsrc (v1, which draws its own timestamp) as the
    font-less fallback so there is always a visible running timer."""
    src = "testsrc2" if CONFIG.get("font") else "testsrc"
    return "%s=size=%s:rate=%d" % (src, CONFIG["size"], CONFIG["fps"])


def _vf(label):
    """Build the -vf overlay chain. The moving boxes need no font; the clocks do.

    Single quotes around any value that contains ``:`` or ``,`` keep ffmpeg from
    treating them as option/filter separators — the command is exec'd directly
    (no shell), so only ffmpeg-level escaping is required.
    """
    # Two boxes sweeping across the frame — pure motion, font-independent.
    boxes = [
        "drawbox=x='mod(t*260,iw)':y=ih-70:w=120:h=36:color=red@0.9:thickness=fill",
        "drawbox=y='mod(t*150,ih)':x=24:w=36:h=120:color=cyan@0.9:thickness=fill",
    ]
    if not CONFIG.get("font"):
        # testsrc (v1) already renders a timestamp + frame number.
        return ",".join(boxes)

    tf = "fontfile=" + CONFIG["font"] + ":"
    meta = CONFIG["size"] + " @ " + str(CONFIG["fps"]) + "fps"
    texts = [
        "drawtext=" + tf + "text='XC_VM TEST - " + label + "'"
        ":x=(w-text_w)/2:y=36:fontsize=44:fontcolor=white"
        ":box=1:boxcolor=black@0.55:boxborderw=12",
        # Real wall-clock time — compare to your own clock to gauge latency.
        "drawtext=" + tf + "text='%{localtime}'"
        ":x=(w-text_w)/2:y=104:fontsize=34:fontcolor=0xFFD400"
        ":box=1:boxcolor=black@0.55:boxborderw=8",
        # Big stopwatch: elapsed time since this ffmpeg (stream) started.
        "drawtext=" + tf + "text='ELAPSED %{pts:hms}'"
        ":x=(w-text_w)/2:y=(h-text_h)/2:fontsize=72:fontcolor=0x00FF66"
        ":box=1:boxcolor=black@0.6:boxborderw=16",
        "drawtext=" + tf + "text='frame %{n}   " + meta + "'"
        ":x=(w-text_w)/2:y=h-150:fontsize=26:fontcolor=white"
        ":box=1:boxcolor=black@0.55:boxborderw=6",
    ]
    return ",".join(texts + boxes)


def _encode(for_hls):
    """Encode args (the synthetic source is raw, so it is always re-encoded)."""
    args = [
        "-c:v", "libx264", "-preset", "veryfast", "-tune", "zerolatency",
        "-pix_fmt", "yuv420p", "-c:a", "aac", "-b:a", "128k", "-ar", "48000",
    ]
    if for_hls:
        # Keyframe every segment so HLS can cut cleanly.
        args += ["-g", str(HLS_SEGMENT_TIME * CONFIG["fps"]),
                 "-force_key_frames", "expr:gte(t,n_forced*%d)" % HLS_SEGMENT_TIME]
    return args


def _source_inputs():
    return [
        "-f", "lavfi", "-i", _base_video(),
        "-f", "lavfi", "-i", AUDIO_SRC,
    ]


def build_ts_cmd(label="TS - LLOD"):
    """Per-client continuous MPEG-TS to stdout (pipe:1). The source is generated
    live, so each client simply starts "now"; there is no file to seek or loop."""
    return [
        CONFIG["ffmpeg"], "-hide_banner", "-loglevel", "error", "-re",
        *_source_inputs(),
        "-vf", _vf(label),
        "-af", "volume=" + AUDIO_VOLUME,
        *_encode(for_hls=False),
        "-mpegts_flags", "+initial_discontinuity",
        "-pat_period", "2",
        "-f", "mpegts", "pipe:1",
    ]


def build_hls_cmd(hls_dir):
    """Background HLS writer: live sliding-window playlist + segments."""
    return [
        CONFIG["ffmpeg"], "-hide_banner", "-loglevel", "error", "-re",
        *_source_inputs(),
        "-vf", _vf("HLS"),
        "-af", "volume=" + AUDIO_VOLUME,
        *_encode(for_hls=True),
        "-f", "hls",
        "-hls_time", str(HLS_SEGMENT_TIME),
        "-hls_list_size", str(HLS_LIST_SIZE),
        "-hls_flags", "delete_segments+omit_endlist+independent_segments",
        "-hls_segment_type", "mpegts",
        "-hls_segment_filename", os.path.join(hls_dir, "seg-%06d.ts"),
        os.path.join(hls_dir, "stream.m3u8"),
    ]


# --------------------------------------------------------------------------- #
# Background HLS writer with auto-restart
# --------------------------------------------------------------------------- #
class HlsWriter:
    """Keeps a single ffmpeg process producing the live HLS output alive."""

    def __init__(self, hls_dir):
        self.hls_dir = hls_dir
        self.proc = None
        self._stop = threading.Event()
        self._thread = threading.Thread(target=self._run, daemon=True)

    def start(self):
        self._thread.start()

    def _run(self):
        while not self._stop.is_set():
            self.proc = subprocess.Popen(
                build_hls_cmd(self.hls_dir),
                stdout=subprocess.DEVNULL,
                stderr=subprocess.PIPE,
            )
            _, err = self.proc.communicate()
            if self._stop.is_set():
                return
            sys.stderr.write(
                "[hls] ffmpeg exited (code %s), restarting in 2s\n%s\n"
                % (self.proc.returncode, (err or b"").decode("utf-8", "replace").strip())
            )
            time.sleep(2)

    def stop(self):
        self._stop.set()
        if self.proc and self.proc.poll() is None:
            self.proc.terminate()
            try:
                self.proc.wait(timeout=5)
            except subprocess.TimeoutExpired:
                self.proc.kill()


# --------------------------------------------------------------------------- #
# HTTP handler
# --------------------------------------------------------------------------- #
class Handler(BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"
    server_version = "XC_VM-TestStream/2.0"

    # ---- helpers ---------------------------------------------------------- #
    def _base_url(self):
        host = self.headers.get("Host") or "%s:%d" % (CONFIG["advertise_host"], CONFIG["port"])
        return "http://%s" % host

    def _send_text(self, body, content_type="text/plain; charset=utf-8", status=200):
        data = body.encode("utf-8") if isinstance(body, str) else body
        self.send_response(status)
        self.send_header("Content-Type", content_type)
        self.send_header("Content-Length", str(len(data)))
        self.send_header("Cache-Control", "no-cache")
        self.end_headers()
        if self.command != "HEAD":
            self.wfile.write(data)

    def _serve_hls_file(self, name):
        # basename() guards against path traversal.
        path = os.path.join(CONFIG["hls_dir"], os.path.basename(name))
        if not os.path.isfile(path):
            self._send_text("Not ready yet, retry in a moment.\n", status=404)
            return
        ctype = "application/vnd.apple.mpegurl" if name.endswith(".m3u8") else "video/mp2t"
        try:
            with open(path, "rb") as fh:
                data = fh.read()
        except OSError:
            self._send_text("Not ready yet, retry in a moment.\n", status=404)
            return
        self.send_response(200)
        self.send_header("Content-Type", ctype)
        self.send_header("Content-Length", str(len(data)))
        self.send_header("Cache-Control", "no-cache")
        self.end_headers()
        if self.command != "HEAD":
            self.wfile.write(data)

    def _serve_playlist_m3u(self):
        base = self._base_url()
        lines = [
            "#EXTM3U",
            '#EXTINF:-1 tvg-id="xcvm.test.ts" tvg-name="XC_VM Test TS (LLOD)" '
            'group-title="XC_VM Test",XC_VM Test TS (LLOD)',
            "%s/stream.ts" % base,
            '#EXTINF:-1 tvg-id="xcvm.test.hls" tvg-name="XC_VM Test HLS" '
            'group-title="XC_VM Test",XC_VM Test HLS',
            "%s/stream.m3u8" % base,
            "",
        ]
        self._send_text("\n".join(lines), content_type="audio/x-mpegurl")

    def _stream_ts(self):
        """Continuous MPEG-TS — the LLOD-compatible endpoint. A per-client ffmpeg
        (which the panel LLOD probe/pull expects) generating the synthetic source
        live, so opening the channel starts a fresh 'now'.

        Long-run hardening: a bounded number of concurrent pulls (each is an
        ffmpeg), a socket write timeout + TCP keepalive so a stalled/half-open
        client is dropped instead of pinning the ffmpeg forever, and a hard kill
        if terminate() does not reap it."""
        slots = CONFIG["ts_slots"]
        if not slots.acquire(blocking=False):
            sys.stderr.write("[ts] refused %s: at client cap (%d)\n"
                             % (self.address_string(), CONFIG["max_clients"]))
            self._send_text("Too many concurrent streams, try later.\n", status=503)
            return

        proc = None
        try:
            self.send_response(200)
            self.send_header("Content-Type", "video/mp2t")
            self.send_header("Cache-Control", "no-cache")
            self.send_header("Connection", "close")
            self.end_headers()
            if self.command == "HEAD":
                return

            # Drop a stalled/half-open client instead of blocking forever on a
            # write: keepalive surfaces dead peers, settimeout bounds each send.
            try:
                self.connection.setsockopt(socket.SOL_SOCKET, socket.SO_KEEPALIVE, 1)
            except OSError:
                pass
            self.connection.settimeout(TS_WRITE_TIMEOUT)

            sys.stderr.write("[ts] start %s\n" % self.address_string())
            proc = subprocess.Popen(
                build_ts_cmd(), stdout=subprocess.PIPE, stderr=subprocess.DEVNULL
            )
            while True:
                chunk = proc.stdout.read(TS_CHUNK)
                if not chunk:
                    break
                self.wfile.write(chunk)
        except (BrokenPipeError, ConnectionResetError, ConnectionAbortedError,
                socket.timeout, TimeoutError, OSError):
            pass  # client disconnected or stalled past the write timeout — expected
        finally:
            if proc is not None and proc.poll() is None:
                proc.terminate()
                try:
                    proc.wait(timeout=5)
                except subprocess.TimeoutExpired:
                    proc.kill()
                    try:
                        proc.wait(timeout=2)
                    except subprocess.TimeoutExpired:
                        pass
            slots.release()
            sys.stderr.write("[ts] end   %s\n" % self.address_string())

    def _index(self):
        base = self._base_url()
        font = CONFIG.get("font") or "(none — testsrc fallback)"
        html = """<!doctype html>
<html><head><meta charset="utf-8"><title>XC_VM test stream generator</title>
<style>body{{font-family:system-ui,sans-serif;max-width:760px;margin:40px auto;padding:0 16px}}
code{{background:#f3f3f3;padding:2px 6px;border-radius:4px}}
li{{margin:8px 0}}</style></head><body>
<h1>XC_VM test stream generator</h1>
<p>Source: <b>generated</b> ({size} @ {fps}fps) &mdash; moving test pattern with a
live stopwatch, wall-clock and sweeping boxes. No input file.</p>
<p>Font: <code>{font}</code></p>
<h2>Stream URLs</h2>
<ul>
<li><b>MPEG-TS (for LLOD)</b>: <a href="{base}/stream.ts"><code>{base}/stream.ts</code></a><br>
    Paste into the stream source field, enable <b>LLOD</b>.</li>
<li><b>HLS</b>: <a href="{base}/stream.m3u8"><code>{base}/stream.m3u8</code></a><br>
    Paste into the stream source field for a normal live stream.</li>
<li><b>M3U channel list</b>: <a href="{base}/playlist.m3u"><code>{base}/playlist.m3u</code></a><br>
    Import as a playlist to add both channels at once.</li>
</ul>
<p>Add the URL in the admin panel under the stream's
<code>stream_source[]</code> field (Streams &rarr; Add/Edit).</p>
</body></html>
""".format(base=base, size=CONFIG["size"], fps=CONFIG["fps"], font=font)
        self._send_text(html, content_type="text/html; charset=utf-8")

    # ---- routing ---------------------------------------------------------- #
    def _route(self):
        path = self.path.split("?", 1)[0]
        if path == "/" or path == "/index.html":
            self._index()
        elif path == "/stream.ts":
            self._stream_ts()
        elif path == "/stream.m3u8":
            self._serve_hls_file("stream.m3u8")
        elif path == "/playlist.m3u":
            self._serve_playlist_m3u()
        elif path.startswith("/seg-") and path.endswith(".ts"):
            self._serve_hls_file(path[1:])
        else:
            self._send_text("Not found\n", status=404)

    def do_GET(self):
        self._route()

    def do_HEAD(self):
        self._route()

    def log_message(self, fmt, *args):
        # Per-request access logging is off by default: an HLS player polls the
        # playlist + segments every few seconds, so over days this floods the
        # log (and any file it is redirected to). Meaningful events (stream
        # start/stop, refusals, hls restarts) are logged explicitly elsewhere.
        if CONFIG.get("verbose"):
            sys.stderr.write("[http] %s - %s\n" % (self.address_string(), fmt % args))


# --------------------------------------------------------------------------- #
# main
# --------------------------------------------------------------------------- #
def parse_args():
    p = argparse.ArgumentParser(
        description="Serve a generated moving test pattern as TS/HLS/M3U streams for XC_VM testing."
    )
    p.add_argument("--host", default="0.0.0.0", help="bind address (default 0.0.0.0)")
    p.add_argument("--port", type=int, default=8088, help="bind port (default 8088)")
    p.add_argument(
        "--advertise-host", default=None,
        help="host/IP to print in URLs (default: autodetected LAN IP)",
    )
    p.add_argument("--size", default="1280x720", help="frame size WxH (default 1280x720)")
    p.add_argument("--fps", type=int, default=25, help="frame rate (default 25)")
    p.add_argument(
        "--font", default=None,
        help="path to a .ttf for the on-screen clock/labels "
             "(default: autodetect; falls back to testsrc's built-in timer)",
    )
    p.add_argument("--ffmpeg", default="ffmpeg", help="ffmpeg binary (default: ffmpeg in PATH)")
    p.add_argument(
        "--max-clients", type=int, default=DEFAULT_MAX_TS_CLIENTS,
        help="max concurrent /stream.ts pulls, each is an ffmpeg (default %d)" % DEFAULT_MAX_TS_CLIENTS,
    )
    p.add_argument(
        "--verbose", action="store_true",
        help="log every HTTP request (off by default to keep long runs quiet)",
    )
    return p.parse_args()


def detect_font(explicit):
    """Return a usable .ttf path, or None. Prefers --font; then common paths;
    then anything fontconfig can point at (fc-match)."""
    if explicit:
        return explicit if os.path.isfile(explicit) else None
    for cand in FONT_CANDIDATES:
        if os.path.isfile(cand):
            return cand
    try:
        out = subprocess.check_output(
            ["fc-match", "-f", "%{file}", "sans"], stderr=subprocess.DEVNULL
        ).decode("utf-8", "replace").strip()
        if out and os.path.isfile(out):
            return out
    except Exception:
        pass
    return None


def detect_lan_ip():
    s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
    try:
        s.connect(("8.8.8.8", 80))
        return s.getsockname()[0]
    except OSError:
        return "127.0.0.1"
    finally:
        s.close()


class QuietThreadingHTTPServer(ThreadingHTTPServer):
    """Threaded HTTP server that ignores expected client disconnects.

    The panel's ffmpeg (probe/LLOD) reads part of a stream then resets the
    connection — the stdlib server would otherwise print a full traceback per
    disconnect. Those are normal, so swallow them and keep the log clean.
    """

    daemon_threads = True

    def handle_error(self, request, client_address):
        exc = sys.exc_info()[1]
        if isinstance(exc, (ConnectionResetError, BrokenPipeError,
                            ConnectionAbortedError, TimeoutError)):
            return  # client (panel) disconnected mid-request — expected
        super().handle_error(request, client_address)


def main():
    args = parse_args()

    if shutil.which(args.ffmpeg) is None and not os.path.isfile(args.ffmpeg):
        sys.exit("error: ffmpeg not found (looked for %r). Install it or pass --ffmpeg." % args.ffmpeg)
    if not re.fullmatch(r"\d+x\d+", args.size):
        sys.exit("error: --size must be WxH (e.g. 1280x720), got %r" % args.size)
    if args.fps <= 0:
        sys.exit("error: --fps must be positive")
    if args.max_clients < 1:
        sys.exit("error: --max-clients must be >= 1")

    if args.font and not os.path.isfile(args.font):
        sys.stderr.write("warning: --font %r not found; falling back.\n" % args.font)
    font = detect_font(args.font)

    hls_dir = tempfile.mkdtemp(prefix="xcvm-teststream-")
    atexit.register(lambda: shutil.rmtree(hls_dir, ignore_errors=True))

    CONFIG.update({
        "host": args.host,
        "port": args.port,
        "advertise_host": args.advertise_host or detect_lan_ip(),
        "size": args.size,
        "fps": args.fps,
        "font": font,
        "ffmpeg": args.ffmpeg,
        "hls_dir": hls_dir,
        "verbose": args.verbose,
        "max_clients": args.max_clients,
        "ts_slots": threading.BoundedSemaphore(args.max_clients),
    })

    writer = HlsWriter(hls_dir)
    writer.start()

    httpd = QuietThreadingHTTPServer((args.host, args.port), Handler)

    base = "http://%s:%d" % (CONFIG["advertise_host"], args.port)
    print("XC_VM test stream generator")
    print("  source: generated %s @ %dfps (moving pattern + stopwatch + wall-clock)" % (args.size, args.fps))
    print("  font  : %s" % (font or "(none — testsrc built-in timer)"))
    print("  bind  : %s:%d  (max %d TS clients)" % (args.host, args.port, args.max_clients))
    print("")
    print("Paste one of these into the panel's stream source field:")
    print("  TS (LLOD): %s/stream.ts" % base)
    print("  HLS      : %s/stream.m3u8" % base)
    print("  M3U list : %s/playlist.m3u" % base)
    print("  Index    : %s/" % base)
    print("")
    print("Ctrl+C to stop.")

    def shutdown(*_):
        print("\nstopping...")
        writer.stop()
        threading.Thread(target=httpd.shutdown, daemon=True).start()

    signal.signal(signal.SIGINT, shutdown)
    signal.signal(signal.SIGTERM, shutdown)

    try:
        httpd.serve_forever()
    finally:
        httpd.server_close()
        writer.stop()


if __name__ == "__main__":
    main()
