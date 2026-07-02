# Interactive API Reference (Swagger)

Interactive, always-in-sync Swagger UI for every XC_VM API, generated from OpenAPI 3.0 specifications. Use the **API tabs** at the top of the page to switch between APIs, or open one directly via the links below.

| API | Description | Auth | Open |
| --- | --- | --- | --- |
| **Admin API** | XUI.ONE-compatible administration — lines, users, streams, VOD, series, servers, settings (104 endpoints) | `api_key` + access code | [Open ↗](_media/swagger-ui.html?spec=admin ':ignore :target=_blank') |
| **System API** | Internal `/api.php` — stream/VOD control, stats, processes, files, connections (31 actions) | `password` (`live_streaming_pass`) | [Open ↗](_media/swagger-ui.html?spec=system ':ignore :target=_blank') |
| **Player API** | XtreamCodes player — Live TV, VOD, Series, EPG | `username` + `password` | [Open ↗](_media/swagger-ui.html?spec=player ':ignore :target=_blank') |
| **Playlist API** | `/playlist` authentication + playlist generation | `username`/`password` or `token` | [Open ↗](_media/swagger-ui.html?spec=playlist ':ignore :target=_blank') |

---

## Using "Try it out"

1. Open a spec, then use the **API tabs** to switch APIs, and the **Documentation / Interactive (Swagger)** tabs for each API.
2. Expand any endpoint → **Try it out** → fill parameters → **Execute** to see the real request URL, cURL command and live response.
3. For the Admin API, click **Authorize 🔓** and paste your API key — it is then attached to every request automatically.

> **CORS note:** direct "Try it out" calls from the browser to your server may be blocked by CORS. This is expected — use the generated cURL command, Postman or Insomnia instead. The error does not mean the API is broken.

---

## Raw specifications

Every spec can be imported into Postman, Insomnia or any OpenAPI 3.0 tooling:

- [`admin-api.openapi.yaml`](_media/admin-api.openapi.yaml ':ignore :target=_blank')
- [`system-api.openapi.yaml`](_media/system-api.openapi.yaml ':ignore :target=_blank')
- [`player-api.openapi.yaml`](_media/player-api.openapi.yaml ':ignore :target=_blank')
- [`playlist-api.openapi.yaml`](_media/playlist-api.openapi.yaml ':ignore :target=_blank')
