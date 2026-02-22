<?php

include 'session.php';
include 'functions.php';

if (!checkPermissions()) {
    goHome();
}

$rBouquets = getBouquets();
$_TITLE = 'Watch Settings';

include 'header.php';
include dirname(__DIR__) . '/modules/watch/views/settings_watch.php';
include 'footer.php';
include dirname(__DIR__) . '/modules/watch/views/settings_watch_scripts.php';
