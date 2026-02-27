<?php

if (!isset($__viewMode)):

	include 'session.php';
	include 'functions.php';

	if (!checkPermissions()) {
		goHome();
	}

	if (!isset(CoreUtilities::$rRequest['id']) || ($rFolder = getWatchFolder(CoreUtilities::$rRequest['id']))) {
	} else {
		goHome();
	}

	$rBouquets = BouquetService::getAllSimple();
	$_TITLE = 'Add Folder';

	require_once __DIR__ . '/../interfaces/Http/Views/layouts/admin.php';
	renderUnifiedLayoutHeader('admin');

endif; // !$__viewMode

include dirname(__DIR__) . '/modules/watch/views/watch_add.php';

require_once __DIR__ . '/../interfaces/Http/Views/layouts/footer.php';
renderUnifiedLayoutFooter('admin');

include dirname(__DIR__) . '/modules/watch/views/watch_add_scripts.php';
