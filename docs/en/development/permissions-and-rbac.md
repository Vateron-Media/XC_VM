# Permissions and RBAC

XC_VM access control combines:

- group permissions (allowed capabilities)
- object-level authorization (specific entities)
- page-level authorization (panel routes/pages)

---

## Model

```text
user -> member_group_id
      -> is_admin / is_reseller / advanced[]
```

Permission checks rely on `$rPermissions` initialized during session flow.

---

## Permission Keys

Permission key catalog is declared in `src/config/permissions.php` (`$rPermissionKeys`).

Categories in practice:

- create/edit operations (`add_*`, `edit_*`)
- mass operations (`mass_*`)
- import operations (`import_*`)
- security controls (`block_*`, `fingerprint`)
- visibility/sections (`streams`, `servers`, `settings`, `database`, ...)

Use key checks consistently via authorization helpers, not ad-hoc conditionals.

---

## Authorization Classes

### `Authorization`

File: `src/core/Auth/Authorization.php`

Primary method:

```php
Authorization::check(string $type, mixed $id): bool
```

Supported types:

| Type | Meaning |
| --- | --- |
| `'user'` | can access target user under reseller report tree |
| `'line'` | can access target line under reseller report tree |
| `'adv'` | has advanced permission key |

Examples:

```php
Authorization::check('user', $userId);
Authorization::check('line', $lineId);
Authorization::check('adv', 'block_isps');
Authorization::check('adv', 'edit_bouquet');
```

Reseller helper:

```php
Authorization::hasResellerPermissions(string $type): bool
```

---

### `PageAuthorization`

File: `src/core/Auth/PageAuthorization.php`

Methods:

```php
PageAuthorization::checkPermissions(?string $page = null): bool
PageAuthorization::checkResellerPermissions(?string $page = null): bool
```

If `$page` is omitted, page name is inferred from `SCRIPT_FILENAME`.

Page-level checks map pages to advanced keys (`adv:*`) and reseller-specific toggles.

---

## Super Admin Behavior

`member_group_id = 1` is treated as super admin.
For advanced checks this bypasses granular key filtering.

```php
if (0 < count($rPermissions['advanced']) && $rUserInfo['member_group_id'] != 1) {
    return in_array($rID, $rPermissions['advanced']);
}
return true;
```

---

## Adding a New Permission

1. Add key to `src/config/permissions.php`:

```php
'my_new_permission',
```

1. Use it in code:

```php
if (!Authorization::check('adv', 'my_new_permission')) {
    // deny
}
```

1. If page gating is needed, map it in `PageAuthorization::checkPermissions()`.

---

## Related Files

| File | Purpose |
| --- | --- |
| `src/config/permissions.php` | permission key registry |
| `src/core/Auth/Authorization.php` | object/advanced checks |
| `src/core/Auth/PageAuthorization.php` | page-level gating |
| `src/core/Auth/SessionManager.php` | session context for auth state |
