<?php

if (!isset($__viewMode)):

	include 'session.php';
	include 'functions.php';

	if (!checkPermissions()) {
		goHome();
	}

	$_TITLE = 'Plex Sync';
	$rPlexServers = PlexRepository::getPlexServers();

	require_once __DIR__ . '/../interfaces/Http/Views/layouts/admin.php';
	renderUnifiedLayoutHeader('admin');

endif; // !$__viewMode
include dirname(__DIR__) . '/modules/plex/views/index.php';

require_once __DIR__ . '/../interfaces/Http/Views/layouts/footer.php';
renderUnifiedLayoutFooter('admin');
include dirname(__DIR__) . '/modules/plex/views/library_scripts.php';
