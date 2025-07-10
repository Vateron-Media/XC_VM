# System API

## File location
```
/home/xc_vm/wwwdir/api.php
```

## Overview
This API provides various functionalities including viewing logs, managing video on demand (VOD) and streams, retrieving statistics, executing background commands, and more.

## API Architecture Overview
**Base URI**: `http://<host>:25461/api.php`  
**Authentication**: `password` parameter matching `live_streaming_pass` config
**Example**: `http://<host>:25461/api.php?&password=<live_streaming_pass>`  

---

## Core API Endpoints

### 1. VOD Stream Control
#### **GET** `/api.php?action=vod`
**Description:** Start or stop video on demand (VOD) streams.
**Parameters:**
| Parameter   | Type     | Required | Description |
|------------|----------|----------|-------------|
|stream_ids   |array of integers   |yes      |List of stream IDs. |
|function     |string              |yes      |Action to perform (`start` or `stop`) |

---

### 2. Manage Streams
#### **GET** `/api.php?action=stream`
**Description:** Start or stop live streams.
**Parameters:**
| Parameter   | Type     | Required | Description |
|------------|----------|----------|-------------|
|stream_ids   |array of integers   |yes      |List of stream IDs. |
|function     |string              |yes      |Action to perform (`start` or `stop`) |

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
  ...,
}
```

---

### 4. Process Lifecycle Verification
#### **GET** `/api.php?action=pidsAreRunning`
**Description:** Checks if given process IDs (PIDs) are running.
| Parameter   | Type     | Required | Description |
|------------|----------|----------|-------------|
|pids         |array of integers   | yes   |List of PIDs to check. |
|program      |string    |yes       |Program name. |

---

### 5. Retrieve File
#### **GET** `/api.php?action=getFile`
**Description:** Downloads a specified file.
**Parameters:**
| Parameter   | Type     | Required | Description |
|------------|----------|----------|-------------|
|filename     |string    |yes       |Path to the file.|
**Response:**
- File content.

---

### 6. List Directory Contents
#### **GET** `/api.php?action=viewDir`
**Description:** Retrieves a directory listing.
**Parameters:**
| Parameter   | Type     | Required | Description |
|------------|----------|----------|-------------|
|dir          |string    |yes       |Path to the directory.|
**Response:**
```html
<ul class="jqueryFileTree" style="display: none;">
  <li class="directory collapsed"><a href="#" rel="/path/to/directory/">directory_name</a></li>
  <li class="file ext_txt"><a href="#" rel="/path/to/file.txt">file.txt</a></li>
</ul>
```

---

### 7. Redirect Connection
#### **GET** `/api.php?action=redirect_connection`
**Description:** Redirects a connection based on activity ID and stream ID.
**Parameters:**
| Parameter   | Type     | Required | Description |
|------------|----------|----------|-------------|
|activity_id  |integer   |yes       |Activity identifier. |
|stream_id    |integer   |yes       |Stream identifier. |

---

### 8. Send Signal
#### **GET** `/api.php?action=signal_send`
**Description:** Sends a signal message to an activity.
**Parameters:**
| Parameter   | Type     | Required | Description |
|------------|----------|----------|-------------|
|message      |string    |yes       |The message to send. |
|activity_id  |integer   |yes       |The activity identifier.|


---

### 9. Clear the temporary files folder
#### **GET** `/api.php?action=free_temp`
**Description:** Deletes temporary files and runs a script for caching..

---

### 10. Clear the Broadcasts folder
#### **GET** `/api.php?action=free_streams`
**Description:** Clears the stream folder.

---

### 11. Get free space
#### **GET** `/api.php?action=get_free_space`
**Description:** Returns information about free disk space.

---

### 12. Get PIDs
#### **GET** `/api.php?action=get_pids`
**Description:** Returns a list of running processes.

---
### 13. End the process by PID
#### **GET** `/api.php?action=kill_pid`
**Description:** Kill the process by PID.

---

## Error Codes
| Code                 | Description |
|----------------------|-------------|
| INVALID_API_PASSWORD | API password is incorrect |
| API_IP_NOT_ALLOWED   | IP address is not allowed |
| INVALID_REQUEST      | Invalid request parameters |
## Notes
- All requests must be authenticated with the correct API password.
- Some actions may require additional permissions or restrictions based on server configurations.