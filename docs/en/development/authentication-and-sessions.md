# Authentication and Sessions

XC_VM authentication flow supports admin, reseller, and player contexts with isolated session keys.

---

## Login Flow Overview

```text
request -> BruteforceGuard checks
        -> Authenticator::login()
           -> optional reCAPTCHA
           -> credential validation
           -> access code/group check
           -> permission/status check
           -> session write + login log
```

---

## `Authenticator`

File: `src/core/Auth/Authenticator.php`

Main method:

```php
Authenticator::login(array $data, bool $bypassRecaptcha = false): array
```

Typical return statuses:

- `STATUS_SUCCESS`
- `STATUS_FAILURE`
- `STATUS_INVALID_CAPTCHA`
- `STATUS_INVALID_CODE`
- `STATUS_NOT_ADMIN`
- `STATUS_DISABLED`

Admin session values after successful login:

```php
$_SESSION['hash'] = $userId;
$_SESSION['ip'] = $clientIp;
$_SESSION['code'] = $accessCode;
$_SESSION['verify'] = md5($username . '||' . $hashedPassword);
```

---

## `SessionManager`

File: `src/core/Auth/SessionManager.php`

Unified session API with context key mapping.

### Context key map

| Logical key | Admin key | Reseller key | Player key |
| --- | --- | --- | --- |
| `auth` | `hash` | `reseller` | `phash` |
| `activity` | `last_activity` | `rlast_activity` | — |
| `ip` | `ip` | `rip` | — |
| `code` | `code` | `rcode` | — |
| `verify` | `verify` | `rverify` | `pverify` |

### Common usage

```php
SessionManager::start('admin');
SessionManager::requireAuth();
```

Default inactivity timeout is 60 minutes (`DEFAULT_TIMEOUT`).

---

## `BruteforceGuard`

File: `src/core/Auth/BruteforceGuard.php`

Centralized anti-bruteforce/rate-limit logic.
Integrates with IP utilities, allow/block lists, and DB-backed state.

Flood/blocked-IP markers are enforced by HTTP request guards as early as possible.

---

## Session Security

In admin bootstrap context, session cookies use strict same-site mode:

```php
$params['samesite'] = 'Strict';
session_set_cookie_params($params);
session_start();
```

---

## Login Logging

When `save_login_logs` is enabled, all login attempts are recorded in `login_logs` with status and source IP.

Typical statuses: `SUCCESS`, `INVALID_LOGIN`, `INVALID_CODE`, `NOT_ADMIN`, `DISABLED`.

---

## Related Files

| File | Purpose |
| --- | --- |
| `src/core/Auth/Authenticator.php` | login logic |
| `src/core/Auth/SessionManager.php` | unified sessions |
| `src/core/Auth/BruteforceGuard.php` | anti-bruteforce/rate-limit |
| `src/core/Auth/Authorization.php` | object/advanced authorization |
| `src/core/Auth/PageAuthorization.php` | page-level access checks |
| `src/bootstrap.php` | starts/admin context session setup |
