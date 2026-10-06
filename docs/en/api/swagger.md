# Interactive API Reference (Swagger)

Interactive, always-in-sync Swagger UI for every XC_VM API, generated from OpenAPI 3.0 specifications. Use the **API tabs** at the top of the page to switch between APIs, or open one directly via the links below.

| API | Description | Auth | Open |
| --- | --- | --- | --- |
| **Admin API** | XUI.ONE-compatible administration — lines, users, streams, VOD, series, servers, settings (104 endpoints) | `api_key` + access code | [Open ↗](../../_media/swagger-ui.html?spec=admin) |
| **System API** | Internal `/api.php` — stream/VOD control, stats, processes, files, connections (31 actions) | `password` (`live_streaming_pass`) | [Open ↗](../../_media/swagger-ui.html?spec=system) |
| **Player API** | XtreamCodes player — Live TV, VOD, Series, EPG | `username` + `password` | [Open ↗](../../_media/swagger-ui.html?spec=player) |
| **Playlist API** | `/playlist` authentication + playlist generation | `username`/`password` or `token` | [Open ↗](../../_media/swagger-ui.html?spec=playlist) |

---

## Using "Try it out"

1. Open a spec, then use the **API tabs** to switch APIs, and the **Documentation / Interactive (Swagger)** tabs for each API.
2. Expand any endpoint → **Try it out** → fill parameters → **Execute** to see the real request URL, cURL command and live response.
3. For the Admin API, click **Authorize 🔓** and paste your API key — it is then attached to every request automatically.

> **CORS note:** direct "Try it out" calls from the browser to your server may be blocked by CORS. This is expected — use the generated cURL command, Postman or Insomnia instead. The error does not mean the API is broken.

---

## Notes on answers

**Admin API**

- An API key acts with the permissions its holder's group lists. The table under [Admin API keys](../guides/permissions-and-rbac.md#admin-api-keys) names the permission each action asks for; an action the group has no permission for answers `STATUS_NO_PERMISSIONS`. The active-code API answers the same when it is called with an Admin API key.
- `delete_user`, `disable_user`, `enable_user` and `adjust_credits` answer `STATUS_FAILURE` for an administrator's account unless the key belongs to a full administrator.
- `get_user`, `create_user` and `edit_user` answer the account without its `password`, and with its `api_key` only when it is the account the calling key belongs to.
- `create_line`, `edit_line`, `create_mag`, `edit_mag`, `create_enigma` and `edit_enigma` answer `STATUS_INVALID_USERNAME` or `STATUS_INVALID_PASSWORD` when the username or password contains `/`. A line or device that already has such a value keeps it and stays editable while that value is sent unchanged or left out. The reseller API answers the same for lines; see [Reseller System](../administration/reseller-system.md#rest-api).
- `edit_line` keeps the line's username and password when the request does not send them. An empty value asks for a generated one.
- `edit_user`: `credits` sets the balance to that value; leave it out to keep the balance. A value equal to the balance as `get_user` answers it (six significant digits) keeps the balance too.
- `create_group` and `edit_group`: `group_id` in the body is ignored. A new group gets the next id, and an edit changes the group named by `id`.
- `create_transcode_profile` and `edit_transcode_profile` answer `STATUS_INVALID_INPUT` when `video_codec_cpu`, `video_codec_gpu`, `audio_codec` or a `preset_*` field is not a single name, or a `video_profile_*` field is not a name with an optional ` -level N`.
- `install_server` installs a load balancer and `install_proxy` a proxy. Both need `ssh_port`, `root_username` and `root_password`, and answer `STATUS_INVALID_INPUT` without one of them.
- `activity_logs` rows (`player`) and `live_connections` rows (`user_agent`) carry the device's name as it is stored, with HTML entities encoded (`&amp;`, `&quot;`). The panel's tables show the decoded name.

**Player API**

- Signing in with an activation code as username or token answers `user_info.status` `Expired` ("Account has expired.") when the code's subscription has run out, and `Disabled` ("Account has been disabled.") when the code is suspended or revoked. Other refused codes answer "Username or password is invalid.".

---

## Raw specifications

Every spec can be imported into Postman, Insomnia or any OpenAPI 3.0 tooling:

- [`admin-api.openapi.yaml`](../../_media/admin-api.openapi.yaml)
- [`system-api.openapi.yaml`](../../_media/system-api.openapi.yaml)
- [`player-api.openapi.yaml`](../../_media/player-api.openapi.yaml)
- [`playlist-api.openapi.yaml`](../../_media/playlist-api.openapi.yaml)
