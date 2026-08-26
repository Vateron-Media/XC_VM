# Device Detection & STB Locking

XC_VM parses the client User-Agent (Mobile_Detect) to adapt the admin UI, and locks set-top-box (STB) accounts to specific hardware so a line cannot be shared across devices. These checks run alongside the geo checks in `src/Public/stream/auth.php`.

> For GeoIP/ISP/ASN geolocation, geo routing and MaxMind updates, see the companion page [GeoIP, ISP Detection & Geo Routing](geoip-isp-and-geo-routing.md). For the Stalker/Ministra portal handshake that drives STB profiles, see [Ministra STB Emulation](ministra-browser-emulation.md).

---

## Mobile_Detect

File: `src/vendor/mobiledetect/mobiledetectlib/src/MobileDetect.php` (Composer dependency `mobiledetect/mobiledetectlib`)

Library (v4.9.0, namespace `Detection\MobileDetect`) for User-Agent parsing:

```php
$detect = new \Detection\MobileDetect();
$detect->isMobile();    // phones
$detect->isTablet();    // tablets
$detect->isAndroid();   // brand-specific
```

Used in `src/bootstrap.php` to set the `$rMobile` flag, which switches the admin/reseller panel to its responsive (mobile) layout. This is **UI-only** — it does not affect streaming access; STB access control is the hardware-lock logic below.

---

## Set-Top Box (STB) Devices

Two services manage STB accounts and their hardware-lock fields. Each device is a row (MAG → `mag_devices`, Enigma2 → `enigma2_devices`) bound to a line.

**EnigmaService** (`src/Domain/Device/EnigmaService.php`) — Enigma2 STBs.
Lock fields: `token`, `lversion`, `cpu`, `enigma_version`, `modem_mac`, `local_ip`.

**MagService** (`src/Domain/Device/MagService.php`) — MAG STBs.
Lock fields: `ver`, `device_id2`, `device_id`, `hw_version`, `image_version`, `stb_type`, `sn`.

Both device types share three enforcement toggles:

| Field | Effect |
| --- | --- |
| `lock_device` | hardware lock — pins the account to the identifiers captured on first connect |
| `is_isplock` | ISP binding (see the geo page) |
| `forced_country` | force the device to a specific country (see the geo page) |

### Where it's configured

In the admin panel: **MAG Devices** and **Enigma Devices** pages — add/edit a device row, toggle **Lock Device**, and (for Ministra portals) set the allowed STB types via `allowed_stb_types`. The set of STB types the portal advertises to the box flows from panel settings through `PortalHandler` into the client profile (see [Ministra STB Emulation](ministra-browser-emulation.md)).

### How the lock is enforced

1. **First connect** — with `lock_device = 1` and no identifiers stored yet, the box's hardware ids (e.g. MAG `device_id`/`device_id2`/`sn`, Enigma `modem_mac`) are captured onto the device row.
2. **Subsequent connects** — the values presented in the request/token must match the stored ones. A mismatch fails auth with `DEVICE_NOT_ALLOWED` (or `TOKEN_EXPIRED` when the portal token no longer matches the locked device).
3. **stb_type gate** — if `allowed_stb_types` is set, the box's reported `stb_type` must be one of the allowed values, otherwise the portal rejects it.

**Worked example (MAG):** a reseller creates a MAG line with `lock_device = 1`. The customer's box connects once → its `device_id`/`sn` are stored. If the customer copies the portal URL + MAC to a second box, that box reports a different `device_id`/`sn` → auth returns `DEVICE_NOT_ALLOWED`. To move the line to a new box, an admin clears the stored identifiers (edit the device row) so the next connect re-captures them.

---

## Access Control Checks (device)

These run in `src/Public/stream/auth.php` during token validation, after the geo checks (1-4, on the [geo page](geoip-isp-and-geo-routing.md)).

### 5. User-Agent blocking

```text
check against BlocklistService::checkBlockedUAs()
error: BLOCKED_USER_AGENT

if user has allowed_ua set:
    user_agent must match one entry
    error: NOT_IN_ALLOWED_UAS
```

If `disallow_empty_user_agents` is enabled, a request with no `User-Agent` header is rejected outright.

### 6. Device type validation

```text
MAG device flag must match token
error: DEVICE_NOT_ALLOWED or TOKEN_EXPIRED
```

---

## Configuration (device settings)

| Setting | Type | Description |
| --- | --- | --- |
| `disallow_empty_user_agents` | `0/1` | reject requests without a User-Agent header |
| `allowed_stb_types` | `array` | STB types a Ministra portal will accept (empty = all) |

Per-device toggles (`lock_device`, `is_isplock`, `forced_country`) are set on the device row, not in global settings.

---

## Related files

| File | Purpose |
| --- | --- |
| `src/vendor/mobiledetect/mobiledetectlib/src/MobileDetect.php` | `\Detection\MobileDetect` User-Agent library (Composer dep) |
| `src/Domain/Device/EnigmaService.php` | Enigma2 STB management + lock fields |
| `src/Domain/Device/MagService.php` | MAG STB management + lock fields |
| `src/Public/stream/auth.php` | streaming auth with the device checks (5-6) |
| `src/Domain/Security/BlocklistService.php` | blocked / allowed User-Agent checks |
