#!/bin/bash
# ──────────────────────────────────────────────────────────────────────────────
# gen-problem-stream.sh — synthesize mpegts streams with ac3/eac3 audio that
# exhibit the exact defects XUI's custom "-fix_dts" flag was made to fix:
#   • non-monotonic DTS (timestamps jumping backwards mid-stream)
#   • audio/video drift (desync)
#
# Purpose: reproduce, without hunting for a real broken channel, the condition
# that StreamProcess routes to the legacy 4.0 binary with `-nofix_dts`
# (dts_legacy_ffmpeg + ac3/eac3). Feed the output into ./compare-audio.sh to
# see what -fix_dts actually changes and whether stock `-copyts` reproduces it.
#
# Output (in $OUT_DIR, default /tmp/dtstest):
#   clean.ts  — reference, no defects
#   disc.ts   — non-monotonic DTS (two 0-based parts spliced → backward jump)
#   drift.ts  — audio shifted -0.4s vs video (a/v desync)
#   combo.ts  — both defects
#
# Usage:
#   ./gen-problem-stream.sh                 # ac3 (default)
#   ACODEC=eac3 ./gen-problem-stream.sh     # E-AC-3
#   DUR=20 ABR=384k ./gen-problem-stream.sh
#   FFMPEG=/path/to/ffmpeg ./gen-problem-stream.sh
#
# Any ffmpeg works to GENERATE (ac3/eac3 encoders are native). Decode/test on
# Ubuntu 20.04 only with the 4.0/7.1 binaries (8.0 needs glibc 2.35).
# ──────────────────────────────────────────────────────────────────────────────
set -u

FFDIR="${FFDIR:-/home/xc_vm/bin/ffmpeg_bin}"
FF="${FFMPEG:-$FFDIR/4.0/ffmpeg}"
ACODEC="${ACODEC:-ac3}"          # ac3 | eac3
ABR="${ABR:-448k}"
DUR="${DUR:-30}"                 # seconds per part
OUT_DIR="${OUT_DIR:-/tmp/dtstest}"
mkdir -p "$OUT_DIR"

command -v "$FF" >/dev/null 2>&1 || [ -x "$FF" ] || { echo "ffmpeg not found: $FF (set FFMPEG=)"; exit 1; }

VSRC="testsrc2=size=1280x720:rate=25"
ASRC1="sine=frequency=440:sample_rate=48000"   # tone A (part 1)
ASRC2="sine=frequency=660:sample_rate=48000"   # tone B (part 2) — the seam is audible
VOPT="-c:v libx264 -preset veryfast -g 25 -pix_fmt yuv420p"
AOPT="-c:a $ACODEC -b:a $ABR -ac 2 -ar 48000"

echo ">>> clean.ts (reference)"
"$FF" -y -hide_banner -loglevel error \
  -f lavfi -i "$VSRC" -f lavfi -i "$ASRC1" -t $((DUR * 2)) \
  $VOPT $AOPT -f mpegts "$OUT_DIR/clean.ts"

echo ">>> disc.ts (non-monotonic DTS: two 0-based parts spliced → backward jump)"
"$FF" -y -hide_banner -loglevel error \
  -f lavfi -i "$VSRC" -f lavfi -i "$ASRC1" -t "$DUR" $VOPT $AOPT -f mpegts "$OUT_DIR/p1.ts"
"$FF" -y -hide_banner -loglevel error \
  -f lavfi -i "$VSRC" -f lavfi -i "$ASRC2" -t "$DUR" $VOPT $AOPT -f mpegts "$OUT_DIR/p2.ts"
cat "$OUT_DIR/p1.ts" "$OUT_DIR/p2.ts" > "$OUT_DIR/disc.ts"   # raw TS concat = DTS goes backward at the seam

echo ">>> drift.ts (audio shifted -0.4s vs video → desync)"
"$FF" -y -hide_banner -loglevel error \
  -f lavfi -i "$VSRC" -itsoffset -0.4 -f lavfi -i "$ASRC1" -t $((DUR * 2)) \
  -map 0:v -map 1:a $VOPT $AOPT -f mpegts "$OUT_DIR/drift.ts"

echo ">>> combo.ts (backward DTS jump + drift)"
"$FF" -y -hide_banner -loglevel error \
  -f lavfi -i "$VSRC" -itsoffset -0.4 -f lavfi -i "$ASRC2" -t "$DUR" \
  -map 0:v -map 1:a $VOPT $AOPT -f mpegts "$OUT_DIR/p2d.ts"
cat "$OUT_DIR/p1.ts" "$OUT_DIR/p2d.ts" > "$OUT_DIR/combo.ts"
rm -f "$OUT_DIR/p1.ts" "$OUT_DIR/p2.ts" "$OUT_DIR/p2d.ts"

echo ""
echo ">>> generated (audio codec + non-monotonic-DTS warnings already present):"
for f in clean disc drift combo; do
    ac=$("$FF" -hide_banner -loglevel error -i "$OUT_DIR/$f.ts" 2>&1 | grep -oiE 'Audio: [a-z0-9]+' | head -1)
    n=$("$FF" -hide_banner -loglevel verbose -i "$OUT_DIR/$f.ts" -c copy -f null - 2>&1 | grep -ciE 'non-monoton|invalid.*dts')
    printf "  %-9s %6s | %-14s | non-monotonic DTS: %s\n" "$f.ts" "$(du -h "$OUT_DIR/$f.ts" | cut -f1)" "$ac" "$n"
done
echo ""
echo ">>> next — feed into the audio comparison:"
echo "     ./compare-audio.sh $OUT_DIR/disc.ts"
echo "     ./compare-audio.sh $OUT_DIR/combo.ts"
