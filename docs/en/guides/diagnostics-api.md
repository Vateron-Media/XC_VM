# Diagnostics Service

`DiagnosticsService` provides operational diagnostics helpers:

- SSL certificate inspection
- codec compatibility checks
- panel log export/submission
- external API IP detection

---

## Methods

### `getCertificateInfo(?string $certificate = null): ?array`

Returns certificate details (`serial`, `expiration`, `subject`, `path`).

If `$certificate` is null, certificate path is auto-detected from nginx SSL config.
Returns `null` if file is missing or OpenSSL parsing fails.

---

### `checkCompatibility(array|string $data, bool $allowHEVC = false): bool`

Checks whether media codecs are compatible with player constraints.
Accepts FFProbe payload as array or JSON string.

Default accepted codecs include:

- video: `h264`, `vp8`, `vp9`, `ogg`, `av1`
- audio: `aac`, `libfdk_aac`, `opus`, `vorbis`, `pcm_s16le`, `mp2`, `mp3`, `flac`

With `$allowHEVC=true`, allows `hevc`/`h265` and `ac3`.

---

### `downloadPanelLogs(object $db): array`

Fetches up to 1000 recent rows from `panel_logs` (excluding `epg`), sanitizes output fields, and clears log rows after export.

Return shape:

```php
[
  'errors' => [...],
  'version' => '...'
]
```

Throws `Exception` on DB query failure.

---

### `submitPanelLogs(object $db): string|false`

Submits panel logs to remote diagnostics endpoint and clears submitted logs.
Returns the response string on success, `false` on failure (e.g. API IP resolution fails).
Used for support/telemetry workflows.

---

## `panel_logs` Shape (used fields)

| Column | Type | Meaning |
| --- | --- | --- |
| `type` | string | event type |
| `log_message` | string | main message |
| `log_extra` | string | extra context |
| `line` | int | source line |
| `date` | int | UNIX timestamp |

---

### `getPIDs(int $rServerID): array`

Parses system process information from server API response. Returns structured process data for monitoring.

---

### `getNVENCProcesses(int $rServerID): array`

Extracts GPU (NVENC) process data from server GPU info. Used for GPU encoding diagnostics.

---

## Related Files

| File | Purpose |
| --- | --- |
| `src/core/Diagnostics/DiagnosticsService.php` | diagnostics methods |
| `src/core/Logging/Logger.php` | writes logs |
| `src/core/Config/AppConfig.php` | version metadata |
