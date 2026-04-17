# Documentation Gaps Backlog

This backlog tracks missing documentation discovered during a full docs audit.

---

## High Priority

1. Permissions and RBAC
Location: `docs/en/development/permissions-and-rbac.md`
Reason: Permission model is critical for secure feature development and admin access control.

2. Bootstrap Contexts
Location: `docs/en/development/bootstrap-contexts.md`
Reason: Runtime contexts (ADMIN, STREAM, CLI, MINIMAL) affect behavior and initialization.

3. HTTP Request Handling and Middleware
Location: `docs/en/development/http-request-handling.md`
Reason: Request validation and middleware flow are central for new controllers and APIs.

4. Error Handling Model
Location: `docs/en/development/error-handling.md`
Reason: Error lifecycle and error codes are referenced in architecture but not documented for contributors.

5. Input Validation Strategy
Location: `docs/en/development/input-validation.md`
Reason: Validation rules and usage patterns are required to avoid inconsistent endpoint behavior.

6. Authentication and Sessions
Location: `docs/en/development/authentication-and-sessions.md`
Reason: Auth/session flow is security-sensitive and currently under-documented.

---

## Medium Priority

1. Streaming Subsystem Overview
Location: `docs/en/development/streaming-subsystem.md`
Reason: Streaming hot path and boundary points are spread across architecture docs only.

2. Event Catalog and Module Hooks
Location: `docs/en/development/modules.md` (expand)
Reason: Event-driven extensions need a complete event list and payload guidance.

3. Caching and Redis Strategy
Location: `docs/en/development/caching-and-redis.md`
Reason: Cache usage and invalidation patterns are not centralized.

4. Reseller System Overview
Location: `docs/en/administration/reseller-system.md`
Reason: Reseller workflows are core functionality but lack dedicated docs.

5. Backup Strategy
Location: `docs/en/administration/backup-strategy.md`
Reason: Operational safety depends on consistent backup guidance.

6. GeoIP and Device Detection
Location: `docs/en/development/geoip-and-device-detection.md`
Reason: Region/device behavior impacts stream logic and access control.

---

## Low Priority

1. Process Management Patterns
Location: `docs/en/development/process-management.md`
Reason: Useful for daemon and background processing contributions.

2. Development Feature Flags
Location: `docs/en/development/feature-flags.md`
Reason: Clarifies behavior differences across environments.

3. Diagnostics Service Notes
Location: `docs/en/development/diagnostics-api.md`
Reason: Improves troubleshooting and support workflow.

---

## Notes

- This backlog lists missing docs only.
- It does not change architecture behavior.
- Update this file as gaps are addressed.
