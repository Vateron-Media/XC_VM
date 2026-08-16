# ADR 0003 — Full live-delivery cutover to `xc_fanout` (retire the legacy byte path)

- **Status:** Proposed
- **Date:** 2026-08-16
- **Builds on:** ADR 0001 (tmpfs-free streaming), ADR 0002 (`xc_fanout` daemon). S3 (proxy live TS) + P3 (in-RAM HLS) are box-validated.
- **Goal (from Danil):** make the daemon the **single, always-on** delivery layer for **all live streams** — proxy *and* non-proxy, TS *and* HLS — then delete the legacy byte-path code that becomes unused.

---

## 1. What "all streams" means — scope

The daemon is a **live fan-out engine**: one ingest per stream → many viewers, TS + in-RAM HLS. That maps cleanly onto **live** streams and nothing else:

| Category (`streams_types.live`) | In scope? | Why |
|---|---|---|
| **Live, proxy** (`direct_proxy=1`) | ✅ done (S3) | daemon owns the pull + fan-out |
| **Live, non-proxy** (`direct_source=0`) | ✅ target (Phase A) | ffmpeg stays (transcode/logo); daemon takes **delivery** via a tee |
| **Live, adaptive / restreamer / redirect-to-LB** | ✅ target (Phase C) | same fan-out, extra auth/routing parity |
| **VOD / movie** | ❌ out of scope | file + HTTP **range/seek**, not fan-out |
| **Series** | ❌ out of scope | VOD episodes |
| **Timeshift / archive / catchup** | ❌ out of scope | seeks over recorded segments, not a live tail |

> **Assessment:** VOD/timeshift are a different delivery model (random-access byte ranges over files), not a shared live tail. Forcing them through a fan-out engine buys nothing and adds risk. They keep their current path (P1 X-Accel already took *their* HLS/segment reads off PHP where it mattered). "Full transition" = **100% of live streaming**, which is the part that pins workers.

**Two things the daemon does NOT replace:** the per-stream **ffmpeg** for non-proxy streams (it does real work — transcode, logo, profiles, timeshift recording) and **auth** (stays in PHP). The daemon replaces *delivery*: the source pull for proxy, and the fan-out + HLS segmentation + serving for everyone.

---

## 2. Target end-state

```
 proxy live     ─ daemon pulls source ─┐
                                        ├─▶ xc_fanout (per stream: ring + in-RAM HLS) ─▶ nginx ─▶ N viewers
 non-proxy live ─ ffmpeg ─tee mpegts ──┘        (TS fan-out + /hls/<id>/*)              (auth_request/X-Accel)
                    │
                    └─ (still writes timeshift/recording where needed)
```

- **PHP = auth only** (`live` + `hls` auth), never in the byte path.
- **One feed per stream → both TS and HLS** from the daemon's RAM (validated: real source → `/live/<id>` TS *and* `/hls/<id>/index.m3u8` with PTS-accurate EXTINF, concurrently).
- **No streaming tmpfs**: non-proxy ffmpeg stops writing `<id>_*.ts`/`.m3u8` to `STREAMS_PATH`; HLS comes from the daemon. (Timeshift/archive files, a separate dir, stay.)
- **Legacy deleted**: `ProxyCommand`, the two `live.php` byte loops, the `segment.php`/`HLSGenerator` tmpfs-serving path, the `CONS_TMP_PATH` datagram sockets.

---

## 3. Phases (each reversible — the daemon's reachability is the switch; stop it to fall back)

**Phase 0 — ~~Schema/enablement~~ (dropped).**
The original plan added a `live_fanout` settings column + admin toggle. **Removed** (done): for a *permanent* cutover a runtime settings flag is throwaway scaffolding, and a settings column that ends up always-1 is dead weight (cf. P1's dormant `hls_accelerator`). **The switch is the daemon itself.** `live.php` routes a proxy stream to the daemon iff its control socket is present *and* `FanoutClient::register` succeeds; otherwise the full legacy path runs (producer included). So **stopping the daemon is the rollback** — every stream falls back automatically, per node, with no DB write, no cache rebuild, no admin UI, and self-healing (crash → keepalive → back). Which *stream types* the daemon handles stays a code decision (proxy now; +non-proxy after Phase A), which is the granularity that actually matters. The test-box `live_fanout` column was dropped.

**Phase A — Non-proxy live via the daemon (ADR 0002 S4). _Biggest new piece._**
- **A1 — daemon ingest listener. _Done (0.3.0), box-validated._** Per-stream push-fed mode: `PUT /ingest/<id>` listens on `<ingestdir>/<id>.sock`; the producer connects and pushes mpegts, which the daemon fans out to `/live` and segments for `/hls` (no puller). `DELETE`/Unregister cleans up. Proven with real ffmpeg, including the production tee form below.
- **A2 — `StreamProcess` tee. _Done, box-validated._** `startStream` calls `FanoutClient::registerIngest($id)` **before** `buildLive`; when it succeeds, the live ffmpeg's HLS output is emitted through the `tee` muxer so one ffmpeg feeds both on-disk HLS and the daemon:
  `-map … -f tee "[f=hls…]<id>_.m3u8|[f=mpegts:onfail=ignore]unix:<ingest.sock>"` — `onfail=ignore` keeps HLS alive if the daemon output drops; daemon unreachable ⇒ original on-disk-only HLS (reachability switch). Two tee gotchas handled: tee needs an explicit `-map` (auto stream-selection fails → "does not contain any stream"); `individual_header_trailer` is a segment-muxer option the hls muxer ignores in flag form but rejects fatally in a tee slave, so it is omitted.
- **A3 — `live.php` non-proxy → daemon. _Done._** At the non-proxy delivery point, if the daemon is reachable and `FanoutClient::isStreamFed($id)` (control `GET /streams/<id>` → `has_data`), X-Accel to `/xc_fanout/<id>` like proxy; else the legacy `.ts` chase-read. The tee (A2) feeds the daemon from the same ffmpeg that writes the on-disk HLS, so by delivery time (playlist ready) the daemon has data. Note: ingest-fed streams report `running:false` (no puller) — the gate is `has_data`, not `running`.
- **LLOD v3 (`LlodCommand`) — daemon feed _done_.** (was: no daemon path.) On-demand routing (`MonitorCommand`:329-342 by `streams.llod`): llod=0 and llod=1 ("v2") go through `startStream`→`buildLive`, so A2's tee covers them; **llod=2 ("v3") goes to `startLLOD`→`LlodCommand`, a separate PHP segmenter (not ffmpeg), which A2 does not touch** — those streams stay 100% legacy (verified working, no regression). LLOD v3 now **pushes** the MPEG-TS it reads into the daemon ingest socket (`FanoutClient::registerIngest` + a best-effort non-blocking `stream_socket_client` write), so A3 routes its viewers to the daemon too; it still writes its own on-disk segments as the legacy fallback. Box-validated: daemon has_data + a routed viewer (refs:1) + valid /live and /hls, LLOD segments still produced, playback OK.
- **Test:** real non-proxy stream, player, TS + HLS both from the daemon; transcode/logo intact.

**Phase B — HLS through the daemon end-to-end. _Done, box-validated (unencrypted)._**
- `live.php` m3u8 branch: when the daemon is reachable, the stream is fed (`isStreamFed`) and `!encrypt_hls`, it fetches the daemon's playlist (`FanoutClient::hlsPlaylist` → client socket `/hls/<id>/index.m3u8`) and tokenizes its `<seq>.ts` entries via `HLSGenerator::tokenizeDaemonPlaylist` into the same `/hls/<token>` auth'd URLs — segment name marked `<id>_d<seq>.ts`. Else the legacy on-disk `generateHLS` runs.
- `segment.php`: a daemon-segment token (`<id>_d<seq>.ts`) runs the same uuid + IP auth, then `X-Accel-Redirect: /xc_fanout_hls/<id>_<seq>` → internal nginx `location ^~ /xc_fanout_hls/` → daemon `/hls/<id>/<seq>.ts` (two-segment target to dodge the rewrite hijack). No on-disk file needed.
- The HLS on-demand lifecycle gap is already closed (daemon 0.2.0: `/hls` `touch()` + reaper). Box-validated: `569.m3u8` → playlist with the daemon's `MEDIA-SEQUENCE` + tokenized segments; a segment fetch → 200 valid TS from the daemon's RAM.
- **Scope/limitation:** daemon HLS is plain mpegts, so it covers **unencrypted** streams (all on-demand + `encrypt_hls=0`); **encrypted** streams stay on the legacy tmpfs HLS. Adding daemon-side encryption (or an encrypting proxy) is a later step — it (and removing the tee's on-disk HLS slave) is what Phase F needs before the streaming tmpfs can be dropped.

**Phase C — Parity & robustness (the real work; gates making the daemon the default).**
_Foundations landed (daemon 0.2.0 + `service`):_ HLS now joins the on-demand lifecycle (`/hls` `touch()`es the stream — starts/keeps the puller for an HLS-only audience; unified idle-stop reaper covers TS+HLS); off-air signal via control `GET /streams/<id>` → `{running,refs,has_data,since_data_ms}`; daemon auto-restarts on crash (keepalive loop in `service`, `Restart=always` equivalent). _Still to do:_ wire the auth endpoint to read the status for off-air; on-demand cold-start bounds; adaptive/restreamer/redirect parity; disconnect accounting (→ P4 Redis).

Everything `live.php`'s legacy block does must have a daemon-model equivalent before the daemon can be the default:
- **Off-air / source-down detection. _Done._** `POST /probe/<id>?wait=<ms>` (daemon 0.4.0) prewarms the puller and waits for first data; `live.php` calls `FanoutClient::probe` after registering a proxy source and shows the not-on-air page when `has_data` is false — parity with legacy startProxy (bounded by `on_demand_wait_time`; a warm stream returns immediately). Box-validated: dead source → not-on-air after the window; live source → fast play. (Non-proxy off-air is already covered by the process block waiting for the playlist + `isStreamFed`.)
- **On-demand cold start** (first viewer starts the stream; bounded wait; expiry).
- **Adaptive / multi-bitrate**, **restreamer chains**, **redirect-to-LB** routing.
- **Connection limits / IP match / telemetry** — keep the `ConnectionTracker` writes at auth; move real connect/disconnect accounting to the daemon (→ **P4** Redis), since with X-Accel PHP no longer sees disconnect.

**Phase D — Make the daemon the default.**
No flag to flip: a reachable daemon already serves every wired stream type. Phase D = widen coverage to all live types and confirm parity in a canary node before rolling the daemon to every node. Rollback stays "stop the daemon" until legacy is deleted (Phase E).

**Phase E — Delete legacy (the requested cleanup). _Only after D is stable._**
Remove and update consumers:
- `Cli/Commands/ProxyCommand.php` (whole file) + its callers/refs (`StreamsCronJob`, `SignalsCommand`, `LlodCommand`, `MonitorCommand`, `ProcessManager::startProxy`).
- `live.php`: the proxy socket-relay loop and the non-proxy `.ts` chase-read loop (the ~350→628 block) — `live.php` becomes auth + register + X-Accel only (the "slim `live_auth`" ADR 0002 originally imagined, reached by deletion rather than duplication).
- `segment.php` / `HLSGenerator` tmpfs-serving path once HLS is daemon-served (keep timeshift/archive readfile).
- `CONS_TMP_PATH` datagram sockets + related cleanup in `MonitorCommand`.
Each deletion gated on the daemon being the sole live path; `git revert`-able.

**Phase F — Drop streaming tmpfs mounts (ADR 0001 P5).**
Once nothing writes `<id>_*.ts`/`.m3u8` to `STREAMS_PATH`, remove the tmpfs mount for it (installer + `service`). Timeshift/archive unaffected.

**Phase G — LB rollout (P6).**
Ship the daemon to LB nodes (binary already multi-arch, static; LB archive stays privilege-free). LB nodes run only pull+fan-out (no admin/DB).

---

## 4. Assessment / recommendations ("что скажешь")

- **Yes to the full cutover for live** — the box A/B proves the payoff (8 viewers: 9 php-fpm workers/242 MB → 1 worker/45 MB). It scales the exact wall P0 found.
- **Keep VOD/timeshift out** — different model; no benefit, real risk. (Confirm this scope.)
- **The daemon replaces delivery, not ffmpeg** — non-proxy transcode stays; we only tee its output.
- **Delete legacy LAST (Phase E), not now** — keep both paths (daemon reachable ⇒ daemon, else legacy) until parity (Phase C) is proven in production. Deleting `ProxyCommand`/`live.php` loops before that removes our rollback.
- **Real effort is Phase C**, not the happy path. Off-air, on-demand cold-start, adaptive, restreamer, limits, and disconnect-accounting are where correctness lives. Budget accordingly.
- **Two concrete daemon gaps** surfaced already: (1) `/hls` must join the on-demand lifecycle; (2) the daemon needs a health/last-data signal for off-air. Both are Phase A/B.
- **Order:** Phase 0 (schema) → A (non-proxy) → B (HLS) → C (parity) → D (default) → E (delete) → F (tmpfs) → G (LB).

---

## 5. Risks

- **Parity gaps** silently degrade edge streams (adaptive/restreamer/off-air) — mitigate with per-feature regression before widening the flag.
- **On-demand lifecycle races** (first-viewer start / last-viewer stop) across TS+HLS audiences — one lifecycle owner per stream in the daemon.
- **Disconnect accounting** moves to the daemon (PHP no longer sees it) — until P4/Redis, limits lean on the auth-time write + reaper.
- **Deleting legacy too early** — gate strictly on Phase D stability.
- **Timeshift/recording coupling** — the non-proxy ffmpeg also feeds recording; the tee must not disturb those outputs.
