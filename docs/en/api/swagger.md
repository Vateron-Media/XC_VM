# Interactive API Reference (Swagger)

Interactive, always-in-sync Swagger UI for every XC_VM API, generated from OpenAPI 3.1 specifications traced from the source: each operation cites the file and line it was read from (`src/...:line`). Use the **API tabs** at the top of the page to switch between APIs, or open one directly via the links below.

| API | Description | Auth | Open |
| --- | --- | --- | --- |
| **Player API** | `player_api.php` sign-in, live TV, VOD, series and EPG; legacy `panel_api.php`; `xmltv.php` guide (15 operations) | `username` + `password`, `token` or activation code | [Open ↗](../../_media/swagger-ui.html?spec=player) |
| **Playlist API** | `get.php` playlists — M3U, M3U Plus, Enigma2 bouquets, set-top box formats; also `/playlist` | `username` + `password` or `token` | [Open ↗](../../_media/swagger-ui.html?spec=playlist) |
| **Streaming** | Live, VOD, series, catch-up, HLS segments and keys, thumbnails, subtitles (36 operations) | Line credentials in the path, or a line / play token | [Open ↗](../../_media/swagger-ui.html?spec=streaming) |
| **MAG Portal** | Stalker/Ministra portal for MAG and compatible set-top boxes (91 operations) | MAC handshake + bearer token | [Open ↗](../../_media/swagger-ui.html?spec=mag) |
| **Enigma2** | `enigma2.php` XML playlists and the `xplugin.php` companion API (13 operations) | `username` + `password`; device MAC + token | [Open ↗](../../_media/swagger-ui.html?spec=enigma2) |
| **Activation** | `active_code.php` activation and code management, and the `/portal` self-activation page (15 operations) | Activation code, or admin / reseller `api_key` | [Open ↗](../../_media/swagger-ui.html?spec=activation) |
| **Admin API** | Administration — lines, devices, users, groups, packages, streams, VOD, series, servers, settings, logs (187 actions) | `api_key` + Admin API access code | [Open ↗](../../_media/swagger-ui.html?spec=admin) |
| **Reseller API** | A reseller's lines, devices, sub-accounts, activation codes and credits (49 actions) | `api_key` + Reseller API access code | [Open ↗](../../_media/swagger-ui.html?spec=reseller) |
| **System API** | Node actions of `/api.php` (now run over the cluster bus; HTTP answers 404) and the `/admin/*` stream endpoints (37 operations) | Signed `node.rpc`; relay ticket or `live_streaming_pass` | [Open ↗](../../_media/swagger-ui.html?spec=system) |
| **Cluster API** | MAIN-only node-to-MAIN control plane `/cluster/v1/<op>` (22 operations) | Signed `X-XCVM-*` headers | [Open ↗](../../_media/swagger-ui.html?spec=cluster) |
| **Monitoring** | `/metrics` (Prometheus), `/healthz`, `/progress` (3 operations) | `metrics_token` bearer for `/metrics` | [Open ↗](../../_media/swagger-ui.html?spec=monitoring) |

---

## Using "Try it out"

1. Open a spec, then use the **API tabs** to switch APIs, and the **Documentation / Interactive (Swagger)** tabs for each API.
2. Expand any endpoint → **Try it out** → fill parameters → **Execute** to see the real request URL, cURL command and live response.
3. For the Admin, Reseller and Monitoring APIs, click **Authorize 🔓** and paste your API key or metrics token — it is then attached to every request automatically.

> **CORS note:** direct "Try it out" calls from the browser to your server may be blocked by CORS. This is expected — use the generated cURL command, Postman or Insomnia instead. The error does not mean the API is broken.

---

## Notes on answers

**Admin API**

- `api_key` takes an account's API key or one of its [API tokens](../guides/permissions-and-rbac.md#api-tokens), which can be limited to reading, or to lines, devices and activation codes.
- An API key acts with the permissions its holder's group lists. The table under [Admin API keys](../guides/permissions-and-rbac.md#admin-api-keys) names the permission each action asks for; an action the group has no permission for answers `STATUS_NO_PERMISSIONS`. The active-code API answers the same when it is called with an Admin API key.
- `delete_user`, `disable_user`, `enable_user` and `adjust_credits` answer `STATUS_FAILURE` for an administrator's account unless the key belongs to a full administrator.
- `get_user`, `create_user` and `edit_user` answer the account without its `password`, and with its `api_key` only when it is the account the calling key belongs to.
- `create_line`, `edit_line`, `create_mag`, `edit_mag`, `create_enigma` and `edit_enigma` answer `STATUS_INVALID_USERNAME` or `STATUS_INVALID_PASSWORD` when the username or password contains `/`. A line or device that already has such a value keeps it and stays editable while that value is sent unchanged or left out. The reseller API answers the same for lines; see [Reseller System](../administration/reseller-system.md#rest-api).
- `edit_line` keeps the line's username and password when the request does not send them. An empty value asks for a generated one.
- Every `edit_*` action keeps each field the request leaves out, as the record has it stored, so a request may send only the fields it changes. Send a field to change it; send it empty to clear it (a list sent empty is an empty list, a date sent empty is none). A switch (a checkbox of the panel's form) is on at `1` and off at `0` or empty. The fields are named as the panel's form posts them: lists as arrays (`bouquets[]`, `category_id[]`, `domain_name[]`, `http_broadcast_ports[]`), or as a JSON array where the form posts one (`bouquets_selected`, `groups_selected`, `permissions_selected`, `packages_selected`, `server_tree_data`). An HMAC key is kept unless `keygen` is sent; an RTMP address keeps its password unless one is sent. `edit_settings` changes only the settings the request names, the stream argument defaults (`user_agent`, `http_proxy`, `cookie`, `headers`) and the genre mapping included.
- For `edit_line`: `exp_date` empty, or `no_expire` on, removes the expiry (a `no_expire` of `0` or empty is off); `isp_clear` clears the stored ISP. `exp_date` takes a date string, not the timestamp `get_line` answers. `bouquets_selected` is a JSON array, outputs are `access_output[]`. `is_stalker`, `is_restreamer`, `is_trial`, `is_isplock` and `bypass_ua` are off at `0` or empty. `enabled` and `admin_enabled` are kept: send `enabled=1` / `admin_enabled=1` to switch a disabled or banned line back on (an edit used to do that whatever it sent).
- `edit_user`: `credits` sets the balance to that value; leave it out to keep the balance. A value equal to the balance as `get_user` answers it (six significant digits) keeps the balance too.
- `create_group` and `edit_group`: `group_id` in the body is ignored. A new group gets the next id, and an edit changes the group named by `id`.
- `create_transcode_profile` and `edit_transcode_profile` answer `STATUS_INVALID_INPUT` when `video_codec_cpu`, `video_codec_gpu`, `audio_codec` or a `preset_*` field is not a single name, or a `video_profile_*` field is not a name with an optional ` -level N`.
- `install_server` installs a load balancer and `install_proxy` a proxy. Both need `ssh_port`, `root_username` and `root_password`, and answer `STATUS_INVALID_INPUT` without one of them.
- `activity_logs` rows (`player`) and `live_connections` rows (`user_agent`) carry the device's name as it is stored, with HTML entities encoded (`&amp;`, `&quot;`). The panel's tables show the decoded name.

**Player API**

- Signing in with an activation code as username or token answers `user_info.status` `Expired` ("Account has expired.") when the code's subscription has run out, and `Disabled` ("Account has been disabled.") when the code is suspended or revoked. Other refused codes answer "Username or password is invalid.".

---

## Raw specifications

Every spec can be imported into Postman, Insomnia or any OpenAPI 3.1 tooling:

- [`player-api.openapi.yaml`](../../_media/player-api.openapi.yaml)
- [`playlist-api.openapi.yaml`](../../_media/playlist-api.openapi.yaml)
- [`streaming-api.openapi.yaml`](../../_media/streaming-api.openapi.yaml)
- [`mag-portal-api.openapi.yaml`](../../_media/mag-portal-api.openapi.yaml)
- [`enigma2-api.openapi.yaml`](../../_media/enigma2-api.openapi.yaml)
- [`activation-api.openapi.yaml`](../../_media/activation-api.openapi.yaml)
- [`admin-api.openapi.yaml`](../../_media/admin-api.openapi.yaml)
- [`reseller-api.openapi.yaml`](../../_media/reseller-api.openapi.yaml)
- [`system-api.openapi.yaml`](../../_media/system-api.openapi.yaml)
- [`cluster-api.openapi.yaml`](../../_media/cluster-api.openapi.yaml)
- [`monitoring-api.openapi.yaml`](../../_media/monitoring-api.openapi.yaml)
