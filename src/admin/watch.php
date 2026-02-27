<?php

if (!isset($__viewMode)):

	include 'session.php';
	include 'functions.php';

	if (!checkPermissions()) {
		goHome();
	}

	$_TITLE = 'Watch Folder';

	require_once __DIR__ . '/../interfaces/Http/Views/layouts/admin.php';
	renderUnifiedLayoutHeader('admin');

endif; // !$__viewMode

include dirname(__DIR__) . '/modules/watch/views/watch.php';

require_once __DIR__ . '/../interfaces/Http/Views/layouts/footer.php';

renderUnifiedLayoutFooter('admin');
include dirname(__DIR__) . '/modules/watch/views/watch_scripts.php';
