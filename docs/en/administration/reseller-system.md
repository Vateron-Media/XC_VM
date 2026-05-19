# Reseller System

The reseller system enables multi-level affiliate management with credit-based line provisioning.
Resellers create and manage IPTV lines, MAG devices, and Enigma2 devices within their allocated credits and permissions.

---

## Navigation

- [Overview](#overview)
- [Credit System](#credit-system)
- [Line Management](#line-management)
- [Device Management](#device-management)
- [Sub-Reseller Hierarchy](#sub-reseller-hierarchy)
- [Permissions](#permissions)
- [REST API](#rest-api)
- [Session and Bootstrap](#session-and-bootstrap)
- [Routes](#routes)
- [Related Files](#related-files)

---

## Overview

```text
Admin
  └── assigns credits + group permissions
        └── Reseller
              ├── creates IPTV lines (costs credits)
              ├── creates MAG devices (costs credits)
              ├── creates Enigma2 devices (costs credits)
              └── creates sub-resellers (costs credits)
                    └── sub-reseller has own lines + credits
```

Core business logic is in `src/domain/User/ResellerAPI.php`. Web controllers are under `src/public/Controllers/Reseller/`. REST API is in `src/public/Controllers/Api/ResellerRestApiController.php`.

---

## Credit System

Credits are the currency for all reseller operations. Each action has a cost, and the reseller's balance must cover it.

### Credit costs

| Action | Cost source |
| --- | --- |
| Create line (official) | `package.official_credits` |
| Create line (trial) | `package.trial_credits` |
| Create MAG device | same as line |
| Create Enigma2 device | same as line |
| Create sub-reseller | `permissions.create_sub_resellers_price` |

### Override pricing

Resellers can have custom per-package pricing via `override_packages` JSON on their user record:

```php
$rOverride = json_decode($rUserInfo['override_packages'], true);
if (isset($rOverride[$rPackage['id']]['official_credits'])) {
    $rCost = intval($rOverride[$rPackage['id']]['official_credits']);
}
```

### Credit transfer

Resellers can transfer credits to their direct reports via the `adjust_credits` API action. Both balances must remain >= 0.

### Logging

All credit operations are recorded in `users_logs`:

| Field | Description |
| --- | --- |
| `owner` | reseller user ID |
| `type` | `line`, `mag`, `enigma`, `user` |
| `action` | `new`, `extend`, `edit`, `adjust_credits` |
| `cost` | credits spent |
| `credits_after` | balance after operation |
| `package_id` | package used |
| `date` | timestamp |

---

## Line Management

Lines are IPTV user subscriptions. Types:

| Type | Flags |
| --- | --- |
| Standard IPTV line | `is_mag=0, is_e2=0` |
| MAG device | `is_mag=1` |
| Enigma2 device | `is_e2=1` |

### Creation process

1. Validate package accessibility (must be in reseller's group permissions).
2. Verify `credits >= cost`.
3. Generate username/password if allowed by permissions.
4. Apply package: `exp_date`, `max_connections`, `bouquets`, `allowed_outputs`.
5. Set restrictions: `allowed_ips` (JSON), `allowed_ua`, `bypass_ua`, `is_isplock`.
6. Insert into `lines` table via `REPLACE INTO`.
7. Sync device entries (`mag_devices` or `enigma2_devices`).
8. Broadcast signal event to streaming servers.
9. Deduct credits and log transaction.

### Bouquet assignment

Each package specifies available bouquets via `bouquets` JSON array.
If `allow_change_bouquets` permission is enabled, the reseller can select a subset of the package bouquets. Otherwise all package bouquets are auto-assigned.

---

## Device Management

### MAG devices

Managed by `MagService` (`src/domain/Device/MagService.php`).
Lock fields: `ver`, `device_id2`, `device_id`, `hw_version`, `image_version`, `stb_type`, `sn`.

### Enigma2 devices

Managed by `EnigmaService` (`src/domain/Device/EnigmaService.php`).
Lock fields: `token`, `lversion`, `cpu`, `enigma_version`, `modem_mac`, `local_ip`.

Both device types support `lock_device` (hardware binding), `is_isplock` (ISP binding), and `forced_country`.

---

## Sub-Reseller Hierarchy

Resellers can create sub-resellers (if `create_sub_resellers` permission is granted):

- Sub-resellers are linked via `owner_id` field.
- Multi-level: a sub-reseller can create their own sub-resellers.
- Each creation costs `create_sub_resellers_price` credits.
- Assigned `member_group_id` must be in the parent's `subresellers` permission array.

Ownership queries:

```php
Authorization::check('user', $rID)   // checks reseller hierarchy
Authorization::check('line', $rID)   // checks if reseller's reports own the line
```

Methods:

```php
UserRepository::getResellers($rOwner, $rIncludeSelf)
UserRepository::getDirectReports()
AuthRepository::getGroupPermissions()  // builds all_reports recursively
```

---

## Permissions

Permissions come from the `users_groups` table, loaded via `AuthRepository::getPermissions()`.

### Key permission fields

| Permission | Type | Description |
| --- | --- | --- |
| `is_reseller` | `bool` | user is a reseller |
| `create_line` | `bool` | can create IPTV lines |
| `create_mag` | `bool` | can create MAG devices |
| `create_enigma` | `bool` | can create Enigma2 devices |
| `create_sub_resellers` | `bool` | can create sub-resellers |
| `create_sub_resellers_price` | `int` | credit cost per sub-reseller |
| `allow_change_bouquets` | `bool` | can select bouquet subset |
| `allow_change_username` | `bool` | can set custom username |
| `allow_change_password` | `bool` | can set custom password |
| `allow_restrictions` | `bool` | can set IP/UA restrictions |
| `can_view_vod` | `bool` | can view VOD content |
| `reseller_client_connection_logs` | `bool` | can view connection logs |
| `minimum_username_length` | `int` | minimum username length |
| `minimum_password_length` | `int` | minimum password length |

### Page-level checks

`PageAuthorization::checkResellerPermissions()` maps pages to permissions:

| Pages | Required permission |
| --- | --- |
| `user`, `users` | `create_sub_resellers` |
| `line`, `lines` | `create_line` |
| `mag`, `mags` | `create_mag` |
| `enigma`, `enigmas` | `create_enigma` |
| `epg_view`, `streams`, `movies` | `can_view_vod` |
| `live_connections`, `line_activity` | `reseller_client_connection_logs` |

### Boundaries

What resellers **cannot** do:

- Access lines/users outside their hierarchy.
- Create or modify packages.
- Access admin-only settings.
- Exceed their credit balance.
- Bypass package group restrictions.

---

## REST API

File: `src/public/Controllers/Api/ResellerRestApiController.php`

Authentication via API key. Actions:

| Action | Description |
| --- | --- |
| `user_info` | reseller account info |
| `packages` | available packages |
| `get_lines` / `get_mags` / `get_enigmas` | list resources |
| `create_line` / `edit_line` / `delete_line` | line CRUD |
| `enable_line` / `disable_line` | toggle line status |
| `create_mag` / `edit_mag` / `delete_mag` | MAG CRUD |
| `create_enigma` / `edit_enigma` / `delete_enigma` | Enigma CRUD |
| `create_user` / `edit_user` / `delete_user` | sub-reseller CRUD |
| `adjust_credits` | transfer credits to sub-reseller |
| `activity_logs` / `live_connections` | connection data |

The `ResellerAPIWrapper` class validates the API key, initializes a session via `ResellerAPI`, and returns filtered JSON responses.

---

## Session and Bootstrap

### Session

File: `src/infrastructure/bootstrap/reseller_session.php`

- 60-minute timeout with last activity tracking.
- IP change detection (if `ip_logout` setting enabled).
- Session keys: `reseller` (user ID), `rip`, `rcode`, `rverify`, `rlast_activity`.

### Functions bootstrap

File: `src/infrastructure/bootstrap/reseller_functions.php`

- Loads database and utilities.
- Initializes `$rUserInfo` and `$rPermissions`.
- Validates session integrity (username/password hash verification).
- Sets timezone and language preferences.

---

## Routes

File: `src/public/routes/reseller.php`

Key routes:

```text
GET  /dashboard              → ResellerDashboardController
GET  /edit_profile            → ResellerEditProfileController
POST /post                    → ResellerPostController (form handler)
GET  /api, POST /api          → ResellerApiController
GET  /table, POST /table      → ResellerTableController

GET  /lines                   → ResellerLinesController
GET  /line                    → ResellerLineController
GET  /mags                    → ResellerMagsController
GET  /mag                     → ResellerMagController
GET  /enigmas                 → ResellerEnigmasController
GET  /enigma                  → ResellerEnigmaController

GET  /users                   → ResellerUsersController
GET  /user                    → ResellerUserController
GET  /user_logs               → ResellerUserLogsController
GET  /live_connections        → ResellerLiveConnectionsController
GET  /line_activity           → ResellerLineActivityController
GET  /tickets                 → ResellerTicketsController
```

---

## Related Files

| File | Purpose |
| --- | --- |
| `src/domain/User/ResellerAPI.php` | core business logic |
| `src/public/Controllers/Api/ResellerRestApiController.php` | REST API |
| `src/public/Controllers/Reseller/*.php` | web controllers |
| `src/public/routes/reseller.php` | URL routing |
| `src/public/Views/reseller/*.php` | view templates |
| `src/infrastructure/ResellerApiDispatcher.php` | AJAX action routing |
| `src/infrastructure/ResellerTableRenderer.php` | DataTables rendering |
| `src/infrastructure/bootstrap/reseller_session.php` | session management |
| `src/infrastructure/bootstrap/reseller_functions.php` | initialization |
| `src/core/Auth/Authorization.php` | ownership checks |
| `src/core/Auth/PageAuthorization.php` | page-level gating |
