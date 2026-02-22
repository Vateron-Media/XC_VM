<?php

include 'session.php';
include 'functions.php';

if (!checkPermissions()) {
    goHome();
}

$rBouquets = getBouquets();
$_TITLE = 'Plex Settings';

include 'header.php';
include dirname(__DIR__) . '/modules/plex/views/settings.php';
include 'footer.php';
include dirname(__DIR__) . '/modules/plex/views/settings_scripts.php';