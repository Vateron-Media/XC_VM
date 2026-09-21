---
name: "dead-code"
description: "Strict dead code verification and handling policy for XC_VM."
---

# Dead Code Policy & Verification Rules

In XC_VM, unused functions or methods must be handled strictly and safely:

## Verification Standard
Before declaring any code dead or unused:
```bash
grep -rn '<symbol_name>' /home/xc_vm/ --include='*.php' | grep -v vendor
```
- Must verify across ALL entry points: CLI commands, Web API controllers, Cron jobs, Event listeners, Views, and Module contracts.
- Remember: dynamic method calls (`$obj->$method()`), event names, or legacy hooks may not show up in simple grep without careful search.

## Action Rules
1. **Comment out, do not delete immediately**:
   - Comment out the block.
   - Leave a clear note with the date, author, and explanation:
     ```php
     // DEAD_CODE (2026-09-17): No call sites found across codebase. Preserved pending next major release.
     ```
2. **Update DEAD_CODE.md**:
   - Add an entry to `/home/xc_vm/DEAD_CODE.md` with:
     `| File | Symbol | Refs | Commented out | Notes |`
3. **Hard deletion**:
   - Removal happens only during scheduled major/minor release cleanup after verification across at least one release cycle.
