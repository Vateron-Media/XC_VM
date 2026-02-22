<?php

include 'session.php';
include 'functions.php';

if (!checkPermissions()) {
	goHome();
}

$_TITLE = 'Plex Sync';
$rPlexServers = getPlexServers();

include 'header.php';
include dirname(__DIR__) . '/modules/plex/views/index.php';
include 'footer.php';
include dirname(__DIR__) . '/modules/plex/views/library_scripts.php';
