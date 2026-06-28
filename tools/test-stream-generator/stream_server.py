#!/usr/bin/env python3
"""
XC_VM test stream generator.

Turns a single MP4 file into looping, HTTP-served streams that can be pasted
straight into the panel as a live stream source:

    /stream.ts      Continuous MPEG-TS (Content-Type: video/mp2t).
                    This is the endpoint for testing LLOD (the panel's
                    Low-Latency On-Demand processor, src/Cli/Commands/LlodCommand.php,
                    which validates the upstream Content-Type is video/mp2t).
    /stream.m3u8    Live HLS playlist + rolling .ts segments (sliding window).
                    Use as a normal live-stream source.
    /playlist.m3u   M3U channel list referencing the URLs above, for bulk import.
    /               Human-readable index listing every URL.

The MP4 is looped forever and paced in real time (-re), so the panel sees a
never-ending "live" channel. Only the Python 3 standard library + ffmpeg are
required (no pip packages).

Examples:
    ./stream_server.py -i sample.mp4
    ./stream_server.py -i sample.mp4 --host 0.0.0.0 --port 8088
    ./stream_server.py -i sample.mp4 --encode h264   # re-encode for odd codecs

Stop with Ctrl+C.
"""

import argparse
import atexit
import os
import shutil
import signal
import subprocess
import sys
import tempfile
import threading
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

# MPEG-TS packets are 188 bytes; read/forward in packet-aligned chunks.
TS_PACKET = 188
TS_CHUNK = TS_PACKET * 64  # ~12 KiB

# Sliding-window HLS so the playlist never grows unbounded.
HLS_SEGMENT_TIME = 4
HLS_LIST_SIZE = 6

CONFIG = {}  # populated in main()


# --------------------------------------------------------------------------- #
# ffmpeg command builders
# --------------------------------------------------------------------------- #
def _codec_args(for_hls):
    """Return the encode/copy arguments shared by the TS and HLS pipelines."""
    if CONFIG["encode"] == "h264":
        args = [
            "-c:v", "libx264", "-preset", "veryfast", "-tune", "zerolatency",
            "-pix_fmt", "yuv420p", "-c:a", "aac", "-b:a", "128k", "-ar", "48000",
        ]
        if for_hls:
            # Keyframe every segment so HLS can cut cleanly.
            args += ["-g", str(HLS_SEGMENT_TIME * 25), "-force_key_frames",
                     "expr:gte(t,n_forced*%d)" % HLS_SEGMENT_TIME]
        return args
    # Default: stream copy (lightest). genpts smooths the loop boundary.
    return ["-c", "copy"]


def build_ts_cmd():
    """Per-client continuous MPEG-TS to stdout (pipe:1)."""
    return [
        CONFIG["ffmpeg"], "-hide_banner", "-loglevel", "error",
        "-re", "-stream_loop", "-1", "-i", CONFIG["input"],
        "-fflags", "+genpts",
        *_codec_args(for_hls=False),
        "-mpegts_flags", "+initial_discontinuity",
        "-pat_period", "2",
        "-f", "mpegts", "pipe:1",
    ]


def build_hls_cmd(hls_dir):
    """Background HLS writer: live sliding-window playlist + segments."""
    return [
        CONFIG["ffmpeg"], "-hide_banner", "-loglevel", "error",
        "-re", "-stream_loop", "-1", "-i", CONFIG["input"],
        "-fflags", "+genpts",
        *_codec_args(for_hls=True),
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
    server_version = "XC_VM-TestStream/1.0"

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
        """Continuous MPEG-TS — the LLOD-compatible endpoint."""
        self.send_response(200)
        self.send_header("Content-Type", "video/mp2t")
        self.send_header("Cache-Control", "no-cache")
        self.send_header("Connection", "close")
        self.end_headers()
        if self.command == "HEAD":
            return
        proc = subprocess.Popen(
            build_ts_cmd(), stdout=subprocess.PIPE, stderr=subprocess.DEVNULL
        )
        try:
            while True:
                chunk = proc.stdout.read(TS_CHUNK)
                if not chunk:
                    break
                self.wfile.write(chunk)
        except (BrokenPipeError, ConnectionResetError):
            pass  # client (panel) disconnected — expected
        finally:
            if proc.poll() is None:
                proc.terminate()
                try:
                    proc.wait(timeout=5)
                except subprocess.TimeoutExpired:
                    proc.kill()

    def _index(self):
        base = self._base_url()
        html = """<!doctype html>
<html><head><meta charset="utf-8"><title>XC_VM test stream generator</title>
<style>body{{font-family:system-ui,sans-serif;max-width:760px;margin:40px auto;padding:0 16px}}
code{{background:#f3f3f3;padding:2px 6px;border-radius:4px}}
li{{margin:8px 0}}</style></head><body>
<h1>XC_VM test stream generator</h1>
<p>Source file: <code>{input}</code> &mdash; encode mode: <code>{encode}</code></p>
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
""".format(base=base, input=CONFIG["input"], encode=CONFIG["encode"])
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
        sys.stderr.write("[http] %s - %s\n" % (self.address_string(), fmt % args))


# --------------------------------------------------------------------------- #
# main
# --------------------------------------------------------------------------- #
def parse_args():
    p = argparse.ArgumentParser(
        description="Serve a looping MP4 as TS/HLS/M3U streams for XC_VM testing."
    )
    p.add_argument("-i", "--input", required=True, help="path to the source .mp4 file")
    p.add_argument("--host", default="0.0.0.0", help="bind address (default 0.0.0.0)")
    p.add_argument("--port", type=int, default=8088, help="bind port (default 8088)")
    p.add_argument(
        "--advertise-host", default=None,
        help="host/IP to print in URLs (default: autodetected LAN IP)",
    )
    p.add_argument(
        "--encode", choices=["copy", "h264"], default="copy",
        help="copy = remux (fast, needs H.264/AAC mp4); h264 = re-encode (any codec)",
    )
    p.add_argument("--ffmpeg", default="ffmpeg", help="ffmpeg binary (default: ffmpeg in PATH)")
    return p.parse_args()


def detect_lan_ip():
    import socket
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

    if not os.path.isfile(args.input):
        sys.exit("error: input file not found: %s" % args.input)
    if shutil.which(args.ffmpeg) is None and not os.path.isfile(args.ffmpeg):
        sys.exit("error: ffmpeg not found (looked for %r). Install it or pass --ffmpeg." % args.ffmpeg)

    hls_dir = tempfile.mkdtemp(prefix="xcvm-teststream-")
    atexit.register(lambda: shutil.rmtree(hls_dir, ignore_errors=True))

    CONFIG.update({
        "input": os.path.abspath(args.input),
        "host": args.host,
        "port": args.port,
        "advertise_host": args.advertise_host or detect_lan_ip(),
        "encode": args.encode,
        "ffmpeg": args.ffmpeg,
        "hls_dir": hls_dir,
    })

    writer = HlsWriter(hls_dir)
    writer.start()

    httpd = QuietThreadingHTTPServer((args.host, args.port), Handler)

    base = "http://%s:%d" % (CONFIG["advertise_host"], args.port)
    print("XC_VM test stream generator")
    print("  input : %s" % CONFIG["input"])
    print("  encode: %s" % CONFIG["encode"])
    print("  bind  : %s:%d" % (args.host, args.port))
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
