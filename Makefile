PROJECT_NAME := $(notdir $(CURDIR))
TIMESTAMP := $(shell date +%s)
TEMP_DIR := /tmp/XC_VM-$(TIMESTAMP)
MAIN_DIR = ./src
DIST_DIR = ./dist
CONFIG_DIR := ./lb_configs
TEMP_ARCHIVE_NAME := $(TIMESTAMP).tar.gz
MAIN_ARCHIVE_NAME := xc_vm.tar.gz
MAIN_UPDATE_ARCHIVE_NAME := update.tar.gz
MAIN_ARCHIVE_INSTALLER := XC_VM.zip
LB_ARCHIVE_NAME := loadbalancer.tar.gz
LB_UPDATE_ARCHIVE_NAME := loadbalancer_update.tar.gz
LAST_TAG := $(shell curl -s https://api.github.com/repos/Vateron-Media/XC_VM/releases/latest | grep '"tag_name":' | sed -E 's/.*"([^"]+)".*/\1/')
HASH_FILE := hashes.md5

# Directories and files to exclude (can be easily edited)
EXCLUDES := \
	.git

# Directories to copy from MAIN to LB
LB_DIRS := bin cli config content core domain includes \
	infrastructure resources signals streaming tmp www

# Root-level files to copy from MAIN to LB (not inside directories)
LB_ROOT_FILES := autoload.php bootstrap.php console.php service status update

# Directories to remove from LB (admin-only content)
LB_DIRS_TO_REMOVE := \
	bin/install \
	bin/redis \
	bin/nginx/conf/codes \
	includes/langs \
	includes/api \
	includes/libs/resources \
	includes/bootstrap \
	domain/User \
	domain/Device \
	domain/Auth \
	resources/langs \
	resources/libs

# Files to remove from LB
LB_FILES_TO_REMOVE := \
	bin/maxmind/GeoLite2-City.mmdb \
	includes/admin_api.php \
	includes/admin.php \
	includes/reseller_api.php \
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
	cli/CronJobs/TmdbCronJob.php \
	cli/CronJobs/TmdbPopularCronJob.php \
	domain/Epg/EPG.php \
	bin/nginx/conf/gzip.conf

EXCLUDE_ARGS := $(addprefix --exclude=,$(EXCLUDES))

.PHONY: new lb main main_update lb_update lb_copy_files lb_update_copy_files main_copy_files main_update_copy_files set_permissions create_archive lb_archive_move lb_update_archive_move main_archive_move main_update_archive_move main_install_archive clean delete_files_list lb_delete_files_list

lb: lb_copy_files set_permissions create_archive lb_archive_move clean
main: main_copy_files set_permissions create_archive main_archive_move main_install_archive clean
main_update: main_update_copy_files delete_files_list set_permissions create_archive main_update_archive_move clean
lb_update: lb_update_copy_files lb_delete_files_list set_permissions create_archive lb_update_archive_move clean

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
# 			printf "   → Copying: %s\n" "$$root_file"; \
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

lb_update_copy_files:
	@echo "[INFO] Using last tag: $(LAST_TAG)"
	@echo "[INFO] Checking for changes in 'src/' from $(LAST_TAG) to HEAD..."

	@echo "[INFO] Preparing output directories"
	@mkdir -p $(DIST_DIR)
	@mkdir -p $(TEMP_DIR)

	@echo "[INFO] Copying modified or added files from 'src/' that are in LB scope..."
	@for file in $$(git diff --name-only --diff-filter=AMR $(LAST_TAG)..HEAD | grep '^src/'); do \
		rel_path=$$(echo "$$file" | sed 's|^src/||'); \
		allowed=0; \
		for lb_item in $(LB_DIRS); do \
			if echo "$$rel_path" | grep -q "^$$lb_item/"; then \
				allowed=1; \
				break; \
			fi; \
		done; \
		if [ "$$allowed" -eq 0 ]; then \
			for root_file in $(LB_ROOT_FILES); do \
				if [ "$$rel_path" = "$$root_file" ]; then \
					allowed=1; \
					break; \
				fi; \
			done; \
		fi; \
		if [ "$$allowed" -eq 1 ] && [ -f "$$file" ]; then \
			echo "[COPY] $$file -> $(TEMP_DIR)/$$rel_path"; \
			mkdir -p "$(TEMP_DIR)/$$(dirname $$rel_path)"; \
			cp "$$file" "$(TEMP_DIR)/$$rel_path"; \
		else \
			echo "[SKIP] $$file (not in LB scope)"; \
		fi \
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
	@# Copy only files tracked by git under src/
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

main_update_copy_files:
	@echo "[INFO] Using last tag: $(LAST_TAG)"
	@echo "[INFO] Checking for changes in 'src/' from $(LAST_TAG) to HEAD..."

	@echo "[INFO] Preparing output directories"
	@mkdir -p $(DIST_DIR)
	@mkdir -p $(TEMP_DIR)

	@echo "[INFO] Copying modified or added files from 'src/'..."
	@for file in $$(git diff --name-only --diff-filter=AMR $(LAST_TAG)..HEAD | grep '^src/'); do \
		rel_path=$$(echo "$$file" | sed 's|^src/||'); \
		if [ -f "$$file" ]; then \
			echo "[COPY] $$file -> $(TEMP_DIR)/$$rel_path"; \
			mkdir -p "$(TEMP_DIR)/$$(dirname "$$rel_path")"; \
			cp "$$file" "$(TEMP_DIR)/$$rel_path"; \
		fi \
	done

	@echo "Remove all .gitkeep files..."
	@find $(TEMP_DIR) -name .gitkeep \
		-not -path "*/.git/*" \
		-delete
	@echo "All files gitkeep deleted"

delete_files_list:
	@echo "[INFO] Generating deleted files list from $(LAST_TAG) to HEAD"
	@if [ -z "$(LAST_TAG)" ]; then \
		echo "[ERROR] LAST_TAG is empty — cannot generate deleted files list"; \
		exit 1; \
	fi
	@mkdir -p $(TEMP_DIR)/migrations
	@git diff --name-status --diff-filter=DR $(LAST_TAG)..HEAD \
		| cut -f2 | grep '^src/' | sed 's|^src/||' | sort -u \
		> $(TEMP_DIR)/migrations/deleted_files.txt
	@if [ -s $(TEMP_DIR)/migrations/deleted_files.txt ]; then \
		echo "[INFO] Files to delete on update:"; \
		cat $(TEMP_DIR)/migrations/deleted_files.txt; \
	else \
		echo "[INFO] No deleted files found"; \
		rm -f $(TEMP_DIR)/migrations/deleted_files.txt; \
	fi

lb_delete_files_list:
	@echo "[INFO] Generating LB-scoped deleted files list from $(LAST_TAG) to HEAD"
	@if [ -z "$(LAST_TAG)" ]; then \
		echo "[ERROR] LAST_TAG is empty — cannot generate deleted files list"; \
		exit 1; \
	fi
	@mkdir -p $(TEMP_DIR)/migrations
	@git diff --name-status --diff-filter=DR $(LAST_TAG)..HEAD \
		| cut -f2 | grep '^src/' | sed 's|^src/||' | sort -u \
		| awk -v dirs="$(LB_DIRS)" -v files="$(LB_ROOT_FILES)" ' \
			BEGIN { n=split(dirs,d," "); m=split(files,f," ") } \
			{ ok=0; for(i=1;i<=n;i++) if(index($$0,d[i]"/")==1){ok=1;break} \
			  if(!ok) for(i=1;i<=m;i++) if($$0==f[i]){ok=1;break} \
			  if(ok) print }' \
		> $(TEMP_DIR)/migrations/deleted_files.txt
	@if [ -s $(TEMP_DIR)/migrations/deleted_files.txt ]; then \
		echo "[INFO] LB files to delete on update:"; \
		cat $(TEMP_DIR)/migrations/deleted_files.txt; \
	else \
		echo "[INFO] No LB-scoped deleted files found"; \
		rm -f $(TEMP_DIR)/migrations/deleted_files.txt; \
	fi

set_permissions:
	@echo "==> Setting file and directory permissions"

	@if [ -d "$(TEMP_DIR)/public" ]; then \
		find "$(TEMP_DIR)/public" -type d -exec chmod 755 {} +; \
		find "$(TEMP_DIR)/public" -type f -exec chmod 644 {} +; \
	fi

	# /backups
	chmod 0750 $(TEMP_DIR)/backups 2>/dev/null || true

	# /bin
	chmod 0750 $(TEMP_DIR)/bin 2>/dev/null || true
	chmod 0775 $(TEMP_DIR)/bin/certbot 2>/dev/null || true

	chmod 0755 $(TEMP_DIR)/bin/ffmpeg_bin 2>/dev/null || true
	chmod 0755 $(TEMP_DIR)/bin/ffmpeg_bin/4.0 2>/dev/null || true
	chmod 0755 $(TEMP_DIR)/bin/ffmpeg_bin/4.3 2>/dev/null || true
	chmod 0755 $(TEMP_DIR)/bin/ffmpeg_bin/4.4 2>/dev/null || true
	chmod 0755 $(TEMP_DIR)/bin/ffmpeg_bin/5.1 2>/dev/null || true
	chmod 0755 $(TEMP_DIR)/bin/ffmpeg_bin/7.1 2>/dev/null || true
	chmod 0755 $(TEMP_DIR)/bin/ffmpeg_bin/8.0 2>/dev/null || true
	chmod 0551 $(TEMP_DIR)/bin/ffmpeg_bin/4.0/ffmpeg 2>/dev/null || true
	chmod 0551 $(TEMP_DIR)/bin/ffmpeg_bin/4.0/ffprobe 2>/dev/null || true
	chmod 0551 $(TEMP_DIR)/bin/ffmpeg_bin/4.3/ffmpeg 2>/dev/null || true
	chmod 0551 $(TEMP_DIR)/bin/ffmpeg_bin/4.3/ffprobe 2>/dev/null || true
	chmod 0551 $(TEMP_DIR)/bin/ffmpeg_bin/4.4/ffmpeg 2>/dev/null || true
	chmod 0551 $(TEMP_DIR)/bin/ffmpeg_bin/4.4/ffprobe 2>/dev/null || true
	chmod 0551 $(TEMP_DIR)/bin/ffmpeg_bin/5.1/ffmpeg 2>/dev/null || true
	chmod 0551 $(TEMP_DIR)/bin/ffmpeg_bin/5.1/ffprobe 2>/dev/null || true
	chmod 0551 $(TEMP_DIR)/bin/ffmpeg_bin/7.1/ffmpeg 2>/dev/null || true
	chmod 0551 $(TEMP_DIR)/bin/ffmpeg_bin/7.1/ffprobe 2>/dev/null || true
	chmod 0551 $(TEMP_DIR)/bin/ffmpeg_bin/8.0/ffmpeg 2>/dev/null || true
	chmod 0551 $(TEMP_DIR)/bin/ffmpeg_bin/8.0/ffprobe 2>/dev/null || true

	chmod 0775 $(TEMP_DIR)/bin/install 2>/dev/null || true
	chmod 0644 $(TEMP_DIR)/bin/install/database.sql 2>/dev/null || true
	chmod 0644 $(TEMP_DIR)/bin/install/proxy.tar.gz 2>/dev/null || true

	chmod 0750 $(TEMP_DIR)/bin/maxmind 2>/dev/null || true
	chmod 0750 $(TEMP_DIR)/bin/maxmind/GeoIP2-ISP.mmdb 2>/dev/null || true
	chmod 0750 $(TEMP_DIR)/bin/maxmind/GeoLite2-City.mmdb 2>/dev/null || true
	chmod 0750 $(TEMP_DIR)/bin/maxmind/GeoLite2-Country.mmdb 2>/dev/null || true
	chmod 0750 $(TEMP_DIR)/bin/maxmind/version.json 2>/dev/null || true
	chmod 0550 $(TEMP_DIR)/bin/maxmind/cidr.db 2>/dev/null || true

	find $(TEMP_DIR)/bin/nginx -type d -exec chmod 750 {} \; 2>/dev/null || true
	find $(TEMP_DIR)/bin/nginx -type f -exec chmod 550 {} \; 2>/dev/null || true
	chmod 0755 $(TEMP_DIR)/bin/nginx/conf 2>/dev/null || true
	chmod 0644 $(TEMP_DIR)/bin/nginx/conf/server.crt 2>/dev/null || true
	chmod 0600 $(TEMP_DIR)/bin/nginx/conf/server.key 2>/dev/null || true
	chmod 0755 $(TEMP_DIR)/bin/nginx_rtmp/conf 2>/dev/null || true

	find $(TEMP_DIR)/bin/php -type d -exec chmod 750 {} \; 2>/dev/null || true
	find $(TEMP_DIR)/bin/php -type f -exec chmod 550 {} \; 2>/dev/null || true
	chmod 0750 $(TEMP_DIR)/bin/php/etc 2>/dev/null || true
	chmod 0644 $(TEMP_DIR)/bin/php/etc/1.conf 2>/dev/null || true
	chmod 0644 $(TEMP_DIR)/bin/php/etc/2.conf 2>/dev/null || true
	chmod 0644 $(TEMP_DIR)/bin/php/etc/3.conf 2>/dev/null || true
	chmod 0644 $(TEMP_DIR)/bin/php/etc/4.conf 2>/dev/null || true
	chmod 0750 $(TEMP_DIR)/bin/php/sessions 2>/dev/null || true
	chmod 0750 $(TEMP_DIR)/bin/php/sockets 2>/dev/null || true
	find $(TEMP_DIR)/bin/php/var -type d -exec chmod 750 {} \; 2>/dev/null || true
	chmod 0551 $(TEMP_DIR)/bin/php/bin/php 2>/dev/null || true
	chmod 0551 $(TEMP_DIR)/bin/php/sbin/php-fpm 2>/dev/null || true

	chmod 0755 $(TEMP_DIR)/bin/php/lib/php/extensions/no-debug-non-zts-20210902 2>/dev/null || true

	chmod 0755 $(TEMP_DIR)/bin/redis 2>/dev/null || true
	chmod 0755 $(TEMP_DIR)/bin/redis/redis-server 2>/dev/null || true

	chmod 0750 $(TEMP_DIR)/bin/daemons.sh 2>/dev/null || true
	chmod 0755 $(TEMP_DIR)/bin/guess 2>/dev/null || true
	chmod 0550 $(TEMP_DIR)/bin/free-sans.ttf 2>/dev/null || true
	chmod 0550 $(TEMP_DIR)/bin/network 2>/dev/null || true
	chmod 0550 $(TEMP_DIR)/bin/network.py 2>/dev/null || true
	chmod 0755 $(TEMP_DIR)/bin/yt-dlp 2>/dev/null || true

	# /content
	chmod 0750 $(TEMP_DIR)/content 2>/dev/null || true
	find $(TEMP_DIR)/content -exec chmod 750 {} \; 2>/dev/null || true
	chmod 0755 $(TEMP_DIR)/content/epg 2>/dev/null || true
	chmod 0755 $(TEMP_DIR)/content/playlists 2>/dev/null || true
	chmod 0770 $(TEMP_DIR)/content/streams 2>/dev/null || true

	# /includes (PHP read by php-fpm)
	chmod 0755 $(TEMP_DIR)/includes 2>/dev/null || true
	find $(TEMP_DIR)/includes -type d -exec chmod 755 {} \; 2>/dev/null || true
	find $(TEMP_DIR)/includes -type f -exec chmod 644 {} \; 2>/dev/null || true

	# New architecture directories (PHP code: 644, dirs: 755)
	@for arch_dir in core domain streaming infrastructure resources cli crons modules migrations; do \
		if [ -d "$(TEMP_DIR)/$$arch_dir" ]; then \
			find "$(TEMP_DIR)/$$arch_dir" -type d -exec chmod 755 {} +; \
			find "$(TEMP_DIR)/$$arch_dir" -type f -exec chmod 644 {} +; \
		fi; \
	done

	# Root-level PHP files
	chmod 0644 $(TEMP_DIR)/autoload.php 2>/dev/null || true
	chmod 0644 $(TEMP_DIR)/bootstrap.php 2>/dev/null || true
	chmod 0644 $(TEMP_DIR)/console.php 2>/dev/null || true

	@if [ -d "$(TEMP_DIR)/ministra" ]; then \
		chmod 0755 $(TEMP_DIR)/ministra; \
		find $(TEMP_DIR)/ministra -type d -exec chmod 755 {} +; \
		find $(TEMP_DIR)/ministra -type f -exec chmod 644 {} +; \
		chmod 0644 $(TEMP_DIR)/ministra/portal.php 2>/dev/null || true; \
	fi

	@if [ -d "$(TEMP_DIR)/player" ]; then \
		find $(TEMP_DIR)/player -type d -exec chmod 755 {} +; \
		find $(TEMP_DIR)/player -type f -exec chmod 644 {} +; \
	fi

	@if [ -d "$(TEMP_DIR)/reseller" ]; then \
		chmod 0755 $(TEMP_DIR)/reseller; \
		find $(TEMP_DIR)/reseller -type d -exec chmod 755 {} +; \
		find $(TEMP_DIR)/reseller -type f -exec chmod 644 {} +; \
	fi

	find $(TEMP_DIR)/tmp -type d -exec chmod 755 {} \; 2>/dev/null || true

	# /www — web entry points (read by php-fpm, dirs traversable)
	chmod 0755 $(TEMP_DIR)/www 2>/dev/null || true
	find $(TEMP_DIR)/www -type d -exec chmod 755 {} \; 2>/dev/null || true
	find $(TEMP_DIR)/www -type f -name '*.php' -exec chmod 0644 {} \; 2>/dev/null || true
	find $(TEMP_DIR)/www -type f -name '*.html' -exec chmod 0644 {} \; 2>/dev/null || true
	chmod 0755 $(TEMP_DIR)/www/images 2>/dev/null || true
	chmod 0755 $(TEMP_DIR)/www/images/admin 2>/dev/null || true
	chmod 0755 $(TEMP_DIR)/www/images/enigma2 2>/dev/null || true
	chmod 0644 $(TEMP_DIR)/www/images/admin/index.html 2>/dev/null || true
	chmod 0644 $(TEMP_DIR)/www/images/enigma2/index.html 2>/dev/null || true
	chmod 0644 $(TEMP_DIR)/www/images/index.html 2>/dev/null || true

	# Root-level executables
	chmod 0750 $(TEMP_DIR)/service 2>/dev/null || true
	chmod 0750 $(TEMP_DIR)/status 2>/dev/null || true
	chmod 0755 $(TEMP_DIR)/tmp 2>/dev/null || true
	chmod 0750 $(TEMP_DIR)/tools 2>/dev/null || true
	chmod 0750 $(TEMP_DIR)/update 2>/dev/null || true
	chmod 0750 $(TEMP_DIR)/signals 2>/dev/null || true

	chmod 0750 $(TEMP_DIR)/config 2>/dev/null || true
	chmod 0640 $(TEMP_DIR)/config/modules.php 2>/dev/null || true
	chmod 0550 $(TEMP_DIR)/config/rclone.conf 2>/dev/null || true

	chmod 0750 $(TEMP_DIR)/bin/nginx_rtmp/sbin/nginx_rtmp 2>/dev/null || true

create_archive:
	@echo "==> Creating final archive: ${TEMP_ARCHIVE_NAME}"
	@tar -czf ${DIST_DIR}/${TEMP_ARCHIVE_NAME} -C $(TEMP_DIR) .

lb_archive_move:
	@echo "==> Moving LB archive to: ${DIST_DIR}/${LB_ARCHIVE_NAME}"
	@rm -f ${DIST_DIR}/${LB_ARCHIVE_NAME}
	@mv ${DIST_DIR}/${TEMP_ARCHIVE_NAME} ${DIST_DIR}/${LB_ARCHIVE_NAME}
	md5sum "${DIST_DIR}/${LB_ARCHIVE_NAME}" | awk -v name="${LB_ARCHIVE_NAME}" '{print $$1, name}' >> "${DIST_DIR}/${HASH_FILE}"

lb_update_archive_move:
	@echo "==> Moving LB update archive to: ${DIST_DIR}/${LB_UPDATE_ARCHIVE_NAME}"
	@rm -f ${DIST_DIR}/${LB_UPDATE_ARCHIVE_NAME}
	@mv ${DIST_DIR}/${TEMP_ARCHIVE_NAME} ${DIST_DIR}/${LB_UPDATE_ARCHIVE_NAME}
	md5sum "${DIST_DIR}/${LB_UPDATE_ARCHIVE_NAME}" | awk -v name="${LB_UPDATE_ARCHIVE_NAME}" '{print $$1, name}' >> "${DIST_DIR}/${HASH_FILE}"

main_archive_move:
	@echo "==> Moving MAIN archive to: ${DIST_DIR}/${MAIN_ARCHIVE_NAME}"
	@rm -f ${DIST_DIR}/${MAIN_ARCHIVE_NAME}
	@mv ${DIST_DIR}/${TEMP_ARCHIVE_NAME} ${DIST_DIR}/${MAIN_ARCHIVE_NAME}

main_update_archive_move:
	@echo "==> Moving MAIN update archive to: ${DIST_DIR}/${MAIN_UPDATE_ARCHIVE_NAME}"
	@rm -f ${DIST_DIR}/${MAIN_UPDATE_ARCHIVE_NAME}
	@mv ${DIST_DIR}/${TEMP_ARCHIVE_NAME} ${DIST_DIR}/${MAIN_UPDATE_ARCHIVE_NAME}
	md5sum "${DIST_DIR}/${MAIN_UPDATE_ARCHIVE_NAME}" | awk -v name="${MAIN_UPDATE_ARCHIVE_NAME}" '{print $$1, name}' >> "${DIST_DIR}/${HASH_FILE}"

main_install_archive:
	@echo "==> Creating installer archive: ${DIST_DIR}/${MAIN_ARCHIVE_INSTALLER}"
	@rm -f ${DIST_DIR}/${MAIN_ARCHIVE_INSTALLER}
	@zip -r ${DIST_DIR}/${MAIN_ARCHIVE_INSTALLER} install && zip -j ${DIST_DIR}/${MAIN_ARCHIVE_INSTALLER} ${DIST_DIR}/${MAIN_ARCHIVE_NAME}
	@echo "==> Remove archive: ${DIST_DIR}/${MAIN_ARCHIVE_NAME}"
	rm -rf ${DIST_DIR}/${MAIN_ARCHIVE_NAME}

clean:
	@echo "==> Cleaning up temporary directory: $(TEMP_DIR)"
	@rm -rf $(TEMP_DIR)
	@echo "✅ Project build complete"
	
new:
	@echo "==> Cleaning up temporary directory: $(DIST_DIR)"
	@rm -rf $(DIST_DIR)
	@echo "==> [LB] Creating distribution directory: $(DIST_DIR)"
	@mkdir -p ${DIST_DIR}
