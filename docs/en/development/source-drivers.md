# Source Drivers

A **source driver** lets a module add a kind of live source that ffmpeg cannot read,
for example DASH with DRM, and run **its own engine** for it. The engine takes ffmpeg's
place for those sources only. The stream stays an ordinary XC_VM stream: the same stream
id, categories, bouquets, monitoring, Start/Stop/Restart, connections, archive and
thumbnails.

A stream can mix both kinds. With the primary on your engine and a backup on plain HTTP,
the stream fails over between the two with no extra code.

!!! info "Status"
    The driver interface, registration, producer selection, process recognition,
    source checks and save-time validation are in core, and a module tab on the stream
    form and a module import kind are available ([Stream form tabs](module-extension-points.md#stream-form-tabs-streamformregistry),
    [Import kinds](module-extension-points.md#import-kinds-importsourceregistry)). HLS renditions are still planned; see
    [Current limitations](#current-limitations).

For the module basics (layout, manifest, module class) see
[Module Authoring](module-authoring.md); for other hooks see
[Module Extension Points](module-extension-points.md).

## How it works

```text
stream_source: ["acmedash://prov1/demo-001", "http://backup.example/live.ts"]
                          │                                 │
           SourceDriverRegistry::for(url)          core: ffmpeg or native remux
                          │                                 │
              driver->buildArgv($ctx)          buildLive() / buildNativeLive()
                          │                                 │
                          └───────────── sources[] ─────────┘
                                          │
                     the supervisor (xc_fanout) or the PHP monitor
                     runs one entry and fails over to the next on exit
```

1. Your module declares a driver class in `module.json`.
2. The driver claims one or more URL schemes (`acmedash://…`).
3. When a stream starts, core looks at each source URL. For a URL with your scheme, it
   asks the driver whether the source is reachable (`available()`) and for the engine's
   command line (`buildArgv()`).
4. Core launches that command exactly like an ffmpeg producer, supervises it,
   restarts it, and serves its output.

| Term | Meaning |
| --- | --- |
| **Producer** | The process that produces a live stream's bytes: ffmpeg, `xc_fanout remux`, the proxy, the LLOD/delay/loopback workers, or your engine. |
| **Source driver** | Your class: it claims URL schemes and builds the producer command for them. |
| **Supervisor** | The `xc_fanout` daemon, which starts, watches, restarts and adopts producers. When supervision is off, the PHP monitor (`console.php monitor`) does that job. |

## Where the real stream URL comes from

Core never knows the upstream URL of a driver source. It stores the URL the operator
entered in the stream's source list, such as `acmedash://prov1/demo-001`, and hands that
string to your driver unchanged:

- in `available($streamId, $url)`, as `$url`;
- in `buildArgv($ctx)`, as `$ctx['url']`.

Turning it into something your engine can fetch is entirely your module's job. Two
patterns work.

### Pattern A: an id your module resolves

The URL names a channel, not a location: `acmedash://<provider>/<channel-id>`. The real
manifest URL, headers and keys are looked up **when the stream starts**, not stored in
core:

```text
operator / import   →  stream_source = acmedash://prov1/demo-001
stream start        →  core calls buildArgv(['url' => 'acmedash://prov1/demo-001', …])
your engine         →  runs the provider script: manifest id=demo-001
                    →  gets manifest_url, manifest_headers, media_headers
                    →  runs the provider script: cdm id=demo-001 (keys, if needed)
                    →  starts fetching; re-signs the URL when it expires
```

Use this pattern when the upstream URL is short-lived, signed, or needs keys. Nothing
secret or expiring ends up in `streams.stream_source`, in core logs or in `ps`. Keep the
provider definitions (which script, which settings) in your module's own tables, keyed
by the `<provider>` part of the URL.

### Pattern B: the upstream URL wrapped in your scheme

The URL carries the location itself, and only the scheme changes:
`acmedash://cdn.example.com/live/ch1/manifest.mpd`. Your driver maps it back:

```php
$upstream = 'https://' . substr($ctx['url'], strlen('acmedash://'));
```

Use this pattern when the operator already has a stable, public manifest URL and only
needs your engine to read it. Anything after `://` is yours, including the query string,
so the upstream URL survives intact.

### How the URL gets into a stream

- **By hand:** the operator types it as a source on the normal Add/Edit Stream page.
  Core accepts any scheme there.
- **By import:** register an [import kind](module-extension-points.md#import-kinds-importsourceregistry)
  that lists your provider's channels with your URLs. The admin picks it on the
  Import & Review page and imports the channels as ordinary streams.
- **As a backup:** put your URL anywhere in the source list. Primary/backup order works
  the same as for core sources.

## Quick start

This walkthrough builds a module named `acme-dash` with the scheme `acmedash://`.
Replace both with your own names.

### 1. Layout

```text
src/Modules/acme-dash_9f1c0/
├── module.json
├── AcmeDashModule.php        # the module class
├── AcmeDashDriver.php        # the source driver
├── bin/
│   └── xcvm-acmedash         # your engine, executable (chmod 755)
└── migrations/
    ├── 1.0.0.up.sql
    └── 1.0.0.down.sql
```

Ship the engine inside the module directory, so uninstalling the module removes it.
Do not install it under `/home/xc_vm/bin/`, which belongs to core.

### 2. module.json

```json
{
    "name": "acme-dash",
    "hash_id": "9f1c0b7e4d2a6538c1e0a4b7d6f39e21",
    "description": "DASH sources through the Acme engine",
    "version": "1.0.0",
    "requires_core": ">=2.5",
    "environment": "main",
    "source_drivers": ["XcVm\\Module\\AcmeDash\\AcmeDashDriver"],
    "start_timeout": 60
}
```

| Key | Meaning |
| --- | --- |
| `source_drivers` | Fully-qualified class names implementing `XcVm\Core\Module\SourceDriverInterface`. A module may declare several. |
| `start_timeout` | Optional, in seconds. How long core waits for your first playlist before it counts the start as failed. Leave it out for the core default (20–30 s). Set it when a cold start includes key acquisition. |
| `environment` | Use `any` when the module runs on both main and LBs. Omit it for a main-only module. |
| `lb_source_drivers` | Optional boolean, default `false`. Set to `true` with `environment: any` to allow direct-source LB assignments for this module's drivers. |

### 3. The driver class

```php
<?php

namespace XcVm\Module\AcmeDash;

use XcVm\Core\Module\SourceDriverInterface;
use XcVm\Infrastructure\Database\DatabaseAware;

final class AcmeDashDriver implements SourceDriverInterface {
    use DatabaseAware;

    public function schemes(): array {
        return ['acmedash'];
    }

    public function binary(): string {
        return 'xcvm-acmedash';
    }

    public function buildArgv(array $ctx): array {
        $argv = [
            __DIR__ . '/bin/xcvm-acmedash',
            '--source', $ctx['url'],
            '--config', $this->writeConfig($ctx),
            '--playlist', $ctx['hls']['dir'] . $ctx['hls']['playlist'],
            '--segment', $ctx['hls']['dir'] . $ctx['hls']['segment_pattern'],
            '--hls-time', (string) $ctx['hls']['seg_time'],
            '--hls-list-size', (string) $ctx['hls']['list_size'],
            '--hls-delete-threshold', (string) $ctx['hls']['delete_threshold'],
            '--progress', $ctx['progress_path'],
        ];
        if ($ctx['ingest'] !== null) {
            array_push($argv, '--ingest', $ctx['ingest']);
        }
        return $argv;
    }

    public function available(int $streamId, string $url): bool {
        // Cheap and local: is the provider known and enabled? No manifest fetch here.
        $provider = (string) parse_url($url, PHP_URL_HOST);
        return (bool) self::db()->fetchValue('SELECT `enabled` FROM `acmedash_providers` WHERE `name` = ?', $provider);
    }

    /** Network settings and credentials go to a private file, never into argv. */
    private function writeConfig(array $ctx): string {
        $dir = TMP_PATH . 'acmedash/';
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        $path = $dir . intval($ctx['stream_id']) . '.json';
        file_put_contents($path, json_encode(['fetch' => $ctx['fetch']]));
        chmod($path, 0600);
        return $path;
    }
}
```

What core expects of this class:

- **No constructor arguments and no container services.** Core loads drivers without
  booting modules, because a viewer's request can start a stream from the lightweight
  streaming entry point.
- **Database access only through `DatabaseAware` + `self::db()`.**
- **`schemes()` and `binary()` must be instant:** no network and no subprocesses.
- **`available()` must answer within a few seconds.** Core calls it before a start and
  before switching to this source, in place of running ffprobe on the URL.
- **Exceptions are safe.** If any method throws, core skips that source and writes the
  message to the stream's log.

### 4. The module class

Keep your per-stream settings in your own tables, and clean them up when streams are
deleted:

```php
<?php

namespace XcVm\Module\AcmeDash;

use XcVm\Core\Events\ListensTo;
use XcVm\Core\Events\Stream\StreamsDeletedEvent;
use XcVm\Core\Module\BaseModule;
use XcVm\Infrastructure\Database\DatabaseAware;

class AcmeDashModule extends BaseModule {
    use DatabaseAware;

    public function getName(): string {
        return 'acme-dash';
    }

    public function getVersion(): string {
        return '1.0.0';
    }

    #[ListensTo(StreamsDeletedEvent::class)]
    public function onStreamsDeleted(StreamsDeletedEvent $event): void {
        $ids = array_map('intval', $event->streamIds);
        if ($ids !== []) {
            self::db()->delete('acmedash_streams', '`stream_id` IN (' . implode(',', $ids) . ')');
        }
    }
}
```

**Never add a foreign key to `streams`.** Saving a stream rewrites its row
(`REPLACE INTO`), so an `ON DELETE CASCADE` would wipe your data on every edit.

### 5. The engine

Your engine is an ordinary executable that follows the
[producer contract](#producer-contract). The short version:

- It runs in the foreground, one process per stream.
- It writes HLS to disk at the paths it is given.
- When `--ingest` is given, it also writes MPEG-TS to that unix socket.
- It appends progress blocks.
- It survives `SIGKILL` without leaving children behind.

### 6. Try it

1. Install the module and run `console.php status`. This installs bundled modules and
   runs your migrations.
2. Create a Live Stream with the source `acmedash://prov1/demo-001`, no transcoding,
   delay off. Save and start it.
3. Watch the stream's log: `/home/xc_vm/content/streams/<id>.errors`. Every decision
   core makes about your source is written there with a `[panel]` prefix (see
   [Troubleshooting](#troubleshooting)).
4. Check that the stream list shows the producer badge **module**. That means core
   recognises your engine as the running producer.
5. Stop the stream and confirm the engine process is gone. Then kill the engine with
   `kill -9` and confirm that the stream restarts.

## Reference

### SourceDriverInterface

```php
namespace XcVm\Core\Module;

interface SourceDriverInterface {
    /** @return string[] URL schemes this driver owns, lowercase, without "://". */
    public function schemes(): array;

    /** Basename of the engine executable; stable across upgrades, unique among producers. */
    public function binary(): string;

    /**
     * The producer's argv. Element 0 is the absolute path of binary(); one element
     * must contain the full playlist path (hls.dir + hls.playlist). Core escapes
     * every element and adds the redirections and pid handling itself.
     *
     * @return string[]
     */
    public function buildArgv(array $ctx): array;

    /** Whether the source is reachable now; called instead of ffprobe on the URL. */
    public function available(int $streamId, string $url): bool;
}
```

Core rejects an argv whose element 0 is not an absolute path ending in `binary()`, or
that does not name the playlist. It then skips the source and logs why.

### `$ctx`

| Key | Type | Meaning |
| --- | --- | --- |
| `stream_id` | int | Stream id. |
| `url` | string | The source URL your driver claimed, unchanged. |
| `label` | string | The source's index in the stream's source list (`0`, `1`, …), for logs. |
| `fetch` | array | The stream's network options: `user_agent`, `proxy`, `cookie`, `headers`. Only values the operator set; a value equal to the global default is left out, so it never overrides your provider's own. |
| `hls.dir` | string | Directory for disk HLS, with a trailing slash. |
| `hls.playlist` | string | Playlist file name: `<id>_.m3u8`. |
| `hls.segment_pattern` | string | Segment file name pattern: `<id>_%d.ts`. |
| `hls.seg_time` | int | Target segment duration, in seconds. |
| `hls.list_size` | int | Segments listed in the playlist. |
| `hls.delete_threshold` | int | Extra segments to keep on disk after they leave the playlist. |
| `ingest` | ?string | Unix socket path of the fanout ingest, or `null` when fanout is disabled or down. |
| `progress_path` | string | Where to append progress blocks: `<id>_.progress`. |
| `errors_path` | string | The stream's log, `<id>.errors`. Your stderr is sent there. |
| `supervised` | bool | `true` when the `xc_fanout` supervisor runs the producer, `false` for the PHP monitor. |

### Source URLs

- **Scheme.** Every module picks its own, and one module may own several.
  - The format is `[a-z][a-z0-9+.-]*`.
  - Schemes core reads (`http`, `https`, `rtmp`, `rtsp`, `udp`, `rtp`, `srt`, `file`, …)
    cannot be claimed.
  - A scheme claimed by two installed modules goes to neither, and both names are logged.
  - Prefer a name tied to your module (`acmedash`, not `dash`).
- **Everything after `://` is yours.** Core passes it to the driver unchanged. Validate it
  in `available()` (return `false`) or in `buildArgv()` (throw).
- **The URL is the source's identity**, e.g. `acmedash://<provider>/<channel-id>`. Keep
  identity in the URL rather than in a per-stream table, because each backup source can
  point at a different channel or provider.
- **The URL must be printable**, with no whitespace or control characters. It is stored
  in `stream_source` and shown in the panel.

## Producer contract

### Process

1. **One foreground process per stream.** No daemonising, no double fork: the pid core
   records must be your engine.
2. **The command line names the playlist.** Stop, kill, the cron's pid fallback and
   adoption after a supervisor restart find the producer by `<dir>/<id>_.m3u8` in its
   command line. Core enforces this on the argv.
3. **Stop is `SIGKILL`.**
   - The supervisor kills the whole process group; the PHP monitor kills only the pid.
   - Keep no state that must be flushed on exit.
   - Give every child process (provider scripts, helpers) a parent-death signal, so it
     dies with the engine on both paths. In Go: `SysProcAttr{Pdeathsig: syscall.SIGKILL}`.
4. **Exit codes.** Any exit means the producer ended, and core restarts it or fails over
   according to the stream's settings. Do not use:
   - `3`: reserved (the core remuxer's "unsupported");
   - `4`: reserved for a future "presentation ended, do not restart". It is treated like
     any other exit for now.
5. **stderr** goes to the stream's log. Write short, readable, one-line messages.

### Disk HLS (always)

Archive, thumbnails, the monitor, the stats cron and the non-fanout delivery path all
read disk HLS, so write it even when `ingest` is set.

- **Segment numbering.** Segments are `<id>_<N>.ts`, where N starts at **0 on every
  launch** and has no gaps within a run, as ffmpeg does.
  - Core deletes `<id>_*` on Stop and on an explicit Start.
  - After a crash, the supervisor relaunches you **without** cleaning up. Overwrite
    existing files; never continue a persisted counter.
- **Playlist format.** It lists basenames, with an `#EXTINF` for every segment, and
  `#EXT-X-MEDIA-SEQUENCE` equals the first listed N.
- **Atomic writes.** Write each segment and the playlist atomically: write to a temp file
  in the same directory, then `rename()`. Segments are plain MPEG-TS; core encrypts on
  the fly where configured.
- **Retention.** Keep `hls.delete_threshold` extra segments on disk after they leave the
  playlist.
- **Timing.**
  - The first playlist must appear within the start timeout: `start_timeout`, or the core
    default.
  - After that, the playlist must change at least every `6 × seg_time` seconds, or the
    stream counts as stalled and is restarted.

### Ingest (when `ingest` is not null)

- **Connection.** Connect to the unix socket and write MPEG-TS, and reconnect if the
  connection drops. Under the supervisor the socket is always passed, and the supervisor
  confirms a start by the bytes arriving on it.
- **Packet format.**
  - 188-byte packets, aligned from the first byte.
  - PCR on the PMT's PCR PID.
  - `random_access_indicator` set on video keyframes; HLS segments are cut only there.
- **Audio `stream_type`.** Use the standard value: AAC `0x0F`/`0x11`, MP2/MP3
  `0x03`/`0x04`, AC-3 `0x81`, E-AC-3 `0x87`. Audio signalled as private data (`0x06`)
  disables the audio-loss check.
- **No validation.** The supervisor treats any bytes as a live signal and does not check
  the TS, so its correctness is up to you.

### Progress

- Append ffmpeg-style blocks to `progress_path`: `key=value` lines ending with
  `progress=continue`, or `progress=end` on a clean finish.
- Open the file with `O_APPEND`, because core truncates it between reads.
- Useful keys are `bitrate` (e.g. `bitrate=4200.5kbits/s`), `speed` and `out_time_us`.
- **Omit `fps`** if you don't decode frames. A missing or zero `fps` makes the FPS
  watchdog skip its check. Do not write a placeholder value.

## What core does for you

| Situation | Core behaviour |
| --- | --- |
| Stream start | Asks `available()`. When it returns `true`, runs your argv as the producer; otherwise tries the next source. |
| Failover | Moves to the next source in the list when your engine exits. That source can be yours or core's. |
| Priority return | Every 5 minutes, asks `available()` for higher-priority sources and switches back when one answers. The PHP monitor calls it directly; the supervisor runs `console.php source:probe` (with a base64 URL on main or a local source index on a serve-only LB). |
| Source checks | The probe button on the stream form, the stream tools check and the on-demand scanner report your source as "Module source available / unavailable" from `available()`, since it has no codecs to show before your engine runs. |
| Saving a stream | Rejects Direct Source, Direct Proxy and LLOD v2 on a stream with your source. A direct-source LB requires every module driver in that stream to opt in. The same checks apply to imports and mass edits. |
| Forced source switch | Asks `available()` before switching to your source. |
| Stop / Restart | Kills the process found by pid and playlist, then clears `<id>_*`. |
| Supervisor restart | Re-adopts your running engine by its playlist path instead of starting a second one. |
| Panel status | The stream list shows the producer as **module**, with CPU and memory from your process. |
| Codec info | Read from your output by the stats cron. Until then, the panel shows a placeholder (h264/aac). |

### Refusals

Core refuses your source by itself when the stream uses a setting only ffmpeg can apply,
because your engine would drop that setting silently:

| Stream setting | Log message |
| --- | --- |
| Stream type other than Live Streams | `not a live channel (type …)` |
| Transcoding, including the logo overlay | `transcoding is enabled` |
| Custom ffmpeg command | `the stream has a custom ffmpeg command` |
| Custom track map | `the stream maps specific tracks` |
| RTMP output | `RTMP (FLV) output is enabled` |
| External push | `the stream is pushed to an external server` |
| Forced input audio codec | `an input audio codec is forced` |
| Delay | `a source driver cannot feed a delayed stream` |

A refused source is skipped, and core tries the next one. If no source is left, the stream
fails to start. Core never runs ffmpeg on a URL with your scheme. On a node where your
module is not installed, your sources are refused with `no source driver for acmedash://
on this node`.

## Load balancers

For a driver that can run from node-local data, set `"environment": "any"` and
`"lb_source_drivers": true` in its manifest. The sample driver above uses a module
database table, so adapt that lookup before opting it in.

An opted-in module is installed separately on each LB with its own files and license.
Its cron entries run there. On a direct-source LB, the driver builds its command and
starts its engine on that LB; the stream uses that node's CPU, RAM and upstream bandwidth.
On a serve-only LB, main sends a source index in the portable supervisor spec. The LB
reads the source and options from its local stream replica before calling `buildArgv()`.
The command line never carries those values from main. The driver must resolve its own
settings and secrets on the LB; serve-only nodes cannot query main's database. Keep
node-specific state in local files or another source the LB can access. `available()`
and module crons must work under the same constraint. A module without the opt-in keeps
the main-only rule; an LB child of main still pulls the parent's stream. Required
module dependencies must also target the LB.

## Provider scripts and secrets

If your module runs operator-provided scripts, that is code execution as the `xc_vm` user.

- **Execution.** Run them as a subprocess with an argv list and **no shell**, with JSON in
  and out, a hard timeout, and an absolute cap on stdout and stderr.
- **Where scripts live.** Keep scripts as files on disk in your module directory. Let the
  UI pick from that list and show each script's SHA-256 fingerprint rather than accept
  uploads. If you do add uploads, gate them behind their own permission, not
  `edit_stream`.
- **No secrets in argv.** Core records the command line on disk, and `ps` shows it to
  every user. Pass credentials and network settings through a `0600` file, as the
  example driver does, or fetch them inside the engine.

## Media

- **Tracks.** Tracks in your TS (video, audio, DVB subtitles) reach TS and HLS clients
  unchanged. VLC, Kodi and TiviMate show DVB subtitles; browser players (hls.js, video.js)
  do not.
- **Renditions.** Separate audio renditions and WebVTT subtitles need **HLS renditions**,
  which core does not support yet. When it does, the producer contract gains a
  per-rendition output.

## Troubleshooting

Every decision core makes about your source is written to
`/home/xc_vm/content/streams/<id>.errors` with a `[panel]` prefix:

| Message | Meaning |
| --- | --- |
| `source #N skipped: <refusal>` | A stream setting refuses drivers; see [Refusals](#refusals). |
| `source #N skipped: argv[0] must be the absolute path of …` | `buildArgv()` element 0 is not `/…/<binary()>`. |
| `source #N skipped: the command must name …_.m3u8` | No argv element contains the playlist path. |
| `source driver reports <url> unavailable` | `available()` returned `false`. |
| `source <url> skipped: <exception message>` | A driver method threw. |
| `no source driver for …:// on this node` | The module is not installed or not enabled here, or its scheme was contested. |

When the scheme is contested or invalid, look in the PHP error log for
`SourceDriverRegistry:` lines. They name the modules involved.

If the stream keeps restarting while the engine looks healthy, check these first:

- the playlist path is in the engine's command line;
- the executable's basename equals `binary()`;
- the playlist changes at least every `6 × seg_time` seconds.

## Current limitations

These pieces are planned as separate extension points and are not in core yet:

- honouring exit code `4`, and `SIGTERM` with a grace period before `SIGKILL`;
- HLS renditions (multiple audio tracks, WebVTT).

## Checklist before you ship

- [ ] `binary()` equals the engine's executable basename and is unique.
- [ ] `buildArgv()` element 0 is the absolute engine path; the playlist path is in the argv.
- [ ] No credentials or keys in argv.
- [ ] A fresh launch writes `<id>_0.ts` first, with no gaps; the playlist is replaced atomically.
- [ ] With `ingest` set, the TS is aligned and has PCR and RAI; the engine reconnects after the socket closes.
- [ ] After `kill -9 <engine pid>`, no child process is left running.
- [ ] Progress blocks are appended and end in `progress=`.
- [ ] `available()` is local and fast.
- [ ] Your tables have no foreign key to `streams`; `StreamsDeletedEvent` cleans them.
- [ ] `environment` is `main`.
