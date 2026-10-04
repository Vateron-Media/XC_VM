# XC_VM Release Preparation Checklist

Step-by-step guide for preparing and publishing an XC_VM release.

---

## 1. Changelog

**Reset the build output, then generate the commit log (work commits only):**

```bash
make new                 # wipe + recreate dist/ ONCE, at the very start
PREV_TAG=$(git describe --tags --abbrev=0)
git log --no-merges --pretty=format:"- %s (%h)" "$PREV_TAG"..main > dist/changes.md
```

> ⚠️ Run `make new` here, at the very start of the release — it wipes **and** recreates `dist/`. Everything below writes into `dist/` (starting with `changes.md`), so `make new` must run **before** this and **never again** before building — a later `make new` would delete `dist/changes.md`.

**Update `changelog.json`** in the repository root — this file contains only the changes for the upcoming release:

```json
{
	"version": "X.Y.Z",
	"changes": ["Description of change 1", "Description of change 2"]
}
```

The panel fetches this file from the release tag automatically via `GitHubReleases::getChangelog()`.

> 💬 Keep descriptions concise — focus on user-facing improvements and fixes.

---

## 2. Pre-Release Validation

Before publishing, verify the build works:

**Quality checks** (CI runs the same set on every push to `main` and on pull requests, not on tags — confirm it is green on the release commit once step 5 has pushed it):

```bash
make dev-tools
make phpstan
make cs
make gates
make test-db     # only without a local MariaDB: the unit tests run on MariaDB
php tests/phpunit.phar -c tests/phpunit.xml.dist
make dev-clean   # remove the dev tools afterwards, restoring the prod-only vendor/
```

**Every new migration can be rolled back.** A version rollback (**Servers → Rollback Version**) reverses the migrations the older release lacks with their `down/` files and skips any that has none, leaving its schema change behind. List the migrations added since the last release that have no down file:

```bash
PREV_TAG=$(git describe --tags --abbrev=0)
for f in $(git diff --name-only --diff-filter=A "$PREV_TAG" -- src/migrations/database/up); do
  [ -f "src/migrations/database/down/$(basename "$f")" ] || echo "no down file: $f"
done
```

Write the missing ones (`src/migrations/database/down/<same name>.sql`) before the release; a migration that truly cannot be reversed says so in a comment at the top of its up file.

> ℹ️ The Docker test install moved to step 6 — it requires a built `dist/XC_VM.zip`.

**Security scan:** runs automatically on push/PR via `.github/workflows/security-scan.yml` (Semgrep) — no manual step.

### Regenerate translated documentation

Documentation is written in **English only** (`docs/en`). The Russian tree
(`docs/ru`) is a **generated, committed** artifact refreshed locally before each
release — translation is intentionally **not** run in CI (it is slow); CI only
builds the committed tree. If `docs/en` changed since the last release:

```bash
make docs-translate      # regenerate docs/ru from docs/en (free, no API key)
make docs-build          # strict build — fails on any broken link/anchor
```

- `make docs-translate` re-translates only the English files whose content
  changed (per-file cache), so this is fast on an incremental release.
- **Review and commit the regenerated `docs/ru`** — it is included in the single
  release commit (step 5). Never hand-edit `docs/ru`.
- Documentation is published **per release, not per push**: `pages.yml` runs when
  the version **tag** is pushed (step 7) and publishes this release's docs as a
  versioned snapshot (`X.Y.Z` + the `latest` alias) to the `gh-pages` branch via
  `mike`. Edits merged to `main` between releases go live at the next tagged
  release (which is also when `docs/ru` is regenerated). The Material header's
  version selector lets readers switch between released versions.

### Sync panel language files

New UI strings are added to `src/Core/Localization/lang/en.ini` only. Before a
release, bring every other language file in line with it:

```bash
make lang-translate      # translate keys missing vs en.ini, drop keys en.ini no longer has
```

- Existing translations are kept; missing keys and values still identical to
  the English text are machine-translated; keys removed from `en.ini` are
  deleted from every language (the run lists them — check nothing expected is
  in that list).
- Translations are cached (`build/docs-cache/ini-<lang>.json`), so only keys
  added since the last release hit the network.
- **Review the diff of `src/Core/Localization/lang/*.ini` and commit it** with
  the release (step 5). Placeholders such as `{bin}` and HTML tags must be
  unchanged; see [Adding a Custom Language](../guides/translations.md#keeping-translations-in-sync).

---

## 3. Prepare Release Baseline

First, finish all feature/fix/docs work and make sure it is already in `main`.

Set the version variable once and reuse it in all commands below:

```bash
VERSION="X.Y.Z"
```

**Choosing `X.Y.Z`:** `MAJOR.MINOR.PATCH` — bump **PATCH** for fixes/small changes (e.g.
`2.4.0 → 2.4.1`), **MINOR** for backward-compatible features (`2.4.x → 2.5.0`), **MAJOR** for
breaking changes. Hotfixes are a PATCH bump on top of the current release. `XC_VM_VERSION`
(set in step 5) must match the tag you publish in step 7.

> ⚠️ Do not create a separate version-bump commit/push at this step.
> Otherwise `dist/changes.md` will include extra release commits and force additional edits.

---

## 4. Deleted Files

Before building, generate the list of files to delete on update:

```bash
make generate_deleted_files
```

This runs `git diff` between `LAST_TAG` and `HEAD`, extracts deleted files under `src/`, strips the `src/` prefix, and writes the result to `src/migrations/deleted_files.txt`.

If `LAST_TAG` cannot be auto-detected (no network / no releases), pass it explicitly:

```bash
make generate_deleted_files LAST_TAG=1.2.16
```

**Review the generated file** — verify no critical files are listed by mistake:

```bash
cat src/migrations/deleted_files.txt
```

After validation, `make lb` packs the file into the LB archive via the `lb_delete_files_list` target; on MAIN the file simply rides along in the full-tree copy (there is no separate `delete_files_list` target).

During `php console.php update post-update`, `MigrationRunner::runFileCleanup()` reads it and deletes the listed files automatically.

> ⚠️ Lines starting with `#` are comments and will be ignored. You can comment out files you want to keep.

---

## 5. Update Version and Create a Single Release Commit

Edit the version constant, disable the phpMiniAdmin access flag, and clear its password in:

> **Why disable `DB_ACCESS_ENABLED` / clear `DB_ACCESS_PWD`?** phpMiniAdmin is a raw
> database console handy in development, but shipping it **enabled** would expose the DB to
> anyone who reaches the panel. This step is a security hardening gate — a release must never
> go out with it on.

```text
src/Core/Config/ConstantsInitializer.php
```

These frequently-edited constants are `define()`s at the **top of the file** (above the
class); `appConfig()` reads them back, and `init()` skips the already-defined ones.

**Quick commands:**

```bash
sed -i "s/define('DB_ACCESS_ENABLED', true);/define('DB_ACCESS_ENABLED', false);/" src/Core/Config/ConstantsInitializer.php
sed -i "s/define('DB_ACCESS_PWD', '[^']*');/define('DB_ACCESS_PWD', '');/" src/Core/Config/ConstantsInitializer.php
sed -i "s/define('XC_VM_VERSION', '[0-9]\+\.[0-9]\+\.[0-9]\+');/define('XC_VM_VERSION', '${VERSION}');/" src/Core/Config/ConstantsInitializer.php
```

**Create one final release commit/push:**

```bash
git add src/Core/Config/ConstantsInitializer.php changelog.json src/migrations/deleted_files.txt
git add src/Core/Localization/lang/   # the synced language files (step 2)
git add docs/en docs/ru               # include any doc edits + the regenerated ru (step 2)
git commit -m "Prepare release ${VERSION}"
git push
```

> ⚠️ This removes the need for multiple release commits.

---

## 6. Build Archives

> 🤖 **Production builds** are handled by GitHub Actions (`.github/workflows/build-release.yml`) when a release is published. Assets are attached automatically.

**For local builds:**

```bash
make lb
make main
```

> ⚠️ Do **not** run `make new` here — `dist/` was already reset in step 1, and re-running it would delete `dist/changes.md`. The build targets write into the existing `dist/`.

After building, `dist/` should contain:

| File                  | Description                                    |
| --------------------- | ---------------------------------------------- |
| `XC_VM.zip`           | MAIN installer (install script + xc_vm.tar.gz) |
| `xc_vm.tar.gz`        | MAIN archive (install & update)                |
| `loadbalancer.tar.gz` | LB archive (install & update)                  |
| `hashes.md5`          | MD5 checksums                                  |

> The same archive is used for both clean installation and updates.
> The update script (`src/update`) filters out binary/config directories at runtime using the hardcoded `UPDATE_EXCLUDE_DIRS` list inside the Python script itself.

**Verify integrity:**

```bash
cd dist && md5sum -c hashes.md5
```

**Docker test install** (see `tools/test-install/`) — only after building, since it needs `dist/XC_VM.zip`:

```bash
bash tools/test-install/test_release.sh
```

This builds the image, starts the container with systemd, and runs the installer automatically.
`dist/XC_VM.zip` is mounted into the container as a read-only volume.

> ✅ Verify the panel loads at `http://localhost:8880` and admin login works.

---

## 7. GitHub Release

1. Go to [GitHub Releases](https://github.com/Vateron-Media/XC_VM/releases)
2. Create a new release targeting `main`, with the tag `${VERSION}` from step 3: plain `X.Y.Z`, no `v` prefix (the existing tags are `2.6.0`, `2.5.3`, …), equal to `XC_VM_VERSION`
3. Paste the changelog as the release description
4. Publish **without attaching files** — GitHub Actions will build and attach them

After publishing, the workflow will automatically:

- Build all archives + checksums
- Attach them to the release
- Send a Telegram notification via `release-notifier.yml`
- Publish this version's documentation to GitHub Pages via `pages.yml` (mike):
  a `X.Y.Z` snapshot plus the `latest` alias, selectable from the docs header

> ✅ Wait for the Actions workflow to finish, then verify all files are downloadable.

---

## 8. Post-Release

> **MAIN before LB.** Update the **MAIN** node first. Its `post-update` broadcasts an `update`
> signal to every LB when `auto_update_lbs` is on, so LBs follow automatically; keep MAIN and LB
> on the **same version** — LBs read MAIN's database and a schema/behaviour skew can break
> streaming. Don't leave LBs a release behind.

> **Undo the cluster lockdown first when the update needs it.** Once `cluster:db-allowlist apply`
> has closed MAIN's MariaDB and Redis to the fleet, a node can no longer reach them. If this
> release's update path needs a node to — a node updated through the legacy `update` signal, a
> node moved back below mode 2 for the update — run `console.php cluster:db-allowlist undo` on
> MAIN **before** the update, and `cluster:db-allowlist apply` again once every node is back in
> mode 2. See [Cluster API](../development/cluster-api.md#operating-it).

> **Never downgrade MAIN below what its mode-2 nodes need.** While any node is in mode 2 (the
> *Mode* column of **Servers → Cluster Nodes**), MAIN must stay on a release that serves every op
> and replica section those nodes use — they no longer read MAIN's database, so nothing else
> carries them. Do not roll MAIN back below the release a node was promoted to mode 2 on while it
> is still there: move every such node down to mode 1 first (`mode_down`), and roll back only
> once none is left in mode 2.

- [ ] Verify all 4 assets are attached to the release
- [ ] Run `md5sum -c hashes.md5` on downloaded files
- [ ] Check Telegram notification was sent
- [ ] Close related GitHub issues/milestones

---

## If something goes wrong

- **Actions build failed after publishing** — the release has no (or partial) assets. Re-run the
  failed workflow from the Actions tab; if the tag itself is wrong, delete the release **and** the
  tag (`git push --delete <remote> X.Y.Z`, the remote as `git remote` names it), fix, and re-tag. Don't leave a published release with
  missing assets — panels fetch `hashes.md5` / archives from it.
- **A released asset is broken** — publish a **PATCH** hotfix release (new tag) rather than editing
  a published one; clients pin to a tag.
- **A bad release already reached servers** — operators can downgrade per-server from the panel
  (**Servers → Rollback Version**, see [Update Mechanism → Rollback](../administration/update-system.md#rollback-downgrade)); on MAIN a DB backup is taken automatically first. Migrations are
  forward-only, so prefer a roll-_forward_ hotfix when the fix is small.
  With the cluster API in use, the two cluster rules of [Post-Release](#8-post-release) apply
  to a rollback too: never below what a mode-2 node needs, and the lockdown undone first when
  the rollback needs nodes to reach MAIN's database.

---

## Command Reference

Every `make` target used during release prep, in one place.

**Quality checks** — run `make dev-tools` first, `make dev-clean` when done:

| Command                                            | Purpose                                                               |
| -------------------------------------------------- | --------------------------------------------------------------------- |
| `make dev-tools`                                   | Install dev tooling (PHPStan, phpcs) via `composer install`           |
| `make phpstan`                                     | Static analysis (also catches syntax errors)                          |
| `make phpstan-baseline`                            | Regenerate the PHPStan baseline                                       |
| `make cs`                                          | Code-style check — import/namespace hygiene (phpcs + Slevomat)        |
| `make cs-fix`                                      | Apply code-style fixes in place                                       |
| `make gates`                                       | Regression gates: procedural-use, LB-archive, vendor-prod-only, LB settings keys, Core→cluster refs |
| `make dev-clean`                                   | Remove the dev tools again, restoring the production-only `vendor/`   |
| `make test-db`                                     | Throwaway MariaDB in Docker for the unit tests (no local server)      |
| `php tests/phpunit.phar -c tests/phpunit.xml.dist` | Unit tests, on MariaDB                                                |

**Release prep & build:**

| Command                                   | Purpose                                                                                                                 |
| ----------------------------------------- | ----------------------------------------------------------------------------------------------------------------------- |
| `make generate_deleted_files`             | Regenerate `src/migrations/deleted_files.txt`                                                                           |
| `make new`                                | Wipe + recreate `dist/` — run ONCE at the start (step 1), before writing `dist/changes.md`; never again before building |
| `make lb`                                 | Build the LoadBalancer archive into `dist/`                                                                             |
| `make main`                               | Build the MAIN archive into `dist/`                                                                                     |
| `bash tools/test-install/test_release.sh` | Docker install test of the built release                                                                                |

**Documentation** (English source in `docs/en`; `docs/ru` is generated + committed):

| Command               | Purpose                                                |
| --------------------- | ------------------------------------------------------ |
| `make docs-venv`      | One-time: local venv (build + translation deps)        |
| `make docs-translate` | Regenerate `docs/ru` from `docs/en` (before a release) |
| `make lang-translate` | Sync `lang/*.ini` with `en.ini` (before a release)     |
| `make docs-build`     | Strict MkDocs build into `./build/site` (what CI runs) |
| `make docs-serve`     | Live docs preview at `http://127.0.0.1:8000`           |
