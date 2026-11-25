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

Enabled by default. While **Send redacted panel errors to XC_VM diagnostics** is enabled under Settings → Logs, MAIN sends HTTPS POST requests to `https://www.xcvm.tech/api/v1/report`.

Each request contains the current panel version and up to 1000 errors. Each error contains only `type`, redacted `log_message`, redacted `log_extra`, `line`, UTC `date`, and the panel `version` recorded with that error. Passwords, tokens, authorization values and URL credentials are redacted before local storage and again before transmission.

The receiver's retention period is not defined by the panel. Disabling the setting stops future uploads; it cannot delete data already sent. The method returns `false` without reading logs when the setting is disabled.

---

## `panel_logs` Shape (used fields)

| Column | Type | Meaning |
| --- | --- | --- |
| `type` | string | event type |
| `log_message` | string | main message |
| `log_extra` | string | redacted extra context or stack trace |
| `line` | int | source line |
| `date` | int | UNIX timestamp |

---

### `getPIDs(int $rServerID): array`

Parses system process information from server API response. Returns structured process data for monitoring.

---

### `getNVENCProcesses(int $rServerID): array`

Extracts GPU (NVENC) process data from server GPU info. Used for GPU encoding diagnostics.

---

## Related files

| File | Purpose |
| --- | --- |
| `src/Core/Diagnostics/DiagnosticsService.php` | diagnostics methods |
| `src/Core/Logging/Logger.php` | writes logs |
| `src/Core/Config/ConstantsInitializer.php` | version metadata (`appConfig()` map) |
