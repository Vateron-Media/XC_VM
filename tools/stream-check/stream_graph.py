#!/usr/bin/env python3
"""
stream_graph.py — render static SVG charts from stream_queue_check.py JSON.

Turns the per-stream logs (`--out-dir` files) or an aggregate `--playlist`
report into standalone SVG images you can eyeball and compare side by side —
no dependencies (pure stdlib), same spirit as stream_queue_check.py.

For every stream it draws the "graph" from the live dashboard as a static image:
throughput (kbit/s) over time, the buffered-seconds curve on a second axis,
shaded bands where the virtual player was not PLAYING, and red ticks where the
segment/packet queue broke (CC/sync/gap/discontinuity). A header shows the
verdict (OK/FAIL) and the key numbers. `--combined` also overlays every stream's
bitrate on one chart for a direct comparison.

Run:
    # one SVG per stream + a combined comparison, into ./graphs/
    python3 tools/stream-check/stream_graph.py out/streamtest-20260823-112158/ --combined

    # a specific aggregate report
    python3 tools/stream-check/stream_graph.py out/streamtest-20260823-112158.json

    # individual per-stream files
    python3 tools/stream-check/stream_graph.py logs/01-MPEG-TS.json logs/02-*.json

Arguments:
    inputs               One or more JSON files and/or directories. A file may be
                         a per-stream record (has "samples") or an aggregate
                         report (has "streams": [...]); directories are scanned
                         for *.json. Required.

Options:
    --out-dir DIR        Where to write the SVGs (default: ./graphs).
    --combined           Also write comparison.svg overlaying every stream's
                         bitrate on one chart.
    --width PX           Chart width  (default: 920).
    --height PX          Chart height (default: 460).

SVG opens in any browser/IDE and can be converted to PNG if needed
(e.g. `rsvg-convert`, `inkscape`, or an online converter).

@package XC_VM_Tools
@license AGPL-3.0
"""

import os
import sys
import json
import math
import colorsys
import argparse

# ── palette (light theme; good for embedding / diffing as an image) ──
BG = "#ffffff"
INK = "#374151"
MUTED = "#6b7280"
GRID = "#e5e7eb"
BITRATE = "#2563eb"
BITRATE_FILL = "#2563eb22"
BUFFER = "#16a34a"
ERRTICK = "#dc2626"
NOTPLAY = "#f59e0b22"  # amber band: virtual player not PLAYING
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


def slugify(name, fallback="stream"):
    out = [c if (c.isalnum() or c in "-_.") else "_" for c in (name or "")]
    return ("".join(out).strip("._") or fallback)[:80]


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


def _polyline(points, stroke, w=2, fill="none"):
    pts = " ".join("%.1f,%.1f" % (x, y) for x, y in points)
    return ('<polyline points="%s" fill="%s" stroke="%s" stroke-width="%s" '
            'stroke-linejoin="round" stroke-linecap="round"/>' % (pts, fill, stroke, w))


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
            p.append('<rect x="%.1f" y="%.1f" width="%.1f" height="%.1f" fill="%s"/>'
                     % (px(run_start), y0, max(1, px(t) - px(run_start)), plot_h, NOTPLAY))
            run_start = None
        prev = t
    if run_start is not None:
        p.append('<rect x="%.1f" y="%.1f" width="%.1f" height="%.1f" fill="%s"/>'
                 % (px(run_start), y0, max(1, px(prev) - px(run_start)), plot_h, NOTPLAY))

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
    p.append(_polyline(area, "none", 0, BITRATE_FILL))
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

    colors = distinct_colors(len(recs))
    for i, r in enumerate(recs):
        color = colors[i]
        samples = r.get("samples") or []
        if samples:
            p.append(_polyline([(px(s.get("t", 0) or 0), pyk(s.get("kbit_s", 0) or 0))
                                for s in samples], color, 2))

    # legend grid below the plot
    ly = y0 + plot_h + 40
    col_w = plot_w / 3
    for i, r in enumerate(recs):
        color = colors[i]
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


# ─────────────────────────────── main ────────────────────────────────

def main():
    ap = argparse.ArgumentParser(description="Render static SVG charts from "
                                             "stream_queue_check.py JSON.")
    ap.add_argument("inputs", nargs="+", help="JSON files and/or directories")
    ap.add_argument("--out-dir", default="graphs", help="output directory (default: graphs)")
    ap.add_argument("--combined", action="store_true",
                    help="also write comparison.svg overlaying every stream's bitrate")
    ap.add_argument("--width", type=int, default=920)
    ap.add_argument("--height", type=int, default=460)
    args = ap.parse_args()

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


if __name__ == "__main__":
    sys.exit(main())
