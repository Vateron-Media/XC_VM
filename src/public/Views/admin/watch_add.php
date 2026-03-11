<?php

if (!isset($__viewMode)):

	include 'session.php';
	include 'functions.php';

	if (!checkPermissions()) {
		goHome();
	}

	if (!isset(RequestManager::getAll()['id']) || ($rFolder = getWatchFolder(RequestManager::getAll()['id']))) {
	} else {
		goHome();
	}

	$rBouquets = BouquetService::getAllSimple();
	$_TITLE = 'Add Folder';

	require_once __DIR__ . '/../layouts/admin.php';
	renderUnifiedLayoutHeader('admin');

endif; // !$__viewMode

include dirname(__DIR__, 3) . '/modules/watch/views/watch_add.php';

require_once __DIR__ . '/../layouts/footer.php';
renderUnifiedLayoutFooter('admin');

include dirname(__DIR__, 3) . '/modules/watch/views/watch_add_scripts.php';
