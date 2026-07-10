# DTS/AC3 audio test

Tools to investigate the legacy DTS audio path — specifically what XUI.one's
custom **`-fix_dts`** ffmpeg flag does, and whether stock ffmpeg's `-copyts`
reproduces it. Needed when moving off the bundled XUI `4.0` binary to a rebuilt
one (see `XC_VM_FFMPEG`).

## Background

The panel routes ac3/eac3 audio to the `4.0` ffmpeg binary with `-nofix_dts`
when the `dts_legacy_ffmpeg` setting is on (`Domain/Stream/StreamProcess.php`,
~line 947). `-fix_dts` ("fix invalid dts") is a **XUI CLI patch that does not
exist in stock ffmpeg** — 7.1/8.0 don't recognise it. It sits next to
`-copyts` / `-start_at_zero` and toggles ffmpeg's invalid-DTS correction;
`-nofix_dts` passes the original decode timestamps through unchanged, which
avoids audio glitches/desync on some ac3/eac3 mpegts feeds.

So if the `4.0` bucket is rebuilt from a stock tarball, the panel must stop
sending `-nofix_dts`. These scripts measure the safest replacement.

## Scripts

| Script | Role |
|--------|------|
| `gen-problem-stream.sh` | synthesize mpegts with ac3/eac3 audio and deliberate defects (non-monotonic DTS, a/v drift) — no need to find a real broken channel |
| `compare-audio.sh` | run a source through 4 variants (`-nofix_dts` reference, default, `-copyts`, stock) and print objective signals + how to A/B by ear |

## Usage

```bash
cd tools/dts-audio-test

# 1) make test material (ac3; ACODEC=eac3 for E-AC-3)
./gen-problem-stream.sh

# 2) compare how each ffmpeg variant handles it
./compare-audio.sh /tmp/dtstest/disc.ts
./compare-audio.sh /tmp/dtstest/combo.ts
```

Or point `compare-audio.sh` at a real problematic source:

```bash
./compare-audio.sh "http://.../stream.ts"
```

### Reading the result

`compare-audio.sh` prints one line per variant:

- **ts-warnings** — ffmpeg `non-monotonous/invalid DTS` count (what `fix_dts` suppresses)
- **a/v dur** — audio vs video duration; divergence = desync
- **silence-gaps** — audio dropouts >0.3 s (click/glitch proxy)

Compare `fix_off` (reference) vs `fix_on` on the same 4.0 binary to see exactly
what `-fix_dts` changes; then `copyts` vs `fix_off` to see if stock `-copyts`
matches it. The final verdict is by ear — the script prints a one-liner to dump
8-second WAVs per variant.

## Requirements / notes

- Bundled ffmpeg at `/home/xc_vm/bin/ffmpeg_bin/{4.0,7.1}/` (override with
  `FFDIR=` / `FFMPEG=` / `FF40=` / `FFSTOCK=`).
- ac3/eac3 encoders are native — any ffmpeg can **generate**. On Ubuntu 20.04
  **decode/test only with 4.0 or 7.1** (the 8.0 binary needs glibc 2.35).
- Scratch output goes to `/tmp/dtstest` (override with `OUT_DIR=`).
