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
	Infrastructure Public signals Streaming tmp vendor

# Former LB_DIRS entries that no longer exist under src/. Nothing is copied from
# them; lb_delete_files_list keeps their git deletions in the LB list, so an
# update still removes them from LBs installed by older releases.
LB_RETIRED_DIRS := resources www

# Root-level files to copy from MAIN to LB (not inside directories)
LB_ROOT_FILES := bootstrap.php console.php service update

# Directories to remove from LB (admin-only content)
LB_DIRS_TO_REMOVE := \
	bin/install \
	bin/redis \
	bin/cluster_bus \
	bin/nginx/conf/codes \
	Domain/User \
	Domain/Device \
	Domain/Cluster \
	Domain/Alert \
	Public/cluster \
	Public/Controllers/Admin \
	Public/Controllers/Player \
	Public/Controllers/PlayerV2 \
	Public/Controllers/Reseller \
	Public/Views \
	Public/assets \
	Public/routes \
	Core/Reference \
	Core/Localization/lang

# Files to remove from LB
# Every LB_DIRS / LB_ROOT_FILES / LB_DIRS_TO_REMOVE / LB_FILES_TO_REMOVE entry must
# name a tracked path under src/ of the right kind (a directory in the *DIRS*
# lists, a file in the *FILES* lists), and nothing that lb_configs/nginx.conf
# executes may be stripped — tools/ci/verify-lb-archive.sh (make gates) fails on a
# STALE or WRONG-LIST entry or a MISSING routed script.
# The viewer-API controllers (Player/Enigma2/XPlugin/Epg/PlaylistApiController,
# BaseApiController) still ship: lb_configs/nginx.conf routes /api/player_api etc.
# to Public/index.php, where viewer_api.conf answers 404 on every load balancer
# (they need the stripped Domain/User). They go together with those routes in a
# later phase (§3 of the MAIN <-> LB API communication design).
LB_FILES_TO_REMOVE := \
	Public/admin/api.php \
	Public/admin/proxy_api.php \
	Public/stream/auth.php \
	Public/stream/probe.php \
	Public/Controllers/Api/AdminApiController.php \
	Public/Controllers/Api/AdminAPIWrapper.php \
	Public/Controllers/Api/ActiveCodeApiController.php \
	Public/Controllers/Api/ResellerRestApiController.php \
	Public/Controllers/Api/ResellerAPIWrapper.php \
	Public/Controllers/Api/MetricsController.php \
	Cli/CronJobs/AlertsCronJob.php \
	Cli/CronJobs/RemindersCronJob.php \
	Infrastructure/ResellerApiDispatcher.php \
	Infrastructure/ResellerTableRenderer.php \
	config/rclone.conf \
	Cli/migration_logic.php \
	Cli/Commands/MigrateCommand.php \
	Cli/Commands/DbMigrateCommand.php \
	Cli/Commands/ModuleMigrateCommand.php \
	Cli/Commands/CacheHandlerCommand.php \
	Cli/Commands/ServerInstallCommand.php \
	Cli/Commands/ClusterInitCommand.php \
	Cli/Commands/ClusterExportKeysCommand.php \
	Cli/Commands/ClusterImportKeysCommand.php \
	Cli/Commands/ClusterPassphrase.php \
	Cli/Commands/ClusterDbAllowlistCommand.php \
	Cli/Commands/ClusterRotateStreamSecretCommand.php \
	Cli/Commands/ClusterRotateCredentialsCommand.php \
	Cli/Commands/ClusterLockdownCommand.php \
	Cli/Commands/ClusterRotateSignKeyCommand.php \
	Cli/Commands/ClusterStripCredentialsCommand.php \
	Cli/Commands/ClusterRotateDbPasswordCommand.php \
	Cli/Commands/ClusterMainDataplaneCommand.php \
	Cli/Commands/ClusterEnrolCodeCommand.php \
	Cli/Commands/ClusterEnrolApproveCommand.php \
	Cli/Commands/ClusterPoolsCommand.php \
	Cli/Commands/ClusterMaintainStatsCommand.php \
	Cli/Commands/ClusterNginxCommand.php \
	Cli/Commands/ClusterEndpointCommand.php \
	Cli/Commands/ServerEnrolCommand.php \
	Cli/Commands/ClusterReenrolCommand.php \
	Cli/Commands/SshChannel.php \
	Cli/Commands/SshSession.php \
	Cli/Commands/ServerSyncOpensslExtraCommand.php \
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
	bin/nginx/conf/gzip.conf \
	bin/nginx/conf/cluster_locations.conf

# Stripped trees that lb_delete_files_list must not delete from installed LBs yet.
# Fresh LBs never get them, but LB code on routes the LB nginx still serves calls
# them: Public/stream/rtmp.php (the RTMP on_play auth) and the viewer-API
# controllers use Domain/User. An older LB that still carries it keeps it until
# those routes go (§3 of the MAIN <-> LB API communication design).
LB_KEEP_ON_UPDATE := Domain/User

EXCLUDE_ARGS := $(addprefix --exclude=,$(EXCLUDES))

.PHONY: new lb main lb_copy_files main_copy_files set_permissions create_archive \
	lb_archive_move main_archive_move main_install_archive clean \
	verify_no_lfs_pointers \
	lb_delete_files_list generate_deleted_files \
	phpstan phpstan-baseline cs cs-fix check-procedural-use verify-lb-archive check-lb-settings-keys \
	check-core-cluster-refs gates \
	check-vendor-prod-only dev-tools dev-php dev-clean rector rector-fix

# ─── Dev tooling ────────────────────────────────────────────────
# The committed src/vendor/ is PRODUCTION-ONLY (composer install --no-dev). The
# dev tools below (PHPStan, phpcs) are require-dev packages — install them
# into src/vendor/ once with `make dev-tools` before running phpstan / cs. CI runs
# the equivalent `composer install` step itself. They are never committed (the
# committed vendor stays prod-only — see tools/ci/check-vendor-prod-only.sh).
dev-tools:
	@cd src && composer install --no-interaction

# System PHP 8.1 + the extensions the code, PHPStan and the test suite need
# (Ubuntu/Debian). Adds packages.sury.org when the distro has no php8.1-* (the
# ondrej PPA dropped new Ubuntu releases). Only versioned php8.1-* packages:
# unversioned ones (php-pcov, composer) pull the newest PHP and take over /usr/bin/php,
# so composer comes from getcomposer.org's installer (checksum-verified) instead.
DEV_PHP_PACKAGES := cli mysql mbstring curl xml intl zip gd gmp bcmath redis igbinary pcov
SUDO := $(if $(filter 0,$(shell id -u)),,sudo)

dev-php:
	@if ! apt-cache show php8.1-cli >/dev/null 2>&1; then \
		d=$$(mktemp -d) && \
		curl -sSLo $$d/keyring.deb https://packages.sury.org/debsuryorg-archive-keyring.deb && \
		$(SUDO) dpkg -i $$d/keyring.deb && \
		echo "deb [signed-by=/usr/share/keyrings/debsuryorg-archive-keyring.gpg] https://packages.sury.org/php/ $$(. /etc/os-release && echo $$VERSION_CODENAME) main" \
			| $(SUDO) tee /etc/apt/sources.list.d/php.list >/dev/null && \
		$(SUDO) apt-get update; \
		rc=$$?; rm -rf "$$d"; [ $$rc -eq 0 ] || exit $$rc; \
	fi
	$(SUDO) apt-get install -y $(addprefix php8.1-,$(DEV_PHP_PACKAGES))
	$(SUDO) update-alternatives --set php /usr/bin/php8.1
	@php -v | head -1
	@command -v composer >/dev/null 2>&1 || { \
		d=$$(mktemp -d) && \
		curl -sSLo $$d/composer-setup.php https://getcomposer.org/installer && \
		echo "$$(curl -sSL https://composer.github.io/installer.sig)  $$d/composer-setup.php" | sha384sum -c --quiet && \
		$(SUDO) php $$d/composer-setup.php --quiet --install-dir=/usr/local/bin --filename=composer; \
		rc=$$?; rm -rf "$$d"; exit $$rc; }
	@composer --version

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

# ─── Test database ──────────────────────────────────────────────
# The unit tests run on MariaDB, as production does (tests/Support/TestDb.php).
# A machine without a local server gets a throwaway one in Docker, the version CI
# runs, on 127.0.0.1:3306 only; TestDb connects there when no local socket exists.
# Its data lives in tmpfs: schemas are created and dropped per test, and on disk
# every DDL waits for an fsync.
TEST_DB_CONTAINER := xcvm-test-db
.PHONY: test-db test-db-stop
test-db:
	@docker start $(TEST_DB_CONTAINER) >/dev/null 2>&1 || docker run -d --name $(TEST_DB_CONTAINER) \
		-p 127.0.0.1:3306:3306 --tmpfs /var/lib/mysql:rw,size=2g -e MARIADB_ALLOW_EMPTY_ROOT_PASSWORD=1 \
		mariadb:11.4 --max-connections=1000 >/dev/null
	@until docker exec $(TEST_DB_CONTAINER) healthcheck.sh --connect --innodb_initialized >/dev/null 2>&1; do sleep 1; done
	@echo "test-db: MariaDB on 127.0.0.1:3306 (root, no password)"

test-db-stop:
	@docker rm -f $(TEST_DB_CONTAINER) >/dev/null 2>&1 || true

# The unit suite, against the test database (`make test-db` starts one; it is
# not started here). Extra arguments: make test ARGS="--filter SomeTest".
.PHONY: test
test:
	@php tests/phpunit.phar -c tests/phpunit.xml.dist $(ARGS)

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

# ─── Automated refactoring (Rector) ─────────────────────────────
# Rector is a require-dev tool (like phpstan/phpcs); config in build/rector.php.
# See docs/en/guides/refactoring.md. Always review a `rector` dry-run diff before
# `rector-fix`, then re-run cs-fix + phpstan + tests on the result.
RECTOR := src/vendor/bin/rector

# Dry-run — report what would change, never write. Non-zero exit when changes
# are pending, so it doubles as a CI check.
#
# --clear-cache is not optional here: Rector's cache key does not track the rule
# set, so after editing build/rector.php a cached run reports "Rector is done!"
# and a newly enabled set looks like it changes nothing.
rector:
	@test -x "$(RECTOR)" || { echo "Rector not found — run 'make dev-tools' (composer install) first."; exit 1; }
	@php "$(RECTOR)" process -c build/rector.php --dry-run --clear-cache

# Apply changes in place.
rector-fix:
	@test -x "$(RECTOR)" || { echo "Rector not found — run 'make dev-tools' (composer install) first."; exit 1; }
	@php "$(RECTOR)" process -c build/rector.php

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
# The settings a node replica may carry: what the LB build reads, never a secret.
check-lb-settings-keys:
	@bash tools/ci/lb-settings-keys.sh

# Core ships to load balancers, MAIN's cluster domain does not: every reference
# from src/Core/ to XcVm\Domain\Cluster must sit behind a class_exists() guard.
check-core-cluster-refs:
	@php tools/ci/check-core-cluster-refs.php

gates: check-procedural-use verify-lb-archive check-vendor-prod-only check-lb-settings-keys check-core-cluster-refs

# ─── Semgrep (same rulesets as .github/workflows/security-scan.yml) ───
# Scans PHP files changed vs main (override: make semgrep FILES="src/...").
# Needs Docker; pulls semgrep/semgrep on first run.
SEMGREP_CONFIGS := --config p/php --config p/security-audit --config p/command-injection \
	--config p/sql-injection --config p/xss
.PHONY: semgrep
semgrep:
	@files="$${FILES:-$$(git diff --name-only --diff-filter=d main -- 'src/*.php')}"; \
	test -n "$$files" || { echo "semgrep: no changed PHP files"; exit 0; }; \
	docker run --rm -v "$(CURDIR):/src" -w /src semgrep/semgrep \
		semgrep scan $(SEMGREP_CONFIGS) --severity ERROR --metrics=off --error $$files

# ─── Admin E2E (Playwright) ─────────────────────────────────────
# Browser smoke tests for the admin panel. Run against a LIVE instance:
# set XC_E2E_BASE_URL / XC_E2E_USER / XC_E2E_PASS (see tests/e2e/README.md).
.PHONY: e2e e2e-ui e2e-install
e2e-install:
	cd tests/e2e && npm ci && npx playwright install --with-deps chromium
e2e:
	cd tests/e2e && npx playwright test
e2e-ui:
	cd tests/e2e && npx playwright test --ui

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
main: main_copy_files stamp_release_id set_permissions verify_no_lfs_pointers create_archive main_archive_move main_install_archive clean

# ─── LoadBalancer targets ────────────────────────────────────────
lb: lb_copy_files lb_delete_files_list stamp_release_id set_permissions verify_no_lfs_pointers create_archive lb_archive_move clean

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

# Stamp a unique per-build watermark into the staged tree for provenance / leak
# tracing. Generated per build (NOT git-tracked, one file per archive). Runtime
# exposes it as the XC_VM_BUILD_ID constant (ConstantsInitializer reads
# RELEASE_ID from the deploy root); a source/dev checkout has no file -> "dev".
stamp_release_id:
	@ver=$$(sed -nE "s/.*define\('XC_VM_VERSION', '([^']+)'\).*/\1/p" $(MAIN_DIR)/Core/Config/ConstantsInitializer.php | head -n1); \
	sha=$$(git rev-parse --short=10 HEAD 2>/dev/null || echo nogit); \
	rnd=$$(od -An -N8 -tx1 /dev/urandom | tr -d ' \n'); \
	id="$${ver:-0}+$${sha}.$$(date -u +%Y%m%dT%H%M%SZ).$${rnd}"; \
	printf '%s\n' "$$id" > "$(TEMP_DIR)/RELEASE_ID"; \
	echo "==> [BUILD] Stamped RELEASE_ID: $$id"

# LB-scoped deletions applied on update. An update extracts the archive over the
# installed tree, and MigrationRunner::runFileCleanup() deletes only the files
# that migrations/deleted_files.txt lists. The list is therefore the LB-scoped
# part of the release's git deletions plus every file the LB build strips
# (LB_FILES_TO_REMOVE and the tracked files under LB_DIRS_TO_REMOVE), so a file
# newly stripped from the LB archive also leaves the LBs that already have it.
# Stripped paths are taken from the code trees only, minus LB_KEEP_ON_UPDATE:
# bin/, config/ and content/ hold runtime and per-server files that an update
# must never delete. tools/ci/verify-lb-archive.sh fails if the list names a
# file the LB archive ships (DELETES-SHIPPED).
lb_delete_files_list:
	@echo "[INFO] Building the LB deleted files list (git deletions + LB strip lists)"
	@mkdir -p "$(TEMP_DIR)/migrations"
	@{ \
		if [ -f "$(MAIN_DIR)/migrations/deleted_files.txt" ]; then \
			grep -v '^#' "$(MAIN_DIR)/migrations/deleted_files.txt" | grep -v '^$$' \
				| awk -v dirs="$(LB_DIRS) $(LB_RETIRED_DIRS)" -v files="$(LB_ROOT_FILES)" ' \
					BEGIN { n=split(dirs,d," "); m=split(files,f," ") } \
					{ ok=0; for(i=1;i<=n;i++) if(index($$0,d[i]"/")==1){ok=1;break} \
					  if(!ok) for(i=1;i<=m;i++) if($$0==f[i]){ok=1;break} \
					  if(ok) print }'; \
		fi; \
		{ \
			for f in $(LB_FILES_TO_REMOVE); do echo "$$f"; done; \
			for d in $(LB_DIRS_TO_REMOVE); do git ls-files -- "src/$$d" | sed 's#^src/##'; done; \
		} | grep -E '^(Cli|Core|Domain|Infrastructure|Public|Streaming)/' \
			| awk -v keep="$(LB_KEEP_ON_UPDATE)" ' \
				BEGIN { n=split(keep,k," ") } \
				{ for(i=1;i<=n;i++) if($$0==k[i] || index($$0,k[i]"/")==1) next; print }'; \
	} | sort -u > "$(TEMP_DIR)/migrations/deleted_files.txt"
	@if [ -s "$(TEMP_DIR)/migrations/deleted_files.txt" ]; then \
		echo "[INFO] LB files to delete on update: $$(grep -c . "$(TEMP_DIR)/migrations/deleted_files.txt")"; \
	else \
		echo "[INFO] No LB-scoped deleted files found"; \
		rm -f "$(TEMP_DIR)/migrations/deleted_files.txt"; \
		rmdir "$(TEMP_DIR)/migrations" 2>/dev/null || true; \
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
#
# Panel language files (src/Core/Localization/lang/*.ini) use the same script:
#   make lang-translate                 # every <lang>.ini: translate keys missing vs
#                                       # en.ini, drop keys en.ini no longer has
#   make lang-translate LANG_TRANSLATE=ru
DOCS_VENV := build/docs-venv
DOCS_PY := $(DOCS_VENV)/bin/python
DOCS_TRANSLATE_PROVIDER ?= translators
LANG_TRANSLATE ?= all

.PHONY: docs-venv docs-translate docs-build docs-serve lang-translate

# Install stamp keyed on the system python3 minor version and the requirement
# files: a distro python upgrade (the venv symlinks /usr/bin/python3 and loses its
# site-packages) or a new requirement rebuilds the venv from scratch. Installs
# both the build toolchain and the (local-only) translation deps.
DOCS_STAMP := $(DOCS_VENV)/.installed-py$(shell python3 -c 'import sys; print("%d.%d" % sys.version_info[:2])')
$(DOCS_STAMP): docs/requirements.txt tools/i18n/requirements.txt
	@python3 -m venv --clear $(DOCS_VENV)
	@$(DOCS_VENV)/bin/pip install -q --upgrade pip
	@$(DOCS_VENV)/bin/pip install -q -r docs/requirements.txt -r tools/i18n/requirements.txt
	@touch $@

docs-venv: $(DOCS_STAMP)

# Release-time step: regenerate the committed docs/ru from docs/en. Commit the
# result with the release. NOT part of docs-build (CI builds the committed tree).
docs-translate: $(DOCS_STAMP)
	@DOCS_TRANSLATE_PROVIDER=$(DOCS_TRANSLATE_PROVIDER) $(DOCS_PY) -u tools/i18n/translate.py --lang ru

lang-translate: $(DOCS_STAMP)
	@DOCS_TRANSLATE_PROVIDER=$(DOCS_TRANSLATE_PROVIDER) $(DOCS_PY) -u tools/i18n/translate.py --ini --lang $(LANG_TRANSLATE)

docs-build: $(DOCS_STAMP)
	@$(DOCS_PY) -m mkdocs build --strict

docs-serve: $(DOCS_STAMP)
	@$(DOCS_PY) -m mkdocs serve
