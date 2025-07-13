PROJECT_NAME := $(notdir $(CURDIR))
TIMESTAMP := $(shell date +%s)
TEMP_DIR := /tmp/XC_VM-$(TIMESTAMP)
MAIN_DIR = ./src
DIST_DIR = ./dist
LB_ARCHIVE_DIR := $(MAIN_DIR)/bin/install
CONFIG_DIR := ./lb_configs
TEMP_ARCHIVE_NAME := $(TIMESTAMP).tar.gz
MAIN_ARCHIVE_NAME := xc_vm.tar.gz
MAIN_ARCHIVE_INSTALLER := XC_VM.zip
LB_ARCHIVE_NAME := loadbalancer.tar.gz

# Directories and files to exclude (can be easily edited)
EXCLUDES := \
	.git

# Files and directories to copy from MAIN to LB
LB_FILES := bin config content crons includes signals tmp www status update service

# Directories to remove from LB
LB_DIRS_TO_REMOVE := \
	bin/install \
	bin/redis \
	includes/langs \
	includes/api \
	includes/libs/resources \
	bin/nginx/conf/codes

# Files to remove from LB
LB_FILES_TO_REMOVE := \
	bin/maxmind/GeoLite2-City.mmdb \
	crons/backups.php \
	crons/cache_engine.php \
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
	crons/epg.php \
	crons/update.php \
	crons/providers.php \
	crons/root_mysql.php \
	crons/series.php \
	crons/tmdb.php \
	crons/tmdb_popular.php \
	includes/cli/migrate.php \
	includes/cli/cache_handler.php \
	includes/cli/balancer.php \
	bin/nginx/conf/gzip.conf

EXCLUDE_ARGS := $(addprefix --exclude=,$(EXCLUDES))

.PHONY: all main lb lb_copy_files main_copy_files set-permissions create_archive lb_archive_move main_archive_move clean

lb: lb_copy_files set-permissions create_archive lb_archive_move clean
main: main_copy_files set-permissions create_archive main_archive_move main_install_archive clean

lb_copy_files:
	@echo "==> [LB] Creating distribution directory: $(DIST_DIR)"
	@mkdir -p ${DIST_DIR}
	@echo "==> [LB] Creating temporary directory: $(TEMP_DIR)"
	@mkdir -p ${TEMP_DIR}

	@echo "==> [LB] Copying files from MAIN_DIR"
	@for item in $(LB_FILES); do \
		echo "   → Copying: $$item"; \
		cp -r "$(MAIN_DIR)/$$item" "$(TEMP_DIR)"; \
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

	@echo "==> [MAIN] Copying files from $(MAIN_DIR)"
	@if command -v rsync >/dev/null 2>&1; then \
		echo "   → Using rsync..."; \
		rsync -a $(EXCLUDE_ARGS) $(MAIN_DIR)/ $(TEMP_DIR)/; \
	else \
		echo "⚠️  rsync not found, falling back to tar..."; \
		tar cf - $(EXCLUDE_ARGS) -C $(MAIN_DIR) . | tar xf - -C $(TEMP_DIR); \
	fi

	@echo "Remove all .gitkeep files..."
	@find $(TEMP_DIR) -name .gitkeep \
		-not -path "*/.git/*" \
		-delete
	@echo "All files gitkeep deleted"

set-permissions:
	@echo "==> Setting file and directory permissions"

	@if [ -d "$(TEMP_DIR)/admin" ]; then \
		# /admin \
		find "$(TEMP_DIR)/admin" -type d -exec chmod 755 {} +; \
		find "$(TEMP_DIR)/admin" -type f -exec chmod 644 {} +; \
	fi

	# /backups
	chmod 0750 $(TEMP_DIR)/backups 2>/dev/null || [ $$? -eq 1 ]

	# /bin
	chmod 0750 $(TEMP_DIR)/bin
	chmod 0775 $(TEMP_DIR)/bin/certbot

	chmod 0755 $(TEMP_DIR)/bin/ffmpeg_bin
	chmod 0755 $(TEMP_DIR)/bin/ffmpeg_bin/4.0
	chmod 0755 $(TEMP_DIR)/bin/ffmpeg_bin/4.3
	chmod 0755 $(TEMP_DIR)/bin/ffmpeg_bin/4.4
	chmod 0551 $(TEMP_DIR)/bin/ffmpeg_bin/4.0/ffmpeg
	chmod 0551 $(TEMP_DIR)/bin/ffmpeg_bin/4.0/ffprobe
	chmod 0551 $(TEMP_DIR)/bin/ffmpeg_bin/4.3/ffmpeg
	chmod 0551 $(TEMP_DIR)/bin/ffmpeg_bin/4.3/ffprobe
	chmod 0551 $(TEMP_DIR)/bin/ffmpeg_bin/4.4/ffmpeg
	chmod 0551 $(TEMP_DIR)/bin/ffmpeg_bin/4.4/ffprobe

	chmod 0775 $(TEMP_DIR)/bin/install 2>/dev/null || [ $$? -eq 1 ]
	chmod 0644 $(TEMP_DIR)/bin/install/proxy.tar.gz 2>/dev/null || [ $$? -eq 1 ]
	chmod 0644 $(TEMP_DIR)/bin/install/database.sql 2>/dev/null || [ $$? -eq 1 ]
	chmod 0644 $(TEMP_DIR)/bin/install/loadbalancer.tar.gz 2>/dev/null || [ $$? -eq 1 ]
	chmod 0550 $(TEMP_DIR)/bin/install/loadbalancer_update.tar.gz 2>/dev/null || [ $$? -eq 1 ]

	chmod 0750 $(TEMP_DIR)/bin/maxmind
	chmod 0750 $(TEMP_DIR)/bin/maxmind/GeoIP2-ISP.mmdb
	chmod 0750 $(TEMP_DIR)/bin/maxmind/GeoLite2-City.mmdb 2>/dev/null || [ $$? -eq 1 ]
	chmod 0750 $(TEMP_DIR)/bin/maxmind/GeoLite2.mmdb
	chmod 0750 $(TEMP_DIR)/bin/maxmind/version.json
	chmod 0550 $(TEMP_DIR)/bin/maxmind/cidr.db

	find $(TEMP_DIR)/bin/nginx -type d -exec chmod 750 {} \;
	find $(TEMP_DIR)/bin/nginx -type f -exec chmod 550 {} \;
	chmod 0755 $(TEMP_DIR)/bin/nginx/conf
	chmod 0755 $(TEMP_DIR)/bin/nginx/conf/server.crt
	chmod 0755 $(TEMP_DIR)/bin/nginx/conf/server.key
	chmod 0755 $(TEMP_DIR)/bin/nginx_rtmp/conf

	find $(TEMP_DIR)/bin/php -exec chmod 550 {} \;
	chmod 0750 $(TEMP_DIR)/bin/php/etc
	chmod 0644 $(TEMP_DIR)/bin/php/etc/1.conf
	chmod 0644 $(TEMP_DIR)/bin/php/etc/2.conf
	chmod 0644 $(TEMP_DIR)/bin/php/etc/3.conf
	chmod 0644 $(TEMP_DIR)/bin/php/etc/4.conf
	chmod 0750 $(TEMP_DIR)/bin/php/sessions
	chmod 0750 $(TEMP_DIR)/bin/php/sockets
	find $(TEMP_DIR)/bin/php/var -type d -exec chmod 750 {} \;
	chmod 0551 $(TEMP_DIR)/bin/php/bin/php
	chmod 0551 $(TEMP_DIR)/bin/php/bin/php
	chmod 0551 $(TEMP_DIR)/bin/php/sbin/php-fpm

	chmod 0755 $(TEMP_DIR)/bin/php/lib/php/extensions/no-debug-non-zts-20210902

	chmod 0755 $(TEMP_DIR)/bin/redis 2>/dev/null || [ $$? -eq 1 ]
	chmod 0755 $(TEMP_DIR)/bin/redis/redis-server 2>/dev/null || [ $$? -eq 1 ]

	chmod 0771 $(TEMP_DIR)/bin/daemons.sh
	chmod 0755 $(TEMP_DIR)/bin/guess
	chmod 0550 $(TEMP_DIR)/bin/blkid
	chmod 0550 $(TEMP_DIR)/bin/free-sans.ttf
	chmod 0550 $(TEMP_DIR)/bin/network
	chmod 0550 $(TEMP_DIR)/bin/youtube

	chmod 0750 $(TEMP_DIR)/content
	find $(TEMP_DIR)/content -exec chmod 750 {} \;
	chmod 0755 $(TEMP_DIR)/content/epg
	chmod 0755 $(TEMP_DIR)/content/playlists
	chmod 0777 $(TEMP_DIR)/content/streams

	chmod 0755 $(TEMP_DIR)/crons
	find $(TEMP_DIR)/crons -type f -exec chmod 777 {} \;
	chmod 0755 $(TEMP_DIR)/includes
	find $(TEMP_DIR)/includes -type f -exec chmod 777 {} \;

	@if [ -d "$(TEMP_DIR)/ministra" ]; then \
		# /ministra \
		chmod 0755 $(TEMP_DIR)/ministra;  \
		find $(TEMP_DIR)/ministra -type d -exec chmod 755 {} +; \
		find $(TEMP_DIR)/ministra -type f -exec chmod 644 {} +; \
		chmod 0777 $(TEMP_DIR)/ministra/portal.php; \
	fi

	@if [ -d "$(TEMP_DIR)/player" ]; then \
		# /player \
		find $(TEMP_DIR)/player -type f -exec chmod 644 {} +; \
		find $(TEMP_DIR)/player -type d -exec chmod 755 {} +; \
	fi

	@if [ -d "$(TEMP_DIR)/reseller" ]; then \
		chmod 0755 $(TEMP_DIR)/reseller; \
		find $(TEMP_DIR)/reseller -type f -exec chmod 777 {} +; \
	fi

	find $(TEMP_DIR)/tmp -type d -exec chmod 755 {} \;
	
	chmod 0755 $(TEMP_DIR)/www
	chmod 0755 $(TEMP_DIR)/www/images
	chmod 0755 $(TEMP_DIR)/www/images/admin
	chmod 0755 $(TEMP_DIR)/www/images/enigma2
	chmod 0750 $(TEMP_DIR)/www/images/admin/index.html
	chmod 0750 $(TEMP_DIR)/www/images/enigma2/index.html
	chmod 0750 $(TEMP_DIR)/www/images/index.html
	chmod 0777 $(TEMP_DIR)/www/api.php
	chmod 0777 $(TEMP_DIR)/www/constants.php
	chmod 0777 $(TEMP_DIR)/www/enigma2.php 2>/dev/null || [ $$? -eq 1 ]
	chmod 0777 $(TEMP_DIR)/www/epg.php 2>/dev/null || [ $$? -eq 1 ]
	chmod 0777 $(TEMP_DIR)/www/index.html
	chmod 0777 $(TEMP_DIR)/www/init.php
	chmod 0777 $(TEMP_DIR)/www/player_api.php 2>/dev/null || [ $$? -eq 1 ]
	chmod 0777 $(TEMP_DIR)/www/playlist.php 2>/dev/null || [ $$? -eq 1 ]
	chmod 0777 $(TEMP_DIR)/www/probe.php 2>/dev/null || [ $$? -eq 1 ]
	chmod 0777 $(TEMP_DIR)/www/progress.php
	chmod 0777 $(TEMP_DIR)/www/stream
	chmod 0777 $(TEMP_DIR)/www/stream/auth.php 2>/dev/null || [ $$? -eq 1 ]
	chmod 0777 $(TEMP_DIR)/www/stream/index.php
	chmod 0777 $(TEMP_DIR)/www/stream/init.php
	chmod 0777 $(TEMP_DIR)/www/stream/key.php
	chmod 0777 $(TEMP_DIR)/www/stream/live.php
	chmod 0777 $(TEMP_DIR)/www/stream/rtmp.php
	chmod 0777 $(TEMP_DIR)/www/stream/segment.php
	chmod 0777 $(TEMP_DIR)/www/stream/subtitle.php
	chmod 0777 $(TEMP_DIR)/www/stream/thumb.php
	chmod 0777 $(TEMP_DIR)/www/stream/timeshift.php
	chmod 0777 $(TEMP_DIR)/www/stream/vod.php
	chmod 0777 $(TEMP_DIR)/www/xplugin.php 2>/dev/null || [ $$? -eq 1 ]

	chmod 0777 $(TEMP_DIR)/service
	chmod 0777 $(TEMP_DIR)/status
	chmod 0777 $(TEMP_DIR)/tmp
	chmod 0777 $(TEMP_DIR)/tools 2>/dev/null || [ $$? -eq 1 ]
	chmod 0777 $(TEMP_DIR)/update
	chmod 0750 $(TEMP_DIR)/signals

	chmod 0750 $(TEMP_DIR)/config
	chmod 0550 $(TEMP_DIR)/config/rclone.conf 2>/dev/null || [ $$? -eq 1 ]

	chmod a+x $(TEMP_DIR)/status
	sudo chmod +x $(TEMP_DIR)/bin/nginx_rtmp/sbin/nginx_rtmp

create_archive:
	@echo "==> Creating final archive: ${TEMP_ARCHIVE_NAME}"
	@tar -czf ${DIST_DIR}/${TEMP_ARCHIVE_NAME} -C $(TEMP_DIR) .

lb_archive_move:
	@echo "==> Moving LB archive to: ${LB_ARCHIVE_DIR}/${LB_ARCHIVE_NAME}"
	@rm -f ${LB_ARCHIVE_DIR}/${LB_ARCHIVE_NAME}
	@mv ${DIST_DIR}/${TEMP_ARCHIVE_NAME} ${LB_ARCHIVE_DIR}/${LB_ARCHIVE_NAME}

main_archive_move:
	@echo "==> Moving MAIN archive to: ${DIST_DIR}/${MAIN_ARCHIVE_NAME}"
	@rm -f ${DIST_DIR}/${MAIN_ARCHIVE_NAME}
	@mv ${DIST_DIR}/${TEMP_ARCHIVE_NAME} ${DIST_DIR}/${MAIN_ARCHIVE_NAME}

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
