# Get playlist

## File location
```
/home/xc_vm/wwwdir/get.php
```

## Overview
This API provides authentication and playlist generation functionalities. Clients can authenticate using either username/password or a token. Upon successful authentication, a playlist is generated.

## Authentication
The API requires either a username and password or a token for authentication.

### Base URL
```
http://<your-domain>:25461/get.php
```

## Endpoints

### 1. Authenticate and Generate Playlist
**Endpoint:**
```
GET /
```

**Request Parameters:**

| Parameter   | Type     | Required | Description |
|------------|----------|----------|-------------|
| username   | string   | Yes*     | Username for authentication (Required if `token` is not used). |
| password   | string   | Yes*     | Password for authentication (Required if `token` is not used). |
| token      | string   | Yes*     | Authentication token (Required if `username` and `password` are not used). |
| type       | string   | No       | Device type (default: `m3u_plus`). |
| key        | string   | No       | type of content (live, movie, radio_streams, series) |
| output     | string   | No       | Output format (hls or m3u). |
| nocache    | boolean  | No       | If true, disables caching. |


**Example Request:**
```sh
curl -X GET "http://<your-domain>:25461/get.php?username=test&password=test&type=m3u_plus&output=hls&key=live"
```

**Response:**
- Playlist file.

## Error Codes

| Error Code                 | Description |
|----------------------------|-------------|
| NO_CREDENTIALS             | Missing authentication details. |
| INVALID_CREDENTIALS        | Incorrect username, password, or token. |
| BLOCKED_USER_AGENT         | User agent is blocked. |
| EXPIRED                    | Account has expired. |
| DEVICE_NOT_ALLOWED         | Device type is not permitted. |
| BANNED                     | User is banned. |
| DISABLED                   | Account is disabled. |
| EMPTY_USER_AGENT           | User agent is required but missing. |
| NOT_IN_ALLOWED_IPS         | IP address is not allowed. |
| NOT_IN_ALLOWED_COUNTRY     | Country is not allowed. |
| NOT_IN_ALLOWED_UAS         | User agent is not allowed. |
| ISP_BLOCKED                | ISP is blocked. |
| ASN_BLOCKED                | ASN restriction applied. |
| DOWNLOAD_LIMIT_REACHED     | Too many requests. |
| GENERATE_PLAYLIST_FAILED   | Failed to generate playlist. |

## Notes
- Ensure proper request parameters are provided to avoid authentication errors.
- The API enforces user agent, IP, and country restrictions where applicable.
- Download limits may be enforced to prevent excessive requests.

