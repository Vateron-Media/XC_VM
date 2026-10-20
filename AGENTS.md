# Project working rules

- Add new translation keys only to `src/Core/Localization/lang/en.ini`. Do not manually duplicate them into every language. The maintainer uses `python3 tools/i18n/translate.py --ini --lang all` to translate and fill missing keys.
- Before pushing PHP changes, run the CI checks from `.github/workflows/ci.yml`: `make cs`, `make phpstan`, and `make gates`, plus relevant tests. For authentication, permissions, schema, or shared-service changes, run the full PHPUnit suite against the test database. Do not treat syntax checks or targeted PHPUnit alone as CI verification. Report any check that cannot be reproduced locally.
- Use an existing SSH ControlMaster socket when available; the user prefers socket-based SSH sessions.
- A worktree under `/tmp` is excluded by the PHPCS `*/tmp/*` pattern. For local checks there, use a temporary copy of the same ruleset that omits only that runtime-directory exclusion, and enough memory (1G); verify that real source files were scanned. Never weaken the committed CI ruleset to obtain a pass.
