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

File: `src/core/Auth/Authenticator.php`

### `Authenticator::login(array $data, bool $bypassRecaptcha = false): array`

Admin login method. Steps in order:

1. reCAPTCHA validation (if `recaptcha_enable` setting is on and not bypassed).
2. Credential lookup via `UserRepository::getAuthUserByCredentials()`.
3. Access code group check -- the user's `member_group_id` must be in the current access code's allowed groups, or no access codes must exist.
4. Permission check -- `is_admin` must be true for the user's group.
5. Status check -- `$rUserInfo['status'] == 1` (enabled).
6. On success: re-hashes password, updates `last_login` and `ip` in the database, writes session keys, logs the login.

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
Authenticator::checkPassword(string $password, string $storedHash): string
```

Verifies a plaintext password against a stored hash using `crypt($password, $storedHash)` with timing-safe comparison via `hash_equals()`. The stored hash contains the algorithm, rounds, and salt, so `crypt()` reproduces the correct hash for comparison.

---

## Player Authentication

File: `src/public/Controllers/Player/PlayerLoginController.php`

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

File: `src/public/Views/admin/functions.php`

When `$_SESSION['hash']` is set, the following checks run on every page load:

1. **User lookup** -- `UserRepository::getRegisteredUserById($_SESSION['hash'])`. If the user no longer exists, the session is terminated.
2. **Permission check** -- `AuthRepository::getPermissions()` must return a valid set with `is_admin == true`.
3. **IP verification** -- Compares the current IP against `$_SESSION['ip']`:
   - If `ip_subnet_match` setting is enabled: compares only the first three octets (e.g., `192.168.1.*` matches `192.168.1.*`).
   - If `ip_subnet_match` is disabled: requires an exact IP match.
   - If the IP does not match and `ip_logout` setting is enabled: the session is terminated.
   - If the IP does not match and `ip_logout` is disabled: `$_SESSION['ip']` is silently updated to the new IP.
4. **Verify hash check** -- `$_SESSION['verify']` must equal `md5($rUserInfo['username'] . '||' . $rUserInfo['password'])`. This ensures the session is invalidated if the password changes.

If any check fails, the session is cleared via `SessionManager::clearContext('admin')` and the user is redirected to the index page.

### Reseller Session Validation

File: `src/infrastructure/bootstrap/reseller_functions.php`

Identical logic to admin validation, but uses the reseller session keys:

- Checks `$_SESSION['reseller']` for the user ID.
- Uses `$_SESSION['rip']` for IP comparison.
- Uses `$_SESSION['rverify']` for the verify hash.
- Validates `is_reseller` permission instead of `is_admin`.

The IP subnet matching and IP logout behavior is the same as admin.

### Admin Session Timeout

File: `src/public/Views/admin/session.php`

A separate session timeout check runs for admin sessions. If `$_SESSION['hash']` and `$_SESSION['last_activity']` are both set and more than 60 minutes have elapsed since `last_activity`, the session keys (`hash`, `ip`, `code`, `verify`, `last_activity`) are unset. On every valid request, `$_SESSION['last_activity']` is updated and the session is closed for writing.

### Player Session Validation

The player context does not perform IP verification, subnet matching, or activity timeout checks at the session level. Only `phash` and `pverify` are stored, and validation relies on the application layer to re-check these values against the database.

---

## SessionManager

File: `src/core/Auth/SessionManager.php`

Unified session API that abstracts the different session key names across contexts. Intended as a replacement for the legacy `admin/session.php` and `reseller/session.php` files.

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

Checks for an authenticated session. If the request is to `session.php` directly, returns a JSON `{"result": true/false}` response (used for AJAX session polling). Otherwise, redirects unauthenticated users to the login page. On success, calls `touch()` to update the activity timestamp.

**`isAuthenticated(): bool`**

Non-blocking check. Returns `true` if the session has been started and the `auth` key is set.

**`getUser(): ?string`**

Returns the value stored in the `auth` session key (user ID for admin/reseller, or line ID for player), or `null` if not authenticated.

**`getValue(string $name): mixed`**

Returns a session value by its logical name (`auth`, `activity`, `ip`, `code`, `verify`). The logical name is mapped to the actual `$_SESSION` key based on the current context.

**`setValue(string $name, mixed $value): void`**

Sets a session value by logical name.

**`login(string $hash, ?string $ip = null): void`**

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

File: `src/core/Auth/BruteforceGuard.php`

Centralized rate-limiting and brute-force protection. All methods use file-based state stored at `FLOOD_TMP_PATH` (`/home/xc_vm/tmp/flood/`). Allowed IPs (server IPs) and IPs listed in the `flood_ips_exclude` setting are always exempted.

### `checkFlood(?string $ip = null, bool $useCachedMode = false): null`

Rate-limits requests per IP within a configurable time window.

- **Settings:** `flood_limit` (max requests), `flood_seconds` (window size).
- **State file:** `FLOOD_TMP_PATH . $ip` -- stores a JSON object with `requests` count and `last_request` timestamp.
- **Behavior:** Tracks request count within the time window. If the count exceeds `flood_limit`, the IP is blocked (inserted into `blocked_ips` table or signaled via Redis in cached/streaming mode). The state file is deleted after blocking.
- **Used by:** Player login (called on every failed login attempt), streaming endpoints.

### `checkBruteforce(?string $ip = null, ?string $mac = null, ?string $username = null, bool $useCachedMode = false): null`

Detects brute-force attacks based on the number of unique MAC addresses or usernames seen from a single IP.

- **Settings:** `bruteforce_mac_attempts`, `bruteforce_username_attempts` (max unique values), `bruteforce_frequency` (time window in seconds).
- **State file:** `FLOOD_TMP_PATH . $ip . '_mac'` or `FLOOD_TMP_PATH . $ip . '_user'` -- stores attempts as `{term: timestamp}` pairs.
- **Behavior:** Expired attempts (outside the frequency window) are pruned via `truncateAttempts()`. If the number of unique terms exceeds the limit, the IP is blocked.
- **Used by:** Streaming authentication endpoints.

### `checkAuthFlood(array $user, ?string $ip = null): null`

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

In the admin bootstrap context, session cookies are set with strict SameSite policy:

```php
$params['samesite'] = 'Strict';
session_set_cookie_params($params);
session_start();
```

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

When the `save_login_logs` setting is enabled, all login attempts (success and failure) are recorded in the `login_logs` table:

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

---

## Authorization (Post-Login)

After authentication, two additional authorization layers control what a user can access:

### `Authorization`

File: `src/core/Auth/Authorization.php`

Object-level authorization. Checks whether the current user has permission to access a specific resource (user, stream, etc.) based on reseller ownership hierarchies and group permissions.

- `Authorization::hasResellerPermissions($type)` -- checks a single permission flag on `$rPermissions`.
- `Authorization::check($type, $id)` -- validates access to a specific resource by type and ID.

### `PageAuthorization`

File: `src/core/Auth/PageAuthorization.php`

Page-level access control. Determines whether the current user's group permissions allow access to a specific admin or reseller panel page.

- `PageAuthorization::checkResellerPermissions($page)` -- maps page names to required permission flags and returns whether access is allowed.

---

## Related Files

| File | Purpose |
| --- | --- |
| `src/core/Auth/Authenticator.php` | Admin and reseller login logic, password hashing |
| `src/core/Auth/SessionManager.php` | Unified session API with context key mapping |
| `src/core/Auth/BruteforceGuard.php` | Rate-limiting and brute-force protection |
| `src/core/Auth/Authorization.php` | Object-level authorization checks |
| `src/core/Auth/PageAuthorization.php` | Page-level access control |
| `src/public/Controllers/Player/PlayerLoginController.php` | Player login flow with security checks |
| `src/public/Views/admin/functions.php` | Admin session validation on every page load |
| `src/public/Views/admin/session.php` | Admin session timeout and AJAX session check |
| `src/infrastructure/bootstrap/reseller_functions.php` | Reseller session validation on every page load |
| `src/domain/User/UserRepository.php` | Credential lookup (`getAuthUserByCredentials`) |
| `src/bootstrap.php` | Status constant definitions, bootstrap contexts |
| `src/core/Config/Paths.php` | `FLOOD_TMP_PATH` definition |
