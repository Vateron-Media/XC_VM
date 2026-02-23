<?php

include 'session.php';
include 'functions.php';

if (!checkPermissions()) {
	goHome();
}

$_TITLE = 'Watch Folder';

include 'header.php';
include dirname(__DIR__) . '/modules/watch/views/watch.php';
include 'footer.php';
include dirname(__DIR__) . '/modules/watch/views/watch_scripts.php';
