#!/bin/bash
# ──────────────────────────────────────────────────────────────────────────────
# compare-audio.sh — determine what XUI's custom "-fix_dts" flag does, and
# whether stock ffmpeg's "-copyts" reproduces it, on ac3/eac3 mpegts audio.
#
# The cleanest experiment runs on the SAME 4.0 (XUI) binary twice — with and
# without -nofix_dts — so nothing but the flag differs. Then it tries a stock
# binary (7.1) with -copyts as a drop-in candidate for a rebuilt, patch-free 4.0.
#
# Background: StreamProcess sends `-nofix_dts` ONLY to the 4.0 binary for ac3/eac3
# audio when `dts_legacy_ffmpeg` is on. `-fix_dts` ("fix invalid dts") is a XUI
# CLI patch absent from stock ffmpeg (7.1/8.0 do not recognise it). If 4.0 is
# rebuilt from a stock tarball, the panel must stop sending `-nofix_dts` — this
# script measures the safest replacement.
#
# Usage:
#   ./compare-audio.sh <ac3/eac3 mpegts source>      # url or file
#   ./compare-audio.sh /tmp/dtstest/disc.ts          # from gen-problem-stream.sh
#
# Variants compared:
#   fix_off — 4.0 with -nofix_dts        (current production reference)
#   fix_on  — 4.0 without the flag       (default DTS fixing = naive rebuild)
#   copyts  — 7.1 with -copyts +igndts   (stock replacement candidate)
#   stock   — 7.1 default                (naive stock rebuild)
#
# Ubuntu 20.04 note: uses 4.0/7.1 (both run on glibc 2.31); 8.0 needs 2.35.
# ──────────────────────────────────────────────────────────────────────────────
set -u

SRC="${1:?Usage: compare-audio.sh <ac3/eac3 mpegts source (url or file)>}"
FFDIR="${FFDIR:-/home/xc_vm/bin/ffmpeg_bin}"
FF40="${FF40:-$FFDIR/4.0/ffmpeg}"      # XUI — has custom -fix_dts (default ON)
FFSTOCK="${FFSTOCK:-$FFDIR/7.1/ffmpeg}"  # stock stand-in (glibc 2.31, runs on U20)
FFPROBE="${FFPROBE:-$FFDIR/4.0/ffprobe}"
OUT_DIR="${OUT_DIR:-/tmp/dtstest}"
mkdir -p "$OUT_DIR"

for b in "$FF40" "$FFSTOCK" "$FFPROBE"; do
    [ -x "$b" ] || command -v "$b" >/dev/null 2>&1 || { echo "not found: $b"; exit 1; }
done

# 0) Reproducible ~60s sample with ORIGINAL timestamps preserved.
echo ">>> capturing 60s sample from source ..."
"$FF40" -y -hide_banner -loglevel error -copyts -i "$SRC" -t 60 -c copy -f mpegts "$OUT_DIR/sample.ts" \
    || { echo "capture failed"; exit 1; }
echo "    sample: $(du -h "$OUT_DIR/sample.ts" | cut -f1)"
echo "    audio : $("$FFPROBE" -hide_banner -loglevel error -select_streams a:0 \
    -show_entries stream=codec_name,sample_rate,channels -of csv=p=0 "$OUT_DIR/sample.ts")"

# Transcode like the panel (video copy, audio → aac) to HLS; capture ffmpeg log.
run() {  # <label> <binary> [pre-input flags...]
    local label="$1" bin="$2"; shift 2
    "$bin" -y -hide_banner -loglevel verbose "$@" -i "$OUT_DIR/sample.ts" \
        -map 0:v:0 -map 0:a:0 -c:v copy -c:a aac -b:a 128k \
        -f hls -hls_time 6 -hls_list_size 0 \
        -hls_segment_filename "$OUT_DIR/${label}_%d.ts" "$OUT_DIR/${label}.m3u8" \
        2> "$OUT_DIR/${label}.log"
    local warn dur_a dur_v sil
    warn=$(grep -ciE 'non-monoton|invalid.*(dts|pts)|dts <|past duration' "$OUT_DIR/${label}.log")
    dur_v=$("$FFPROBE" -hide_banner -loglevel error -select_streams v:0 -show_entries stream=duration -of csv=p=0 "$OUT_DIR/${label}.m3u8" 2>/dev/null)
    dur_a=$("$FFPROBE" -hide_banner -loglevel error -select_streams a:0 -show_entries stream=duration -of csv=p=0 "$OUT_DIR/${label}.m3u8" 2>/dev/null)
    # audio dropouts/discontinuities as a glitch proxy
    sil=$("$FFSTOCK" -hide_banner -nostats -i "$OUT_DIR/${label}.m3u8" -map 0:a:0 \
          -af silencedetect=n=-40dB:d=0.3 -f null - 2>&1 | grep -c silence_start)
    printf "  %-8s | ts-warnings:%-4s | a/v dur:%s/%s | silence-gaps:%s\n" \
        "$label" "${warn:-?}" "${dur_a:-?}" "${dur_v:-?}" "${sil:-?}"
}

echo ">>> transcoding variants (video copy, audio aac):"
run fix_off "$FF40" -nofix_dts                    # reference: current production
run fix_on  "$FF40"                               # 4.0 WITHOUT the flag = default DTS fixing
run copyts  "$FFSTOCK" -copyts -fflags +igndts    # stock replacement candidate
run stock   "$FFSTOCK"                            # stock default (naive rebuild)

echo ""
echo ">>> read the columns:"
echo "    ts-warnings  — ffmpeg 'non-monotonous/invalid DTS' count (what fix_dts suppresses)"
echo "    a/v dur      — audio vs video duration; divergence = desync"
echo "    silence-gaps — audio dropouts >0.3s (click/glitch proxy)"
echo ""
echo ">>> then LISTEN (final verdict is by ear) — dump 8s wav per variant:"
echo "    for v in fix_off fix_on copyts stock; do \\"
echo "      $FFSTOCK -v error -i $OUT_DIR/\${v}_0.ts -t 8 -f wav $OUT_DIR/\${v}.wav; done"
echo "    # play /tmp/dtstest/*.wav, or scp them off the node"
