<?php

include 'session.php';
include 'functions.php';

if (!checkPermissions()) {
	goHome();
}

$_TITLE = 'Watch Folder Logs';

include 'header.php';
include dirname(__DIR__) . '/modules/watch/views/watch_output.php';
include 'footer.php';
include dirname(__DIR__) . '/modules/watch/views/watch_output_scripts.php';
