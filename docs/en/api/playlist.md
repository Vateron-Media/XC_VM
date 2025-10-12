<h1 align="center">📺 Get Playlist</h1>

<p align="center">
  This API provides authentication and playlist generation features for <b>XC_VM</b>.  
  Clients can authenticate using a username/password or token, after which a personalized playlist is generated.
</p>

<p align="center">
  <a href="../../en/main-page.md"><b>⬅️ Back to main page</b></a>
</p>

---

## 📚 Navigation

* [📂 File Location](#file-location)
* [🔑 Authentication](#authentication)
* [🚀 Endpoints](#endpoints)
* [❌ Error Codes](#error-codes)
* [🧾 Notes](#notes)

---

## 📂 File Location

The main API file is located at:

```bash
/home/xc_vm/wwwdir/playlist.php
```

---

## 🔑 Authentication

The API requires authentication either via username and password or via token.
This ensures secure access to the content.

### Base URL

```http
http://<your-domain>:80/playlist
```

---

## 🚀 Endpoints

### 1. Authentication and Playlist Generation

**Method & Endpoint:**

```http
GET /
```

**Query Parameters:**

| Parameter | Type    | Required | Description                                                                                       |
| --------- | ------- | -------- | ------------------------------------------------------------------------------------------------- |
| username  | string  | Yes*     | Username for authentication (required if `token` is not used).                                    |
| password  | string  | Yes*     | Password for authentication (required if `token` is not used).                                    |
| token     | string  | Yes*     | Authentication token (required if username and password are not used).                            |
| type      | string  | No       | Device type (default: `m3u_plus`).                                                                |
| key       | string  | No       | Content type (`live` — live TV, `movies` — movies, `radio_streams` — radio, `series` — TV shows). |
| output    | string  | No       | Output format (`hls` or `m3u`).                                                                   |
| nocache   | boolean | No       | If true, disables caching.                                                                        |

> 💡 *Yes* — one option is required: either username/password or token.

**Example Requests:**

```bash
curl -X GET "http://<your-domain>:80/playlist/username/password/type&output=hls&key=live"
```

```bash
curl -X GET "http://<your-domain>:80/playlist/token/type&output=hls&key=live"
```

**Response:**

* Playlist file in the requested format (M3U or HLS).

---

## ❌ Error Codes

If an error occurs, the API returns the corresponding error codes for easier troubleshooting.

| Error Code               | Description                           |
| ------------------------ | ------------------------------------- |
| NO_CREDENTIALS           | Missing authentication data.          |
| INVALID_CREDENTIALS      | Invalid username, password, or token. |
| BLOCKED_USER_AGENT       | User agent is blocked.                |
| EXPIRED                  | Account has expired.                  |
| DEVICE_NOT_ALLOWED       | Device type is not allowed.           |
| BANNED                   | User is banned.                       |
| DISABLED                 | Account is disabled.                  |
| EMPTY_USER_AGENT         | User agent is required but missing.   |
| NOT_IN_ALLOWED_IPS       | IP address is not allowed.            |
| NOT_IN_ALLOWED_COUNTRY   | Country is not allowed.               |
| NOT_IN_ALLOWED_UAS       | User agent is not allowed.            |
| ISP_BLOCKED              | ISP is blocked.                       |
| ASN_BLOCKED              | ASN restriction applied.              |
| DOWNLOAD_LIMIT_REACHED   | Too many requests.                    |
| GENERATE_PLAYLIST_FAILED | Failed to generate playlist.          |

> ⚠️ **Tip:** Check server logs for more details if errors occur.

---

## 🧾 Notes

* **Query Parameters:** Make sure all parameters are provided correctly to avoid authentication errors.
* **Restrictions:** The API applies filters by user agent, IP address, and country to enhance security.
* **Rate Limiting:** Request limits are enforced to prevent server overload.
* **Recommendations:** Use tools like cURL or Postman for testing. In production, integrate this API into your application for automated playlist generation.

> 💬 If you have integration questions, open an issue in the [repository](https://github.com/Vateron-Media/XC_VM/issues).

---

<p align="center">
  <a href="../../en/main-page.md"><b>⬅️ Back to main page</b></a>
</p>
