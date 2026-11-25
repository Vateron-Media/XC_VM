# Security dependency inventory

Verified 2026-11-23 against the supported Ubuntu repositories. Fresh installs run `apt-get update`; `dpkg-query -W` is the authority for the exact package installed on a node.

| Component | Ubuntu 22.04 | Ubuntu 24.04 | Maintenance |
|---|---:|---:|---|
| Redis | `5:6.0.16-1ubuntu1.2` | `5:7.0.15-1ubuntu0.24.04.5` | Ubuntu security/updates; replaces bundled KeyDB 6.3.4 |
| GuessIt | `3.4.3-1` | `3.8.0-2` | Ubuntu universe; replaces bundled Python 3.6 executable |
| OpenSSL 3 compatibility for 20.04 | `3.0.2-0ubuntu1.30` | n/a | Exact Ubuntu security package pinned by SHA-256 in `install` |

The legacy player now reuses the admin assets: jQuery 3.7.1, Bootstrap 5.3.5 and Select2 4.0.13. SweetAlert2 is 11.26.25. Removed copies were jQuery 3.5.1, Bootstrap 4.5.0, Select2 4.0.6-rc.1 and the admin-player jQuery 3.3.1 bundle.

MariaDB 11.4 uses an APT source scoped with `Signed-By`. The installer verifies fingerprint `177F 4010 FE56 CA33 3630 0305 F165 6F24 C74C D1D8` before writing the source.
