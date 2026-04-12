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
LAST_TAG := $(shell curl -s https://api.github.com/repos/Vateron-Media/XC_VM/releases/latest | grep '"tag_name":' | sed -E 's/.*"([^"]+)".*/\1/')
HASH_FILE := hashes.md5

# Directories and files to exclude from archives
EXCLUDES := \
	.git

# Directories to copy from MAIN to LB
# NOTE: modules/ is intentionally excluded — all modules are MAIN-only.
# Modules: tmdb, plex, watch, ministra, fingerprint, theft-detection, magscan
LB_DIRS := bin cli config content core domain includes \
	infrastructure resources signals streaming tmp www

# Root-level files to copy from MAIN to LB (not inside directories)
LB_ROOT_FILES := autoload.php bootstrap.php console.php service update

# Directories to remove from LB (admin-only content)
LB_DIRS_TO_REMOVE := \
	bin/install \
	bin/redis \
	bin/nginx/conf/codes \
	includes/api \
	includes/libs/resources \
	domain/User \
	domain/Device \
	domain/Auth \
	resources/langs \
	resources/libs

# Files to remove from LB
LB_FILES_TO_REMOVE := \
	bin/maxmind/GeoLite2-City.mmdb \
	infrastructure/legacy/reseller_api.php \
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
	cli/Commands/MigrateCommand.php \
	cli/Commands/CacheHandlerCommand.php \
	cli/Commands/BalancerCommand.php \
	cli/CronJobs/RootMysqlCronJob.php \
	cli/CronJobs/BackupsCronJob.php \
	cli/CronJobs/CacheEngineCronJob.php \
	cli/CronJobs/EpgCronJob.php \
	cli/CronJobs/UpdateCronJob.php \
	cli/CronJobs/ProvidersCronJob.php \
	cli/CronJobs/SeriesCronJob.php \
	domain/Epg/EPG.php \
	bin/nginx/conf/gzip.conf

EXCLUDE_ARGS := $(addprefix --exclude=,$(EXCLUDES))

.PHONY: new lb main lb_copy_files main_copy_files set_permissions create_archive \
	lb_archive_move main_archive_move main_install_archive clean \
	delete_files_list lb_delete_files_list generate_deleted_files

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
		git diff --name-status "$(LAST_TAG)"..HEAD -- src/ \
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
main: main_copy_files delete_files_list set_permissions create_archive main_archive_move main_install_archive clean

# ─── LoadBalancer targets ────────────────────────────────────────
lb: lb_copy_files lb_delete_files_list set_permissions create_archive lb_archive_move clean

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
		printf "   → Copying: %s\n" "$$file"; \
		mkdir -p "$(TEMP_DIR)/$$(dirname $$rel)"; \
		cp "$$file" "$(TEMP_DIR)/$$rel"; \
	done

	@echo "Remove all .gitkeep files..."
	@find $(TEMP_DIR) -name .gitkeep \
		-not -path "*/.git/*" \
		-delete
	@echo "All files gitkeep deleted"

delete_files_list:
	@echo "[INFO] Checking for manual deleted files list"
	@if [ -f "$(MAIN_DIR)/migrations/deleted_files.txt" ]; then \
		mkdir -p "$(TEMP_DIR)/migrations"; \
		cp "$(MAIN_DIR)/migrations/deleted_files.txt" "$(TEMP_DIR)/migrations/deleted_files.txt"; \
		echo "[INFO] Files to delete on update:"; \
		grep -v '^#' "$(TEMP_DIR)/migrations/deleted_files.txt" | grep -v '^$$' || true; \
	else \
		echo "[INFO] No deleted_files.txt found — skipping"; \
	fi

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

clean:
	@echo "==> Cleaning up temporary directory: $(TEMP_DIR)"
	@rm -rf $(TEMP_DIR)
	@echo "✅ Project build complete"

new:
	@echo "==> Cleaning up distribution directory: $(DIST_DIR)"
	@rm -rf $(DIST_DIR)
	@echo "==> Creating distribution directory: $(DIST_DIR)"
	@mkdir -p ${DIST_DIR}
