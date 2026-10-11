# Patch builds

A patch build ships fixes to panels without a new release. It is the release with the fixes made since, published as `X.Y.Z-pN` (`2.6.5-p1`, `2.6.5-p2`, …) by one workflow run.

Use it for a fix that should not wait for the next release. Nothing is prepared by hand: no version bump, no `changelog.json` entry, no translation pass, no release PR.

## What a panel sees

- The panel's updater takes a patch build as it takes a release: **Update** on MAIN, then on each load balancer. Nothing is new in the panel.
- A patch is a **small update**: its update archive carries only the files changed since the release, and the updater overlays them onto the tree and restarts the service — not a re-download of the whole panel. The panel shows the update in the top bar and on the Settings page, with a one-click **Update Server** button, the same as for a release.
- It is offered on the channel of the release it patches. A patch of a stable release reaches the `Stable` and `Beta` channels; a patch of a pre-release reaches `Beta` only.
- Versions order as `2.6.5` < `2.6.5-p1` < `2.6.5-p2` < `2.6.6`. A panel on the release goes to its newest patch at once, and on to the next release when there is one. A panel on an older release goes straight to the newest version.
- The panel shows the patch as its version (`2.6.5-p1`). A module that asks for core `2.6.5` keeps loading: a patch counts as its release.
- A rollback may name a patch build or the release under it.
- The changelog the panel shows is every commit since the release, so it reads the same whichever patch the panel comes from.

## Making one

1. Merge the fix (to `main`, or to a branch cut from the release when `main` holds work that should wait).
2. Run **Actions → Build Patch → Run workflow**, or:

    ```bash
    gh workflow run build-patch.yml -f ref=main
    ```

3. The workflow picks the release (`base`: the newest `X.Y.Z` tag the ref contains), numbers the patch, runs the unit tests, stamps the version into the build, and publishes `XC_VM.zip`, `xc_vm.tar.gz`, `loadbalancer.tar.gz`, `hashes.md5` and `changelog.json`. The two update archives (`xc_vm.tar.gz`, `loadbalancer.tar.gz`) are reduced to a **delta** — only the files changed since the release, plus the stamped version file, and the deleted-files list when the patch removes a file. `XC_VM.zip` stays the full installer, because a fresh install lays down the whole tree.

| Input | Meaning |
|-------|---------|
| `ref` | Branch, tag or commit to build. Default `main`. |
| `base` | Release to patch (`X.Y.Z`). Empty: the newest release the ref contains. |
| `dry_run` | Test and build only. Nothing is published; the files are kept as a run artifact for 7 days. |

Try a `dry_run` first when in doubt.

## What it refuses

- A ref that does not contain the release, or that is the release itself (nothing to ship).
- A ref whose source is already the next version (`XC_VM_VERSION` differs from the base): that is the next release, so release it. To patch the older release, build from a branch that still carries its version.
- A second build of a commit that is already the newest patch.

## What a patch may carry

A patch build is built from the full tree of its ref: everything merged since the release goes out, database migrations included. The update archive is then a delta of those changes (the updater overlays it), so a file that did not change since the release is left as the panel has it. Keep `main` releasable, or patch from a release branch.

A patch build is not a release in these ways:

- `changelog.json` in the source tree is untouched; the patch's changelog is generated from the commit subjects. Write the subject line for the people who will read it in the panel.
- The documentation site is not rebuilt for a patch.
- A patch of an older release is never marked as GitHub's "Latest".

The next release is prepared as usual ([Pre-release Checklist](updates_checklist.md)). Its `deleted_files.txt` is still generated against the latest stable release, not against a patch tag.
