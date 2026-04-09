<?php

if (!isset($__viewMode)):

	include 'session.php';
	include 'functions.php';

	if (!PageAuthorization::checkPermissions()) {
		AdminHelpers::goHome();
	}

	$_TITLE = 'Watch Folder';

	require_once __DIR__ . '/../layouts/admin.php';
	renderUnifiedLayoutHeader('admin');

endif; // !$__viewMode

include dirname(__DIR__, 3) . '/modules/watch/views/watch.php';

require_once __DIR__ . '/../layouts/footer.php';

renderUnifiedLayoutFooter('admin');
include dirname(__DIR__, 3) . '/modules/watch/views/watch_scripts.php';
