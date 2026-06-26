# System API

This API provides various system functionalities, including stream and VOD management, system statistics, directory browsing, log viewing, process management, EPG/nginx reloading, and more.

---

## File Location

The System API is handled by the controller:

```text
src/Public/Controllers/Api/InternalApiController.php
```

HTTP entry-point: `/api.php` -> routed by nginx to `Public/index.php` with `XC_API=internal`.

## API Architecture Overview

**Base URI:** `http://<host>:<http port>/api.php`
**Authentication:** `password` parameter matching the `live_streaming_pass` configuration
**Example:**
`http://<host>:<http port>/api.php?password=<live_streaming_pass>&action=<api endpoint>`

---

## Main API Endpoints

### 1. View Stream Log

#### **GET** `/api.php?action=view_log`

**Description:** Returns the error log for a given stream or VOD. Checks `STREAMS_PATH` first, then falls back to `VOD_PATH`.
**Parameters:**

| Parameter | Type    | Required | Description                          |
| --------- | ------- | -------- | ------------------------------------ |
| stream_id | integer | yes      | ID of the stream to retrieve log for |

**Response:** Plain text contents of `<stream_id>.errors` file, or empty if no log exists.

---

### 2. FPM Status

#### **GET** `/api.php?action=fpm_status`

**Description:** Returns the PHP-FPM status page from the local server's HTTP broadcast port.

---

### 3. Reload EPG

#### **GET** `/api.php?action=reload_epg`

**Description:** Triggers a background EPG (Electronic Program Guide) reload by running `console.php cron:epg` asynchronously.

---

### 4. Restore Images

#### **GET** `/api.php?action=restore_images`

**Description:** Triggers a background image restoration by running `console.php tools images` asynchronously.

---

### 5. Reload Nginx

#### **GET** `/api.php?action=reload_nginx`

**Description:** Reloads both the RTMP nginx and the main nginx processes by sending them a reload signal.

---

### 6. Streams Ramdisk Usage

#### **GET** `/api.php?action=streams_ramdisk`

**Description:** Returns per-stream file sizes from the streams ramdisk directory. Has a 30-second time limit.
**Response:**

```json
{
  "result": true,
  "streams": {
    "123": 4096000,
    "456": 2048000
  }
}
```

---

### 7. VOD Management

#### **GET** `/api.php?action=vod`

**Description:** Starts or stops Video-on-Demand (VOD) streams. When starting, the stream is first stopped then either force-started or queued depending on the `force` parameter.
**Parameters:**

| Parameter  | Type              | Required | Description                                         |
| ---------- | ----------------- | -------- | --------------------------------------------------- |
| stream_ids | array of integers | yes      | List of stream IDs                                  |
| function   | string            | yes      | Action to perform (`start` or `stop`)               |
| force      | boolean           | no       | If true, starts immediately instead of queuing (start only) |

**Response:**

```json
{ "result": true }
```

---

### 8. RTMP Stats

#### **GET** `/api.php?action=rtmp_stats`

**Description:** Returns local RTMP server statistics.

---

### 9. Kill Process by PID

#### **GET** `/api.php?action=kill_pid`

**Description:** Terminates a process by sending SIGKILL (signal 9) to the specified PID.
**Parameters:**

| Parameter | Type    | Required | Description                   |
| --------- | ------- | -------- | ----------------------------- |
| pid       | integer | yes      | Process ID to terminate       |

**Response:**

```json
{ "result": true }
```

---

### 10. RTMP Kill

#### **GET** `/api.php?action=rtmp_kill`

**Description:** Drops an RTMP publisher connection by name via the nginx RTMP control interface.
**Parameters:**

| Parameter | Type   | Required | Description                         |
| --------- | ------ | -------- | ----------------------------------- |
| name      | string | yes      | RTMP stream name to drop            |

---

### 11. Live Stream Management

#### **GET** `/api.php?action=stream`

**Description:** Starts or stops live streams. When starting, a 50ms delay is applied between each stream.
**Parameters:**

| Parameter  | Type              | Required | Description                            |
| ---------- | ----------------- | -------- | -------------------------------------- |
| stream_ids | array of integers | yes      | List of stream IDs                     |
| function   | string            | yes      | Action to perform (`start` or `stop`)  |

**Response:**

```json
{ "result": true }
```

---

### 12. System Statistics

#### **GET** `/api.php?action=stats`

**Description:** Retrieves system statistics via `SystemInfo::getStats()`.
**Response:**

```json
{
  "cpu": 8.32,
  "cpu_cores": 56,
  "cpu_avg": 8.86,
  "cpu_name": "Intel(R) Xeon(R) CPU E5-2680 v4 @ 2.40GHz",
  ...
}
```

---

### 13. Force Stream Source

#### **GET** `/api.php?action=force_stream`

**Description:** Forces a stream to use a specific source by writing a force signal file.
**Parameters:**

| Parameter | Type    | Required | Description                              |
| --------- | ------- | -------- | ---------------------------------------- |
| stream_id | integer | yes      | ID of the stream to force                |
| force_id  | integer | yes      | ID of the source to force the stream to  |

---

### 14. Close Connection

#### **GET** `/api.php?action=closeConnection`

**Description:** Closes an active connection by its activity ID.
**Parameters:**

| Parameter   | Type    | Required | Description                        |
| ----------- | ------- | -------- | ---------------------------------- |
| activity_id | integer | yes      | Activity ID of the connection      |

---

### 15. Process Lifecycle Check

#### **GET** `/api.php?action=pidsAreRunning`

**Description:** Checks whether specified process IDs (PIDs) are currently running and match the expected program binary.
**Parameters:**

| Parameter | Type              | Required | Description             |
| --------- | ----------------- | -------- | ----------------------- |
| pids      | array of integers | yes      | List of PIDs to verify  |
| program   | string            | yes      | Expected program name   |

**Response:**

```json
{
  "1234": true,
  "5678": false
}
```

---

### 16. Get File

#### **GET** `/api.php?action=getFile`

**Description:** Downloads the specified file. Supports HTTP range requests for partial content. Only allows files with specific extensions: `log`, `tar.gz`, `gz`, `zip`, `m3u8`, `mp4`, `mkv`, `avi`, `mpg`, `flv`, `3gp`, `m4v`, `wmv`, `mov`, `ts`, `srt`, `sub`, `sbv`, `jpg`, `png`, `bmp`, `jpeg`, `gif`, `tif`.
**Parameters:**

| Parameter | Type   | Required | Description       |
| --------- | ------ | -------- | ----------------- |
| filename  | string | yes      | Path to the file  |

**Response:** File contents with `application/octet-stream` content type. Supports `Range` header for partial downloads (HTTP 206).

---

### 17. Recursive Directory Scan

#### **GET** `/api.php?action=scandir_recursive`

**Description:** Recursively scans a directory using `find`, optionally filtering by file extension. Has a 30-second time limit.
**Parameters:**

| Parameter | Type   | Required | Description                                                            |
| --------- | ------ | -------- | ---------------------------------------------------------------------- |
| dir       | string | yes      | URL-encoded path to the directory                                      |
| allowed   | string | no       | URL-encoded pipe-separated list of allowed extensions (e.g. `mp4\|mkv`) |

**Response:** JSON array of file paths.

---

### 18. Directory Listing

#### **GET** `/api.php?action=scandir`

**Description:** Lists files and subdirectories in a given directory, optionally filtering files by extension. Has a 30-second time limit.
**Parameters:**

| Parameter | Type   | Required | Description                                                              |
| --------- | ------ | -------- | ------------------------------------------------------------------------ |
| dir       | string | yes      | URL-encoded path to the directory                                        |
| allowed   | string | no       | URL-encoded pipe-separated list of allowed file extensions (e.g. `mp4\|mkv`) |

**Response:**

```json
{
  "result": true,
  "dirs": ["subdir1", "subdir2"],
  "files": ["video.mp4", "movie.mkv"]
}
```

---

### 19. Get Free Disk Space

#### **GET** `/api.php?action=get_free_space`

**Description:** Returns disk usage information from `df -h`.
**Response:** JSON array of output lines from `df -h`.

---

### 20. Get Process List

#### **GET** `/api.php?action=get_pids`

**Description:** Returns a list of all running processes with details (user, PID, CPU%, memory%, etc.).
**Response:** JSON array of output lines from `ps -e`.

---

### 21. Redirect Connection

#### **GET** `/api.php?action=redirect_connection`

**Description:** Redirects a connection by writing a signal file identified by UUID.
**Parameters:**

| Parameter | Type    | Required | Description                   |
| --------- | ------- | -------- | ----------------------------- |
| uuid      | string  | yes      | UUID identifying the connection |
| stream_id | integer | yes      | Target stream ID              |

---

### 22. Clear Temporary Folder

#### **GET** `/api.php?action=free_temp`

**Description:** Deletes all files in the `tmp/` directory and runs the cache cron job.

---

### 23. Clear Streams Folder

#### **GET** `/api.php?action=free_streams`

**Description:** Removes all files from the `content/streams/` directory.

---

### 24. Send Signal

#### **GET** `/api.php?action=signal_send`

**Description:** Sends a signal message to a connection identified by UUID.
**Parameters:**

| Parameter | Type   | Required | Description                     |
| --------- | ------ | -------- | ------------------------------- |
| message   | string | yes      | Signal message or command       |
| uuid      | string | yes      | UUID identifying the connection |

---

### 25. Get Certificate Info

#### **GET** `/api.php?action=get_certificate_info`

**Description:** Returns SSL/TLS certificate information via `DiagnosticsService::getCertificateInfo()`.

---

### 26. Force Watch Cron

#### **GET** `/api.php?action=watch_force`

**Description:** Triggers a background watch cron job for a specific item.
**Parameters:**

| Parameter | Type    | Required | Description          |
| --------- | ------- | -------- | -------------------- |
| id        | integer | yes      | Watch item ID        |

---

### 27. Force Plex Cron

#### **GET** `/api.php?action=plex_force`

**Description:** Triggers a background Plex cron job for a specific item.
**Parameters:**

| Parameter | Type    | Required | Description          |
| --------- | ------- | -------- | -------------------- |
| id        | integer | yes      | Plex item ID         |

---

### 28. Get Archive Files

#### **GET** `/api.php?action=get_archive_files`

**Description:** Returns a list of `.ts` archive segment files for a given stream.
**Parameters:**

| Parameter | Type    | Required | Description          |
| --------- | ------- | -------- | -------------------- |
| stream_id | integer | yes      | Stream ID            |

**Response:**

```json
{
  "result": true,
  "data": ["/path/to/archive/123/segment001.ts", "/path/to/archive/123/segment002.ts"]
}
```

---

### 29. Kill Watch Processes

#### **GET** `/api.php?action=kill_watch`

**Description:** Kills all running watch module processes (main PID and worker PIDs).

---

### 30. Kill Plex Processes

#### **GET** `/api.php?action=kill_plex`

**Description:** Kills all running Plex module processes (main PID and worker PIDs).

---

### 31. Probe Stream

#### **GET** `/api.php?action=probe`

**Description:** Probes a stream URL using FFprobe to retrieve media information.
**Parameters:**

| Parameter  | Type   | Required | Description                              |
| ---------- | ------ | -------- | ---------------------------------------- |
| url        | string | yes      | URL of the stream to probe               |
| user_agent | string | no       | Custom User-Agent header                 |
| http_proxy | string | no       | HTTP proxy URL                           |
| cookies    | string | no       | Cookie string to send with the request   |
| headers    | string | no       | Additional HTTP headers                  |

**Response:**

```json
{
  "result": true,
  "data": { ... }
}
```

---

## Error Codes

| Code                 | Description             |
| -------------------- | ----------------------- |
| INVALID_API_PASSWORD | Invalid API password    |
| API_IP_NOT_ALLOWED   | IP address not allowed  |

The default response for an unrecognized action is:

```json
{ "result": false }
```

---

## Notes

* All requests must be authenticated using the correct API password (`live_streaming_pass`).
* The requesting IP must be in the allowed IPs list returned by `ServerRepository::getAllowedIPs()`.
* Some actions run commands asynchronously in the background and return immediately.
