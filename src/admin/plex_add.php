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
$_TITLE = 'Add Library';

include 'header.php';
include dirname(__DIR__) . '/modules/plex/views/library_edit.php';
include 'footer.php';
include dirname(__DIR__) . '/modules/plex/views/library_edit_scripts.php';