---
name: "type-audit"
description: "Strict parameter type-hint and nullability verification rules to prevent TypeError null given crashes."
---

# Parameter Type-Hint Audit & Nullability Rules

When adding, modifying, or reviewing PHP type declarations in XC_VM:

## The `null given` Footgun
Auto-inserting native parameter types from `@param` docblocks across the codebase can cause fatal runtime errors:
```
TypeError: X::y(): Argument #N ($p) must be of type string, null given
```
`declare(strict_types)` does NOT prevent this: passing `null` to a non-nullable scalar param throws in coercive mode too.

## Mandatory Rules
1. **Never blindly trust docblocks** for nullability. If a caller can pass `null` (e.g. from optional DB columns, missing request params, or uninitialized state), the parameter MUST be nullable (`?string`, `?int`, `?array`) or have a default value (`?string $p = null`).
2. **Scan for risk before changing signatures**:
   ```bash
   git grep -lP '\b(string|int|float|bool|array)\s+\$\w+(?=\s*[,\)])' -- 'src/**/*.php'
   ```
3. **Always check call sites** before narrowing a parameter type.
4. If a parameter receives `null`, either:
   - Make the parameter nullable: `?string $param`
   - Widen the type: `string|int|null $param`
   - Or fix the caller if passing `null` is an actual bug.
