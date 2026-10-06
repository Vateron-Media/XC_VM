# Reseller System

The reseller system enables multi-level affiliate management with credit-based line provisioning.
Resellers create and manage IPTV lines, MAG devices, and Enigma2 devices within their allocated credits and permissions.

---

## Overview

```text
Admin
  └── assigns credits + group permissions
        └── Reseller
              ├── creates IPTV lines (costs credits)
              ├── creates MAG devices (costs credits)
              ├── creates Enigma2 devices (costs credits)
              ├── generates activation codes (costs credits)
              └── creates sub-resellers (costs credits)
                    └── sub-reseller has own lines + credits
```

Core business logic is in `src/Domain/User/ResellerAPI.php`. Web controllers are under `src/Public/Controllers/Reseller/`. REST API is in `src/Public/Controllers/Api/ResellerRestApiController.php`.

---

## Credit System

Credits are the currency for all reseller operations. Each action has a cost, and the reseller's balance must cover it.

The price is taken from the balance as it is stored at that moment, before the line, device, sub-reseller or code batch is created. Two requests sent together cannot spend the same credits: the one the balance no longer covers is answered `STATUS_INSUFFICIENT_CREDITS` (a code batch is refused with an *Insufficient balance* message) and creates nothing. When the record cannot be stored after all, the price is returned to the balance.

Prices of lines, MAG devices, Enigma2 devices, sub-resellers and activation codes are charged as they are stored, with their fraction, at the four decimals a balance is kept at (a price of 0.5 costs 0.5, 10.9 costs 10.9). The refusal for an insufficient balance, the cost and balance in the reseller log, and the price the REST API lists all go by that charge. Credit transfers move whole credits (see [Credit transfer](#credit-transfer)). Balances, prices, the credits log and activation codes' purchase cost are stored as `DECIMAL(16,4)` (migration `070`; they were FLOAT, exact for whole credits only up to 16,777,216); a whole amount is shown and answered as the integer it always was.

### Credit costs

| Action | Cost source |
| --- | --- |
| Create line (official) | `package.official_credits` |
| Create line (trial) | `package.trial_credits` |
| Create MAG device | same as line |
| Create Enigma2 device | same as line |
| Extend line or device | same as creation, for the package chosen |
| Generate activation codes | `package.official_credits` per code (`package.trial_credits` for a trial package) |
| Create sub-reseller | `permissions.create_sub_resellers_price` |

One price buys one period. When two requests extend the same line or device at the same moment, one is stored and charged; the other is answered `STATUS_FAILURE`, is not charged and can be sent again.

### Override pricing

Resellers can have custom per-package pricing via `override_packages` JSON on their user record:

```php
$rOverride = json_decode($rUserInfo['override_packages'], true);
if (isset($rOverride[$rPackage['id']]['official_credits'])) {
    $rCost = ResellerAPI::amount($rOverride[$rPackage['id']]['official_credits']);
}
```

### Credit transfer

A reseller moves whole credits to or from a sub-reseller in its tree with the `adjust_credits` action; a negative amount takes credits back. The side that gives must hold the amount at that moment, otherwise nothing moves and the action fails (`STATUS_FAILURE`). The reseller's own account cannot be the target, and an administrator's account in the reseller's tree is excluded in both directions.

Deleting a sub-reseller from the reseller panel returns all the credits it holds at that moment to the reseller, fractions included; the `delete` row of the reseller log shows whole credits. The REST `delete_user` action does the same. Either way the deleted sub-reseller's lines and sub-resellers become the reseller's. If the credits cannot be moved at that moment, nothing is deleted and the action fails; repeat it.

A reseller's edit of a sub-reseller never changes its balance. An administrator changes a balance on the user form: the **Credits** field moves the balance by the difference from the value shown when the form was opened, and that difference is what the credits log records. Saving without touching the field leaves the balance alone. Submitting the same opened form a second time (for example after a lost answer) applies the same change again: reload the form before retrying.

### Logging

All credit operations are recorded in `users_logs`:

| Field | Description |
| --- | --- |
| `owner` | reseller user ID |
| `type` | `line`, `mag`, `enigma`, `user`, `active_code` |
| `action` | `new`, `extend`, `edit`, `enable`, `disable`, `delete`, `convert`, `send_event`, `adjust_credits`, `generate` |
| `cost` | credits spent, with its fraction |
| `credits_after` | balance after operation, with its fraction |
| `package_id` | package used |
| `date` | timestamp |

`cost` and `credits_after` keep fractions since migration `069_exact_reseller_log_amounts`; rows written before it keep the whole values they were stored with.

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
6. Take the price from the stored balance (`UserCredits::debit()`); a balance that no longer covers it stops the request here.
7. Store the line in the `lines` table. A new line is inserted via `REPLACE INTO`. Every save of an existing line, a plain edit as well as an extension, is written only while the line still has the expiry the request read: when the expiry has moved meanwhile, nothing is stored and the request is answered `STATUS_FAILURE`. For a MAG or Enigma2 device the line and the device row are stored in one transaction; steps 8 and 9 follow it.
8. Sync device entries (`mag_devices` or `enigma2_devices`).
9. Broadcast signal event to streaming servers.
10. Log the transaction. When the line or the device row could not be stored, the price is returned instead and the line is left as it was.

A line's username and password cannot contain `/`: the request is answered `STATUS_INVALID_USERNAME` or `STATUS_INVALID_PASSWORD`. A line that already has such a value keeps it and stays editable until that value is changed. To find such lines:

```sql
SELECT id, username FROM `lines` WHERE username LIKE '%/%' OR password LIKE '%/%';
```

### Trials

- A trial is made only when a line or device is created, from a package flagged as trial; `trial` on an edit is ignored.
- A package with *Trial Package* on and *Standard Package* off gives trials only. Creating a line or device from it without `trial` is answered `STATUS_INVALID_PACKAGE`, and so is an edit that names it: the edit is refused, not taken as a purchase. A trial becomes a subscription by buying a package with *Standard Package* on.
- A package sells a reseller what its switches say. *Standard Package* sells subscriptions (a line, a MAG or Enigma2 device) and official activation codes; *Trial Package* gives trials. A package with neither switch on sells a reseller nothing: `create_line`/`edit_line`, `create_mag`/`edit_mag` and `create_enigma`/`edit_enigma` answer `STATUS_INVALID_PACKAGE` for it, and it is not listed to the reseller. Administrators are not bound by the switches.
- *Allowed Trials* N in *Day* / *Month* (group form) means N trials in one rolling day, or in one calendar month back from today (28 to 31 days depending on the date).
- The allowance counts the trial lines, MAG and Enigma2 devices and trial activation codes the reseller itself holds. Each sub-reseller has its own allowance.
- The allowance holds for requests sent together as well: one trial request of a reseller is counted and stored at a time (lines, devices and trial activation codes).
- A trial is created under the reseller that makes it (`member_id` in the request is ignored) and keeps its owner while it is a trial.
- Trials, trial activation codes included, are refused while *Disable Trials* is on, while the reseller's balance is below the group's *Minimum Credits for Trials*, and once the allowance is used up (`STATUS_NO_TRIALS`; a code batch is refused with a message).

### Bouquet assignment

Each package specifies available bouquets via `bouquets` JSON array.
If `allow_change_bouquets` permission is enabled, the reseller can select a subset of the package bouquets. Otherwise all package bouquets are auto-assigned. A request that sends no selection, or a selection that names no bouquet of the package, gets all the package's bouquets.

The same permission (*Allow Bouquet Editing* on the group form) governs the Generate Active Codes form. Without it, codes carry the package's bouquets and the picker is hidden. With it, the reseller narrows the selection within the package.

### Pairing

A line or device can be paired with a line the reseller manages (`pair_id`); it then follows that line's term and bouquets. Pairing applies to bought subscriptions:

- A trial is never paired with another line, and no line is paired with a trial.
- A line or device is not paired with the line of an activation code that has not been redeemed.

In these cases the request is saved unpaired, without an error.

### Activation codes

A reseller generates activation codes from a line package offered to its group that has *Standard Package* or *Trial Package* on. Each code has a companion line.

- *Standard Package* on gives official codes at the official price; *Trial Package* alone gives trial codes at the trial price. With both on, the codes are official unless the request asks for trial codes with `is_trial` (on the form the package is listed twice, the second entry tagged *[Trial]* at the trial price). Trial codes come out of the trial allowance; the trial entry is greyed out without one. Asking for trial codes from a package without *Trial Package* is refused (*Invalid package selected.*).
- An administrator issues codes from any package; an administrator's codes on a package with *Trial Package* on are trial codes. Through the admin API, `is_trial` is read as a switch as for resellers (`false`, `off`, `no` ask for official codes).

- The companion line is created switched off and starts (expiry set, switched on) when the code is redeemed. The credentials and M3U links shown for a code still in stock work only after redemption.
- Enabling or editing a code in stock does not switch its line on. A redeemed code whose line an administrator set to no expiry is switched on by enabling the code.
- Enable changes the status of suspended codes only. A code in stock or active keeps its status, so a redeemed code an administrator returned to stock stays in stock when it is part of an enabled selection.
- Resellers cannot extend codes or change their package; the reseller list has no Extend action. A reseller's edit keeps the code's package, connections and expiry. Setting a redeemed code back to stock stores it as active, unless an administrator had already returned it to stock.
- A reseller's codes take the package's connections and forced country; the reseller form has no country field.
- A custom streaming password cannot contain `/`, and a code cannot be renamed to a value containing `/`.
- A renamed code gives its line the new name as username when the username was the old code or a generated `ac_…` one. The rename is refused (*already taken*) when another line has that username.
- A code a reseller bought refunds its price once, when that reseller deletes it with the refund option while it is in stock. A disabled code refunds nothing, even one that was never redeemed: enable it first, which returns it to stock. A code deleted by another reseller of the tree, or by an administrator, refunds nothing. A code an administrator generated for a reseller has purchase cost 0 and refunds nothing.
- The update applies these rules once to the codes generated before them (database migration `066_hold_unredeemed_activation_codes`): the line of a code nobody redeemed is switched off, with a line or device paired with it that took its state (no expiry). A code nobody redeemed, or one back in stock, keeps its purchase cost only while the reseller log (`users_logs`) holds the purchase of its batch by that reseller under the batch's name, so a code whose record was cleared or whose batch was renamed since refunds nothing. The lines of redeemed codes and the cost of active codes are not changed. A version rollback switches the waiting lines back on, except those of suspended codes (a line or device paired with one follows when that line is next saved), and restores no purchase cost; the next update applies the step again.

---

## Device Management

### MAG devices

Managed by `MagService` (`src/Domain/Device/MagService.php`).
Lock fields: `ver`, `device_id2`, `device_id`, `hw_version`, `image_version`, `stb_type`, `sn`.

### Enigma2 devices

Managed by `EnigmaService` (`src/Domain/Device/EnigmaService.php`).
Lock fields: `token`, `lversion`, `cpu`, `enigma_version`, `modem_mac`, `local_ip`.

Both device types support `lock_device` (hardware binding), `is_isplock` (ISP binding), and `forced_country`.

---

## Sub-Reseller Hierarchy

Resellers can create sub-resellers (if `create_sub_resellers` permission is granted):

- Sub-resellers are linked via `owner_id` field.
- Multi-level: a sub-reseller can create their own sub-resellers.
- Each creation costs `create_sub_resellers_price` credits.
- Assigned `member_group_id` must be in the parent's `subresellers` permission array.
- A sub-reseller cannot be named, or renamed, with a username another panel account has (`STATUS_EXISTS_USERNAME`); an empty name on an edit keeps the current one. Names are compared without case and trailing spaces, and the database holds them unique (migration `068_unique_panel_account_names`), so two requests that create the same name at once store one account and refuse the other.
- A reseller never gives a user an administrator group, and cannot edit, delete, disable or enable an administrator's account in its tree or move its credits. See [Full administrator](../guides/permissions-and-rbac.md#full-administrator).
- A reseller cannot delete, disable or enable its own account: the panel's row action answers `result: false`, and the REST API `STATUS_FAILURE`.

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
| `allow_change_bouquets` | `bool` | can select bouquet subset (lines, devices and activation codes) |
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
- Edit, delete, disable or enable an administrator's account, move its credits, or give a user an administrator group.
- Extend activation codes or change their package.

---

## REST API

File: `src/Public/Controllers/Api/ResellerRestApiController.php`

Authentication via the reseller's API key, or one of its [API tokens](../guides/permissions-and-rbac.md#api-tokens) (`api_key`): a token can be limited to reading, or to lines, devices and activation codes. Actions:

| Action | Description |
| --- | --- |
| `user_info` | reseller account info |
| `packages` | available packages |
| `get_lines` / `get_mags` / `get_enigmas` | list resources |
| `create_line` / `edit_line` / `delete_line` | line CRUD |
| `enable_line` / `disable_line` | toggle line status |
| `create_mag` / `edit_mag` / `delete_mag` | MAG CRUD |
| `create_enigma` / `edit_enigma` / `delete_enigma` | Enigma CRUD |
| `convert_mag` / `convert_enigma` | convert device type |
| `get_users` / `get_user` | list/view sub-resellers |
| `create_user` / `edit_user` / `delete_user` | sub-reseller CRUD |
| `enable_user` / `disable_user` | toggle sub-reseller status |
| `adjust_credits` | move credits to or from a sub-reseller |
| `activity_logs` / `live_connections` | connection data |
| `user_logs` | sub-reseller activity logs |
| `get_active_codes` / `get_active_code` | list/view activation codes |
| `generate_active_codes` (`create_active_code`) | generate a batch of activation codes |
| `edit_active_code` / `delete_active_code` | change or delete a code |
| `enable_active_code` / `disable_active_code` | toggle code status |
| `reset_active_code_device` | clear a code's device lock |
| `mass_active_codes` | one action on several codes |
| `get_active_codes_batches` / `export_active_code_batch` | batch summary and export |
| `check_active_code` | look a code up |

Answers to know:

- `create_line` / `edit_line` answer `STATUS_INVALID_USERNAME` or `STATUS_INVALID_PASSWORD` for a username or password that contains `/`.
- `packages` lists only packages with *Standard Package* or *Trial Package* on, at the price a purchase is charged (the reseller's own price where one is set).
- `create_line`, `create_mag` and `create_enigma` without `trial` answer `STATUS_INVALID_PACKAGE` for a package that does not have *Standard Package* on (trials only, or neither switch), and so do `edit_line`, `edit_mag` and `edit_enigma` when they name such a package (see [Trials](#trials)).
- `edit_line`, `edit_mag` and `edit_enigma` answer `STATUS_FAILURE`, with nothing stored, when the line's expiry changed after the request read it. Send the request again.
- `create_line`, `create_mag` and `create_enigma` with `trial` ignore `pair_id` and `member_id`. `edit_mag` and `edit_enigma` ignore `pair_id` while the device is a trial. A `pair_id` that names a trial line, or the line of an activation code that has not been redeemed, is ignored. No error is returned.
- `enable_line`, `enable_mag` and `enable_enigma` answer `STATUS_FAILURE` for a line that waits for an activation code: the code's own line, or a line or device paired with it that took its state. After the code is redeemed, save the line it is paired with once so the device follows.
- `delete_line`, `disable_line` and `enable_line`, and the delete, disable, enable and convert actions of a MAG or Enigma device, answer `STATUS_FAILURE` for a line or device outside the reseller's tree. They answer `STATUS_NO_PERMISSIONS` when the key's group has no package of that kind: `create_line` for the line actions, `create_mag` for the MAG actions, `create_enigma` for the Enigma actions.
- `disable_line`, `disable_mag` and `disable_enigma` close the line's live sessions (`cluster_kill_on_line_disable`, on by default). Enabling and disabling both update the line cache.
- `delete_user`, `disable_user`, `enable_user` and `adjust_credits` answer `STATUS_FAILURE` for an administrator's account; `edit_user` answers `STATUS_NO_PERMISSIONS`. `delete_user`, `disable_user` and `enable_user` answer `STATUS_FAILURE` for the reseller's own account as well.
- `delete_user`, `disable_user`, `enable_user` and `adjust_credits` answer `STATUS_NO_PERMISSIONS` when the key's group has no `create_sub_resellers`; `delete_user` also needs `delete_users`.
- `mass_active_codes` answers an error for `extend` and `change_package`. `edit_active_code` ignores `max_connections` and `exp_date`, and a `package_id` of the reseller's group; a `package_id` outside the group's packages answers `STATUS_FAILURE`. The code's package does not change either way.
- `edit_active_code` answers `STATUS_FAILURE` with an *already taken* error when the new code is another line's username and the code's line would take it. `enable_active_code` and `mass_active_codes` with `enable` change the status of suspended codes only: a code in stock or active keeps its status.
- `generate_active_codes` takes `is_trial`, read as a switch: `1`, `true`, `on` or `yes` ask for trial codes (the package needs *Trial Package* on and the reseller room in its trial allowance); `0`, `false`, `off`, an empty value or no field ask for the package's official codes. It answers `STATUS_FAILURE` with *Invalid package selected.* for a package with neither switch on.
- `generate_active_codes` applies `category_template_id` only for a template the reseller can access, and ignores `custom_data`.

The `ResellerAPIWrapper` class validates the API key, initializes a session via `ResellerAPI`, and returns filtered JSON responses.

---

## Session and Bootstrap

### Session

File: `src/Infrastructure/Bootstrap/reseller_session.php`

- 60-minute timeout with last activity tracking.
- IP change detection (if `ip_logout` setting enabled).
- The account must still be enabled: disabling a reseller ends the session it has open on its next request.
- Session keys: `reseller` (user ID), `rip`, `rcode`, `rverify`, `rlast_activity`.
- The cookie is the panels' `PHPSESSID`, `HttpOnly` and `SameSite=Strict`. The web players keep their sign-in in a cookie of their own (see [Session cookies](../guides/authentication-and-sessions.md#cookie-configuration)).

### Functions bootstrap

File: `src/Infrastructure/Bootstrap/reseller_functions.php`

- Loads database and utilities.
- Initializes `$rUserInfo` and `$rPermissions`.
- Validates session integrity (username/password hash verification).
- Sets timezone and language preferences.

---

## Routes

File: `src/Public/routes/reseller.php`

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

## Related files

| File | Purpose |
| --- | --- |
| `src/Domain/User/ResellerAPI.php` | core business logic |
| `src/Public/Controllers/Api/ResellerRestApiController.php` | REST API |
| `src/Public/Controllers/Reseller/*.php` | web controllers |
| `src/Public/routes/reseller.php` | URL routing |
| `src/Public/Views/reseller/*.php` | view templates |
| `src/Infrastructure/ResellerApiDispatcher.php` | AJAX action routing |
| `src/Infrastructure/ResellerTableRenderer.php` | DataTables rendering |
| `src/Infrastructure/Bootstrap/reseller_session.php` | session management |
| `src/Infrastructure/Bootstrap/reseller_functions.php` | initialization |
| `src/Core/Auth/Authorization.php` | ownership checks |
| `src/Core/Auth/PageAuthorization.php` | page-level gating |
