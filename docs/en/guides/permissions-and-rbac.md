# Permissions and RBAC

XC_VM access control combines:

- **Group permissions** -- allowed capabilities assigned to an admin group
- **Object-level authorization** -- ownership checks for specific entities (users, lines)
- **Page-level authorization** -- route/page gating in admin and reseller panels

---

## Model

```text
user -> member_group_id -> group
         -> is_admin (boolean)
         -> is_reseller (boolean)
         -> advanced[] (array of permission keys)
```

Permission state is loaded into the `$rPermissions` global during session initialization and remains available throughout the request lifecycle.

Key fields in `$rPermissions`:

| Field | Type | Description |
| --- | --- | --- |
| `is_admin` | bool | Whether the user is an admin |
| `advanced` | array | List of granted permission key strings |
| `all_reports` | array | Reseller report tree (user IDs this reseller manages) |
| `create_line` | bool | Reseller: can create lines |
| `create_sub_resellers` | bool | Reseller: can create sub-resellers |
| `create_mag` | bool | Reseller: can create MAG devices |
| `create_enigma` | bool | Reseller: can create Enigma devices |
| `can_view_vod` | bool | Reseller: can view VOD/streams content |
| `reseller_client_connection_logs` | bool | Reseller: can view connection logs |

---

## Permission Keys

Permission keys are declared in `src/config/permissions.php` as the `$rPermissionKeys` array. Each key is a string identifier used with `Authorization::check('adv', $key)`.

Categories:

| Category | Examples |
| --- | --- |
| Create/Add | `add_stream`, `add_movie`, `add_user`, `add_server`, `add_bouquet`, `add_epg`, `add_code`, `add_hmac`, `add_rtmp` |
| Edit | `edit_stream`, `edit_movie`, `edit_user`, `edit_server`, `edit_bouquet`, `edit_series`, `edit_reguser` |
| Mass operations | `mass_edit_streams`, `mass_edit_lines`, `mass_edit_mags`, `mass_edit_enigmas`, `mass_edit_radio`, `mass_edit_users`, `mass_sedits`, `mass_sedits_vod`, `mass_delete` |
| Import | `import_streams`, `import_movies`, `import_episodes` |
| Security/Blocking | `block_ips`, `block_isps`, `block_uas`, `block_asns`, `fingerprint` |
| Section visibility | `streams`, `movies`, `series`, `episodes`, `radio`, `users`, `servers`, `bouquets`, `epg`, `settings`, `database` |
| Logs | `connection_logs`, `live_connections`, `client_request_log`, `credits_log`, `login_logs`, `panel_logs`, `reg_userlog`, `restream_logs` |
| Tools | `quick_tools`, `stream_tools`, `process_monitor`, `stream_errors` |
| Management | `mng_regusers`, `mng_groups`, `mng_packages`, `manage_mag`, `manage_e2`, `manage_events`, `manage_tickets` |
| Other | `categories`, `channel_order`, `player`, `tprofile`, `tprofiles`, `rtmp`, `folder_watch`, `folder_watch_add`, `folder_watch_output`, `folder_watch_settings`, `ticket`, `add_code`, `add_hmac` |

---

## Authorization Classes

### `Authorization`

File: `src/Core/Auth/Authorization.php`

Primary method:

```php
Authorization::check(string $rType, string|int|null $rID): bool
```

**Preconditions:** Returns `false` immediately if `$rUserInfo`, `$rPermissions`, or `$db` are not initialized.

#### Type: `user`

Checks whether the current user can access a target admin user. Builds a list from the current user's ID plus their `all_reports` tree, then queries the `users` table to verify the target user's `owner_id` is in that list (or the target is the current user).

```php
Authorization::check('user', $userId);
```

#### Type: `line`

Checks whether the current user can access a target line. Same report-tree approach -- queries the `lines` table to verify the target line's `member_id` is in the current user's report tree.

```php
Authorization::check('line', $lineId);
```

#### Type: `adv`

Checks whether the current admin user has a specific advanced permission key.

```php
Authorization::check('adv', 'edit_bouquet');
Authorization::check('adv', 'block_isps');
```

**Important: `is_admin` gate.** Before checking the advanced permissions array, the method requires `$rPermissions['is_admin']` to be true. If the user is not an admin, `check('adv', ...)` always returns `false`:

```php
if (!($rType == 'adv' && $rPermissions['is_admin'])) {
    return false;
}
```

This means `adv` checks are exclusively for admin users. Reseller permissions use a separate system (see below).

#### Super admin bypass

`member_group_id = 1` is the super admin group. When the advanced permissions array is non-empty but the user belongs to group 1, the per-key check is skipped and the method returns `true`:

```php
if (0 < count($rPermissions['advanced']) && $rUserInfo['member_group_id'] != 1) {
    return in_array($rID, $rPermissions['advanced']);
}
return true;
```

This means super admins pass all `adv` checks regardless of which keys are assigned to their group.

#### Reseller helper

```php
Authorization::hasResellerPermissions(string $type): bool
```

Returns whether `$rPermissions[$type]` is non-empty. Used for reseller-specific boolean flags like `create_line`, `create_mag`, etc.

---

### `PageAuthorization`

File: `src/Core/Auth/PageAuthorization.php`

Provides page-level gating for admin and reseller panels. Called during request dispatch to determine whether the current user can access a given page.

```php
PageAuthorization::checkPermissions(?string $page = null): bool
PageAuthorization::checkResellerPermissions(?string $page = null): bool
```

If `$page` is omitted, the page name is inferred from `SCRIPT_FILENAME` (basename without `.php` extension, lowercased).

#### Default-allow behavior

Both methods return `true` for any page not explicitly listed in their switch statements. This means pages without a mapping are accessible to all authorized users of the appropriate type (admin or reseller). Only pages with explicit entries are restricted.

---

## Admin Page Permission Mappings

The `checkPermissions()` method maps admin panel pages to `adv` permission keys. The complete mapping is listed below, grouped by category.

### Create-vs-edit pattern

Many entity pages use conditional logic based on request parameters:

- If an `id` parameter is present, the **edit** permission is checked
- If no `id` parameter is present, the **add** permission is checked
- Some pages (stream, movie) also check for an `import` parameter and require the corresponding import permission

When neither condition is met, behavior depends on the page: some fall through to a related listing permission, others fall through to the switch default (which returns `true`).

### Streams and Content

| Page | Permission | Notes |
| --- | --- | --- |
| `streams`, `stream_view`, `provider`, `providers`, `epg_view`, `created_channels`, `stream_rank`, `archive` | `streams` | |
| `stream` | `edit_stream` | When `id` is present |
| `stream` | `add_stream` | When no `id` |
| `stream` | `import_streams` | When `import` param is present (in addition to `add_stream`) |
| `stream_categories` | `categories` | |
| `stream_category` | `add_cat` | |
| `stream_errors` | `stream_errors` | |
| `stream_mass`, `created_channel_mass` | `mass_edit_streams` | |
| `mass_edit_streams` | `edit_stream` | |
| `review` | `import_streams` | |
| `channel_order` | `channel_order` | |
| `created_channel` | `edit_cchannel` | When `id` is present |
| `created_channel` | `create_channel` | When no `id` |

### Movies and VOD

| Page | Permission | Notes |
| --- | --- | --- |
| `movies` | `movies` | |
| `movie` | `edit_movie` | When `id` is present |
| `movie` | `add_movie` | When no `id` |
| `movie` | `import_movies` | When `import` param is present (in addition to `add_movie`) |
| `movie_mass` | `mass_sedits_vod` | |
| `record` | `add_movie` | |
| `recordings` | `movies` | |

### Series and Episodes

| Page | Permission | Notes |
| --- | --- | --- |
| `series` | `series` | |
| `serie` | `edit_series` | When `id` is present |
| `serie` | `add_series` | When no `id` |
| `series_order` | `edit_series` | |
| `episodes` | `episodes` | |
| `episode` | `edit_episode` | When `id` is present |
| `episode` | `add_episode` | When no `id`; falls through to `episodes` on denial |
| `series_mass`, `episodes_mass` | `mass_sedits` | |

### Radio

| Page | Permission | Notes |
| --- | --- | --- |
| `radios` | `radio` | |
| `radio` | `edit_radio` | When `id` is present |
| `radio` | `add_radio` | When no `id` |
| `radio_mass` | `mass_edit_radio` | |

### Lines (Subscriber Users)

| Page | Permission | Notes |
| --- | --- | --- |
| `lines` | `users` | |
| `line` | `edit_user` | When `id` is present |
| `line` | `add_user` | When no `id` |
| `line_mass` | `mass_edit_lines` | |
| `line_activity`, `theft_detection`, `line_ips` | `connection_logs` | |
| `live_connections` | `live_connections` | |

### MAG and Enigma Devices

| Page | Permission | Notes |
| --- | --- | --- |
| `mags` | `manage_mag` | |
| `mag` | `edit_mag` | When `id` is present |
| `mag` | `add_mag` | When no `id` |
| `mag_events` | `manage_events` | |
| `mag_mass` | `mass_edit_mags` | |
| `enigmas` | `manage_e2` | |
| `enigma_mass` | `mass_edit_enigmas` | |

### Admin Users (Registered Users)

| Page | Permission | Notes |
| --- | --- | --- |
| `users` | `mng_regusers` | |
| `user` | `edit_reguser` | When `id` is present |
| `user` | `add_reguser` | When no `id` |
| `user_mass` | `mass_edit_users` | |
| `user_logs` | `reg_userlog` | |

### Bouquets and Packages

| Page | Permission | Notes |
| --- | --- | --- |
| `bouquets` | `bouquets` | |
| `bouquet` | `edit_bouquet` | When `id` is present |
| `bouquet` | `add_bouquet` | When no `id`; falls through to `edit_bouquet` on denial |
| `bouquet_order`, `bouquet_sort` | `edit_bouquet` | |
| `packages`, `addons` | `mng_packages` | |
| `package` | `edit_package` | When `id` is present |
| `package` | `add_packages` | When no `id` |

### Groups

| Page | Permission | Notes |
| --- | --- | --- |
| `groups` | `mng_groups` | |
| `group` | `edit_group` | When `id` is present |
| `group` | `add_group` | When no `id`; falls through to `mng_groups` on denial |

### EPG

| Page | Permission | Notes |
| --- | --- | --- |
| `epgs` | `epg` | |
| `epg` | `epg_edit` | When `id` is present |
| `epg` | `add_epg` | When no `id`; falls through to `epg` on denial |

### Servers

| Page | Permission | Notes |
| --- | --- | --- |
| `servers`, `server_view`, `server_order`, `proxies` | `servers` | |
| `server`, `proxy` | `edit_server` | When `id` is present |
| `server`, `proxy` | `add_server` | When no `id` |
| `server_install` | `add_server` | |

### Security and Blocking

| Page | Permission | Notes |
| --- | --- | --- |
| `isps`, `isp`, `asns` | `block_isps` | |
| `ip`, `ips` | `block_ips` | |
| `useragents`, `useragent` | `block_uas` | |
| `fingerprint` | `fingerprint` | |

### Tickets

| Page | Permission | Notes |
| --- | --- | --- |
| `ticket` | `ticket` | |
| `ticket_view`, `tickets` | `manage_tickets` | |

### Tools and Settings

| Page | Permission | Notes |
| --- | --- | --- |
| `settings` | `settings` | |
| `backups`, `cache`, `setup` | `database` | |
| `settings_watch`, `settings_plex` | `folder_watch_settings` | |
| `plex`, `watch` | `folder_watch` | |
| `plex_add`, `watch_add` | `folder_watch_add` | |
| `watch_output` | `folder_watch_output` | |
| `mass_delete` | `mass_delete` | |
| `quick_tools` | `quick_tools` | |
| `stream_tools` | `stream_tools` | |
| `process_monitor` | `process_monitor` | |
| `queue` | `streams` OR `episodes` OR `series` | Access if user has any one of these |

### Profiles and Codes

| Page | Permission | Notes |
| --- | --- | --- |
| `profiles` | `tprofiles` | |
| `profile` | `tprofile` | |
| `player` | `player` | |
| `code`, `codes` | `add_code` | |
| `hmac`, `hmacs` | `add_hmac` | |

### RTMP

| Page | Permission | Notes |
| --- | --- | --- |
| `rtmp_ip` | `add_rtmp` | |
| `rtmp_ips`, `rtmp_monitor` | `rtmp` | |

### Logs

| Page | Permission | Notes |
| --- | --- | --- |
| `client_logs` | `client_request_log` | |
| `credit_logs` | `credits_log` | |
| `mysql_syslog`, `panel_logs` | `panel_logs` | |
| `login_logs` | `login_logs` | |
| `restream_logs` | `restream_logs` | |

---

## Reseller Page Permission Mappings

The `checkResellerPermissions()` method maps reseller panel pages to boolean flags in `$rPermissions`. Unlike admin permissions which use the `advanced` array via `Authorization::check('adv', ...)`, reseller permissions are simple boolean fields checked directly.

| Pages | Required permission |
| --- | --- |
| `user`, `users` | `create_sub_resellers` |
| `line`, `lines` | `create_line` |
| `mag`, `mags` | `create_mag` |
| `enigma`, `enigmas` | `create_enigma` |
| `epg_view`, `streams`, `created_channels`, `movies`, `episodes`, `radios` | `can_view_vod` |
| `live_connections`, `line_activity` | `reseller_client_connection_logs` |

Any reseller page not listed above returns `true` (accessible by default).

---

## Adding a New Permission

1. Add the key to `src/config/permissions.php`:

```php
$rPermissionKeys = array(
    // ...existing keys...
    'my_new_permission',
);
```

2. Use it in code via `Authorization::check()`:

```php
if (!Authorization::check('adv', 'my_new_permission')) {
    // deny access
}
```

3. If the permission should gate a page, add a case to `PageAuthorization::checkPermissions()`:

```php
case 'my_new_page':
    return Authorization::check('adv', 'my_new_permission');
```

4. For create/edit entity pages, use the conditional pattern:

```php
case 'my_entity':
    if (isset(RequestManager::getAll()['id']) && Authorization::check('adv', 'edit_my_entity')) {
        return true;
    }
    if (isset(RequestManager::getAll()['id']) || !Authorization::check('adv', 'add_my_entity')) {
        break;
    }
    return true;
```

5. For reseller permissions, add a boolean field to `$rPermissions` and a case to `checkResellerPermissions()`.

---

## Related files

| File | Purpose |
| --- | --- |
| `src/config/permissions.php` | Permission key registry (`$rPermissionKeys` array) |
| `src/Core/Auth/Authorization.php` | Object-level and advanced permission checks |
| `src/Core/Auth/PageAuthorization.php` | Page-level gating for admin and reseller panels |
| `src/Core/Auth/SessionManager.php` | Session context; populates `$rPermissions` and `$rUserInfo` |
| `src/Core/Auth/Authenticator.php` | Authentication (login, credential verification) |
