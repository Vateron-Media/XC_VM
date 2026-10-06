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

A session needs an enabled account (`status = 1`), as the login does: disabling an admin or reseller ends the session the account has open on its next request, without waiting for the idle timeout.

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

Permission keys are declared in `XcVm\Core\Reference\PermissionReference` (`src/Core/Reference/PermissionReference.php`): `PermissionReference::keys()` returns the full list, and `PermissionReference::advanced()` pairs each key with its localized title/description for the group editor. Each key is a string identifier used with `Authorization::check('adv', $key)`.

Categories:

| Category | Examples |
| --- | --- |
| Create/Add | `add_stream`, `add_movie`, `add_user`, `add_server`, `add_bouquet`, `add_epg`, `add_code`, `add_hmac`, `add_rtmp` |
| Edit | `edit_stream`, `edit_movie`, `edit_user`, `edit_server`, `edit_bouquet`, `edit_series`, `edit_reguser` |
| Mass operations | `mass_edit_streams`, `mass_edit_lines`, `mass_edit_mags`, `mass_edit_enigmas`, `mass_edit_radio`, `mass_edit_users`, `mass_sedits`, `mass_sedits_vod`, `mass_delete` |
| Import | `import_streams`, `import_movies`, `import_episodes` |
| Security/Blocking | `block_ips`, `block_isps`, `block_uas`, `block_asns`, `fingerprint` |
| Section visibility | `streams`, `movies`, `series`, `episodes`, `radio`, `users`, `servers`, `bouquets`, `epg`, `settings`, `database` |
| Logs | `connection_logs`, `live_connections`, `client_request_log`, `credits_log`, `login_logs`, `admin_audit`, `panel_logs`, `reg_userlog`, `restream_logs` |
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

#### Full administrator

A *full administrator* is a member of group 1, or of an administrator group whose permission list is empty: both pass every `adv` check. A group that stores no list at all (`allowed_pages` is NULL or empty text) is read as one with an empty list, in the panel, in the tables and for an Admin API key alike. Administrator accounts and administrator groups are managed by a full administrator only. Anyone else cannot:

- edit, mass-edit, delete, disable or enable an administrator's account, or adjust its credits, in the admin panel, the reseller panel, the Admin API or the Reseller API;
- assign an administrator group to a user;
- create, edit or delete an administrator group, or turn a group into one (the *Is Admin* switch of the group form and the group flag action).

A reseller never gives a user an administrator group and cannot edit, delete, disable or enable an administrator's account in its tree.

`GroupService::reservedGroups()` is the single rule: it returns the group ids reserved from the acting user, and an empty list for a full administrator. What a request on a reserved account or group answers depends on where it is made:

- The Admin API and the admin panel's user and group forms answer `STATUS_INVALID_GROUP` when a user or a group is created or edited: an administrator's account, an administrator group, or a user given one. The mass edit answers the same when it assigns an administrator group.
- The Admin API answers `STATUS_FAILURE` for `delete_user`, `disable_user`, `enable_user`, `adjust_credits` and `delete_group`.
- The Reseller API answers `STATUS_FAILURE` for `delete_user`, `disable_user`, `enable_user` and `adjust_credits`, and `STATUS_NO_PERMISSIONS` for `edit_user`. A key whose group lacks the flag the action asks for is answered `STATUS_NO_PERMISSIONS` before that; see [Reseller Page Permission Mappings](#reseller-page-permission-mappings).
- A row action on a user (delete, disable, enable, adjust credits) in either panel, and the group flag action, answer `result: false`.
- Bulk enable, disable and delete, the mass edit of other fields and the group delete action leave reserved accounts and groups as they are and answer success.

The user lists offer no row action on a reserved account, and the group list none on a reserved group.

#### Admin API keys

An Admin API key acts with the permissions its holder's group lists, as the holder does in the panel. A key of group 1, or of an administrator group with an empty permission list, keeps everything. For a key of a restricted group every action asks for a permission of the group, the one the panel asks for the same operation (`AdminApiController::ACTION_PERMISSIONS`): reads, tables and logs, delete, enable/disable and start/stop as well as create, edit and install. Where an action lists several permissions, any one is enough; `user_info` asks for none. A refused action answers `{"status":"STATUS_NO_PERMISSIONS"}`. The list covers the core actions: an action or a table a module registers is not in it and is checked by the module's own handler.

The permission each action asks for (`OR`: any one of the keys is enough):

| Area | Actions | Permission |
| --- | --- | --- |
| Own account | `user_info` | None: every key |
| Database | `mysql_query`, `reload_cache` | `database` |
| Settings | `get_settings`, `edit_settings` | `settings` |
| Lines | `get_lines` | `users` OR `mass_edit_lines` |
|  | `get_line`, `edit_line`, `delete_line`, `disable_line`, `enable_line`, `ban_line`, `unban_line` | `edit_user` |
|  | `create_line` | `add_user` |
| Activation codes | `get_active_codes` | `users` OR `mass_edit_lines` |
|  | `get_active_code`, `get_active_codes_batches`, `export_active_code_batch`, `check_active_code` | `users` |
|  | `generate_active_codes`, `create_active_code` | `add_user` |
|  | `edit_active_code` | `edit_user` |
|  | `delete_active_code`, `disable_active_code`, `enable_active_code`, `reset_active_code_device`, `mass_active_codes` | `edit_user` OR `mass_edit_lines` |
| Users | `get_users` | `mng_regusers` OR `mass_edit_users` |
|  | `get_user`, `edit_user`, `delete_user`, `disable_user`, `enable_user`, `adjust_credits` | `edit_reguser` |
|  | `create_user` | `add_reguser` |
| MAG devices | `get_mags` | `manage_mag` OR `mass_edit_mags` |
|  | `get_mag`, `edit_mag`, `delete_mag`, `disable_mag`, `enable_mag`, `ban_mag`, `unban_mag`, `convert_mag` | `edit_mag` |
|  | `create_mag` | `add_mag` |
| Enigma devices | `get_enigmas` | `manage_e2` OR `mass_edit_enigmas` |
|  | `get_enigma`, `edit_enigma`, `delete_enigma`, `disable_enigma`, `enable_enigma`, `ban_enigma`, `unban_enigma`, `convert_enigma` | `edit_e2` |
|  | `create_enigma` | `add_e2` |
| Groups | `get_groups` | `mng_groups` |
|  | `get_group` | `mng_groups` OR `edit_group` |
|  | `create_group` | `add_group` |
|  | `edit_group`, `delete_group` | `edit_group` |
| Packages | `get_packages` | `mng_packages` |
|  | `get_package` | `mng_packages` OR `edit_package` |
|  | `create_package` | `add_packages` |
|  | `edit_package`, `delete_package` | `edit_package` |
| Bouquets | `get_bouquets` | `bouquets` |
|  | `get_bouquet` | `bouquets` OR `edit_bouquet` |
|  | `create_bouquet` | `add_bouquet` |
|  | `edit_bouquet`, `delete_bouquet` | `edit_bouquet` |
| Categories | `get_categories`, `get_category` | `categories` |
|  | `create_category`, `edit_category` | `add_cat` |
|  | `delete_category` | `edit_cat` |
| Streams | `get_streams` | `streams` OR `mass_edit_streams` |
|  | `get_stream`, `edit_stream`, `delete_stream`, `start_stream`, `stop_stream` | `edit_stream` |
|  | `create_stream` | `add_stream` |
| Created channels | `get_channels` | `streams` OR `mass_edit_streams` |
|  | `get_channel`, `edit_channel` | `edit_cchannel` |
|  | `create_channel` | `create_channel` |
|  | `delete_channel`, `start_channel`, `stop_channel` | `edit_cchannel` OR `edit_stream` |
| Stations | `get_stations` | `radio` OR `mass_edit_radio` |
|  | `get_station`, `edit_station` | `edit_radio` |
|  | `create_station` | `add_radio` |
|  | `delete_station`, `start_station`, `stop_station` | `edit_radio` OR `edit_stream` |
| Movies | `get_movies` | `movies` OR `mass_sedits_vod` |
|  | `get_movie`, `edit_movie`, `delete_movie`, `start_movie`, `stop_movie` | `edit_movie` |
|  | `create_movie` | `add_movie` |
| Series | `get_series_list` | `series` OR `mass_sedits` |
|  | `get_series`, `edit_series`, `delete_series` | `edit_series` |
|  | `create_series` | `add_series` |
| Episodes | `get_episodes` | `episodes` OR `mass_sedits` |
|  | `get_episode`, `edit_episode`, `delete_episode`, `start_episode`, `stop_episode` | `edit_episode` |
|  | `create_episode` | `add_episode` |
| Providers | `get_providers`, `get_provider`, `create_provider`, `edit_provider`, `delete_provider`, `reload_provider` | `streams` |
|  | `get_provider_streams` | `streams` OR `add_stream` OR `edit_stream` OR `add_movie` OR `edit_movie` |
| EPG | `get_epgs` | `epg` |
|  | `get_epg`, `reload_epg` | `epg` OR `epg_edit` |
|  | `create_epg` | `add_epg` |
|  | `edit_epg`, `delete_epg` | `epg_edit` |
| Transcode profiles | `get_transcode_profiles`, `delete_transcode_profile` | `tprofiles` |
|  | `get_transcode_profile` | `tprofiles` OR `tprofile` |
|  | `create_transcode_profile`, `edit_transcode_profile` | `tprofile` |
| RTMP IPs | `get_rtmp_ips` | `rtmp` |
|  | `get_rtmp_ip` | `rtmp` OR `add_rtmp` |
|  | `create_rtmp_ip`, `edit_rtmp_ip`, `delete_rtmp_ip` | `add_rtmp` |
| Access codes | `get_access_codes`, `get_access_code`, `create_access_code`, `edit_access_code`, `delete_access_code` | `add_code` |
| HMAC keys | `get_hmacs`, `get_hmac`, `create_hmac`, `edit_hmac`, `delete_hmac` | `add_hmac` |
| Blocklists | `get_blocked_isps`, `add_blocked_isp`, `delete_blocked_isp` | `block_isps` |
|  | `get_blocked_uas`, `add_blocked_ua`, `delete_blocked_ua` | `block_uas` |
|  | `get_blocked_ips`, `add_blocked_ip`, `delete_blocked_ip`, `flush_blocked_ips` | `block_ips` |
| Servers | `get_servers` | `servers` |
|  | `get_server`, `get_certificate_info` | `servers` OR `edit_server` |
|  | `install_server`, `install_proxy` | `add_server` |
|  | `edit_server`, `edit_proxy`, `delete_server`, `reload_nginx` | `edit_server` |
|  | `get_server_stats` | `index` OR `add_server` OR `edit_server` |
|  | `get_fpm_status` | `add_server` OR `edit_server` |
|  | `get_free_space` | `process_monitor` OR `edit_server` |
|  | `get_pids`, `kill_pid`, `clear_temp`, `clear_streams` | `process_monitor` |
|  | `get_rtmp_stats` | `rtmp` |
|  | `get_directory` | `add_episode` OR `edit_episode` OR `add_movie` OR `edit_movie` OR `create_channel` OR `edit_cchannel` |
| Connections and logs | `live_connections` | `live_connections` |
|  | `activity_logs`, `kill_connection` | `connection_logs` |
|  | `credit_logs` | `credits_log` |
|  | `client_logs` | `client_request_log` |
|  | `user_logs` | `reg_userlog` |
|  | `stream_errors` | `stream_errors` |
|  | `system_logs` | `panel_logs` |
|  | `login_logs` | `login_logs` |
|  | `restream_logs` | `restream_logs` |
|  | `mag_events` | `manage_events` |

Action names and permission keys share words that do not mean the same thing. The action `edit_user` edits a panel user and asks for `edit_reguser`; the permission `edit_user` is the one for lines (`edit_line`). Likewise `get_users` asks for `mng_regusers`, and `get_lines` for `users`.

The active-code API (`/api/active_code`, `/active_code.php`) follows the same rule when it is called with an Admin API key: each of its actions asks for the permission of the Admin API action it stands for, and answers `STATUS_NO_PERMISSIONS` without it. It takes the Admin API names in the right column and these short ones:

| Short name | Admin API action |
| --- | --- |
| `list`, `get_codes` | `get_active_codes` |
| `get`, `details` | `get_active_code` |
| `generate`, `create` | `generate_active_codes` |
| `edit`, `update` | `edit_active_code` |
| `delete` | `delete_active_code` |
| `enable` | `enable_active_code` |
| `disable` | `disable_active_code` |
| `reset_device` | `reset_active_code_device` |
| `mass` | `mass_active_codes` |
| `batches` | `get_active_codes_batches` |
| `export` | `export_active_code_batch` |

A request that sends an activation code and no key (a device activating its code, or `check`) asks for no permission.

#### API tokens

Each admin and reseller can make named tokens on their profile page (**Edit Profile → API Tokens**), up to 20, in place of the account's single API key. A token is sent where a key is, as `api_key`, to the Admin API, the Reseller REST API, the activation-code API and the table endpoints. It acts with its account's group permissions, as a key does, narrowed by its scope (`Core\Auth\ApiTokens::allows()`):

| Scope | Runs |
| --- | --- |
| Full | Every action the group allows, except `mysql_query`. An admin can make a full token that also runs `mysql_query`. |
| Read only | Every `get_*` action, the logs (`activity_logs`, `live_connections`, `credit_logs`, `client_logs`, `user_logs`, `stream_errors`, `system_logs`, `login_logs`, `restream_logs`, `mag_events`), `user_info`, `packages`, `check_active_code` and `export_active_code_batch`. |
| Lines, devices and activation codes | The actions on lines, MAG and Enigma2 devices and activation codes, with `user_info`, `packages`, `get_packages`, `get_package`, `get_bouquets` and `get_bouquet`. |

An action outside the scope answers `{"status":"STATUS_NO_PERMISSIONS"}`; a table outside it answers as an invalid key. A token can also be limited to a list of IP addresses and given an expiry; the profile shows each token's first characters, scope, addresses, expiry and last use (to the minute, with the address). The token itself (`xct_` and 40 hexadecimal digits) is shown once, when it is made: the panel keeps a SHA-256 hash of it (`api_tokens`, migration `076_add_api_tokens.sql`). Revoking one stops it at once. Deleting an account deletes its tokens.

The account's single key keeps working while **Settings → API → Accept Legacy API Keys** (`api_legacy_keys`) is on, the default. Turned off, no API takes a legacy key; tokens are unaffected.

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

If `$page` is omitted, the page name is taken from the request (`AdminHelpers::getPageName()`: the `PAGE_NAME` constant, else the entry script's basename, lowercased). A page's rule is looked up under its underscore name whatever the URL spelling: `line/mass`, `line_mass` and `line_mass.php` all resolve to the rule for `line_mass`.

#### Default-allow behavior

Both methods return `true` for any page not explicitly listed in their switch statements. This means pages without a mapping are accessible to all authorized users of the appropriate type (admin or reseller). Only pages with explicit entries are restricted. A new admin or reseller page that needs a permission must get a case in `PageAuthorization`.

---

## Admin Page Permission Mappings

The `checkPermissions()` method maps admin panel pages to `adv` permission keys. The complete mapping is listed below, grouped by category.

### Create-vs-edit pattern

Many entity pages use conditional logic based on request parameters:

- If an `id` parameter is present, the **edit** permission is checked
- If no `id` parameter is present, the **add** permission is checked
- Some pages (stream, movie) also check for an `import` parameter and require the corresponding import permission

When neither condition is met, behavior depends on the page: some fall through to a related listing permission, others fall through to the switch default (which returns `true`).

### Tables behind the mass-edit pages

A mass-edit page shows the list it edits, so that table is read with the list page's key or the mass-edit page's own: lines with `users` OR `mass_edit_lines`, users with `mng_regusers` OR `mass_edit_users`, MAG devices with `manage_mag` OR `mass_edit_mags`, Enigma devices with `manage_e2` OR `mass_edit_enigmas`. A mass-edit key reads its own list only: `mass_edit_users` does not read the lines table. The provider streams table is read with `streams`, `add_stream`, `edit_stream`, `add_movie` or `edit_movie`.

### Streams and Content

| Page | Permission | Notes |
| --- | --- | --- |
| `streams`, `stream_view`, `provider`, `providers`, `epg_view`, `created_channels`, `stream_rank`, `archive` | `streams` | On `created_channels`, the list inside the page is shown with `manage_cchannels` or `edit_cchannel` |
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
| `episode` | `add_episode` | When no `id` |
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
| `active_codes`, `active_codes_batch` | `users` | Manage Active Codes and the Batch Manager. The controls that change codes (enable, disable, extend, reset device, delete) are shown only with `edit_user` or `mass_edit_lines` |
| `active_code` | `add_user` | Generate Codes |
| `active_codes_mass` | `mass_edit_lines` | Mass Edit Active Codes; the same key also reads the list of codes |
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
| `modules` | `settings` | Checked by the page itself; covers the module list and every module operation, ZIP upload and store install included |
| `backups`, `cache`, `setup` | `database` | The *Backup Settings* and *Cache Settings* topbar links follow the same key |
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
| `admin_actions` | `admin_audit` | The admin action trail; see below |
| `restream_logs` | `restream_logs` | |

#### Admin action trail

**Logs → System → Admin Actions** lists what admins changed: every admin form save (`post.php`), every admin panel Ajax action except those that only read (`AdminAudit::PANEL_READS`: stats, searches, lookups), and every Admin API and activation-code API action except reads (`ApiTokens::isRead()`), refused ones included. Each entry (`admin_audit`, migration `077_add_admin_audit.sql`) has the date, the account, its address, the source (`panel` or `api`), the action and its outcome: **OK** or **Failed** when the answer is the panel's JSON (`result`, or a `STATUS_*` status), blank otherwise (an export, a download). Its detail keeps only the fields that name what was acted on (every id: `id`, `ids`, `edit`, `pid` and any field ending in `_id`; the names in `AdminAudit::DETAIL_KEYS`; the sub-action), at most 20, never a password, key, code or other value sent with the request; a settings save adds the names of the settings it changed, not their values.

The page searches the account, action, address and detail, and exports as CSV or JSON. The trail is kept by backups, and nothing in the panel clears it.

### Actions

The actions below check their own key, whatever page the button is on:

| Action | Permission | Notes |
| --- | --- | --- |
| Generate activation codes | `add_user` | |
| Enable, disable, extend or delete activation codes and whole batches | `edit_user` OR `mass_edit_lines` | Access with either key |
| Open a code's details, export a batch | `users` | |
| Bulk actions on the Lines page | `edit_user` | |
| Bulk series delete | `edit_series` | |
| Cache and Redis buttons (regenerate cache, enable or disable the cache, enable or disable the Redis handler, clear Redis) | `database` | |
| Report export (*Export as CSV* / *Export as JSON*) | `database` | The buttons are shown with the same key |
| EPG grid and programme popup on the EPG view, provider stream list, importing a provider's EPG | `streams` | |
| Clear Logs | The key of the log page the button is on | `client_request_log`, `connection_logs`, `stream_errors`, `credits_log`, `reg_userlog`, `panel_logs` |
| Download the panel log | `panel_logs` | Empties the table after collecting it |

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

The row actions of the reseller panel and the Reseller API ask for the same flags. Without the flag, the Reseller API answers `STATUS_NO_PERMISSIONS`:

| Reseller API actions | Required permission |
| --- | --- |
| `delete_line`, `disable_line`, `enable_line` | `create_line` |
| `delete_mag`, `disable_mag`, `enable_mag`, `convert_mag` | `create_mag` |
| `delete_enigma`, `disable_enigma`, `enable_enigma`, `convert_enigma` | `create_enigma` |
| `disable_user`, `enable_user`, `adjust_credits` | `create_sub_resellers` |
| `delete_user` | `create_sub_resellers` and `delete_users` |

---

## Adding a New Permission

1. Add the key to the `KEYS` constant in `src/Core/Reference/PermissionReference.php`:

```php
private const KEYS = array(
    // ...existing keys...
    'my_new_permission',
);
```

Then add its label keys to the language files so the group editor can show a
title/description: `permission_my_new_permission` and
`permission_my_new_permission_text` in `src/Core/Localization/lang/en.ini`.

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
| `src/Core/Reference/PermissionReference.php` | Permission key registry (`keys()`) + localized rows for the group editor (`advanced()`) |
| `src/Core/Auth/Authorization.php` | Object-level and advanced permission checks |
| `src/Core/Auth/PageAuthorization.php` | Page-level gating for admin and reseller panels |
| `src/Core/Auth/SessionManager.php` | Session context; populates `$rPermissions` and `$rUserInfo` |
| `src/Core/Auth/Authenticator.php` | Authentication (login, credential verification) |
