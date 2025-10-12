<h1 align="center">📡 System API (Method Verification Required)</h1>

<p align="center">
 This API provides various system functionalities, including log viewing, video-on-demand (VOD) and stream management, statistics retrieval, background command execution, and more.
</p>

<p align="center">
  <a href="../../en/main-page.md"><b>⬅️ Back to main page</b></a>
</p>

---

## File Location

```
/home/xc_vm/wwwdir/api.php
```

## API Architecture Overview

**Base URI:** `http://<host>:25461/api.php`
**Authentication:** `password` parameter matching the `live_streaming_pass` configuration
**Example:**
`http://<host>:25461/api.php?&password=<live_streaming_pass>`

---

## Main API Endpoints

### 1. VOD Management

#### **GET** `/api.php?action=vod`

**Description:** Starts or stops Video-on-Demand (VOD) streams.
**Parameters:**

| Parameter  | Type              | Required | Description                            |
| ---------- | ----------------- | -------- | -------------------------------------- |
| stream_ids | array of integers | yes      | List of stream IDs.                    |
| function   | string            | yes      | Action to perform (`start` or `stop`). |

---

### 2. Live Stream Management

#### **GET** `/api.php?action=stream`

**Description:** Starts or stops live streams.
**Parameters:**

| Parameter  | Type              | Required | Description                            |
| ---------- | ----------------- | -------- | -------------------------------------- |
| stream_ids | array of integers | yes      | List of stream IDs.                    |
| function   | string            | yes      | Action to perform (`start` or `stop`). |

---

### 3. System Telemetry

#### **GET** `/api.php?action=stats`

**Description:** Retrieves system statistics.
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

### 4. Process Lifecycle Check

#### **GET** `/api.php?action=pidsAreRunning`

**Description:** Checks whether specified process IDs (PIDs) are currently running.
**Parameters:**

| Parameter | Type              | Required | Description             |
| --------- | ----------------- | -------- | ----------------------- |
| pids      | array of integers | yes      | List of PIDs to verify. |
| program   | string            | yes      | Program name.           |

---

### 5. Get File

#### **GET** `/api.php?action=getFile`

**Description:** Downloads the specified file.
**Parameters:**

| Parameter | Type   | Required | Description       |
| --------- | ------ | -------- | ----------------- |
| filename  | string | yes      | Path to the file. |

**Response:**

* File contents.

---

### 6. Directory Listing

#### **GET** `/api.php?action=viewDir`

**Description:** Retrieves a directory listing.
**Parameters:**

| Parameter | Type   | Required | Description            |
| --------- | ------ | -------- | ---------------------- |
| dir       | string | yes      | Path to the directory. |

**Response:**

```html
<ul class="jqueryFileTree" style="display: none;">
  <li class="directory collapsed"><a href="#" rel="/path/to/directory/">directory_name</a></li>
  <li class="file ext_txt"><a href="#" rel="/path/to/file.txt">file.txt</a></li>
</ul>
```

---

### 7. Connection Redirection

#### **GET** `/api.php?action=redirect_connection`

**Description:** Redirects a connection based on the activity ID and stream ID.
**Parameters:**

| Parameter   | Type    | Required | Description  |
| ----------- | ------- | -------- | ------------ |
| activity_id | integer | yes      | Activity ID. |
| stream_id   | integer | yes      | Stream ID.   |

---

### 8. Send Signal

#### **GET** `/api.php?action=signal_send`

**Description:** Sends a signal message to trigger an action.
**Parameters:**

| Parameter   | Type    | Required | Description         |
| ----------- | ------- | -------- | ------------------- |
| message     | string  | yes      | Message or command. |
| activity_id | integer | yes      | Activity ID.        |

---

### 9. Clear Temporary Folder

#### **GET** `/api.php?action=free_temp`

**Description:** Deletes temporary files and executes a caching script.

---

### 10. Clear Streams Folder

#### **GET** `/api.php?action=free_streams`

**Description:** Clears the streams directory.

---

### 11. Get Free Disk Space

#### **GET** `/api.php?action=get_free_space`

**Description:** Returns information about available disk space.

---

### 12. Get Process PIDs

#### **GET** `/api.php?action=get_pids`

**Description:** Returns a list of running process IDs.

---

### 13. Kill Process by PID

#### **GET** `/api.php?action=kill_pid`

**Description:** Terminates a process by its PID.

---

## Error Codes

| Code                 | Description                 |
| -------------------- | --------------------------- |
| INVALID_API_PASSWORD | Invalid API password.       |
| API_IP_NOT_ALLOWED   | IP address not allowed.     |
| INVALID_REQUEST      | Invalid request parameters. |

---

## Notes

* All requests must be authenticated using the correct API password.
* Some actions may require additional permissions or may be restricted depending on the server configuration.
