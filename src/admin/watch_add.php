<?php

include 'session.php';
include 'functions.php';

if (!checkPermissions()) {
	goHome();
}

if (!isset(CoreUtilities::$rRequest['id']) || ($rFolder = getWatchFolder(CoreUtilities::$rRequest['id']))) {
} else {
	goHome();
}

$rBouquets = getBouquets();
$_TITLE = 'Add Folder';

include 'header.php';
include dirname(__DIR__) . '/modules/watch/views/watch_add.php';
include 'footer.php';
include dirname(__DIR__) . '/modules/watch/views/watch_add_scripts.php';
