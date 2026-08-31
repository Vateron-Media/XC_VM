PROJECT_NAME := $(notdir $(CURDIR))
TIMESTAMP := $(shell date +%s)
TEMP_DIR := /tmp/XC_VM-$(TIMESTAMP)
MAIN_DIR = ./src
DIST_DIR = ./dist
CONFIG_DIR := ./lb_configs
TEMP_ARCHIVE_NAME := $(TIMESTAMP).tar.gz
MAIN_ARCHIVE_NAME := xc_vm.tar.gz
MAIN_ARCHIVE_INSTALLER := XC_VM.zip
LB_ARCHIVE_NAME := loadbalancer.tar.gz
# Latest published release tag — consumed only by generate_deleted_files to diff
# against the previous release. Uses `?=` (lazy) so plain builds (new/main/lb)
# never evaluate it and thus never hit the network; the GitHub API is queried
# only when generate_deleted_files actually expands LAST_TAG. Override on the CLI
# to skip the API call entirely (CI passes it this way):
#   make generate_deleted_files LAST_TAG=v1.2.3
LAST_TAG ?= $(shell curl -s https://api.github.com/repos/Vateron-Media/XC_VM/releases/latest | grep '"tag_name":' | sed -E 's/.*"([^"]+)".*/\1/')
HASH_FILE := hashes.md5

# Directories and files to exclude from archives
EXCLUDES := \
	.git

# Directories to copy from MAIN to LB
# NOTE: Modules/ is intentionally excluded — all modules are MAIN-only
# (e.g. the ministra portal, whose ~50 MB of assets must not ship to LB nodes).
LB_DIRS := bin Cli config content Core Domain\
	Infrastructure Public resources signals Streaming tmp vendor www

# Root-level files to copy from MAIN to LB (not inside directories)
LB_ROOT_FILES := bootstrap.php console.php service update

# Directories to remove from LB (admin-only content)
LB_DIRS_TO_REMOVE := \
	bin/install \
	bin/redis \
	bin/nginx/conf/codes \
	Domain/User \
	Domain/Device \
	Domain/Auth \
	Public/Controllers/Admin \
	Public/Controllers/Player \
	Public/Controllers/Reseller \
	Public/Views \
	Public/assets \
	Public/routes \
	Core/Reference \
	Core/Localization/lang

# Files to remove from LB
LB_FILES_TO_REMOVE := \
	bin/maxmind/GeoLite2-City.mmdb \
	Public/Controllers/Api/AdminApiController.php \
	Public/Controllers/Api/ResellerRestApiController.php \
	www/xplugin.php \
	www/probe.php \
	www/playlist.php \
	www/player_api.php \
	www/epg.php \
	www/enigma2.php \
	www/stream/auth.php \
	www/admin/proxy_api.php \
	www/admin/api.php \
	config/rclone.conf \
	Cli/Commands/MigrateCommand.php \
	Cli/Commands/CacheHandlerCommand.php \
	Cli/Commands/ServerInstallCommand.php \
	Cli/Commands/LbInstallFlow.php \
	Cli/Commands/ProxyInstallFlow.php \
	Cli/CronJobs/RootMysqlCronJob.php \
	Cli/CronJobs/BackupsCronJob.php \
	Cli/CronJobs/CacheEngineCronJob.php \
	Cli/CronJobs/EpgCronJob.php \
	Cli/CronJobs/UpdateCronJob.php \
	Cli/CronJobs/ProvidersCronJob.php \
	Cli/CronJobs/SeriesCronJob.php \
	Domain/Epg/EPG.php \
	Core/Enum/Theme.php \
	Core/Enum/ResellerAction.php \
	Core/Enum/ClientFilter.php \
	bin/nginx/conf/gzip.conf

EXCLUDE_ARGS := $(addprefix --exclude=,$(EXCLUDES))

.PHONY: new lb main lb_copy_files main_copy_files set_permissions create_archive \
	lb_archive_move main_archive_move main_install_archive clean \
	verify_no_lfs_pointers \
	lb_delete_files_list generate_deleted_files \
	phpstan phpstan-baseline cs cs-fix check-procedural-use verify-lb-archive gates \
	check-vendor-prod-only dev-tools dev-clean

# ─── Dev tooling ────────────────────────────────────────────────
# The committed src/vendor/ is PRODUCTION-ONLY (composer install --no-dev). The
# dev tools below (PHPStan, phpcs) are require-dev packages — install them
# into src/vendor/ once with `make dev-tools` before running phpstan / cs. CI runs
# the equivalent `composer install` step itself. They are never committed (the
# committed vendor stays prod-only — see tools/ci/check-vendor-prod-only.sh).
dev-tools:
	@cd src && composer install --no-interaction

# Inverse of dev-tools: once you no longer need the checks, remove the installed
# dev libraries (PHPStan, phpcs + transitive deps) and restore the
# production-only vendor/. `composer install --no-dev` prunes vendor/ back to the
# committed prod set, so `git status` stays clean and no dev package can be staged.
dev-clean:
	@cd src && composer install --no-dev --no-interaction
	@# composer rewrites installed.php/.json with the root package's current git
	@# reference (HEAD sha), so they churn after every commit even though the prod
	@# package set is identical — restore the committed copies to keep vendor clean.
	@git checkout -- src/vendor/composer/installed.php src/vendor/composer/installed.json 2>/dev/null || true

# ─── Static analysis (PHPStan) ──────────────────────────────────
PHPSTAN := src/vendor/bin/phpstan

# Run the analysis using build/phpstan.dist.neon.
phpstan:
	@test -x "$(PHPSTAN)" || { echo "PHPStan not found — run 'make dev-tools' (composer install) first."; exit 1; }
	@php "$(PHPSTAN)" analyse -c build/phpstan.dist.neon --memory-limit=2G

# Dead-code audit (on demand, NOT a CI gate): reports unused PUBLIC methods /
# properties / constants via tomasvotruba/unused-public. Expect false positives
# for dynamically-invoked code (routes, #[ListensTo], commands) — verify by hand.
phpstan-deadcode:
	@test -x "$(PHPSTAN)" || { echo "PHPStan not found — run 'make dev-tools' (composer install) first."; exit 1; }
	@php "$(PHPSTAN)" analyse -c build/phpstan-deadcode.neon --memory-limit=2G

# Freeze all current errors into build/phpstan-baseline.neon (run after a level bump).
phpstan-baseline:
	@test -x "$(PHPSTAN)" || { echo "PHPStan not found — run 'make dev-tools' (composer install) first."; exit 1; }
	@php "$(PHPSTAN)" analyse -c build/phpstan.dist.neon --memory-limit=2G --generate-baseline=build/phpstan-baseline.neon

# Regenerate tools/phpstan/constants.stub.php from the current src/ define()s.
# Run when runtime-defined constants change so the stub PHPStan reads stays
# accurate (constants.stub.php is a bootstrapFile in build/phpstan.dist.neon).
phpstan-stub:
	@php tools/phpstan/gen-constants-stub.php > tools/phpstan/constants.stub.php
	@echo "regenerated tools/phpstan/constants.stub.php ($$(grep -c 'define(' tools/phpstan/constants.stub.php) constants)"

# ─── Code style (PHP_CodeSniffer + Slevomat) ────────────────────
# Narrow ruleset — import/namespace hygiene only (no PSR-12 reformat). See
# build/phpcs.xml.dist. Replaced PHP-CS-Fixer because Slevomat's UnusedUses is
# precise (it does not treat a class name that merely appears in a PHPDoc
# DESCRIPTION as "used", unlike PHP-CS-Fixer's no_unused_imports).
PHPCS  := src/vendor/bin/phpcs
PHPCBF := src/vendor/bin/phpcbf
# View templates (`<?`/`<?=`) are excluded in the ruleset; analysed class files
# use full `<?php`, so no short_open_tag handling is needed.
PHPCS_FLAGS := --standard=build/phpcs.xml.dist --cache=build/.phpcs-cache src

# Check only — non-zero exit on any violation. Used in CI.
cs:
	@test -x "$(PHPCS)" || { echo "phpcs not found — run 'make dev-tools' (composer install) first."; exit 1; }
	@php "$(PHPCS)" $(PHPCS_FLAGS)

# Apply fixes in place (phpcbf).
cs-fix:
	@test -x "$(PHPCBF)" || { echo "phpcbf not found — run 'make dev-tools' (composer install) first."; exit 1; }
	@php "$(PHPCBF)" $(PHPCS_FLAGS)

# ─── PSR-4 regression gates ─────────────────────────────────────
# Helper: print a variable's resolved value (consumed by CI gate scripts).
print-%:
	@echo '$($*)'

# Procedural/view files must import every migrated class they use with a
# top-of-file `use` (PHP `use` is positional outside the top scope).
check-procedural-use:
	@php -d short_open_tag=1 tools/ci/check_procedural_use.php

# LB archive must never contain privileged code (security blocker 1).
verify-lb-archive:
	@bash tools/ci/verify-lb-archive.sh

# Assert the committed src/vendor/ is production-only (no require-dev packages).
# Dev tools (PHPStan, phpcs) are installed locally via `composer install`
# and must never be committed. Checks git-tracked files, so it is correct even
# in a CI job that has already run `composer install`.
check-vendor-prod-only:
	@bash tools/ci/check-vendor-prod-only.sh

# Run every fast PSR-4 gate.
gates: check-procedural-use verify-lb-archive check-vendor-prod-only

# ─── Generate deleted_files.txt from git diff ───────────────────
# Usage: make generate_deleted_files [LAST_TAG=v1.2.3]
# Writes to src/migrations/deleted_files.txt for manual review.
generate_deleted_files:
	@if [ -z "$(LAST_TAG)" ]; then \
		echo "[ERROR] LAST_TAG is empty — cannot detect latest release."; \
		echo "        Pass it manually: make generate_deleted_files LAST_TAG=v1.2.3"; \
		exit 1; \
	fi
	@if ! git rev-parse "$(LAST_TAG)" >/dev/null 2>&1; then \
		echo "[ERROR] Tag '$(LAST_TAG)' not found locally. Run: git fetch --tags"; \
		exit 1; \
	fi
	@echo "[INFO] Generating deleted_files.txt: $(LAST_TAG)..HEAD"
	@{ \
		echo '# Files to delete during update (relative to MAIN_HOME)'; \
		echo '# Auto-generated from: git diff $(LAST_TAG)..HEAD'; \
		echo '# One file per line. Lines starting with # are comments.'; \
		echo '#'; \
		git diff --name-status --no-renames "$(LAST_TAG)"..HEAD -- src/ \
			| awk '$$1 == "D" { sub(/^src\//, "", $$2); print $$2 }'; \
	} > "$(MAIN_DIR)/migrations/deleted_files.txt"
	@rCount=$$(grep -cv '^#\|^$$' "$(MAIN_DIR)/migrations/deleted_files.txt" 2>/dev/null || echo 0); \
		echo "[INFO] Generated $(MAIN_DIR)/migrations/deleted_files.txt ($$rCount files)"; \
		if [ "$$rCount" -gt 0 ]; then \
			echo "[INFO] Files to delete:"; \
			grep -v '^#' "$(MAIN_DIR)/migrations/deleted_files.txt" | grep -v '^$$'; \
		else \
			echo "[INFO] No deleted files detected since $(LAST_TAG)"; \
		fi

# ─── MAIN targets ────────────────────────────────────────────────
# Single archive: used for both clean install and update.
# The update script (src/update) filters out excluded dirs at runtime.
main: main_copy_files set_permissions verify_no_lfs_pointers create_archive main_archive_move main_install_archive clean

# ─── LoadBalancer targets ────────────────────────────────────────
lb: lb_copy_files lb_delete_files_list set_permissions verify_no_lfs_pointers create_archive lb_archive_move clean

lb_copy_files:
	@echo "==> [LB] Creating distribution directory: $(DIST_DIR)"
	@mkdir -p ${DIST_DIR}
	@echo "==> [LB] Creating temporary directory: $(TEMP_DIR)"
	@mkdir -p ${TEMP_DIR}

	@echo "==> [LB] Copying tracked directories from $(MAIN_DIR)"
	@for lb_item in $(LB_DIRS); do \
		printf "   → Scanning: %s\n" "$$lb_item"; \
		git ls-files | grep "^src/$$lb_item/" | while read -r file; do \
			rel=$${file#src/}; \
			printf "      → Copying: %s\n" "$$file"; \
			mkdir -p "$(TEMP_DIR)/$$(dirname $$rel)"; \
			cp "$$file" "$(TEMP_DIR)/$$rel"; \
		done; \
	done

	@echo "==> [LB] Copying root files from $(MAIN_DIR)"
	@for root_file in $(LB_ROOT_FILES); do \
		if git ls-files --error-unmatch "src/$$root_file" >/dev/null 2>&1; then \
			cp "$(MAIN_DIR)/$$root_file" "$(TEMP_DIR)/$$root_file"; \
		else \
			printf "   ⚠ Not tracked: %s\n" "$$root_file"; \
		fi; \
	done

	@echo "==> [LB] Removing excluded directories"
	@for dir in $(LB_DIRS_TO_REMOVE); do \
		echo "   → Removing directory: $$dir"; \
		rm -rf "$(TEMP_DIR)/$$dir"; \
	done

	@echo "==> [LB] Removing excluded files"
	@for file in $(LB_FILES_TO_REMOVE); do \
		echo "   → Removing file: $$file"; \
		rm -f "$(TEMP_DIR)/$$file"; \
	done

	@echo "==> [LB] Copying config files"
	cp "$(CONFIG_DIR)/nginx.conf" $(TEMP_DIR)/bin/nginx/conf/nginx.conf
	cp "$(CONFIG_DIR)/live.conf" $(TEMP_DIR)/bin/nginx_rtmp/conf/live.conf

	@echo "Remove all .gitkeep files..."
	@find $(TEMP_DIR) -name .gitkeep \
		-not -path "*/.git/*" \
		-delete
	@echo "All files gitkeep deleted"

main_copy_files:
	@echo "==> [MAIN] Creating distribution directory: $(DIST_DIR)"
	mkdir -p ${DIST_DIR}
	@echo "==> [MAIN] Creating temporary directory: $(TEMP_DIR)"
	mkdir -p $(TEMP_DIR)

	@echo "==> [MAIN] Copying tracked files from $(MAIN_DIR)"
	@git ls-files src | while read -r file; do \
		rel=$${file#src/}; \
# 		printf "   → Copying: %s\n" "$$file"; \
		mkdir -p "$(TEMP_DIR)/$$(dirname $$rel)"; \
		cp "$$file" "$(TEMP_DIR)/$$rel"; \
	done

	@echo "Remove all .gitkeep files..."
	@find $(TEMP_DIR) -name .gitkeep \
		-not -path "*/.git/*" \
		-delete
	@echo "All files gitkeep deleted"

lb_delete_files_list:
	@echo "[INFO] Checking for manual deleted files list (LB-scoped)"
	@if [ -f "$(MAIN_DIR)/migrations/deleted_files.txt" ]; then \
		mkdir -p "$(TEMP_DIR)/migrations"; \
		grep -v '^#' "$(MAIN_DIR)/migrations/deleted_files.txt" | grep -v '^$$' \
			| awk -v dirs="$(LB_DIRS)" -v files="$(LB_ROOT_FILES)" ' \
				BEGIN { n=split(dirs,d," "); m=split(files,f," ") } \
				{ ok=0; for(i=1;i<=n;i++) if(index($$0,d[i]"/")==1){ok=1;break} \
				  if(!ok) for(i=1;i<=m;i++) if($$0==f[i]){ok=1;break} \
				  if(ok) print }' \
			> "$(TEMP_DIR)/migrations/deleted_files.txt"; \
		if [ -s "$(TEMP_DIR)/migrations/deleted_files.txt" ]; then \
			echo "[INFO] LB files to delete on update:"; \
			cat "$(TEMP_DIR)/migrations/deleted_files.txt"; \
		else \
			echo "[INFO] No LB-scoped deleted files found"; \
			rm -f "$(TEMP_DIR)/migrations/deleted_files.txt"; \
		fi; \
	else \
		echo "[INFO] No deleted_files.txt found — skipping"; \
	fi

set_permissions:
	@echo "==> Setting file and directory permissions"

	# Global defaults: directories 755, regular files 644
	@find $(TEMP_DIR) -type d -exec chmod 755 {} +
	@find $(TEMP_DIR) -type f -exec chmod 644 {} +

	# Restricted root directories (750)
	@for d in backups bin config content signals; do \
		chmod 0750 "$(TEMP_DIR)/$$d" 2>/dev/null || true; \
	done
	@chmod 0770 $(TEMP_DIR)/content/streams 2>/dev/null || true

	# Executable scripts
	@chmod 0750 $(TEMP_DIR)/service 2>/dev/null || true
	@chmod 0750 $(TEMP_DIR)/update 2>/dev/null || true
	@chmod 0750 $(TEMP_DIR)/bin/daemons.sh 2>/dev/null || true
	@chmod 0755 $(TEMP_DIR)/bin/xc_fanout/run.sh 2>/dev/null || true
	@chmod 0755 $(TEMP_DIR)/console.php 2>/dev/null || true
	@chmod 0755 $(TEMP_DIR)/bin/guess 2>/dev/null || true
	@chmod 0755 $(TEMP_DIR)/bin/yt-dlp 2>/dev/null || true
	@chmod 0550 $(TEMP_DIR)/bin/network 2>/dev/null || true
	@chmod 0550 $(TEMP_DIR)/bin/network.py 2>/dev/null || true

	# FFmpeg executables
	@find $(TEMP_DIR)/bin/ffmpeg_bin -type f \( -name 'ffmpeg' -o -name 'ffprobe' \) \
		-exec chmod 0551 {} + 2>/dev/null || true

	# Nginx binaries
	@find $(TEMP_DIR)/bin/nginx -type d -exec chmod 750 {} + 2>/dev/null || true
	@find $(TEMP_DIR)/bin/nginx -type f -exec chmod 550 {} + 2>/dev/null || true
	@chmod 0755 $(TEMP_DIR)/bin/nginx/conf 2>/dev/null || true
	@chmod 0600 $(TEMP_DIR)/bin/nginx/conf/server.key 2>/dev/null || true
	@chmod 0750 $(TEMP_DIR)/bin/nginx_rtmp/sbin/nginx_rtmp 2>/dev/null || true

	# PHP binaries
	@find $(TEMP_DIR)/bin/php -type d -exec chmod 750 {} + 2>/dev/null || true
	@find $(TEMP_DIR)/bin/php -type f -exec chmod 550 {} + 2>/dev/null || true
	@for conf in 1.conf 2.conf 3.conf 4.conf; do \
		chmod 0644 "$(TEMP_DIR)/bin/php/etc/$$conf" 2>/dev/null || true; \
	done
	@chmod 0551 $(TEMP_DIR)/bin/php/bin/php 2>/dev/null || true
	@chmod 0551 $(TEMP_DIR)/bin/php/sbin/php-fpm 2>/dev/null || true

	# Redis executable
	@chmod 0755 $(TEMP_DIR)/bin/redis/redis-server 2>/dev/null || true

	# Sensitive config files
	@chmod 0640 $(TEMP_DIR)/config/modules.php 2>/dev/null || true
	@chmod 0550 $(TEMP_DIR)/config/rclone.conf 2>/dev/null || true

# Fail the build if any staged file is still a Git LFS pointer instead of the
# real binary. This happens when the checkout did not materialise LFS objects
# (e.g. `actions/checkout` without `lfs: true`), which would otherwise ship
# 130-byte text stubs in place of ffmpeg, redis, yt-dlp, etc.
verify_no_lfs_pointers:
	@echo "==> Verifying no Git LFS pointer files leaked into $(TEMP_DIR)"
	@pointers=$$(grep -rlI '^version https://git-lfs.github.com/spec/v1' "$(TEMP_DIR)" 2>/dev/null || true); \
	if [ -n "$$pointers" ]; then \
		echo "ERROR: Git LFS pointer files found in the staged tree:"; \
		echo "$$pointers" | sed 's|^$(TEMP_DIR)/|   - |'; \
		echo "The checkout did not fetch LFS objects. Run 'git lfs pull' or set 'lfs: true' on the CI checkout."; \
		exit 1; \
	fi; \
	echo "OK: no LFS pointers staged"

create_archive:
	@echo "==> Creating final archive: ${TEMP_ARCHIVE_NAME}"
	@tar -czf ${DIST_DIR}/${TEMP_ARCHIVE_NAME} -C $(TEMP_DIR) .

lb_archive_move:
	@echo "==> Moving LB archive to: ${DIST_DIR}/${LB_ARCHIVE_NAME}"
	@rm -f ${DIST_DIR}/${LB_ARCHIVE_NAME}
	@mv ${DIST_DIR}/${TEMP_ARCHIVE_NAME} ${DIST_DIR}/${LB_ARCHIVE_NAME}
	md5sum "${DIST_DIR}/${LB_ARCHIVE_NAME}" | awk -v name="${LB_ARCHIVE_NAME}" '{print $$1, name}' >> "${DIST_DIR}/${HASH_FILE}"

main_archive_move:
	@echo "==> Moving MAIN archive to: ${DIST_DIR}/${MAIN_ARCHIVE_NAME}"
	@rm -f ${DIST_DIR}/${MAIN_ARCHIVE_NAME}
	@mv ${DIST_DIR}/${TEMP_ARCHIVE_NAME} ${DIST_DIR}/${MAIN_ARCHIVE_NAME}
	md5sum "${DIST_DIR}/${MAIN_ARCHIVE_NAME}" | awk -v name="${MAIN_ARCHIVE_NAME}" '{print $$1, name}' >> "${DIST_DIR}/${HASH_FILE}"

main_install_archive:
	@echo "==> Creating installer archive: ${DIST_DIR}/${MAIN_ARCHIVE_INSTALLER}"
	@rm -f ${DIST_DIR}/${MAIN_ARCHIVE_INSTALLER}
	@zip -r ${DIST_DIR}/${MAIN_ARCHIVE_INSTALLER} install && zip -j ${DIST_DIR}/${MAIN_ARCHIVE_INSTALLER} ${DIST_DIR}/${MAIN_ARCHIVE_NAME}
	md5sum "${DIST_DIR}/${MAIN_ARCHIVE_INSTALLER}" | awk -v name="${MAIN_ARCHIVE_INSTALLER}" '{print $$1, name}' >> "${DIST_DIR}/${HASH_FILE}"

clean:
	@echo "==> Cleaning up temporary directory: $(TEMP_DIR)"
	@rm -rf $(TEMP_DIR)
	@echo "✅ Project build complete"

new:
	@echo "==> Cleaning up distribution directory: $(DIST_DIR)"
	@rm -rf $(DIST_DIR)
	@echo "==> Creating distribution directory: $(DIST_DIR)"
	@mkdir -p ${DIST_DIR}

# ─── Documentation (MkDocs Material + auto-translated ru) ────────────
# EDIT ENGLISH ONLY (docs/en). docs/ru is a GENERATED tree that is committed and
# refreshed by `make docs-translate` LOCALLY before a release — translation is
# deliberately kept out of CI (it is slow). CI (pages.yml) only builds the
# already-committed, already-translated tree. Never hand-edit docs/ru.
#
#   make docs-venv       # one-time: local venv (build + translation deps)
#   make docs-serve      # live preview at :8000 (builds committed en+ru)
#   make docs-build      # strict static build into ./build/site (what CI runs)
#   make docs-translate  # release step: (re)generate docs/ru from docs/en, then commit
#
# Translation engine via DOCS_TRANSLATE_PROVIDER (default: translators = free, no key):
#   make docs-translate                                     # free web engines (yandex/...)
#   make docs-translate DOCS_TRANSLATE_PROVIDER=anthropic   # needs ANTHROPIC_API_KEY
#   make docs-translate DOCS_TRANSLATE_PROVIDER=noop        # copy en (fast dry-run)
DOCS_VENV := build/docs-venv
DOCS_PY := $(DOCS_VENV)/bin/python
DOCS_TRANSLATE_PROVIDER ?= translators

.PHONY: docs-venv docs-translate docs-build docs-serve

# The venv's mkdocs binary doubles as the install stamp (built once). Installs
# both the build toolchain and the (local-only) translation deps.
$(DOCS_VENV)/bin/mkdocs:
	@python3 -m venv $(DOCS_VENV)
	@$(DOCS_VENV)/bin/pip install -q --upgrade pip
	@$(DOCS_VENV)/bin/pip install -q -r docs/requirements.txt -r tools/docs/requirements.txt

docs-venv: $(DOCS_VENV)/bin/mkdocs

# Release-time step: regenerate the committed docs/ru from docs/en. Commit the
# result with the release. NOT part of docs-build (CI builds the committed tree).
docs-translate: $(DOCS_VENV)/bin/mkdocs
	@DOCS_TRANSLATE_PROVIDER=$(DOCS_TRANSLATE_PROVIDER) $(DOCS_PY) -u tools/docs/translate.py --lang ru

docs-build: $(DOCS_VENV)/bin/mkdocs
	@$(DOCS_PY) -m mkdocs build --strict

docs-serve: $(DOCS_VENV)/bin/mkdocs
	@$(DOCS_PY) -m mkdocs serve
