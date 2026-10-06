# Authentication and Sessions

XC_VM supports three authentication contexts -- admin, reseller, and player -- each with isolated session keys, distinct login flows, and independent validation logic. This document covers the full authentication lifecycle from login through session validation and security enforcement.

---

## Login Flow Overview

All three contexts follow a similar high-level pattern, with context-specific differences in validation and session storage.

### Admin / Reseller Flow

```text
POST request with credentials
  -> BruteforceGuard checks (flood / brute-force)
  -> Optional reCAPTCHA verification
  -> Credential lookup via UserRepository::getAuthUserByCredentials()
  -> Access code / group validation
  -> Permission check (is_admin or is_reseller)
  -> User status check (enabled/disabled)
  -> Password re-hash + session write + login log
```

### Player Flow

```text
POST request with credentials
  -> UserRepository::getUserInfo() lookup
  -> Line type checks (reject E2, MAG, Stalker)
  -> Expiration date check
  -> admin_enabled / enabled status checks
  -> IP allowlist, country restriction, user agent, ISP checks
  -> Session write + redirect
  -> BruteforceGuard::checkFlood() on any failure
```

---

## Authenticator

File: `src/Core/Auth/Authenticator.php`

### `Authenticator::login(array $data, bool $bypassRecaptcha = false): array`

Admin login method. Steps in order:

1. reCAPTCHA validation (if `recaptcha_enable` setting is on and not bypassed).
2. Credential lookup via `UserRepository::getAuthUserByCredentials()`.
3. Access code group check -- the user's `member_group_id` must be in the current access code's allowed groups, or no access codes must exist.
4. Permission check -- `is_admin` must be true for the user's group.
5. Status check -- `$rUserInfo['status'] == 1` (enabled).
6. On success: re-hashes password, updates `last_login` and `ip` in the database, moves the session onto a fresh id (`session_regenerate_id(true)`), writes session keys, logs the login.

The fresh id matters: the id a visitor arrives with at the login form is one someone else may know (a cookie planted from a sibling subdomain, a shared machine), and keeping it would sign them in too. `resellerLogin()` and the first-run setup page do the same.

Session values written on success:

```php
$_SESSION['hash']   = $rUserInfo['id'];       // User ID
$_SESSION['ip']     = $rIP;                   // Client IP at login
$_SESSION['code']   = AuthRepository::getCurrentCode(); // Current access code
$_SESSION['verify'] = md5($rUserInfo['username'] . '||' . $rCrypt); // Verification hash
```

### `Authenticator::resellerLogin(array $data): array`

Reseller login method. Identical structure to `login()` with these differences:

- reCAPTCHA is always checked when enabled (no bypass parameter).
- Permission check requires `is_reseller` instead of `is_admin`.
- Returns `STATUS_NOT_RESELLER` if the user lacks reseller permission.
- Login logs are recorded with type `RESELLER` instead of `ADMIN`.

Session values written on success:

```php
$_SESSION['reseller'] = $rUserInfo['id'];       // User ID
$_SESSION['rip']      = $rIP;                   // Client IP at login
$_SESSION['rcode']    = AuthRepository::getCurrentCode(); // Current access code
$_SESSION['rverify']  = md5($rUserInfo['username'] . '||' . $rCrypt); // Verification hash
```

### Login Status Constants

Defined in `src/bootstrap.php` via `XC_Bootstrap::defineStatusConstants()`:

| Constant | Value | Meaning |
| --- | --- | --- |
| `STATUS_FAILURE` | 0 | Generic failure (invalid credentials or catch-all) |
| `STATUS_SUCCESS` | 1 | Login succeeded |
| `STATUS_DISABLED` | 5 | Account is disabled |
| `STATUS_NOT_ADMIN` | 6 | User lacks admin permission |
| `STATUS_INVALID_CAPTCHA` | 12 | reCAPTCHA verification failed |
| `STATUS_INVALID_CODE` | 13 | Access code / group mismatch |
| `STATUS_NOT_RESELLER` | 35 | User lacks reseller permission |

### Password Hashing

```php
Authenticator::hashPassword(string $password, ?string $salt = null, int $rounds = 20000): string
```

Uses `crypt()` with SHA-512 (`$6$`). The salt format is `$6$rounds=20000$<salt>$` where `<salt>` is 16 hex characters derived from `openssl_random_pseudo_bytes(16)`. Passwords are re-hashed on every successful login, which rotates the salt.

```php
Authenticator::checkPassword(string $password, string $storedHash): bool
```

Verifies a plaintext password against a stored hash using `crypt($password, $storedHash)` with timing-safe comparison via `hash_equals()`. The stored hash contains the algorithm, rounds, and salt, so `crypt()` reproduces the correct hash for comparison.

---

## Player Authentication

File: `src/Public/Controllers/Player/PlayerLoginController.php`

The player login flow is fundamentally different from admin/reseller. It authenticates end-user "lines" (IPTV subscriptions) rather than panel operators.

### Login Process

`PlayerLoginController::processLogin()` performs these checks in order:

1. **Credential lookup** -- `UserRepository::getUserInfo()` (different from `getAuthUserByCredentials` used by admin/reseller).
2. **Line type rejection** -- E2, MAG, and Stalker lines are rejected with specific error codes.
3. **Expiration check** -- `exp_date` must be null or in the future.
4. **Admin-enabled check** -- `admin_enabled == 0` returns `CLIENT_BANNED`.
5. **User-enabled check** -- `enabled == 0` returns `CLIENT_DISABLED`.
6. **IP allowlist** -- If `allowed_ips` is set on the user, the client IP must match (resolved via `gethostbyname`).
7. **Country restriction** -- Two modes:
   - Per-user: if `forced_country` is set and not `ALL`, the GeoIP country must match.
   - Global: if no per-user override, the global `allow_countries` setting is checked (unless it contains `ALL`).
8. **User agent check** -- If `allowed_ua` is set on the user, the HTTP user agent must match.
9. **ISP check** -- `isp_violate` flag rejects the connection.
10. **ISP server check** -- If `isp_is_server` is true and the user is not a restreamer, the connection is rejected.

Every failure triggers `BruteforceGuard::checkFlood()` before returning an error code.

On success the session moves onto a fresh id (`session_regenerate_id(true)`) before the session keys are written, as at admin and reseller login, so the browser gets a new session cookie at sign-in.

The second web player (`src/Public/Controllers/PlayerV2/PlayerLoginController.php`) also signs in with an account on another Xtream server (the server form, or a playlist address). When that server refuses, the request ends there, for a plain form post as for the page's script: the login page shows that server's answer, the address is counted once by `checkFlood()`, and the username and password are not tried as a line of this panel.

### Player Error Codes

| Constant | Value | Meaning |
| --- | --- | --- |
| `CLIENT_INVALID` | 0 | Invalid username or password |
| `CLIENT_IS_E2` | 1 | Enigma lines not permitted |
| `CLIENT_IS_MAG` | 2 | MAG lines not permitted |
| `CLIENT_IS_STALKER` | 3 | Stalker lines not permitted |
| `CLIENT_EXPIRED` | 4 | Line has expired |
| `CLIENT_BANNED` | 5 | Line banned (admin_enabled = 0) |
| `CLIENT_DISABLED` | 6 | Line disabled (enabled = 0) |
| `CLIENT_DISALLOWED` | 7 | Failed IP/country/UA/ISP check |

### Player Session Keys

```php
$_SESSION['phash']   = $rUserInfo['id'];
$_SESSION['pverify'] = md5($rUserInfo['username'] . '||' . $rUserInfo['password']);
```

The player context stores only two session keys. Unlike admin and reseller, there are no `activity`, `ip`, or `code` keys. This means the player session has no inactivity timeout enforcement and no IP change detection at the session level.

---

## Session Validation on Page Load

After initial login, every authenticated page load re-validates the session. This happens in the bootstrap files, not in `SessionManager`.

### Admin Session Validation

Entry point: `AdminScopeBootstrap::hydrateAdminContext()` (`src/Infrastructure/Bootstrap/AdminScopeBootstrap.php`). It runs on the front-controller admin path (via `boot()`) and is called directly by the `$noBootstrapPages` view scripts (`login`, `setup`, `database`, `logout`, `player`, `post`) — replacing the former `admin/functions.php` include.

When `$_SESSION['hash']` is set, it resolves the user (`UserRepository::getRegisteredUserById($_SESSION['hash'])`) and permissions (`AuthRepository::getPermissions()`), then validates the session's integrity via `SessionManager::adminSessionValid($rUserInfo, $rPermissions, $rSettings)`:

1. **User & admin** -- a user row and permissions exist, `is_admin` is set and the account is still enabled (`status` is 1, as a login requires).
2. **IP verification** -- Compares the current IP against `$_SESSION['ip']`:
   - If `ip_subnet_match` setting is enabled: compares only the first three octets (e.g., `192.168.1.*` matches `192.168.1.*`).
   - If `ip_subnet_match` is disabled: requires an exact IP match.
   - The IP is only enforced when `ip_logout` is enabled; otherwise a changed IP is tolerated (and, on the HTML path, `$_SESSION['ip']` is refreshed to the new value).
3. **Verify hash check** -- `$_SESSION['verify']` must equal `md5($rUserInfo['username'] . '||' . $rUserInfo['password'])`. This ensures the session is invalidated if the password changes.

If validation fails, the session is cleared via `SessionManager::clearContext('admin')` and the user is redirected to the index page. The admin JSON DataTables endpoint (`Public\Controllers\Admin\TableController`) runs the same `SessionManager::adminSessionValid()` check on its session branch but responds with JSON instead of redirecting.

Because the account's `status` is part of the check, disabling an admin ends the session that account has open on its next request; there is no need to wait for the inactivity timeout.

### Reseller Session Validation

File: `src/Infrastructure/Bootstrap/ResellerScopeBootstrap.php`

Identical logic to admin validation, but uses the reseller session keys:

- Checks `$_SESSION['reseller']` for the user ID.
- Uses `$_SESSION['rip']` for IP comparison.
- Uses `$_SESSION['rverify']` for the verify hash.
- Validates `is_reseller` permission instead of `is_admin`.
- Requires the account to be enabled (`status` is 1), so disabling a reseller ends its open session on the next request.

The IP subnet matching and IP logout behavior is the same as admin.

### Admin Session Lifecycle

Session start and the 60-minute inactivity timeout run in `AdminScopeBootstrap::bootSession()` (front-controller path) and, for the legacy view scripts, in `SessionManager::start('admin')` + `requireAuth()` — which replaced the former `admin/session.php` include. If `$_SESSION['hash']` and `$_SESSION['last_activity']` are both set and more than 60 minutes have elapsed since `last_activity`, the session keys (`hash`, `ip`, `code`, `verify`, `last_activity`) are unset. On every valid request, `$_SESSION['last_activity']` is updated and the session is closed for writing. The AJAX session-poll endpoint (`{"result": true/false}`) is served by `Public\Controllers\Admin\SessionController` (the `session` route).

### Player Session Validation

The player context does not perform IP verification, subnet matching, or activity timeout checks at the session level. Only `phash` and `pverify` are stored, and validation relies on the application layer to re-check these values against the database.

---

## SessionManager

File: `src/Core/Auth/SessionManager.php`

Unified session API that abstracts the different session key names across contexts. It is the replacement for the legacy admin `session.php` / `functions.php` includes (now removed); reseller and player scopes bootstrap through their own `*ScopeBootstrap` classes.

### Context Key Map

| Logical key | Admin `$_SESSION` key | Reseller `$_SESSION` key | Player `$_SESSION` key |
| --- | --- | --- | --- |
| `auth` | `hash` | `reseller` | `phash` |
| `activity` | `last_activity` | `rlast_activity` | -- |
| `ip` | `ip` | `rip` | -- |
| `code` | `code` | `rcode` | -- |
| `verify` | `verify` | `rverify` | `pverify` |

The player context intentionally omits `activity`, `ip`, and `code` mappings.

### Methods

**`start(string $context, int $timeout = 60): void`**

Starts a PHP session (if not already started), sets the active context, and runs `checkTimeout()` to expire stale sessions. Context must be `'admin'`, `'reseller'`, or `'player'`.

**`requireAuth(?string $loginUrl = null): void`**

Redirects unauthenticated users to the login page; on success, calls `touch()` to update the activity timestamp. Dedicated AJAX session polling is now served by `SessionController` (the `session` route), not by this method.

**`isAuthenticated(): bool`**

Non-blocking check. Returns `true` if the session has been started and the `auth` key is set.

**`getUser(): mixed`**

Returns the value stored in the `auth` session key (user ID for admin/reseller, or line ID for player), or `null` if not authenticated.

**`getValue(string $name): mixed`**

Returns a session value by its logical name (`auth`, `activity`, `ip`, `code`, `verify`). The logical name is mapped to the actual `$_SESSION` key based on the current context.

**`setValue(string $name, mixed $value): void`**

Sets a session value by logical name.

**`login(mixed $hash, ?string $ip = null): void`**

Creates an authenticated session by setting the `auth` and `activity` values. Optionally stores the client IP.

**`destroy(): void`**

Clears all session keys for the current context. If no other context is active (checks the opposite admin/reseller context), destroys the entire PHP session.

**`clearContext(string $context): void`**

Clears all session keys for a specific context without destroying the session. Drop-in replacement for legacy `destroySession($type)`.

**`touch(): void`**

Updates `$_SESSION[$activityKey]` to the current timestamp and calls `session_write_close()` to release the session lock.

**`getContext(): ?string`**

Returns the current context string (`'admin'`, `'reseller'`, `'player'`) or `null` if not set.

### Timeout Behavior

`SessionManager::DEFAULT_TIMEOUT` is 60 minutes. The `checkTimeout()` method (called automatically by `start()`) compares the elapsed time since `last_activity`. If the timeout is exceeded, all context-specific session keys are unset, effectively logging the user out.

Since the player context has no `activity` key in the key map, timeout checks do not apply to player sessions.

---

## BruteforceGuard

File: `src/Core/Auth/BruteforceGuard.php`

Centralized rate-limiting and brute-force protection. All methods use file-based state stored at `FLOOD_TMP_PATH` (`/home/xc_vm/tmp/flood/`). Allowed IPs (server IPs) and IPs listed in the `flood_ips_exclude` setting are always exempted.

Every count and block marker is a file named after the address, so `checkFlood()`, `checkBruteforce()` and `checkAuthFlood()` count IP addresses only. A value that is not an IP address is not counted and creates no file under `tmp/flood/`.

### `checkFlood(?string $ip = null, bool $useCachedMode = false): void`

Rate-limits requests per IP within a configurable time window.

- **Settings:** `flood_limit` (max requests), `flood_seconds` (the longest gap between two requests that are counted together).
- **State file:** `FLOOD_TMP_PATH . $ip` -- stores a JSON object with `requests` count and `last_request` timestamp.
- **Behavior:** Counts requests in a row, each within `flood_seconds` of the one before; a longer gap starts the count again. If the count exceeds `flood_limit`, the IP is blocked (inserted into `blocked_ips` table or signaled via Redis in cached/streaming mode). The state file is deleted after blocking.
- **Used by:** Player login (called on every failed login attempt), streaming endpoints, the client APIs (a refused sign-in on `player_api.php` / `panel_api.php` included) and the MAG portal.

### `checkBruteforce(?string $ip = null, ?string $mac = null, ?string $username = null, bool $useCachedMode = false, ?string $password = null): void`

Detects brute-force attacks based on the number of unique MAC addresses or usernames seen from a single IP, and on the number of different passwords tried for one username from it.

- **Settings:** `bruteforce_mac_attempts`, `bruteforce_username_attempts` (max unique values), `bruteforce_frequency` (time window in seconds). A limit that is 0, empty or absent switches that count off: MAC addresses and usernames are counted separately, each against its own limit.
- **State file:** `FLOOD_TMP_PATH . $ip . '_mac'` stores attempts as `{term: timestamp}` pairs. `FLOOD_TMP_PATH . $ip . '_user'` holds `{"attempts": {term: timestamp}, "passwords": {username: {digest: timestamp}}}`: the `attempts` keys are the usernames as sent; the `passwords` keys are the username in lower case without trailing spaces, and each digest is the first 16 hex characters of HMAC-SHA256 over that username and the password, keyed with the panel's stream secret. The password itself is never stored.
- **Behavior:** Expired attempts and digests (outside the frequency window) are pruned via `truncateAttempts()`. The IP is blocked when the number of unique terms reaches the limit, or when the different passwords tried for one username reach `bruteforce_username_attempts` (same limit, same note). The same wrong password sent again counts once; an empty or absent password is not counted; a correct sign-in is never counted.
- **Concurrency:** a call holds an exclusive `flock` on the count file for its read and replace, so requests refused at the same moment for one address are each counted. The file is still replaced by rename; a waiting request locks the file now at the path again (at most 20 turns, then it proceeds without the lock). No lock file is created.
- **Used by:** Streaming authentication endpoints, the client APIs and web player sign-in (unknown usernames, tokens and activation codes), and the MAG portal. The username and password sign-ins pass the password too (player_api, playlist, EPG, Enigma2, both web players, stream authentication, RTMP and the probe); token, activation-code and MAG portal callers pass none. The portal reports a handshake for a MAC the panel does not know and a `get_profile` that does not verify the device: each calls `checkBruteforce()` with the SHA-256 of the MAC as sent, then `checkFlood()`.

### `checkAuthFlood(array $user, ?string $ip = null): void`

Rate-limits authentication requests for a specific user+IP combination. Designed to throttle repeated auth attempts without fully blocking.

- **Settings:** `auth_flood_limit` (max attempts), `auth_flood_seconds` (window), `auth_flood_sleep` (delay in seconds when blocked).
- **State file:** `FLOOD_TMP_PATH . $userId . '_' . $ip` -- stores attempts as indexed timestamps plus an optional `block_until` timestamp.
- **Behavior:** When the attempt count exceeds the limit, a `block_until` timestamp is set. Subsequent requests during the block period are delayed by `auth_flood_sleep` seconds (via `sleep()`). Does not permanently block the IP. Restreamer users (`is_restreamer`) are exempt.
- **Used by:** Streaming authentication.

### `truncateAttempts(array $attempts, int $frequency, bool $list = false): array`

Filters out expired attempts from the tracking array. When `$list` is `true`, treats the array as indexed (for `checkAuthFlood`); otherwise as associative keyed by term (for `checkBruteforce`).

### Blocking Mechanism

When an IP is blocked:

- **Normal mode:** Inserts into the `blocked_ips` database table with a reason (`FLOOD ATTACK` or `BRUTEFORCE MAC/USER ATTACK`) and refreshes the `BlocklistService` cache.
- **Cached/streaming mode (`$useCachedMode = true`):** Sets a Redis signal (`bruteforce_attack/$ip` or `flood_attack/$ip`) via `RedisManager::setSignal()` for streaming-context blocking without a database write.
- In both modes, a marker file `FLOOD_TMP_PATH . 'block_' . $ip` is touched for fast filesystem-level checks.

---

## Session Security

### Cookie Configuration

The session cookie parameters are set in one place, `SessionStage::startSession()` (`src/Core/Bootstrap/Stage/SessionStage.php`): the admin bootstrap context runs it as a boot stage, and the admin, reseller and player scope bootstraps call it for the session they start before the framework boot. The admin session poll (`Public\Controllers\Admin\SessionController`, the `session` route) and `SessionManager::start()` call it too; the latter makes the first start of a form save, because nginx runs `Public/Views/admin/post.php` itself. Every session start of both web players (sign-in, sign-out, external accounts) goes through it as well.

It chooses the cookie from the request's scope (`XC_SCOPE`, set by nginx):

- The admin and reseller panels, the APIs and every other scope keep PHP's `PHPSESSID`, `SameSite=Strict`.
- The two web players (`player`, `player_v2`) use their own cookie, `PLAYERSESSID` (`SessionStage::PLAYER_COOKIE`), `SameSite=Lax`.

Both are `HttpOnly`, and PHP runs in strict mode, refusing session ids it never issued:

```php
if (in_array($_SERVER['XC_SCOPE'] ?? '', ['player', 'player_v2'], true)) {
    session_name(self::PLAYER_COOKIE);
}
$rParams = session_get_cookie_params();
$rParams['samesite'] = session_name() === self::PLAYER_COOKIE ? 'Lax' : 'Strict';
$rParams['httponly'] = true;
session_set_cookie_params($rParams);
ini_set('session.use_strict_mode', '1');
session_start();
```

No panel script reads the session cookie, so `HttpOnly` costs nothing and keeps an XSS from reading it.

`SameSite=Strict` means a browser does not send the panels' cookie on a request that starts on another website, so a link from another site never reaches a signed-in panel. The player's cookie is sent on a link from another site (a top-level `GET`), so a viewer who follows one stays signed in; it is not sent on a form post or a script request from another site.

A panel sign-in and a player sign-in in one browser are two sessions: a player request neither reads nor writes the panel's session, and the reverse. Signing out of the player ends the player's session only. A session from before the player had its own cookie is not carried over: viewers sign in to the player once after the update.

The players' actions that change something run only on a `POST`, which a browser does not send with the player's cookie from another site: `logout` (both players; a `GET` leads to the home page), `refresh` (second player; `GET` is not routed) and the bouquet order of `profile` (first player; a `GET` only shows the page). The players' own links and scripts send `POST`.

### Verify Hash

The verify hash (`$_SESSION['verify']` / `$_SESSION['rverify']` / `$_SESSION['pverify']`) is computed as:

```php
md5($username . '||' . $hashedPassword)
```

This value is checked on every page load against the current database values. If an administrator changes a user's password (which changes the stored hash), all existing sessions for that user are automatically invalidated because the verify hash will no longer match.

For admin and reseller logins, the password is re-hashed at login time, so `$rCrypt` (the new hash) is used. For player logins, the existing stored `$rUserInfo['password']` hash is used directly.

### IP Change Handling

Two settings control IP change behavior:

| Setting | Effect |
| --- | --- |
| `ip_logout` | When enabled, terminates the session if the client IP changes (exact or subnet, depending on `ip_subnet_match`). |
| `ip_subnet_match` | When enabled, compares only the first three octets of the IP address instead of requiring an exact match. Allows users on dynamic IPs within the same subnet to retain their session. |

When `ip_logout` is disabled and the IP changes, the session's stored IP is silently updated to the new IP.

---

## Login Logging

Failed sign-ins (`INVALID_LOGIN`) are always recorded, because the login flood limit counts them. When the `save_login_logs` setting is enabled, every other outcome is recorded too. All of it goes into the `login_logs` table:

```sql
INSERT INTO `login_logs`(`type`, `access_code`, `user_id`, `status`, `login_ip`, `date`)
VALUES($type, $codeId, $userId, $status, $ip, $timestamp);
```

| Column | Description |
| --- | --- |
| `type` | `ADMIN` or `RESELLER` |
| `access_code` | ID of the current access code |
| `user_id` | User ID (0 for invalid credentials) |
| `status` | `SUCCESS`, `INVALID_LOGIN`, `INVALID_CODE`, `NOT_ADMIN`, `DISABLED` |
| `login_ip` | Client IP address |
| `date` | Unix timestamp |

Player logins do not write to `login_logs`.

### Login Flood Limit

The admin and reseller login pages call `Authenticator::loginFloodExceeded($ip, $rSettings['login_flood'])` before processing a login. When an address has `login_flood` or more `INVALID_LOGIN` rows dated within the last 24 hours, it is added to the blocklist (`LOGIN FLOOD ATTACK`) and the request ends. A `login_flood` of 0 turns the limit off. **Quick Tools → Clear login flood** deletes the counted rows.

`date` is a Unix timestamp, so the window is `date >= time() - 86400`. The pages used to filter it with `TIME_TO_SEC(TIMEDIFF(NOW(), date))`, which is NULL for an integer column, so no address was ever blocked.

---

## Authorization (Post-Login)

After authentication, two additional authorization layers control what a user can access:

### `Authorization`

File: `src/Core/Auth/Authorization.php`

Object-level authorization. Checks whether the current user has permission to access a specific resource (user, stream, etc.) based on reseller ownership hierarchies and group permissions.

- `Authorization::hasResellerPermissions($type)` -- checks a single permission flag on `$rPermissions`.
- `Authorization::check($type, $id)` -- validates access to a specific resource by type and ID.

### `PageAuthorization`

File: `src/Core/Auth/PageAuthorization.php`

Page-level access control. Determines whether the current user's group permissions allow access to a specific admin or reseller panel page.

- `PageAuthorization::checkResellerPermissions($page)` -- maps page names to required permission flags and returns whether access is allowed.

A page's rule is looked up under its underscore name, whichever way the URL spells it (`line/mass` for `line_mass`, with or without a trailing `.php`). A new admin or reseller page that needs a permission gets a case in `PageAuthorization`; a page with no case stays open to every admin group. See [Permissions and RBAC](permissions-and-rbac.md).

---

## Related files

| File | Purpose |
| --- | --- |
| `src/Core/Auth/Authenticator.php` | Admin and reseller login logic, password hashing |
| `src/Core/Auth/SessionManager.php` | Unified session API with context key mapping |
| `src/Core/Auth/BruteforceGuard.php` | Rate-limiting and brute-force protection |
| `src/Core/Auth/Authorization.php` | Object-level authorization checks |
| `src/Core/Auth/PageAuthorization.php` | Page-level access control |
| `src/Public/Controllers/Player/PlayerLoginController.php` | Player login flow with security checks |
| `src/Infrastructure/Bootstrap/AdminScopeBootstrap.php` | Admin bootstrap: session lifecycle, `$rUserInfo`/`$rPermissions`, integrity guard |
| `src/Public/Controllers/Admin/SessionController.php` | AJAX admin session poll (`session` route) |
| `src/Infrastructure/Bootstrap/ResellerScopeBootstrap.php` | Reseller session validation on every page load |
| `src/Core/Bootstrap/Stage/SessionStage.php` | `startSession()`: session start with the panel's cookie parameters |
| `src/Domain/User/UserRepository.php` | Credential lookup (`getAuthUserByCredentials`) |
| `src/bootstrap.php` | Status constant definitions, bootstrap contexts |
| `src/Core/Config/ConstantsInitializer.php` | `FLOOD_TMP_PATH` definition (`paths()` map) |
