<?php

include 'session.php';
include 'functions.php';

if (!checkPermissions()) {
    goHome();
}

$rBouquets = BouquetService::getAllSimple();
$_TITLE = 'Watch Settings';

require_once __DIR__ . '/../interfaces/Http/Views/layouts/admin.php';
renderUnifiedLayoutHeader('admin');
include dirname(__DIR__) . '/modules/watch/views/settings_watch.php';
require_once __DIR__ . '/../interfaces/Http/Views/layouts/footer.php';
renderUnifiedLayoutFooter('admin');
include dirname(__DIR__) . '/modules/watch/views/settings_watch_scripts.php';
